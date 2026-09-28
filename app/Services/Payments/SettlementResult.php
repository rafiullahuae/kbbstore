<?php

declare(strict_types=1);

namespace App\Services\Payments;

/**
 * What a gateway's capture or refund call actually did.
 *
 * Deliberately a separate shape from WebhookOutcome. A webhook outcome is an
 * HTTP answer written for the provider's delivery log; this is an answer
 * written for our own ledger and for the admin who clicked the button, so it
 * carries a machine code and a small bag of named fields instead of a status
 * code and a sentence.
 *
 * `summary` is what lands in `payment_events.payload`. It is built by the
 * gateway from NAMED fields of the provider's response — never the response
 * itself. A capture response carries item titles and a refund response can
 * echo the buyer block back; a raw copy of either in our audit table is buyer
 * data we were never asked to keep.
 */
final class SettlementResult
{
    private function __construct(
        public readonly bool $ok,
        /** Short machine code: captured | already_captured | refunded | gateway_error | ... */
        public readonly string $code,
        /** The provider's own id for the capture or refund, when it gave one. */
        public readonly ?string $reference = null,
        /** Safe, named fields for the audit row. Never a raw body. */
        public readonly array $summary = [],
        /** Shown to the admin. Never carries an API body, a key or a buyer field. */
        public readonly ?string $message = null,
        /**
         * What the PROVIDER says it has actually taken, in integer fils, or
         * null when it named no figure on this path.
         *
         * ── WHY THIS FIELD EXISTS ──────────────────────────────────────────
         *
         * PaymentCapturer wrote `captured_total` = the amount it ASKED for on
         * every success, including the successes where the provider had
         * already reached a captured state without us. Three of the four
         * gateways return `already_captured` on exactly that, and on all three
         * that state can be a PARTIAL capture: Tamara's own
         * `partially_captured`, a Tabby payment CLOSED over a short capture, a
         * Stripe intent whose `amount_received` is below its `amount`. An order
         * captured at 120.00 of 300.00 recorded 300.00 — and `captured_total`
         * is the ceiling PaymentRefunder::capturedFils() measures a refund
         * against, so the shop would hand back 180.00 it was never paid.
         *
         * ── WHY NULLABLE RATHER THAN DEFAULTING TO THE REQUESTED AMOUNT ────
         *
         * Null is not zero and not "all of it". It means "the provider named no
         * figure on this path, so the caller should use what it requested" —
         * which is what cash on delivery reports (there is no provider to ask)
         * and what an ordinary capture this shop made itself reports. A gateway
         * that filled this in with the requested amount would make "the
         * provider agreed" and "we assumed" indistinguishable one layer up, and
         * that distinction is the whole of what this field is for.
         *
         * ── MONEY ──────────────────────────────────────────────────────────
         *
         * Integer fils, always. The two major-unit APIs (Tabby, Tamara) come
         * through RemoteGateway::toFils() and its round(), never an (int) cast
         * on a float; Stripe is already in minor units. No float reaches this
         * property and none is stored in it.
         */
        public readonly ?int $capturedFils = null,
    ) {}

    public static function ok(
        string $code,
        ?string $reference = null,
        array $summary = [],
        ?string $message = null,
        ?int $capturedFils = null,
    ): self {
        return new self(true, $code, $reference, $summary, $message, $capturedFils);
    }

    /**
     * The provider refused, or could not be reached.
     *
     * Every one of these is written to `payment_events` by the caller before
     * it is returned. A refund that silently failed is money the merchant
     * believes has gone back.
     */
    public static function failed(string $code, array $summary = [], ?string $message = null): self
    {
        return new self(false, $code, null, $summary, $message);
    }
}
