<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\OrderNote;
use App\Support\Money;
use App\Support\OrderTax;
use App\Support\TaxRule;

/**
 * Recomputes an order's subtotal, tax and total from its own lines, discount,
 * shipping and fee -- never from a client-sent figure.
 *
 * Moved here VERBATIM from AdminOrderController::recalcTotals() (2.60.641) so
 * the payment-method and coupon edits on the order screen re-price an order
 * through the very same code as an item edit does; the reasoning for every
 * branch is still on AdminOrderController::recalcTotals()'s docblock. The one
 * addition is `$why`, the words after "Tax recalculated after" in the order
 * note: "the items changed" (the item edits, byte for byte as before) or "the
 * coupon changed".
 */
final class OrderTotalsRecalc
{
    public function recalc(Order $order, string $why = 'the items changed'): void
    {
        $subtotal = (int) $order->items()->sum('total');
        $order->subtotal = $subtotal;

        $recorded = OrderTax::recorded($order);

        if ($recorded === null) {
            /*
             * THE SAME CLAMP THE TAXED BRANCH BELOW GETS FOR FREE.
             *
             * This was `$subtotal + shipping + fee + tax_total - discount`,
             * with nothing stopping the subtraction. `discount_total` is
             * whatever the coupon was worth against the basket AS PLACED and
             * no edit here re-evaluates it, so an operator who removes the
             * discounted line — or prices it down to zero — leaves a discount
             * larger than the order it is taken off, and the total went
             * NEGATIVE: a 150.00 order with a 100.00 coupon, minus its 100.00
             * line, wrote total = -50.00.
             *
             * A negative `orders.total` is not a display glitch. It is summed
             * as revenue on the dashboard and in customerHistory(), printed on
             * the invoice as the amount due, and read as the refund ceiling by
             * PaymentRefunder::capturedFils() for an order with no payment row
             * — where <= 0 means "nothing captured" and refunds are refused
             * outright.
             *
             * The taxed branch below never had this: it builds its base
             * through OrderTax::base(), which is `max(0, subtotal - discount)
             * + shipping`. Two branches of one method disagreeing about what
             * an over-large discount means is the defect; this uses the same
             * expression so they cannot disagree again. `tax_total` is still
             * added verbatim, exactly as before, because with no recorded rule
             * there is nothing to recompute it from.
             */
            $order->total = OrderTax::base($order) + (int) $order->fee_total + (int) $order->tax_total;
            $order->save();

            return;
        }

        $rule = new TaxRule($recorded['rate'], $recorded['basis']);

        // OrderTax::base() reads the columns just written, so this is the base
        // AFTER the edit: subtotal - discount + shipping, the identical
        // expression CartService::totals() taxes.
        $base = OrderTax::base($order);
        $was = (int) $order->tax_total;

        /*
         * `flat` charges nothing and contains nothing, so its column stays 0 —
         * exactly what the checkout writes for it. OrderTax::recorded()
         * recomputes the figure a flat order PRINTS from the rate, and does it
         * on demand, so there is nothing here to keep in step with it.
         */
        $order->tax_total = $rule->basis === TaxRule::FLAT ? 0 : $rule->taxOn($base);

        $order->total = $rule->grossOf($base) + (int) $order->fee_total;
        $order->save();

        if ((int) $order->tax_total !== $was) {
            $this->noteTaxRecalculated($order, $was, (int) $order->tax_total, $rule, $why);
        }
    }

    /**
     * The order's own history, told that its tax moved and on what authority.
     *
     * Deliberately names the rate and the basis it used. "The tax changed" is
     * not something an operator can check; "recalculated at 15% exclusive, the
     * rate recorded on this order" is, and it is the sentence that makes it
     * obvious if the figure ever does come from somewhere else.
     *
     * THE TWO FIGURES ARE RENDERED AT Money::minorExponent(), NOT AT THE
     * STOREFRONT'S DISPLAY WIDTH. Money::plain() with no width follows
     * Money::displayDecimals(), which is 0 on this store, so every movement
     * smaller than half a dirham wrote itself down as
     *
     *     Tax recalculated after the items changed: AED 4 → AED 4 at 2% …
     *
     * an audit entry stating that a figure changed to the value it already
     * had. This note is only written BECAUSE the figure moved — the caller
     * checks `!==` first — so the rounded form contradicts the reason the row
     * exists. And `order_notes` has no edit path: there is no screen, no
     * endpoint and no command that can correct one, so a note that records
     * nothing records nothing permanently.
     *
     * Same rule, same reason as a receipt, and the argument is written out in
     * App\Services\Mail\OrderEmailPresenter's header: a document somebody
     * will act on may not round the figure it is about. The rate and the basis
     * were always exact; only the two amounts the sentence is ABOUT were not.
     */
    private function noteTaxRecalculated(Order $order, int $was, int $now, TaxRule $rule, string $why): void
    {
        OrderNote::create([
            'order_id' => $order->getKey(),
            'author' => auth('admin')->user()?->name ?: 'Admin',
            'is_customer_note' => false,
            'content' => sprintf(
                'Tax recalculated after ' . $why . ': %s → %s at %s%% %s, the rate recorded on this order when it was placed.',
                Money::plain($was, Money::minorExponent()),
                Money::plain($now, Money::minorExponent()),
                $rule->printableRate(),
                $rule->basis,
            ),
        ]);
    }
}
