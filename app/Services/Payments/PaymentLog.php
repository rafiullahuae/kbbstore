<?php

declare(strict_types=1);

namespace App\Services\Payments;

use Illuminate\Support\Facades\DB;

/**
 * The payment log on Store -> Payments -> Stripe. (Lane SR.)
 *
 * What the WooCommerce Stripe plugin calls "Log debug messages", for the owner
 * rather than for a shell: gateway events, API errors and webhook outcomes, the
 * newest first, readable in the admin.
 *
 * ── WHAT NEVER GOES IN ────────────────────────────────────────────────────
 *
 * Belt and braces, because a log is where secrets leak from:
 *
 *   1. CONTEXT IS AN ALLOWLIST. Only the keys in ALLOWED survive, only as
 *      scalars, each cut to 120 characters. A caller cannot hand this class a
 *      request body or a Stripe object and have it written: anything not named
 *      below is dropped before it is looked at.
 *   2. EVERY STRING IS SCRUBBED BY PATTERN: Stripe secret and restricted keys
 *      (sk_/rk_), webhook signing secrets (whsec_), our own URL secret
 *      (whsec-...), PaymentIntent/SetupIntent client secrets (..._secret_...),
 *      and any run of 13–19 digits, which is the shape of a card number
 *      whatever separates its groups. The card number never reaches this
 *      server at all (Stripe Elements), so the last rule should never fire;
 *      it is here so that it cannot be the first time.
 *   3. NOTHING HERE READS A REQUEST. Callers pass what they decided to pass.
 *
 * ── BOUNDED ───────────────────────────────────────────────────────────────
 *
 * KEEP rows, enforced on every write by deleting everything older than the
 * newest KEEP — one indexed DELETE on the primary key, so the table cannot
 * grow however busy the shop gets or however long nobody opens the screen.
 *
 * ── AND IT CANNOT BREAK A PAYMENT ─────────────────────────────────────────
 *
 * A log write that fails (table missing before its migration ran, database
 * busy) is swallowed. It is a query-builder insert with no model instance, so
 * a failure leaves no dirty state behind to be re-sent by a later save — the
 * CLAUDE.md landmine about UpdateRunner::recordManifest() is exactly why it is
 * not an Eloquent model.
 */
final class PaymentLog
{
    public const TABLE = 'payment_logs';

    public const KEEP = 500;

    public const LEVELS = ['info', 'error'];

    /** The context keys that may be written, and nothing else. */
    public const ALLOWED = [
        'order', 'reference', 'payment_intent', 'refund', 'event_id', 'event_type',
        'endpoint', 'http_status', 'error_code', 'decline_code', 'error_type', 'error_message',
        'status', 'outcome', 'detail', 'amount', 'currency', 'capture_method', 'action',
        'webhook_endpoint', 'events', 'removed', 'mode', 'reason',
    ];

    /** One write. Never throws. */
    public static function record(string $gateway, string $level, string $event, string $message, array $context = [], ?string $mode = null): void
    {
        try {
            $id = DB::table(self::TABLE)->insertGetId([
                'gateway' => substr($gateway, 0, 20),
                'mode' => $mode !== null ? substr($mode, 0, 8) : null,
                'level' => in_array($level, self::LEVELS, true) ? $level : 'info',
                'event' => substr(self::scrub($event), 0, 60),
                'message' => mb_substr(self::scrub($message), 0, 255),
                'context' => ($clean = self::clean($context)) === [] ? null : json_encode($clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
            ]);

            if ($id > self::KEEP) {
                DB::table(self::TABLE)->where('id', '<=', $id - self::KEEP)->delete();
            }
        } catch (\Throwable) {
            // The log is for troubleshooting; it must never become the trouble.
        }
    }

    /**
     * The newest rows for a gateway, for the admin screen.
     *
     * @return list<array<string, mixed>>
     */
    public static function recent(string $gateway, int $limit = 100): array
    {
        try {
            return DB::table(self::TABLE)
                ->where('gateway', $gateway)
                ->orderByDesc('id')
                ->limit(max(1, min($limit, self::KEEP)))
                ->get(['id', 'mode', 'level', 'event', 'message', 'context', 'created_at'])
                ->map(fn ($row) => self::present($row))
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * The newest row of one event type, or null.
     *
     * @return array<string, mixed>|null
     */
    public static function latest(string $gateway, string $event): ?array
    {
        try {
            $row = DB::table(self::TABLE)
                ->where('gateway', $gateway)
                ->where('event', $event)
                ->orderByDesc('id')
                ->first(['id', 'mode', 'level', 'event', 'message', 'context', 'created_at']);

            return $row === null ? null : self::present($row);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Removes secrets and card-shaped digit runs from one string. */
    public static function scrub(string $value): string
    {
        $value = preg_replace('/\b(sk|rk)_(test|live)_[A-Za-z0-9]+/', '$1_$2_[redacted]', $value) ?? '';
        $value = preg_replace('/\bwhsec[_-][A-Za-z0-9_-]+/', 'whsec_[redacted]', $value) ?? '';
        $value = preg_replace('/\b((pi|seti|pm|src)_[A-Za-z0-9]+)_secret_[A-Za-z0-9]+/', '$1_secret_[redacted]', $value) ?? '';
        // 13-19 digits, optionally grouped by spaces or dashes: a card number.
        $value = preg_replace('/\b(?:\d[ -]?){12,18}\d\b/', '[card-like number removed]', $value) ?? '';

        return $value;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, string|int|bool|null>
     */
    private static function clean(array $context): array
    {
        $out = [];

        foreach ($context as $key => $value) {
            if (! is_string($key) || ! in_array($key, self::ALLOWED, true)) {
                continue;
            }

            if (is_array($value)) {
                $value = implode(', ', array_filter($value, 'is_scalar'));
            }

            if ($value === null || is_bool($value) || is_int($value)) {
                $out[$key] = $value;

                continue;
            }

            if (! is_scalar($value)) {
                continue;
            }

            $out[$key] = mb_substr(self::scrub((string) $value), 0, 120);
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private static function present(object $row): array
    {
        $context = json_decode((string) ($row->context ?? ''), true);

        return [
            'id' => (int) $row->id,
            'mode' => $row->mode,
            'level' => (string) $row->level,
            'event' => (string) $row->event,
            'message' => (string) $row->message,
            'context' => is_array($context) ? $context : [],
            'at' => $row->created_at !== null ? \Illuminate\Support\Carbon::parse($row->created_at)->toIso8601String() : null,
        ];
    }
}
