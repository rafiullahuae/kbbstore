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

    /**
     * Whether the applied coupon pays for delivery.
     *
     * coupons.free_shipping arrived in the WooCommerce import and, until this
     * lane, was read by nothing: the coupon editor drew it as a disabled box
     * and said on the screen that the shop did not apply it. It does now, in
     * CartService::totals().
     *
     * Through withRules() rather than straight off the attribute, and that is
     * not belt-and-braces. The three storefront paths eager-load the relation
     * as `coupon:id,code,type,amount`, so on the instance totals() actually
     * holds, free_shipping is ABSENT and reads null. That direction fails
     * closed — the shopper is charged for delivery a coupon promised them —
     * which is the cheaper of the two failures but still the wrong answer, and
     * it would show up as "the cart page charges shipping and the confirmation
     * email does not" depending on which instance each one happened to load.
     */
    public function grantsFreeShipping(Coupon $coupon): bool
    {
        return (bool) $this->withRules($coupon)->free_shipping;
    }

    /** Discount in fils. Never exceeds the eligible subtotal. */
    public function discountFor(Coupon $coupon, Cart $cart): int
    {
        // Before anything reads a rule off it. eligibleItems() does this for
        // itself, but the item cap below is read HERE, on this variable, and a
        // partial instance would report no cap at all — the same fail-open
        // withRules() exists to stop, one frame further out.
        $coupon = $this->withRules($coupon);

        $lines = $this->cappedLines($coupon, $this->eligibleItems($coupon, $cart));
        $eligibleSubtotal = 0;

        foreach ($lines as $line) {
            $eligibleSubtotal += $line['unit_price'] * $line['quantity'];
        }

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
            // Per UNIT, and never more than the unit itself costs. $line's
            // quantity is already the capped one, so the allowance is spent
            // here without being tracked a second time.
            'fixed_product' => array_sum(array_map(
                fn (array $line) => min((int) $coupon->amount, $line['unit_price']) * $line['quantity'],
                $lines
            )),
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
     * The columns the pricing path reads to decide what a code may discount,
     * how many units of it, and whether it also pays for delivery.
     *
     * Named here because the storefront does NOT select them. Every path that
     * reaches discountFor() — CartController::loadCart(),
     * Store\CheckoutController::loadCart() and CartDrawerComposer — eager-loads
     * the relation as `coupon:id,code,type,amount`, the four columns a discount
     * arithmetic needs, and hands that instance straight in.
     *
     * ADDING A RULE MEANS ADDING IT HERE, and the cost of forgetting is not an
     * error, it is money. An unselected attribute reads null, a null rule is
     * skipped, and the code discounts more of the basket than the owner fenced
     * it to. The note on withRules() below sets this out at length; these are
     * the names it checks against.
     */
    private const RULE_COLUMNS = [
        'exclude_sale_items',
        'product_ids',
        'excluded_product_ids',
        'category_ids',
        'excluded_category_ids',
        'brand_ids',
        'excluded_brand_ids',
        // Not an eligibility rule — a cap on how many eligible UNITS get
        // priced — but read off the same instance in discountFor() and it
        // fails open in exactly the same way, so it is guarded the same way.
        'limit_usage_to_x_items',
        // Likewise not eligibility: read by grantsFreeShipping() for
        // CartService::totals(). Absent it reads null and the shopper is
        // charged for delivery the coupon promised.
        'free_shipping',
    ];

    /**
     * The eligible lines, expanded to (unit_price, quantity) pairs and
     * truncated to the coupon's item allowance.
     *
     * WHICH UNITS A CAP DISCOUNTS IS A REAL DECISION, NOT AN IMPLEMENTATION
     * DETAIL. "Limit usage to 2 items" against a basket holding an AED 100
     * serum and three AED 60 toners has to choose two of those four units, and
     * the three plausible choices pay out three different amounts. This shop
     * takes the CHEAPEST eligible units first, ties broken by cart line id.
     *
     * Two reasons.
     *
     * It is the merchant-protective reading. A cap exists to bound what a code
     * can cost; when it binds, the shop should pay the smaller of the figures
     * available, not the larger. The owner sets "2 items" to limit exposure,
     * so the limit resolves in favour of the limit.
     *
     * And it does not depend on the order the shopper clicked things into the
     * basket. Cart order is not a property of the basket the owner can reason
     * about: it changes when a line is removed and re-added, and a cart page
     * and a checkout that load the items in different orders would price the
     * same basket differently. Sorting by price makes the answer a function of
     * what is in the basket and nothing else, which is what lets
     * CartService::totals() and Store\CheckoutController::place() agree.
     *
     * The tie-break on line id is there so that two units at the same price
     * still resolve deterministically rather than on whatever order the
     * database handed the rows back in — the same reason the coupon editor
     * settles duplicate codes itself rather than leaving it to the engine.
     *
     * NEVER MUTATES THE CART. The quantities here are copies. $cart->items
     * holds live models that CartService::totals() has already summed for the
     * basket subtotal and that later callers re-read; reducing a quantity on
     * one of those to express a cap would silently shrink the basket itself.
     *
     * @param  \Illuminate\Support\Collection  $items
     * @return array<int, array{unit_price: int, quantity: int}>
     */
    private function cappedLines(Coupon $coupon, $items): array
    {
        $lines = $items->map(fn ($i) => [
            'unit_price' => (int) $i->unit_price,
            'quantity' => (int) $i->quantity,
            'id' => (int) $i->getKey(),
        ])->values()->all();

        // NULL is no cap, which is what every coupon on this shop holds: the
        // WooCommerce import never wrote the column and the migration that
        // added it defaulted it to NULL for exactly that reason.
        if ($coupon->limit_usage_to_x_items === null) {
            return $lines;
        }

        $allowance = max(0, (int) $coupon->limit_usage_to_x_items);

        usort($lines, fn ($a, $b) => [$a['unit_price'], $a['id']] <=> [$b['unit_price'], $b['id']]);

        $capped = [];

        foreach ($lines as $line) {
            if ($allowance <= 0) {
                break;
            }

            $take = min($line['quantity'], $allowance);

            if ($take <= 0) {
                continue;
            }

            $capped[] = ['unit_price' => $line['unit_price'], 'quantity' => $take];
            $allowance -= $take;
        }

        return $capped;
    }

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

    /** Items the coupon may discount, after product/category/brand include and exclude rules. */
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

            /*
             * Brands, off products.brand_id — a column that has existed since
             * the initial schema and that no coupon rule read until now. Cast
             * because an unbranded product is null and the id lists hold ints:
             * (int) null is 0, which matches no brand id, so an unbranded item
             * is correctly outside a brand-restricted code and correctly NOT
             * caught by an exclusion. No relation is touched, so this costs
             * nothing per line, unlike the category clause below it.
             */
            $brandId = (int) $product->brand_id;

            if ($coupon->brand_ids && ! in_array($brandId, $coupon->brand_ids, true)) {
                return false;
            }

            if ($coupon->excluded_brand_ids && in_array($brandId, $coupon->excluded_brand_ids, true)) {
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
