<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\Order;

/**
 * A gateway whose money can be taken and given back.
 *
 * Confirmation and capture are not the same event, and conflating them is the
 * outage this interface exists to prevent. Tabby and Tamara AUTHORISE at
 * checkout: the buyer is committed and the funds are held, but nothing has
 * moved. An authorisation that is never captured is auto-voided by both
 * providers, at which point the goods have shipped and the merchant is not
 * paid. PaymentConfirmer marks the order paid; this interface is what
 * eventually makes that true.
 *
 * Three rules every implementation follows:
 *
 *   1. **Integer fils in, integer fils out.** Conversion to whatever decimal
 *      shape the provider wants happens inside the gateway class, through
 *      RemoteGateway::toMajor(), and nowhere else.
 *   2. **Never trust a caller's amount.** The amount arriving here has already
 *      been checked against the order and the ledger by PaymentCapturer or
 *      PaymentRefunder. A gateway does not re-derive it from a request.
 *   3. **Never throw.** A provider being down must not 500 the admin panel.
 *      Return SettlementResult::failed() and let the caller record it.
 */
interface SettlesPayments
{
    /**
     * How long after authorisation the money can still be captured.
     *
     * A sentence for the admin screen, not a parseable value — see
     * captureWindowDays() for the number. Every gateway has a different answer
     * and for two of them it is a merchant setting rather than a constant, so
     * this is written per gateway rather than assumed.
     */
    public function captureWindow(): string;

    /**
     * Days from authorisation within which capture must happen, or null when
     * the gateway has no window at all (cash on delivery).
     *
     * Advisory. The authority on whether a specific payment can still be
     * captured is the gateway's own live status, which capture() re-reads
     * before it does anything — this number is what lets the admin screen warn
     * BEFORE the window runs out rather than explain afterwards.
     */
    public function captureWindowDays(): ?int;

    /**
     * Take the authorised money.
     *
     * Must be safe to call twice: a gateway that reports the payment already
     * captured returns ok() with the `already_captured` code rather than
     * failing, so a retry after a dropped connection settles rather than
     * alarms.
     */
    public function capture(Order $order, int $amountFils): SettlementResult;

    /**
     * Give some or all of it back.
     *
     * @param  string|null  $captureRef  the provider's capture id, which Tabby
     *                                   and Tamara both require on a refund.
     * @param  string|null  $idempotencyKey  our own refund key, passed on to any
     *                                   provider that has a native idempotency
     *                                   header. Our unique index is the real
     *                                   guard; this stops a retry that never
     *                                   reached us from charging back twice at
     *                                   the provider's end either.
     */
    public function refund(
        Order $order,
        int $amountFils,
        ?string $reason,
        ?string $captureRef,
        ?string $idempotencyKey = null,
    ): SettlementResult;
}
