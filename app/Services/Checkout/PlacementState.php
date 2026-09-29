<?php

declare(strict_types=1);

namespace App\Services\Checkout;

use App\Models\Order;
use App\Services\Payments\GatewayRegistry;

/**
 * Has this order actually been placed? — asked of the shop, never of the URL.
 *
 * ── WHY THIS EXISTS ─────────────────────────────────────────────────────────
 *
 * The owner asked for an animated tick the moment an order is placed, and for
 * that tick to be waiting on the order-received page when a shopper comes back
 * from Tabby or Tamara. The single worst thing available here is a tick for an
 * order that was declined, and the easiest way to build one is to hang the
 * animation on "we are on /checkout/success", which is a page a shopper reaches
 * by pressing Back at the provider just as surely as by paying.
 *
 * So the tick is a SERVER decision read off the order's own row, and this class
 * is the only place that decision is made. Nothing is carried across the
 * redirect — not in the query string, not in storage, not in a cookie — because
 * nothing carried by the browser is evidence of a payment.
 *
 * ── THE THREE ANSWERS ───────────────────────────────────────────────────────
 *
 *   REFUSED    the shop has recorded that this order will not happen —
 *              `failed` (the gateway refused, or the card form could not be
 *              completed) or `cancelled`. No tick, and the shopper is told.
 *   CONFIRMED  the shop is satisfied the order stands. Tick.
 *   AWAITING   the order exists and the money has not been confirmed yet. This
 *              is the ordinary state for the first seconds after a Tamara
 *              return, because Tamara notifies by webhook and a shopper on a
 *              fast connection beats it home. No tick; an honest, BOUNDED
 *              "confirming" state instead — see partials/checkout/placed-tick.
 *
 * ── WHY `paid_at` IS NOT THE WHOLE TEST ─────────────────────────────────────
 *
 * A cash-on-delivery order never carries `paid_at` and never will: the money
 * moves at the door. Testing `paid_at` alone would therefore leave every COD
 * order — which on this shop is most of them — sitting in the "confirming"
 * state for ever.
 *
 * The question that actually separates them is "does this gateway need an
 * online payment before the order stands", and the gateway is asked it:
 * PaymentGateway::journey() answers `placed` for cash on delivery, `confirm`
 * for the card fields and the two wallets, `redirect` for Tabby and Tamara.
 * There is no list of gateway ids here or anywhere else this lane wrote, so a
 * gateway added tomorrow is classified by its own author rather than missed.
 *
 * An unknown `payment_method` — a gateway the owner has since switched off, or
 * an order imported from the old shop — resolves to no gateway at all, and the
 * answer then falls through to the status and `paid_at`, which is the
 * conservative half: an order nobody can identify is AWAITING rather than
 * CONFIRMED, so the worst it can do is withhold an animation.
 */
final class PlacementState
{
    public const CONFIRMED = 'confirmed';

    public const AWAITING = 'awaiting';

    public const REFUSED = 'refused';

    /**
     * The shop has given up on this order.
     *
     * `failed` is what CheckoutController writes when a gateway refuses to
     * start, and what the card form's abandon path writes; `cancelled` is the
     * operator's. Both mean there is nothing to celebrate.
     */
    private const REFUSED_STATUSES = ['failed', 'cancelled', 'canceled'];

    /**
     * Statuses that can only have been reached by the shop accepting the order.
     *
     * `refunded` is in the list on purpose: money moved and then came back, so
     * the order was certainly placed. Nothing on the received page treats a
     * refund specially and this class is not the place to start.
     */
    private const ACCEPTED_STATUSES = ['processing', 'onhold', 'on-hold', 'shipped', 'completed', 'refunded'];

    public function __construct(private readonly GatewayRegistry $gateways) {}

    /**
     * @return self::CONFIRMED|self::AWAITING|self::REFUSED|null  null when
     *         there is no order to say anything about, which is exactly what
     *         the order-received page renders today for a visitor who may not
     *         see one. That path is untouched.
     */
    public function forOrder(?Order $order): ?string
    {
        if ($order === null) {
            return null;
        }

        $status = strtolower(trim((string) $order->status));

        // FIRST, and it has to be first: a `failed` order can carry a
        // `paid_at` from a payment that was later reversed, and an order the
        // shop has given up on is not confirmed however it got there.
        if (in_array($status, self::REFUSED_STATUSES, true)) {
            return self::REFUSED;
        }

        if ($order->paid_at !== null || in_array($status, self::ACCEPTED_STATUSES, true)) {
            return self::CONFIRMED;
        }

        $gateway = $this->gateways->find((string) $order->payment_method);

        if ($gateway !== null && $gateway->journey() === 'placed') {
            return self::CONFIRMED;
        }

        return self::AWAITING;
    }

    /** Did the shopper leave this shop to pay for this order? */
    public function leftTheShopFor(?Order $order): bool
    {
        if ($order === null) {
            return false;
        }

        $gateway = $this->gateways->find((string) $order->payment_method);

        return $gateway !== null && $gateway->journey() === 'redirect';
    }
}
