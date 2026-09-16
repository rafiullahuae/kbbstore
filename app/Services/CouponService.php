<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Cart;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use Illuminate\Support\Facades\DB;
use LogicException;

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
            /*
             * INTEGER ARITHMETIC, for the reason BundleService::unitFor()
             * sets out at length about the identical sum.
             *
             * This was `(int) round($eligibleSubtotal * ($coupon->amount / 10000))`.
             * The division is floating point and most percentages are not
             * representable in binary -- 35% is 0.34999999999999997779… -- so a
             * discount whose exact value lands on a half fil fell the wrong
             * side of round():
             *
             *     35% of 20,490 fils -> exact 7,171.5, rounds to 7,172
             *                           float 7,171.4999…, rounds to 7,171
             *
             * Always downward, so the shopper was short-changed a fil and the
             * stored discount disagreed with any exact recomputation of the
             * same coupon. Across baskets up to AED 2,000 that is 2,340 of
             * them at 35% and 1,170 at 17.5%.
             *
             * It survived because every round percentage anyone reaches for
             * when testing -- 10, 12.5, 20, 25, 50 -- happens to be exact in
             * binary. The awkward ones are the ones a sale actually uses.
             *
             * `amount` is hundredths of a percent (35% -> 3500), which is the
             * precision the coupon editor offers, so the whole sum is exact in
             * integers. `+ 5000` before the division is round-half-up on a
             * positive value, which is what round() did.
             */
            'percent' => intdiv($eligibleSubtotal * (int) $coupon->amount + 5000, 10000),
            'fixed_product' => (int) $items->sum(fn ($i) => min($coupon->amount, $i->unit_price) * $i->quantity),
            default => (int) $coupon->amount,                                        // fixed_cart
        };

        return max(0, min($discount, $eligibleSubtotal));
    }

    /**
     * Spend one use of $coupon against an order that is being written now.
     *
     * MUST be called from inside the transaction that creates the order, and
     * the guard below enforces that rather than trusting it. The two halves —
     * "the order exists" and "the coupon was spent" — have to commit or roll
     * back together. An order that saved without its redemption is the bug
     * this whole lane exists to fix; a redemption for an order that then
     * failed to save is worse, because it silently consumes a use of a code
     * for a sale that never happened.
     *
     * THE RACE. validate() checks the limit when the shopper applies the code,
     * which can be a long time before they press Place Order, and it reads the
     * counter without a lock. Two shoppers holding the last use of a code can
     * therefore both pass that check and both place an order. A transaction
     * alone does not stop this: under REPEATABLE READ both transactions read
     * usage_count = 1 against a limit of 2, both write, and the code is
     * redeemed three times.
     *
     * So a coupon that carries EITHER limit is re-read here under
     * SELECT ... FOR UPDATE and re-checked against that locked row. The second
     * transaction blocks on the lock until the first commits, then sees the
     * counter the first one wrote and throws. The coupon row is the mutex for
     * both limits, which is why the per-user count is taken under it too —
     * every redemption of a given coupon passes through this same lock, so the
     * count cannot move underneath it.
     *
     * A coupon with no limit at all skips the lock. There is nothing to
     * serialise, and taking a row lock on every order that uses an unlimited
     * site-wide code would turn the busiest coupon into a checkout queue.
     *
     * @throws CouponExhausted  when the limit was reached before this order got there
     */
    public function recordRedemption(Coupon $coupon, int $amount, ?int $orderId, ?int $customerId, ?string $email): CouponRedemption
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException(
                'CouponService::recordRedemption() must run inside the transaction that creates the order. '
                . 'Recording a redemption that can commit on its own re-introduces the bug it was written to fix.'
            );
        }

        $email = ($email !== null && trim($email) !== '') ? mb_strtolower(trim($email)) : null;

        $locked = $this->lockForRedemption($coupon);

        $this->assertRoomFor($locked, $email);

        $redemption = CouponRedemption::create([
            'coupon_id' => $locked->id,
            'customer_id' => $customerId,
            'order_id' => $orderId,
            'email' => $email,
            // Already integer fils, straight from discountFor(). Never a float,
            // and never re-derived from a major-unit amount on the way in.
            'amount' => $amount,
        ]);

        // Through the query builder, not $model->increment(): the model method
        // would also persist any other attribute that happens to be dirty on
        // the instance the caller handed us. UPDATE ... usage_count + 1 is
        // atomic in its own right, and we hold the row lock besides.
        Coupon::whereKey($locked->id)->increment('usage_count');

        // Keep the in-memory copies in step, so a caller that reads
        // $coupon->usage_count after this does not see the pre-write value.
        $locked->usage_count = (int) $locked->usage_count + 1;
        $coupon->usage_count = $locked->usage_count;

        return $redemption;
    }

    /**
     * Hand back the uses an order consumed, and delete its redemption rows.
     *
     * Used on one path only: a storefront placement whose payment could not be
     * started. That failure is detected synchronously, in the same request, by
     * the same code that recorded the redemption a moment earlier, so the undo
     * is complete and there is no path it can miss.
     *
     * It is deliberately NOT wired to cancellation or refund — see the note on
     * that decision in Store\CheckoutController::place().
     *
     * Idempotent: calling it twice for the same order releases nothing the
     * second time, because the rows are gone.
     *
     * @return int  how many redemptions were released
     */
    public function releaseRedemptions(int $orderId): int
    {
        return DB::transaction(function () use ($orderId) {
            $rows = CouponRedemption::where('order_id', $orderId)->get();

            foreach ($rows as $row) {
                $row->delete();

                // Floored at zero. usage_count was migrated from WooCommerce
                // verbatim and can legitimately be higher than the number of
                // redemption rows this application holds, but it must never be
                // driven negative by a release — an unsigned column would
                // reject the write and a signed one would make the limit read
                // backwards.
                Coupon::whereKey($row->coupon_id)
                    ->where('usage_count', '>', 0)
                    ->decrement('usage_count');
            }

            return $rows->count();
        });
    }

    /**
     * The coupon row to check and spend against: locked when it carries a
     * limit, the caller's own instance when it does not.
     */
    private function lockForRedemption(Coupon $coupon): Coupon
    {
        /*
         * Re-read by key rather than trusting the instance we were handed.
         *
         * THE CALLER'S COUPON IS ROUTINELY A PARTIAL ROW. Both storefront
         * paths reach this through a cart whose relation is eager-loaded as
         * 'coupon:id,code,type,amount' — the four columns a discount needs —
         * in CheckoutController::loadCart(), CartController and
         * CartDrawerComposer. usage_limit, usage_limit_per_user and
         * usage_count are not selected, so on that instance they read null,
         * and a limit check against null passes every time. That is not a
         * hypothetical: it is what this method did on its first draft, and
         * the limit tests failed against a live coupon whose row said 1.
         *
         * Reading the row here means the check can never be weakened by a
         * caller's choice of columns, which is a decision made far away from
         * this file and for unrelated reasons.
         */
        $fresh = Coupon::whereKey($coupon->getKey())->first();

        if ($fresh === null) {
            return $coupon;
        }

        if ($fresh->usage_limit === null && $fresh->usage_limit_per_user === null) {
            return $fresh;
        }

        // Limited, so take it again under a lock. Whether a coupon carries a
        // limit at all is configuration, not a racing value, so deciding that
        // from the unlocked read above is safe; the counter it is compared
        // against is read below, under the lock.
        //
        // lockForUpdate() is a no-op on SQLite, which serialises writers with a
        // single database-level write lock instead. The behaviour this needs is
        // the same either way; it is MySQL, which production runs, where the
        // clause is doing the work.
        return Coupon::whereKey($coupon->getKey())->lockForUpdate()->first() ?? $fresh;
    }

    /**
     * Re-check both limits against the locked row. Same wording as validate()
     * so one situation has one explanation.
     *
     * @throws CouponExhausted
     */
    private function assertRoomFor(Coupon $coupon, ?string $email): void
    {
        if ($coupon->usage_limit !== null && $coupon->usage_count >= $coupon->usage_limit) {
            throw new CouponExhausted('That code has been fully redeemed.');
        }

        if ($coupon->usage_limit_per_user !== null && $email !== null) {
            $used = CouponRedemption::where('coupon_id', $coupon->getKey())
                ->where('email', $email)
                ->count();

            if ($used >= $coupon->usage_limit_per_user) {
                throw new CouponExhausted('You have already used that code.');
            }
        }
    }

    /**
     * The columns eligibleItems() reads to decide what a code may discount.
     *
     * Named here because the storefront does NOT select them. Every path that
     * reaches discountFor() — CartController::loadCart(),
     * Store\CheckoutController::loadCart() and CartDrawerComposer — eager-loads
     * the relation as `coupon:id,code,type,amount`, the four columns a discount
     * arithmetic needs, and hands that instance straight in.
     */
    private const RULE_COLUMNS = [
        'exclude_sale_items',
        'product_ids',
        'excluded_product_ids',
        'category_ids',
        'excluded_category_ids',
    ];

    /**
     * The coupon row with its eligibility rules on it, re-read if they are not.
     *
     * THE BUG THIS EXISTS TO STOP. An attribute that was never selected reads
     * null on an Eloquent model — no error, no warning — so on a partial
     * instance `product_ids` is null and `if ($coupon->product_ids && ...)`
     * is skipped, `exclude_sale_items` is null and the sale check is skipped,
     * and so on for all five. Every rule fails OPEN: a code restricted to one
     * product discounted the entire basket, and the inflated figure went
     * through CartService::totals() into `orders.discount_total` and into the
     * redemption row. It is the same defect lockForRedemption() below already
     * guards the usage limits against, on the same instances, for the same
     * reason — that method's note spells it out — and the money at stake here
     * is larger, because a discount is not a refusal, it is a payment.
     *
     * The re-read is conditional on the attributes being absent, so the
     * complete rows that validate() and the admin screens hand in cost nothing.
     * A cart with a coupon applied pays one extra SELECT per render.
     */
    private function withRules(Coupon $coupon): Coupon
    {
        $present = $coupon->getAttributes();

        foreach (self::RULE_COLUMNS as $column) {
            if (! array_key_exists($column, $present)) {
                return Coupon::whereKey($coupon->getKey())->first() ?? $coupon;
            }
        }

        return $coupon;
    }

    /** Items the coupon may discount, after product/category include and exclude rules. */
    private function eligibleItems(Coupon $coupon, Cart $cart)
    {
        $coupon = $this->withRules($coupon);

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
