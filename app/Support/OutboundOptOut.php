<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * The unsubscribe that both shopper-triggered emails carry (Lane EN).
 *
 * ── ONE TOKEN SCHEME, NOT A SECOND ONE ──────────────────────────────────────
 *
 * The links here are signed by Support\CustomerLinkSigner, the same primitive
 * the email-verification link and the newsletter round trip use, and for the
 * reasons set out at length in that class: this install carries the signed-URL
 * path-confusion advisory, and every route is prefixed by KBB_BASE_PATH, so a
 * signature computed over a rendered URL is a link that is valid when it is
 * sent and rejected when it is clicked. Nothing new is invented here — only two
 * new PURPOSE strings, which is what keeps a stock-alert token from acting as a
 * cart-recovery one.
 *
 * The claims are the row id and a DIGEST of the address, exactly as
 * NewsletterList::claims() builds them. The address never appears in the URL:
 * a query string ends up in Referer headers, in proxy logs and in whatever an
 * inbox provider does when it prefetches links.
 *
 * ── THE DECOY ───────────────────────────────────────────────────────────────
 *
 * CLAUDE.md records that Api\QuizController::expertRequest looks its row up
 * BEFORE it checks the signature so that a forged token and an id that was
 * never issued do the same work and return the same answer, and that branching
 * differently on the two restores the id oracle. NewsletterList::rowOrDecoy()
 * keeps that shape and so does `resolve()` below.
 *
 * It matters more here than it looks. `stock_alerts` ids are small sequential
 * integers, and "does row 41 exist" is "did somebody ask to be told when THAT
 * product came back" — which, on a shop selling what this one sells, is a
 * question about a person. The decoy makes both answers cost the same.
 *
 * ── TEN YEARS ───────────────────────────────────────────────────────────────
 *
 * The same number NewsletterList::UNSUBSCRIBE_TTL uses and for the same reason:
 * an unsubscribe link that has expired costs somebody the ability to get off a
 * list they are still being mailed on, and it expires precisely in the case
 * that matters — the message somebody finds in a folder two years later.
 */
final class OutboundOptOut
{
    /** Separate purposes, so one kind of token can never act as the other. */
    public const PURPOSE_STOCK = 'stock-alert-unsubscribe';

    public const PURPOSE_CART = 'cart-recovery-unsubscribe';

    /** Effectively forever, with a bound on it. See the header. */
    public const TTL = 60 * 60 * 24 * 365 * 10;

    /**
     * kind => [table, purpose].
     *
     * The kind travels in the path, so it is matched against this closed list
     * and never used to name a table directly.
     */
    public const KINDS = [
        'stock' => ['stock_alerts', self::PURPOSE_STOCK],
        'cart' => ['cart_recoveries', self::PURPOSE_CART],
    ];

