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
    /** giveBack(): the basket is live again, exactly as it was converted. */
    public const RESTORED = 'restored';

    /** giveBack(): its lines were folded into the basket this browser has now. */
    public const MERGED = 'merged';

    /** giveBack(): the order is over, but this basket was already given back. */
    public const GONE = 'gone';

    /** giveBack(): money moved or the order is still being paid. Nothing written. */
    public const HELD = 'held';

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

            $released = $this->isOver(Order::query()->whereKey($order->getKey())->lockForUpdate()->first());

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

    /**
     * Fail $order and give the shopper their basket, merged into the one they
     * have now if they started another. (Lane BK)
     *
     * ONE TRANSACTION, and both rows are re-read under a lock inside it:
     *
     *   - the ORDER, by isOver(): `paid_at` still null and the order refused.
     *     The move itself is conditional on the status this caller decided on,
     *     so a confirmation that lands after that decision makes the move
     *     refuse and the basket is not touched (HELD). Paid always wins.
     *   - the BASKET, by cartBelongsTo() on a locked fresh copy. A second
     *     return (Back, a reload, two tabs) finds it already `active` or
     *     `merged` and writes nothing (GONE) -- which is what makes a double
     *     return unable to add the same lines twice.
     *
     * $live is this browser's CURRENT basket when it is a different, non-empty
     * one: the shopper came back and added something before returning. The
     * restored lines are added to it rather than replacing it, quantities
     * summed exactly as CartService::mergeGuestCart() sums them, and the old
     * row is marked `merged`. Its coupon travels when $live has none.
     */
    public function giveBack(Order $order, Cart $basket, ?Cart $live, string $reason): string
    {
        $result = self::HELD;
        $decidedOn = (string) $order->status;

        DB::transaction(function () use ($order, $basket, $live, $reason, $decidedOn, &$result) {
            app(\App\Services\Mail\OrderStatusMailPolicy::class)->decideFor($order, false);

            $this->status->moveTo(
                $order,
                'failed',
                by: 'system',
                reason: $reason,
                only: ['paid_at' => null, 'status' => $decidedOn],
            );

            if (! $this->isOver(Order::query()->whereKey($order->getKey())->lockForUpdate()->first())) {
                return;
            }

            $fresh = Cart::query()->whereKey($basket->getKey())->lockForUpdate()->first();

            if ($fresh === null || ! $this->cartBelongsTo($fresh, $order)) {
                $result = self::GONE;

                return;
            }

            if ($live === null || (int) $live->getKey() === (int) $fresh->getKey()) {
                $fresh->forceFill([
                    'status' => 'active',
                    'converted_at' => null,
                    'converted_order_id' => null,
                    'last_activity_at' => now(),
                ])->save();
                $result = self::RESTORED;

                return;
            }

            $this->fold($fresh, $live);
            $result = self::MERGED;
        });

        return $result;
    }

    /** The order is over and took no money: $fresh is the row re-read under a lock. */
    private function isOver(?Order $fresh): bool
    {
        return $fresh !== null
            && $fresh->paid_at === null
            && $this->placement->forOrder($fresh) === PlacementState::REFUSED;
    }

    /** $from's lines into $into, summed; $from is left `merged` and empty. */
    private function fold(Cart $from, Cart $into): void
    {
        $have = $into->items()->get()->keyBy(
            fn ($line) => $line->product_id.'|'.(int) $line->product_variant_id
        );

        foreach ($from->items()->get() as $line) {
            $existing = $have->get($line->product_id.'|'.(int) $line->product_variant_id);

            if ($existing !== null) {
                $existing->update(['quantity' => min(99, $existing->quantity + $line->quantity)]
                    + ($line->bt_group && ! $existing->bt_group
                        ? ['bt_group' => $line->bt_group, 'bt_size' => $line->bt_size] : []));

                continue;
            }

            $into->items()->create([
                'product_id' => $line->product_id,
                'product_variant_id' => $line->product_variant_id,
                'quantity' => $line->quantity,
                'unit_price' => $line->unit_price,
                'bt_group' => $line->bt_group,
                'bt_size' => $line->bt_size,
            ]);
        }

        $from->items()->delete();

        $tracker = app(\App\Services\CartTracking\CartTracker::class);
        $tracker->revalue($from, 0);

        $from->forceFill(['status' => 'merged', 'converted_order_id' => null])->save();

        $into->forceFill(array_filter([
            'coupon_id' => $into->coupon_id ? null : $from->coupon_id,
            'last_activity_at' => now(),
        ], fn ($v) => $v !== null))->save();

        $tracker->revalue($into);
    }
}
