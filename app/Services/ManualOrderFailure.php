<?php

declare(strict_types=1);

namespace App\Services;

/**
 * A manual order that cannot be placed for a reason the operator can act on:
 * an expired coupon, a country the shop does not deliver to, an empty basket.
 *
 * Carries the wording produced by CouponService or ShippingService so the
 * screen shows the storefront's own message rather than a rephrasing of it,
 * and unwinds the transaction on the way out so a half-written order can never
 * survive.
 */
class ManualOrderFailure extends \RuntimeException {}