    /** Normalised the one way, everywhere, so two spellings are one address. */
    public static function normalise(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * Has this address told us to stop?
     *
     * Guarded against the table's absence, like everything else that runs in a
     * request path on this install: a package can land before its migration
     * runs (see PackageMigrationFlagTest), and the safe answer to "is this
     * address suppressed" when the list cannot be read is TRUE. Failing closed
     * costs a shopper an email they asked for; failing open sends one to
     * somebody who asked us not to, and only one of those is recoverable.
     */
    public static function suppressed(string $email): bool
    {
        try {
            return DB::table('outbound_optouts')
                ->where('email', self::normalise($email))
                ->exists();
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * Record an opt-out. Idempotent by the unique index, not by a prior read.
     *
     * insertOrIgnore rather than updateOrInsert: there is nothing to update,
     * and a second press must not move `created_at`, which is the record of
     * when the person said stop.
     */
    public static function suppress(string $email): void
    {
        $email = self::normalise($email);

        if ($email === '') {
            return;
        }

        try {
            DB::table('outbound_optouts')->insertOrIgnore([
                'email' => $email,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable) {
            // A suppression that cannot be written must not 500 the page the
            // recipient is standing on. The caller still cancels the row it
            // came from, so this message's sequence stops either way.
        }
    }

    /**
     * The link that goes in an email.
     *
     * Path-and-query only in the signature, never a host — Url::redirect()
     * renders the absolute form afterwards, and the signature does not depend
     * on it.
     */
    public static function link(string $kind, int $id, string $email, ?int $expiresAt = null): string
    {
        $purpose = self::KINDS[$kind][1] ?? '';
        $expiresAt ??= time() + self::TTL;

        return Url::external(sprintf(
            '/mail-preferences/%s/%d/?expires=%d&signature=%s',
            $kind,
            $id,
            $expiresAt,
            CustomerLinkSigner::sign($purpose, self::claims($id, $email), $expiresAt),
        ));
    }

    /**
     * Verify a link and act on it: suppress the address and cancel the row it
     * came from.
     *
     * Returns a plain bool, and the SAME bool for a forged signature, an
     * expired one, an unknown kind and an id that was never issued. The caller
     * renders one page for all of them.
     */
    public static function act(string $kind, int $id, int $expiresAt, string $signature): bool
    {
        if (! array_key_exists($kind, self::KINDS)) {
            /*
             * An unknown kind still costs a signature computation against a
             * decoy, so that a probe cannot learn which kinds exist by timing
             * the difference. `stock` is chosen as the stand-in purpose only
             * because it has to be something; nothing can verify against it.
             */
            $kind = 'stock';
            $id = 0;
        }

        [$table, $purpose] = self::KINDS[$kind];

        $row = self::resolve($table, $id);

        if (! CustomerLinkSigner::verify($purpose, self::claims($id, $row['email']), $expiresAt, $signature)) {
            return false;
        }

        // A decoy carries a random address, so no signature this application
        // ever issued can match the claims it produced. It never gets here.
        if (! $row['real']) {
            return false;
        }

        self::suppress($row['email']);

        try {
            if ($table === 'cart_recoveries') {
                /*
                 * Cancelled, not deleted. A deleted row is indistinguishable
                 * from a cart that was never captured, so the next capture
                 * would start a fresh sequence for the same basket — the exact
                 * thing the unique index exists to prevent, defeated by the
                 * unsubscribe. NewsletterList::unsubscribe() keeps its row for
                 * the same reason.
                 */
                DB::table('cart_recoveries')
                    ->where('id', $id)
                    ->whereNull('cancelled_at')
                    ->update([
                        'cancelled_at' => now(),
                        'cancel_reason' => 'unsubscribed',
                        'updated_at' => now(),
                    ]);
            } else {
                /*
                 * A pending stock alert is spent rather than deleted, by the
                 * same mechanism a send uses: `slot` moves off 'pending' so the
                 * unique index no longer holds it, and `notified_at` is stamped
                 * so the sweep cannot see it. The row stays as the record that
                 * a request existed and was closed.
                 */
                DB::table('stock_alerts')
                    ->where('id', $id)
                    ->whereNull('notified_at')
                    ->update([
                        'notified_at' => now(),
                        'slot' => 'sent:' . $id,
                        'updated_at' => now(),
                    ]);
            }
        } catch (\Throwable) {
            // The suppression above is the load-bearing half and it is already
            // written. A row that could not be closed is caught by the
            // due-queries, which exclude suppressed addresses.
        }

        return true;
    }

    /* ------------------------------------------------------------ internals */

    /**
     * The claims a signature covers. Identical in shape to
     * NewsletterList::claims(), deliberately — one scheme, two purposes.
     *
     * @return array<string, string>
     */
    private static function claims(int $id, string $email): array
    {
        return [
            'id' => (string) $id,
            'hash' => hash('sha256', self::normalise($email)),
        ];
    }

    /**
     * The row, or something shaped like it. THE ORACLE-CLOSING HALF.
     *
     * The decoy's address is random per call rather than a constant, so the
     * comparison it feeds cannot be precomputed either.
     *
     * @return array{real: bool, email: string}
     */
    private static function resolve(string $table, int $id): array
    {
        try {
            $email = DB::table($table)->where('id', $id)->value('email');
        } catch (\Throwable) {
            $email = null;
        }

        if (is_string($email) && $email !== '') {
            return ['real' => true, 'email' => $email];
        }

        return ['real' => false, 'email' => bin2hex(random_bytes(16)) . '@invalid.example'];
    }
}
