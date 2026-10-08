<?php

declare(strict_types=1);

namespace App\Services\Checkout;

use App\Models\Cart;
use App\Models\Order;
use App\Services\Orders\OrderStatus;
use Illuminate\Support\Facades\DB;

/**
 * An order that took no money is over, and its basket is the shopper's again.
 * (Lane CO)
 *
 * ONE PLACE FOR "FAIL IT AND PUT THE BASKET BACK", because four paths need it
 * and the two that had it were the only two that worked:
 *
 *   - place(), when the gateway refuses to START the payment. The order was
 *     failed and the basket was left `converted`, so the shopper's very next
 *     press of Place order found no basket and answered "Your bag is empty."
 *     over a page still showing their items. That is the owner's report of
 *     8 October: a Stripe refusal, then "Your bag is empty" on every card.
 *   - place(), for a card order posted without JavaScript. Same shape.
 *   - CheckoutController::cardAbandoned(), which had its own copy of this.
 *   - place() again, resuming a basket a previous card attempt in this same
 *     browser left behind (see CheckoutController::resumeCardBasket()).
 *
 * THE BASKET GOES BACK ONLY IF THE ORDER REALLY IS OVER, read off the row
 * under a lock inside the same transaction as the cart write — the rule
 * cardAbandoned() and CheckoutReturnController::restore() already follow, for
 * the reasons written beside both: a confirmation landing between a stale read
 * and the write would otherwise leave an order paid AND its basket live.
 *
 * AND ONLY THE BASKET THIS ORDER CAME FROM. `carts.converted_order_id` names
 * it. Before that column, a late "abandon" for a first card attempt re-opened
 * a basket that a second attempt had just converted again — a live basket of
 * goods with a live intent against them, payable twice.
 */
final class BasketRelease
{
    public function __construct(
        private readonly OrderStatus $status,
        private readonly PlacementState $placement,
    ) {}

    /** Was this `converted` basket converted INTO this order? */
    public function cartBelongsTo(Cart $cart, Order $order): bool
    {
        if ($cart->status !== 'converted') {
            return false;
        }

        $named = $cart->getAttribute('converted_order_id');

        /*
         * NULL is a basket converted before the column existed (or by a path
         * that does not stamp it). That is "unknown", and unknown keeps the
         * behaviour every caller had before: the cookie's converted basket.
         * The race this guards is only ever between two placements made by
         * this code, and both of those stamp it.
         */
        return $named === null || (int) $named === (int) $order->getKey();
    }

    /**
     * Fail $order (if it is not already over) and re-open $cart, atomically.
     *
     * Returns true when the order is over and took no money — the basket is
     * then live again if one was given and it belonged to this order. Returns
     * false, writing nothing to the cart, when money moved or the order is
     * still being paid for.
     */
    public function failAndRestore(Order $order, ?Cart $cart, string $reason): bool
    {
        $released = false;

        DB::transaction(function () use ($order, $cart, $reason, &$released) {
            // Lane RL: the shopper is looking at this failure on screen, so no
            // "payment failed" email for it -- the 30-minute reminder follows up.
            app(\App\Services\Mail\OrderStatusMailPolicy::class)->decideFor($order, false);

            $this->status->moveTo(
                $order,
                'failed',
                by: 'system',
                reason: $reason,
                only: ['paid_at' => null],
            );

            $fresh = Order::query()->whereKey($order->getKey())->lockForUpdate()->first();

            $released = $fresh !== null
                && $fresh->paid_at === null
                && $this->placement->forOrder($fresh) === PlacementState::REFUSED;

            if (! $released || $cart === null || ! $this->cartBelongsTo($cart, $order)) {
                return;
            }

            $cart->forceFill([
                'status' => 'active',
                'converted_at' => null,
                'converted_order_id' => null,
                'last_activity_at' => now(),
            ])->save();
        });

        return $released;
    }
}
