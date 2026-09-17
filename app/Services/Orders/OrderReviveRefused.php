<?php

declare(strict_types=1);

namespace App\Services\Orders;

use RuntimeException;

/**
 * An order that had given back what it was holding could not take it back, so
 * it was not brought alive again.
 *
 * ---------------------------------------------------------------------------
 * What it means
 * ---------------------------------------------------------------------------
 *
 * Cancelling an order puts its units on the shelf and hands its coupon use
 * back. Until this existed, setting that order's status to `processing` —
 * from the dropdown on the order screen, or from the orders list in a bulk
 * action — did neither of those things in reverse. The result was a live order
 * that still had to ship a unit the shop had re-sold, carrying a discount off a
 * code that read unused. Both halves are money, and neither was visible.
 *
 * So a revive now re-takes what the cancellation gave back, in the same
 * transaction as the status change, and when it cannot it throws this. The
 * order is left exactly where it was: the transaction rolls back, so there is
 * no half-applied state to find later — no units taken against a status that
 * did not move, no coupon use spent on an order still cancelled.
 *
 * ---------------------------------------------------------------------------
 * Why it is an exception and not a `false`
 * ---------------------------------------------------------------------------
 *
 * OrderStatus::moveTo() returns the previous status, or null for "nothing was
 * written", and null already carries three ordinary meanings — no such order,
 * a precondition that did not hold, already there. A refusal is not one of
 * those. It is the one outcome a caller MUST say something about, and the one
 * a caller that ignores it turns into the exact silent success this whole
 * guard exists to remove. Throwing is what makes ignoring it impossible, and it
 * is what rolls the transaction back — the same reasoning StockUnavailable and
 * CouponExhausted already give for the placement path.
 *
 * ---------------------------------------------------------------------------
 * What a caller does with it
 * ---------------------------------------------------------------------------
 *
 * Says the message. It is written for the shop's owner and it names the thing
 * that stopped the move — which product is short and by how many, or which
 * code and why — because "could not change the status" on a forty-order bulk
 * action tells him nothing he can act on. `kind` is there for a screen that
 * wants to group or ICON them; the sentence is the part that matters.
 */
final class OrderReviveRefused extends RuntimeException
{
    public const STOCK = 'stock';

    public const COUPON = 'coupon';

    public function __construct(
        string $message,
        /** self::STOCK or self::COUPON — which half could not be re-taken. */
        public readonly string $kind,
        /** The order this is about, so a bulk caller can report it by name. */
        public readonly int $orderId,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
