<?php

declare(strict_types=1);

namespace App\Services\Mail\Kit;

use App\Support\Url;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * "View this email in your browser" — Lane RM.
 *
 * ── WHAT THE LINK IS ───────────────────────────────────────────────────────
 *
 * A capability, not an address: /mail/view/{token}, where the token is 256
 * random bits. The server keeps only its SHA-256 beside the HTML that was
 * sent and an expiry, so a database read cannot be turned back into a working
 * link, and a forged, mistyped, expired or never-sent token all reach the same
 * single indexed lookup and the same 404 (WebCopyController) — there is no
 * second branch to time and no id to walk.
 *
 * ── WHAT IT SHOWS, AND TO WHOM ─────────────────────────────────────────────
 *
 * Exactly the HTML that went to the recipient, byte for byte, captured from
 * the outgoing message itself (Listeners\StoreMailWebCopy on MessageSending)
 * — not re-rendered later from data that may since have changed, and nothing
 * the recipient was not already shown. Whoever holds the email holds the link,
 * which is the same audience the email has.
 *
 * ── WHY MINT AT RENDER AND STORE AT SEND ───────────────────────────────────
 *
 * The link has to be inside the HTML before the HTML exists, so the footer
 * mints a token while it renders and remembers it here. Only a message that is
 * actually handed to the transport is stored: an admin preview, a test render
 * or a mailable built and then abandoned leaves a token nobody will ever be
 * able to open. Rows expire after DAYS and are pruned as new ones arrive.
 *
 * ── IT CANNOT COST AN EMAIL ────────────────────────────────────────────────
 *
 * mint() answers null (the footer then prints no link) when the table is not
 * there yet, and capture() swallows and logs everything. It writes with a
 * query builder insert and touches no model, so a failure leaves no state
 * behind to be re-sent by a later save — the UpdateRunner lesson in CLAUDE.md.
 */
final class WebCopy
{
    public const TABLE = 'mail_web_copies';

    /** How long a browser copy can be opened. */
    public const DAYS = 45;

    /** The route's path prefix; routes/mail-kit.php serves it. */
    public const PATH = '/mail/view/';

    /** base64url of 32 random bytes is always exactly 43 characters. */
    public const TOKEN_PATTERN = '[A-Za-z0-9_-]{43}';

    /** A stored copy larger than this (compressed) is not kept. */
    private const MAX_STORED_BYTES = 200_000;

    /** @var array<string, true> tokens minted in this process and not yet sent */
    private static array $pending = [];

    private static ?bool $ready = null;

    /** A fresh link for the email being rendered, or null. */
    public static function mint(): ?string
    {
        if (! self::ready()) {
            return null;
        }

        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        // A long-lived worker renders many messages; never let the list grow.
        if (count(self::$pending) >= 64) {
            self::$pending = array_slice(self::$pending, -32, null, true);
        }

        self::$pending[$token] = true;

        return Url::external(self::PATH . $token);
    }

    /**
     * Store the sent HTML under every token of ours it carries. Returns how
     * many copies were written. Never throws.
     */
    public static function capture(?string $html): int
    {
        if (self::$pending === [] || $html === null || $html === '') {
            return 0;
        }

        $written = 0;

        try {
            foreach (array_keys(self::$pending) as $token) {
                if (! str_contains($html, self::PATH . $token)) {
                    continue;
                }

                unset(self::$pending[$token]);

                $body = base64_encode((string) gzdeflate($html, 6));

                if (strlen($body) > self::MAX_STORED_BYTES) {
                    continue;
                }

                $now = now();

                DB::table(self::TABLE)->insert([
                    'token_hash' => hash('sha256', $token),
                    'body' => $body,
                    'expires_at' => $now->copy()->addDays(self::DAYS),
                    'created_at' => $now,
                ]);

                $written++;
            }

            if ($written > 0) {
                DB::table(self::TABLE)->where('expires_at', '<', now())->limit(200)->delete();
            }
        } catch (\Throwable $e) {
            Log::warning('mail web copy not stored', [
                'exception' => class_basename($e),
                'message' => $e->getMessage(),
            ]);
        }

        return $written;
    }

    /**
     * The HTML for a token, or null — malformed, unknown and expired alike.
     */
    public static function find(string $token): ?string
    {
        if (preg_match('/^' . self::TOKEN_PATTERN . '$/', $token) !== 1) {
            // Still one hash and one lookup, so a malformed token costs what a
            // well-formed unknown one does.
            $token = str_repeat('x', 43);
        }

        try {
            $row = DB::table(self::TABLE)
                ->where('token_hash', hash('sha256', $token))
                ->where('expires_at', '>', now())
                ->first(['body']);
        } catch (\Throwable) {
            return null;
        }

        if ($row === null) {
            return null;
        }

        $html = gzinflate((string) base64_decode((string) $row->body, true));

        return is_string($html) && $html !== '' ? $html : null;
    }

    /** Tests: forget what this process minted. */
    public static function reset(): void
    {
        self::$pending = [];
        self::$ready = null;
    }

    private static function ready(): bool
    {
        if (self::$ready === null) {
            try {
                self::$ready = Schema::hasTable(self::TABLE);
            } catch (\Throwable) {
                self::$ready = false;
            }
        }

        return self::$ready;
    }
}
