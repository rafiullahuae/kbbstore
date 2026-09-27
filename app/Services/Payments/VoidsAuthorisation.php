<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\Order;

/**
 * A gateway whose authorisation can be released before it is captured.
 *
 * ── WHY THIS IS A THIRD VERB AND NOT A REFUND ───────────────────────────────
 *
 * SettlesPayments has two: take the money, and give it back. Between them sits
 * a state that only the BNPL gateways have, and that neither verb reaches.
 *
 * Tabby and Tamara AUTHORISE at checkout. The buyer has signed up to an
 * instalment plan, the plan exists on their Tamara account, and the merchant
 * has not been paid. If the shop then cancels the order — out of stock, a
 * duplicate, a customer who rang up — three things are true at once:
 *
 *   - capture() must not be called. PaymentCapturer already refuses a
 *     cancelled order, and its own comment explains why: the units went back
 *     on the shelf and have since been sold.
 *   - refund() cannot be called. There is nothing to refund; Tamara's own
 *     refund endpoint requires a capture_id and this order has none. Our
 *     TamaraGateway::refund() says so in as many words — `not_captured`.
 *   - and the authorisation is STILL LIVE at Tamara, for up to 180 days.
 *
 * So the shop cancels, restocks, releases the coupon, emails the customer —
 * and the customer's Tamara account still shows an active payment plan for an
 * order that no longer exists. They are asked for the first instalment. They
 * ring Tamara, Tamara points at the merchant, and there is no record on the
 * order of anyone having tried to release it, because nothing ever did.
 *
 * The merchant's WooCommerce plugin has this and it is not an afterthought
 * there: `WCTamaraGateway::tamaraCancelOrder()` hangs off the order-status
 * transition to `cancelled`, calls `POST /orders/{id}/cancel` when there is no
 * capture id, and when there IS one it refuses and writes the note "The order
 * can not be cancelled as it was captured. Please try Refund instead." Both
 * halves of that are the contract below.
 *
 * ── THE CONTRACT ────────────────────────────────────────────────────────────
 *
 *   1. **Never after a capture.** Money that has moved is a refund, and a void
 *      attempted on a captured order must fail with a code that says so rather
 *      than a generic one — the operator's next action is different.
 *   2. **Idempotent, and a repeat is a success.** A provider that reports the
 *      authorisation already cancelled returns ok() with `already_voided`. An
 *      operator pressing the button twice, or a cancel arriving from two
 *      places, must not produce an error that reads like a failure to release.
 *   3. **Never throw.** Same as SettlesPayments: a provider being down must
 *      not 500 the order screen. Return SettlementResult::failed().
 *   4. **Integer fils in.** The amount is the order's own, and the gateway
 *      converts. No floats cross this interface.
 *
 * Cash on delivery does not implement this — there is no authorisation to
 * release, and `cancelled` is the whole of the release. Stripe does not either:
 * a PaymentIntent is voided through StripeGateway::abandonIntent(), which
 * predates this interface, is called from the checkout rather than from the
 * order screen, and answers a different question (may this intent still be
 * confirmed by a stale browser tab). Folding the two together would mean one
 * method whose two callers want different refusals.
 */
interface VoidsAuthorisation
{
    /**
     * Release the authorisation this order is holding.
     *
     * @param  int  $amountFils  the authorised amount, from the order's own
     *                           column. Tamara wants the order's figures echoed
     *                           back on a cancel exactly as it does on a
     *                           capture, and they have to balance.
     */
    public function void(Order $order, int $amountFils): SettlementResult;
}
