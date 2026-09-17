<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Services\StockClaim;
use Illuminate\Support\Facades\Log;

/**
 * What an order's change of status means for the shelf. One place, one answer.
 *
 * ---------------------------------------------------------------------------
 * The problem this exists to stop
 * ---------------------------------------------------------------------------
 *
 * `orders.status` is written from four places and no two of them write it the
 * same way: AdminController::updateOrderStatus saves a model,
 * AdminOrderController::runAction('cancel') calls update() on one,
 * OrdersApiController::bulkStatus() issues a mass
 * Order::whereIn(...)->update() that fires no Eloquent events at all, and
 * PaymentRefunder flips a fully-refunded order with the query builder. An
 * Eloquent observer therefore cannot see all of them, which is recorded in
 * OrderMailObserver's own header as something it does not catch.
 *
 * Store\CheckoutController::place() sets out the consequence at length where it
 * declines to release coupon redemptions on cancellation: a rule hooked to some
 * of those sites and not the others makes the books disagree depending on which
 * screen the operator happened to use, which is the exact failure that gets
 * lanes opened, reintroduced one layer down.
 *
 * So the RULE lives here, once, and each site calls it with the transition it
 * just made. The sites keep their own writes — they differ for good reasons,
 * and a mass update is the right shape for forty orders — but not one of them
 * decides what a cancellation costs the shelf.
 *
 * ---------------------------------------------------------------------------
 * The rule, and its two halves
 * ---------------------------------------------------------------------------
 *
 * Units go back when an order that WAS NOT YET DISPATCHED moves to a status
 * that means it never will be. Both halves are needed:
 *
 *   - the destination, because `cancelled` and `failed` are the two ways an
 *     order stops on this site without shipping. The goods are still in the
 *     stock room, and leaving them off the count is the bug: the shop refuses
 *     to sell something it physically has.
 *
 *   - the origin, because `shipped` and `completed` mean the parcel has gone.
 *     An operator cancelling a completed order is correcting the books or
 *     recording a return, and only they know which. Crediting the shelf for a
 *     parcel that is with the customer invents inventory, which is worse than
 *     the bug. Those transitions are deliberately left alone and named in the
 *     lane report as the owner's decision to make.
 *
 * WHAT IS NOT HERE, ON PURPOSE.
 *
 *   - `refunded`. A refund is a statement about money, not about goods. A full
 *     refund moves the status; whether the jar came back with it is something
 *     only the person who handled the parcel knows. PaymentRefunder therefore
 *     does not call this.
 *
 *   - A PARTIAL refund does not move the status at all, and could not drive a
 *     stock return even if it did: the `refunds` table records an amount, a
 *     reason and a provider reference, and has no line-level detail whatsoever.
 *     "AED 40 back" cannot be turned into "one of these and none of those"
 *     without guessing, and a guess here writes a number into the inventory the
 *     stock room is counted against.
 *
 * Nothing here decides anything about an order that never claimed stock. It
 * cannot: StockClaim::release() works off the ledger of what was actually
 * taken, so an imported WooCommerce order, one typed through ManualOrderBuilder
 * or one placed while `manage_stock` was off has nothing to give back and this
 * returns 0 for it.
 */
final class OrderTransitionStock
{
    /**
     * Destinations that mean the order will never leave the building.
     *
     * `failed` is here as well as `cancelled` because a gateway that declines
     * leaves a real order behind on purpose — place() keeps it so the shopper
     * can retry and support can see what happened — and that order is holding
     * units it will never ship.
     */
    public const RETURNS_STOCK = ['cancelled', 'failed'];

    /**
     * Origins from which nothing has physically gone out.
     *
     * `draft` is included because ManualOrderBuilder can write one and the
     * orders screen can duplicate into one; neither ever claimed stock, so it
     * costs nothing to be complete here. `failed` is included so that a failed
     * order later cancelled is still handled, which matters because the release
     * is idempotent and the second call is a no-op rather than a second credit.
     */
    public const NOT_YET_DISPATCHED = ['draft', 'pending', 'processing', 'onhold', 'failed'];

    public function __construct(private StockClaim $stock) {}

    /** Does moving from $from to $to put units back? */
    public function returns(string $from, string $to): bool
    {
        if ($from === $to) {
            return false;
        }

        return in_array($to, self::RETURNS_STOCK, true)
            && in_array($from, self::NOT_YET_DISPATCHED, true);
    }

