<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * Something in the basket could not be bought at the moment the order was
 * being written.
 *
 * This is NOT the refusal Store\CartController::add() gives. That one runs when
 * the shopper puts something in the bag, which is minutes, days or a fortnight
 * before they press Place order; this is thrown from inside the transaction
 * that creates the order, against product and variant rows held under a lock,
 * and it means the shelf emptied in the interval between the two — because the
 * owner marked it sold out, or because somebody else bought the last unit.
 *
 * It carries a sentence written for a shopper, naming the product and, where
 * stock is counted, how many are actually left. The checkout puts it straight
 * on the page above the form, which is the whole point: the customer is told,
 * in words, before any money moves.
 *
 * Throwing rather than returning is deliberate — it is what rolls the order
 * back, the same way CouponExhausted does. A caller that swallowed this and
 * carried on would write an order for stock that is not there, which is the
 * original bug wearing a different hat.
 */
class StockUnavailable extends RuntimeException
{
}
