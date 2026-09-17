<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Cart;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Support\WholeDirhams;
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
                // Released rows do not count. A use that was handed back when
                // the order was cancelled has to be usable again, or the
                // release gave the shopper nothing — see releaseRedemptions().
                // The same clause is on the locked re-check in assertRoomFor();
                // the two must agree or the checkout page and the placement
                // would tell the shopper different things.
                $used = CouponRedemption::where('coupon_id', $coupon->id)
                    ->where('email', mb_strtolower($email))
                    ->whereNull('released_at')
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

    /**
     * The discount this shop actually takes off, in whole dirhams.
     *
     * The percentage arithmetic itself lives in exactDiscountFor() and is
     * unchanged and still exact to the fil; this is the whole-dirham step on
     * top of it. The two are separate methods ON PURPOSE rather than one with
     * a rounding at the end: CouponPercentRoundingTest pins the property that
     * the percentage equals exact integer arithmetic for every percentage the
     * editor can store, and a fil of float drift is invisible once the answer
     * has been rounded to a dirham. Folding the two together would leave that
     * test passing over the defect it was written for.
     */
    public function discountFor(Coupon $coupon, Cart $cart): int
    {
        $eligibleSubtotal = $this->eligibleSubtotalFor($coupon, $cart);
        $discount = $this->exactDiscountFor($coupon, $cart);

        if ($discount <= 0) {
            return 0;
        }

        /*
         * WHOLE DIRHAMS, ROUNDED UP — Lane FA, and the direction is the whole
         * decision, so it is written down rather than left to the reader.
         *
         * THE ARITHMETIC. 10% off AED 199 is AED 19.90 exactly. Every figure
         * that went into it is whole — the price, the quantity — and the
         * result is not, because a percentage of a whole number is not one.
         * There is no version of the owner's "no decimals" that reaches this
         * number by making the inputs tidier.
         *
         * WHY IT IS ADJUSTED AND NOT REFUSED. Nobody typed AED 19.90. The
         * shopper typed a code; the owner typed "10%". There is no operator
         * standing in front of this figure to show it to, which is the test
         * App\Support\WholeDirhams sets for the two halves of the policy.
         *
         * WHY UP, WHICH COSTS THE SHOP. Rounding a discount DOWN takes money
         * from the customer: a code advertised as "10% off" would hand back
         * AED 19 on a AED 199 basket, which is 9.55%, and the shop would have
         * printed a percentage it did not honour. Rounding UP costs the shop
         * at most one dirham less a fil per order and makes the advertised
         * percentage a floor rather than a ceiling. Between a shop that pays
         * 90 fils and a customer quietly short-changed 90 fils on a promise
         * the shop made, this lane picks the shop. There is no neutral choice
         * here and this one is deliberate.
         *
         * STILL CAPPED at the eligible subtotal, AFTER the rounding as well as
         * inside exactDiscountFor(): rounding up a discount that was already
         * the whole basket would hand back more than was spent. On a
         * whole-dirham basket the cap changes nothing; it is the guard for the
         * one basket where it would.
         *
         * A FIXED-AMOUNT COUPON IS USUALLY A NO-OP HERE, because its amount is
         * refused unless it is whole (CouponAdminApiController) and
         * `fixed_product` multiplies it by an integer quantity.
         *
         * ── BUT THERE IS A SECOND DOOR INTO `coupons`, AND IT IS NOT THAT ───
         *
         * This used to say "passes through untouched", which was true of every
         * coupon the OWNER TYPES and not true of an imported one.
         * Import\Entities\CouponImporter writes the same column and does not
         * go through that controller — deliberately, because a 40,000-row
         * migration must not die on a rounding policy.
         *
         * So a WooCommerce `fixed_cart` coupon of AED 99.50 arrives as 9950
         * fils, and this line rounds it UP at the till: the shopper gets
         * AED 100.00 off while Store → Coupons shows 99.50. Fifty fils a use,
         * the shop's way, and the screen disagrees with the basket.
         *
         * It is rounded here rather than refused on the way in ON PURPOSE. The
         * alternative — honouring 9950 exactly — would put fils back into a
         * basket total on a shop whose owner asked for whole dirhams, which is
         * the thing this method exists to prevent. Rounding up keeps the one
         * rule and costs the shop the difference, the same trade the percentage
         * branch above makes and for the same reason.
         *
         * The importer REPORTS every such coupon in its adjusted channel, and
         * `kbb:whole-dirhams` reads these three columns, so the owner can
         * settle them all at once with the figures in front of him. Run it
         * after a migration; the import runbook says so.
         */
        return min(WholeDirhams::away($discount), $eligibleSubtotal);
    }

    /**
     * The eligible subtotal a coupon is priced against — the cap, and the base.
     *
     * Extracted so discountFor() can apply the whole-dirham cap without
     * recomputing the basket a second way and risking a different answer.
     */
    private function eligibleSubtotalFor(Coupon $coupon, Cart $cart): int
    {
        $coupon = $this->withRules($coupon);
        $total = 0;

        foreach ($this->cappedLines($coupon, $this->eligibleItems($coupon, $cart)) as $line) {
            $total += $line['unit_price'] * $line['quantity'];
        }

        return $total;
    }

    /**
     * The discount to the exact fil, BEFORE the whole-dirham policy is applied.
     *
     * This is the sum itself, and the integer arithmetic in it is the point:
     * see the note on the `percent` arm. Public so the property test can hold
     * it to exact rational arithmetic — the whole-dirham rounding in
     * discountFor() would otherwise hide a fil of float drift behind a dirham.
     *
     * Not what the shop charges. discountFor() is.
     */
    public function exactDiscountFor(Coupon $coupon, Cart $cart): int
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
     * Hand back the uses an order consumed, and mark its redemption rows
     * released.
     *
     * TWO CALLERS, ONE MEANING. A storefront placement whose payment could not
     * be started calls this in the same request that recorded the redemption a
     * moment earlier. App\Services\Orders\OrderStatus calls it when an order
     * moves to a status that means the sale is off. Both are saying the same
     * thing — this order is not going to happen, give the code back — so they
     * say it through one method rather than two that can drift.
     *
     * THE ROW IS KEPT, NOT DELETED, and `released_at` is set instead. The row
     * is the only record that a code was ever accepted on this order; Store ->
     * Coupons is drawn from those rows and CouponAdminApiController::destroy()
     * counts them to protect a code's history. Deleting them would make a
     * cancelled order's coupon vanish from the usage report while
     * `orders.coupon_code` carried on printing it on the order.
     *
     * IT IS ALSO WHAT MAKES A DOUBLE RELEASE IMPOSSIBLE, which a delete could
     * not do. Each row is claimed by a conditional UPDATE — set released_at
     * WHERE released_at IS NULL — and only the caller the database hands a row
     * count of 1 decrements the counter. Two operators cancelling the same
     * order at the same instant therefore return one use between them, not two.
     * Reading the rows first and then deleting them, which is what this did,
     * lets both readers see the same unreleased row and both act on it. It is
     * the claim PaymentCapturer makes against `captured_at`, for the same
     * reason and in the same shape.
     *
     * NOTHING IS INVENTED. Only rows that exist are released, so an order that
     * never went through recordRedemption() — every WooCommerce import, and any
     * order placed before that call existed — releases nothing and leaves
     * `usage_count` exactly where the import put it.
     *
     * Idempotent: a second call finds every row already stamped and claims
     * none of them.
     *
     * @return int  how many redemptions this call released
     */
    public function releaseRedemptions(int $orderId): int
    {
        return DB::transaction(function () use ($orderId) {
            // Re-read by key here, and claim by key below, rather than trusting
            // any model a caller might be holding — the trap lockForRedemption()
            // sets out at length: an instance loaded with a partial column list
            // reads null for whatever was not selected, and a released_at that
            // reads null on an already-released row would release it twice.
            $rows = CouponRedemption::where('order_id', $orderId)
                ->whereNull('released_at')
                ->get(['id', 'coupon_id']);

            $released = 0;

            foreach ($rows as $row) {
                $claimed = CouponRedemption::whereKey($row->getKey())
                    ->whereNull('released_at')
                    ->update(['released_at' => now(), 'updated_at' => now()]);

                if ($claimed !== 1) {
                    // Somebody else claimed this row between the read above and
                    // this statement. Theirs to give back, not ours.
                    //
                    // THE GUARD IS THE WHERE CLAUSE; this count only reports
                    // what it did. Do not be tempted to drop the whereNull and
                    // trust the number — MySQL reports rows CHANGED, not rows
                    // matched, so two releases landing in the same second write
                    // the same timestamp, the loser is told "0 rows" for a row
                    // it really did overwrite, and the protection would be an
                    // accident of the clock. Proven: with the clause removed
                    // the two-process race still passed, and with the whole
                    // claim removed it handed the use back twice.
                    continue;
                }

                // Floored at zero. usage_count was migrated from WooCommerce
                // verbatim and can legitimately be higher than the number of
                // redemption rows this application holds, but it must never be
                // driven negative by a release — an unsigned column would
                // reject the write and a signed one would make the limit read
                // backwards.
                Coupon::whereKey($row->coupon_id)
                    ->where('usage_count', '>', 0)
                    ->decrement('usage_count');

                $released++;
            }

            return $released;
        });
    }

    /**
     * Take back the uses a release handed out, for an order that has come
     * alive again.
     *
     * ---------------------------------------------------------------------
     * The bug this closes
     * ---------------------------------------------------------------------
     *
     * releaseRedemptions() was one-way. An order cancelled with a one-use code
     * on it gave the use back — correctly — and an operator who then set the
     * status to `processing` on the order screen had a LIVE order carrying that
     * discount while `coupons.usage_count` read 0 and the code was on offer to
     * the next shopper. The shop honours the discount twice and the second one
     * is free. Nothing on the screen said anything had happened.
     *
     * ---------------------------------------------------------------------
     * The rows decide, the same way they do on the way out
     * ---------------------------------------------------------------------
     *
     * Only rows this order actually had released — `released_at IS NOT NULL` —
     * are taken back. Two properties follow without a list to keep in step:
     *
     *   AN ORDER THAT NEVER RELEASED TAKES NOTHING. Every WooCommerce import,
     *   and any order that never went through recordRedemption(), has no rows
     *   here at all. Nothing is invented — which is exactly the promise
     *   releaseRedemptions() makes in the other direction.
     *
     *   A SECOND REVIVE TAKES NOTHING. The first clears `released_at`; the
     *   second matches no rows. Cancel, revive, cancel, revive leaves
     *   `usage_count` where it started.
     *
     * ---------------------------------------------------------------------
     * WHEN IT REFUSES, AND WHEN IT DELIBERATELY DOES NOT
     * ---------------------------------------------------------------------
     *
     * REFUSED: the code has been fully redeemed since, either site-wide
     * (`usage_limit`) or by this customer (`usage_limit_per_user`). Somebody
     * else is holding the use this order wants back. Taking it anyway would
     * drive `usage_count` past the limit the owner set, which is the
     * double-spend this method exists to stop, arrived at from the other side.
     * So the whole transition is refused and the caller is told which code and
     * why, in the wording validate() uses for the same situation.
     *
     * NOT REFUSED: the code has EXPIRED, or has not started yet. That is
     * deliberate and it is the judgement worth writing down. An expiry date
     * governs who may APPLY a code; it is not a scarce resource. Nobody else
     * can spend an expired code, so taking its use back costs the shop
     * nothing, and the row going back to counting is what keeps Store ->
     * Coupons agreeing with the order that is printing the discount. Refusing
     * there would mean an owner who mis-cancelled an order last month cannot
     * put it right, on a host with no shell and no other way in — a refusal
     * that fires when it should not is its own kind of expensive.
     *
     * THE COUPON ROW IS LOCKED for the same reason recordRedemption() locks it:
     * the counter it is checked against must not move between the check and the
     * increment. A coupon that carries no limit at all skips the lock, because
     * there is nothing to serialise.
     *
     * @return int  how many redemptions this call took back
     *
     * @throws CouponExhausted  naming the code; nothing is left applied
     */
    public function reclaimRedemptions(int $orderId): int
    {
        return DB::transaction(function () use ($orderId) {
            // Re-read by key, and claim by key, for the reason
            // lockForRedemption() sets out: a partial instance reads null for
            // whatever was not selected, and a released_at that reads null on
            // a live row would take a use this order is already holding.
            $rows = CouponRedemption::where('order_id', $orderId)
                ->whereNotNull('released_at')
                ->orderBy('id')
                ->get(['id', 'coupon_id', 'email']);

            $taken = 0;

            foreach ($rows as $row) {
                $coupon = Coupon::whereKey($row->coupon_id)->first();

                if ($coupon === null) {
                    /*
                     * The code itself has been deleted since. There is no
                     * counter to take a use from and nothing anybody could
                     * spend twice, so this is not a refusal — but the row is
                     * still stamped released against a live order, which is
                     * untrue. Un-stamp it and move on, exactly as the release
                     * side leaves `usage_count` alone for a row it cannot
                     * decrement.
                     */
                    CouponRedemption::whereKey($row->getKey())
                        ->whereNotNull('released_at')
                        ->update(['released_at' => null, 'updated_at' => now()]);

                    continue;
                }

                $locked = $this->lockForRedemption($coupon);

                $this->assertRoomToReclaim($locked, $row);

                $claimed = CouponRedemption::whereKey($row->getKey())
                    ->whereNotNull('released_at')
                    ->update(['released_at' => null, 'updated_at' => now()]);

                if ($claimed !== 1) {
                    // Somebody else took this one back between the read and
                    // here. Theirs to count, not ours — the same stand-down
                    // releaseRedemptions() does, and the guard is the WHERE
                    // clause rather than this number.
                    continue;
                }

                Coupon::whereKey($locked->getKey())->increment('usage_count');

                $taken++;
            }

            return $taken;
        });
    }

    /**
     * Is there still room for a use this order gave back?
     *
     * Same two limits as assertRoomFor(), same wording, prefixed with the code
     * so an operator looking at a refused revive knows which one to go and
     * look at. Expiry is NOT among them — see reclaimRedemptions().
     *
     * @throws CouponExhausted
     */
    private function assertRoomToReclaim(Coupon $coupon, CouponRedemption $row): void
    {
        $code = trim((string) $coupon->code);
        $named = $code !== '' ? 'Coupon ' . $code . ': ' : '';

        if ($coupon->usage_limit !== null && (int) $coupon->usage_count >= (int) $coupon->usage_limit) {
            throw new CouponExhausted($named . 'that code has been fully redeemed since this order was cancelled.');
        }

        $email = $row->email !== null ? mb_strtolower((string) $row->email) : null;

        if ($coupon->usage_limit_per_user !== null && $email !== null) {
            /*
             * This row is released, so it is not in the count — which is what
             * makes the comparison the right one: it asks whether there is room
             * for one MORE unreleased use by this customer, which is exactly
             * what taking this row back would create.
             */
            $used = CouponRedemption::where('coupon_id', $coupon->getKey())
                ->where('email', $email)
                ->whereNull('released_at')
                ->count();

            if ($used >= (int) $coupon->usage_limit_per_user) {
                throw new CouponExhausted($named . 'that customer has used that code on another order since this one was cancelled.');
            }
        }
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
            // Released uses are spent no longer — the matching clause, and the
            // reason for it, are in validate().
            $used = CouponRedemption::where('coupon_id', $coupon->getKey())
                ->where('email', $email)
                ->whereNull('released_at')
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