    /**
     * Call this immediately after writing the new status.
     *
     * AFTER, not before, and it does not write the status itself. Every caller
     * already has a status write shaped to what it is doing — a model save, an
     * update(), a mass update across forty ids — and rerouting those through
     * one method would change three screens' behaviour to fix an inventory bug.
     * What matters is that the RULE is not copied, and it is not.
     *
     * IT CANNOT THROW. A failure to return stock must not turn the operator's
     * Cancel button into a 500 on an order that is, by then, already cancelled.
     * The same reasoning OrderMailObserver gives for swallowing mail failures
     * applies here and for the same reason: the status change has happened and
     * the screen is waiting. A failure is logged with the order number so it
     * can be corrected by hand.
     *
     * @return int  units returned; 0 when the rule does not apply or the order
     *              never claimed any.
     */
    public function applied(int $orderId, string $from, string $to): int
    {
        if (! $this->returns($from, $to)) {
            return 0;
        }

        try {
            return $this->stock->release($orderId, 'order ' . $to);
        } catch (\Throwable $e) {
            Log::error('stock return failed', [
                'order_id' => $orderId,
                'from' => $from,
                'to' => $to,
                'exception' => class_basename($e),
                'message' => $e->getMessage(),
            ]);

            return 0;
        }
    }


    /**
     * The other direction: take back the units this order's release gave up.
     *
     * ---------------------------------------------------------------------
     * Why the rule is not a second list
     * ---------------------------------------------------------------------
     *
     * The obvious shape is `returns()` read backwards — a move out of
     * RETURNS_STOCK into something else. It is very nearly right and it is
     * wrong in a way that costs a unit.
     *
     * An order cancelled (units back), then refunded, then set to `processing`
     * by an operator arrives here with `$from = 'refunded'`. `refunded` is not
     * in RETURNS_STOCK — deliberately, and this class's header says why — so
     * read backwards the lists answer "this order gave nothing back" about an
     * order whose units are sitting on the shelf. The oversell survives, and it
     * survives on the path an operator is most likely to take.
     *
     * So the lists decide nothing here. StockClaim::reclaim() takes back
     * exactly the ledger rows a release stamped, whenever that release
     * happened and whatever the order has been through since. That is not a
     * different rule from this class's: those rows are stamped BECAUSE
     * returns() said so, so asking the ledger is asking this class's own rule
     * as it was actually applied, at the moment it was applied, rather than
     * re-deriving it from a status that has moved on twice.
     *
     * Two properties fall out, both required of this and both otherwise
     * needing their own guard:
     *
     *   - an order cancelled from `shipped` released nothing (returns() said
     *     so), so there are no stamped rows and this takes nothing. Re-taking
     *     stock that was never returned is the same bug in the other
     *     direction, and it cannot happen here.
     *   - reviving twice takes nothing the second time, because the first
     *     revive un-stamped the rows.
     *
     * ---------------------------------------------------------------------
     * IT CAN THROW. applied() cannot, and the difference is the whole point
     * ---------------------------------------------------------------------
     *
     * applied() swallows, because the status has already moved and a failure
     * to credit the shelf must not turn Cancel into a 500. Here nothing has
     * been committed yet and there is no honest way to carry on: if the unit
     * has been sold since, finishing the revive means a live order for stock
     * the shop does not have. The exception is what refuses the whole
     * transition and rolls it back, and OrderStatus::moveTo() — the only
     * caller — turns it into a sentence naming the product.
     *
     * @return int  units taken back; 0 when this order released nothing.
     *
     * @throws \App\Services\StockUnavailable  naming the shelf that is short
     */
    public function reclaimed(int $orderId, string $from, string $to): int
    {
        return $this->stock->reclaim($orderId);
    }

    /**
     * The same rule across a batch, one order at a time.
     *
     * Deliberately not a single query. The release is per claim row and has to
     * check each one's own `released_at` to stay safe against a concurrent
     * cancellation of the same order, and a bulk action is capped at 200 orders
     * (OrdersApiController::BULK_MAX) most of which will have nothing to
     * return. Correctness first; the cost is bounded and small.
     *
     * @param  array<int, string>  $fromById  order id => the status it was in
     * @return int  units returned across the whole batch
     */
    public function appliedMany(array $fromById, string $to): int
    {
        $returned = 0;

        foreach ($fromById as $orderId => $from) {
            $returned += $this->applied((int) $orderId, (string) $from, $to);
        }

        return $returned;
    }
}
