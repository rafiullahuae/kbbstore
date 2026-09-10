<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Cart;
use App\Models\Coupon;
use App\Models\CouponRedemption;

/**
 * Coupon validation and discount calculation.
 *
 * Every rule is checked server-side. The old build kept coupon codes in a
 * JavaScript object, which meant a discount applied in the browser never reached
 * the order — this replaces that entirely.
 *
 * Usage counts are migrated from WooCommerce verbatim so an exhausted code cannot
 * come back to life after cutover.
 */
class CouponService
{
    /** @return array{ok: bool, coupon: ?Coupon, error: ?string} */
    public function validate(string $code, Cart $cart, ?string $email = null): array
    {
        $coupon = Coupon::code($code)->first();

        if (! $coupon) {
            return $this->fail('That code is not valid.');
        }

        $now = now();

        if ($coupon->starts_at && $now->lt($coupon->starts_at)) {
            return $this->fail('That code is not active yet.');
        }

        if ($coupon->expires_at && $now->gt($coupon->expires_at)) {
            return $this->fail('That code has expired.');
        }

        if ($coupon->usage_limit !== null && $coupon->usage_count >= $coupon->usage_limit) {
            return $this->fail('That code has been fully redeemed.');
        }

        $subtotal = $this->cartSubtotal($cart);

        if ($coupon->minimum_amount && $subtotal < $coupon->minimum_amount) {
            return $this->fail('Your basket does not meet the minimum for that code.');
        }

        if ($coupon->maximum_amount && $subtotal > $coupon->maximum_amount) {
            return $this->fail('That code does not apply to a basket this size.');
        }

        if ($email) {
            if ($coupon->allowed_emails && ! in_array(mb_strtolower($email), array_map('mb_strtolower', $coupon->allowed_emails), true)) {
                return $this->fail('That code is not available on this account.');
            }

            if ($coupon->usage_limit_per_user !== null) {
                $used = CouponRedemption::where('coupon_id', $coupon->id)
                    ->where('email', mb_strtolower($email))
                    ->count();

                if ($used >= $coupon->usage_limit_per_user) {
                    return $this->fail('You have already used that code.');
                }
            }
        }

        if ($this->eligibleItems($coupon, $cart)->isEmpty()) {
            return $this->fail('That code does not apply to anything in your basket.');
        }

        return ['ok' => true, 'coupon' => $coupon, 'error' => null];
    }

    /** Discount in fils. Never exceeds the eligible subtotal. */
    public function discountFor(Coupon $coupon, Cart $cart): int
    {
        $items = $this->eligibleItems($coupon, $cart);
        $eligibleSubtotal = (int) $items->sum(fn ($i) => $i->lineTotal());

        if ($eligibleSubtotal <= 0) {
            return 0;
        }

        $discount = match ($coupon->type) {
            'percent' => (int) round($eligibleSubtotal * ($coupon->amount / 10000)), // amount stored as percent x 100
            'fixed_product' => (int) $items->sum(fn ($i) => min($coupon->amount, $i->unit_price) * $i->quantity),
            default => (int) $coupon->amount,                                        // fixed_cart
        };

        return max(0, min($discount, $eligibleSubtotal));
    }

    public function recordRedemption(Coupon $coupon, int $amount, ?int $orderId, ?int $customerId, ?string $email): void
    {
        CouponRedemption::create([
            'coupon_id' => $coupon->id,
            'customer_id' => $customerId,
            'order_id' => $orderId,
            'email' => $email ? mb_strtolower($email) : null,
            'amount' => $amount,
        ]);

        $coupon->increment('usage_count');
    }

    /** Items the coupon may discount, after product/category include and exclude rules. */
    private function eligibleItems(Coupon $coupon, Cart $cart)
    {
        return $cart->items->filter(function ($item) use ($coupon) {
            $product = $item->product;
            if (! $product) {
                return false;
            }

            if ($coupon->exclude_sale_items && $product->isOnSale()) {
                return false;
            }

            if ($coupon->product_ids && ! in_array($product->id, $coupon->product_ids, true)) {
                return false;
            }

            if ($coupon->excluded_product_ids && in_array($product->id, $coupon->excluded_product_ids, true)) {
                return false;
            }

            if ($coupon->category_ids || $coupon->excluded_category_ids) {
                $categoryIds = $product->categories->pluck('id')->all();

                if ($coupon->category_ids && ! array_intersect($categoryIds, $coupon->category_ids)) {
                    return false;
                }

                if ($coupon->excluded_category_ids && array_intersect($categoryIds, $coupon->excluded_category_ids)) {
                    return false;
                }
            }

            return true;
        });
    }

    private function cartSubtotal(Cart $cart): int
    {
        return (int) $cart->items->sum(fn ($i) => $i->lineTotal());
    }

    private function fail(string $message): array
    {
        return ['ok' => false, 'coupon' => null, 'error' => $message];
    }
}
