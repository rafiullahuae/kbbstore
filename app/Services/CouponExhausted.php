<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * A coupon's limit was reached at the moment the order was being written.
 *
 * This is NOT the same refusal as CouponService::validate() returning ok=false.
 * validate() runs when the shopper applies the code, which is minutes or hours
 * before they press Place Order; this is thrown from inside the transaction
 * that creates the order, against a coupon row held under a lock, and it means
 * the limit was reached in the interval between the two — by someone else's
 * order, or by an earlier order of this shopper's own.
 *
 * It carries the wording validate() would have used, so the shopper or the
 * operator sees one explanation for one situation rather than two.
 *
 * Throwing (rather than returning) is deliberate: it is what rolls the order
 * back. A caller that swallowed this and carried on would write an order whose
 * coupon was never actually redeemed, which is the original bug wearing a
 * different hat.
 */
class CouponExhausted extends RuntimeException
{
}
