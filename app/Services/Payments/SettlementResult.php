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
    ) {}

    public static function ok(string $code, ?string $reference = null, array $summary = [], ?string $message = null): self
    {
        return new self(true, $code, $reference, $summary, $message);
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
