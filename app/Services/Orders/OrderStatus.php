<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\OrderNote;
use App\Services\CouponService;
use Illuminate\Support\Facades\DB;

/**
 * The one place an existing order's status changes.
 *
 * ---------------------------------------------------------------------------
 * What was wrong
 * ---------------------------------------------------------------------------
 *
 * Nine places wrote `orders.status`, each in its own shape: a model save in
 * AdminController, an update() on a model in AdminOrderController, a mass
 * whereIn()->update() from the orders list, three query-builder updates in the
 * payments services, a save in the cash-on-delivery gateway and two in the
 * checkout controllers. Nothing could stand behind all of them, so anything
 * that has to happen when an order's state changes either happened on some
 * paths and not others, or was not attempted at all.
 *
 * Two consequences were being paid for daily.
 *
 *   A COUPON USE WAS NEVER GIVEN BACK. Store\CheckoutController::place()
 *   releases the redemption when a payment cannot be started, because that
 *   failure is synchronous and it can be sure it has not missed a path. It
 *   says at length why it does NOT do the same for cancellation: a release
 *   hooked to some of the writers and not the others would make
 *   `coupons.usage_count` disagree with the redemption rows depending on which
 *   screen the operator happened to use. So a one-per-customer code stayed
 *   spent for ever on an order that came to nothing, and the customer was
 *   refused their own code by an order that was never dispatched.
 *
 *   NOTHING COULD WATCH A TRANSITION. OrderMailObserver had to be an Eloquent
 *   observer to catch four of the writers, and its own header records the ones
 *   it still cannot see; OrdersApiController::bulkStatus had to send its own
 *   emails because a mass update fires no model events. Both are the same
 *   problem written twice.
 *
 * ---------------------------------------------------------------------------
 * Why a service, and not an observer or a model method
 * ---------------------------------------------------------------------------
 *
 * AN OBSERVER catches every write, including ones nobody thought of, which is
 * genuinely more than this can promise — a new controller that saves a status
 * tomorrow bypasses this method and this method cannot know. It was still the
 * wrong instrument here, for three reasons:
 *
 *   - it CANNOT see the writes that matter most. A query-builder update fires
 *     no model events, and three of the nine writers are query-builder
 *     updates, including PaymentRefunder's `refunded` — the one that has money
 *     behind it. An observer would have released coupons on six paths out of
 *     nine, which is the disagreement this lane exists to end, moved one layer
 *     down. OrderMailObserver already documents living with exactly that.
 *
 *   - it fires on the IMPORTER. Services\Import\Entities\OrderImporter writes
 *     a status onto every historical WooCommerce order it re-syncs, ten
 *     thousand of them in one run. Every one of those would look like a
 *     transition: ten thousand notes, and a `cancelled` row from 2023 would
 *     "release" a coupon WooCommerce already accounted for. Under an observer
 *     the importer would need a flag to switch the observer off, and a rule
 *     with an off switch is a rule with a bug waiting on the day somebody
 *     forgets to turn it back on. A method the importer simply does not call
 *     has no switch to leave in the wrong position.
 *
 *   - it has no room for the caller's own reason, author or preconditions —
 *     all of which the sites here need, and all of which would have to be
 *     smuggled to it through request state.
 *
 * A MODEL METHOD would be closer to hand, but the work is not the model's: it
 * releases coupon redemptions, writes an order note and puts units back on the
 * shelf through OrderTransitionStock. That is a service's work, and putting it
 * on the model would drag CouponService into every place an Order is hydrated.
 *
 * A STATE MACHINE with a table of permitted edges was considered and rejected.
 * It is configuration that has to be read, kept in step with a free-form column
 * that deliberately carries imported WooCommerce statuses, and consulted on
 * every order in the shop to answer a question — "is this edge allowed?" —
 * whose honest answer here is "yes, an operator may correct any mistake".
 *
 * ---------------------------------------------------------------------------
 * What is refused
 * ---------------------------------------------------------------------------
 *
 * Exactly one thing: a move to the status the order already has, when there is
 * nothing else to write. Not out of tidiness — it is half of the double-release
 * guard. Cancelling an order twice must not hand back two uses of a code, and
 * the cheapest way to be sure is that the second cancellation is not a
 * transition at all. (The other half is that a release is CLAIMED: see
 * CouponService::releaseRedemptions(). Both are needed, because cancelling and
 * then refunding IS two real transitions.)
 *
 * NOTHING ELSE IS REFUSED, including `completed` back to `pending`. The status
 * is typed by a human on the order screen, and a human who has just marked the
 * wrong order completed needs to be able to put it back. A whitelist of
 * permitted edges would leave that operator with a dropdown that refuses them
 * and no other way to fix it — on a host with no shell, that means the wrong
 * answer stays in the database. The transition is recorded, which is the
 * property that actually helps: an odd-looking move can be seen and explained
 * afterwards, which a refusal never allows.
 *
 * ---------------------------------------------------------------------------
 * Where the transition is recorded
 * ---------------------------------------------------------------------------
 *
 * In `order_notes`, the history the order screen already draws — not in a new
 * table. The screen has shown that list since the order page was built, the
 * payment services already write their audit lines into it, and an operator
 * looking for "who cancelled this and when" looks there. A second, parallel
 * history would be a table nothing reads with a screen nobody has built.
 *
 * The machine-readable fact this lane needs — has this order's coupon use been
 * handed back? — deliberately does NOT live in that note. Text is not evidence.
 * It lives on the redemption row itself, as `coupon_redemptions.released_at`,
 * where it can be claimed in one statement.
 */
class OrderStatus
{
    /**
     * Destinations that mean the sale is off, so the code goes back in the
     * customer's pocket.
     *
     *   cancelled — nobody is paying and nothing is shipping. The order cost
     *               the shop nothing, and a public code that could be exhausted
     *               by placing and cancelling orders is a code anyone can
     *               burn.
     *   failed    — the payment did not happen. Store\CheckoutController
     *               already releases on this one synchronously, and has since
     *               before this class existed; a `failed` written later by a
     *               gateway webhook means precisely the same thing and now
     *               gets the same answer.
     *   refunded  — every fil captured has gone back. The sale has been undone,
     *               so the use that paid for it is undone too.
     *
     * A PARTIAL REFUND IS NOT HERE, and cannot be: PaymentRefunder only moves
     * the status when the refunded total reaches the captured total, so a
     * partial refund is not a transition at all and never reaches this class.
     * That is the right answer as well as the existing one. A partial refund is
     * a price adjustment on a sale that still happened — the customer keeps
     * the goods, the order still ships, and the code was honoured on it. Handing
     * the use back there would let a shopper keep the discount, keep most of the
     * order, and spend the code again; and the `refunds` table records an amount
     * and a reason with no line-level detail, so there is nothing to work out a
     * partial release from that would not be a guess about money.
     */
    public const RELEASES_COUPON = ['cancelled', 'failed', 'refunded'];

    public function __construct(private CouponService $coupons) {}

    /**
     * Move $order to $to. The only thing in this application that writes
     * `orders.status` on an order that already exists.
     *
     * Everything happens inside one transaction, against the row re-read under
     * SELECT ... FOR UPDATE:
     *
     *   THE ROW IS RE-READ BY KEY, never trusted from the caller's instance.
     *   CouponService::lockForRedemption() documents why at length and the trap
     *   is the same one here: OrdersApiController hands over models loaded as
     *   `get(['id', 'order_number', 'status', 'total'])`, and an attribute that
     *   was never selected reads null with no error. A `from` status read off
     *   such an instance would be wrong, and the note, the release rule and the
     *   no-op refusal all turn on it.
     *
     *   THE LOCK IS WHAT MAKES THE REST TRUE. Two requests cancelling one order
     *   serialise on it, so the second reads the status the first wrote and
     *   finds nothing to do. It is also at least as strong as the conditional
     *   UPDATE it replaces in PaymentConfirmer: a second webhook delivery blocks
     *   here until the first commits and then fails the `$only` check below on
     *   the value the first wrote, instead of matching zero rows. (On SQLite
     *   lockForUpdate() is a no-op — it serialises writers with one
     *   database-wide lock instead — which is the same trade CouponService
     *   already makes, and MySQL is what production runs.)
     *
     *   THE STATUS AND ITS CONSEQUENCES COMMIT TOGETHER. If the coupon release
     *   fails, the status change goes with it rather than leaving an order
     *   cancelled with its redemption still spent. That is the same rule
     *   recordRedemption() enforces on the way in, and the failure it guards
     *   against is the same one in reverse.
     *
     * THE WRITE IS A MODEL SAVE, not a query-builder update, and that is load
     * bearing: OrderMailObserver's `updated` hook is what tells the customer,
     * and a query-builder update fires no model events. Routing the bulk list
     * through here is therefore what finally makes the mass update's missing
     * emails impossible rather than remembered.
     *
     * @param  Order|int    $order  a model or an id; only its key is believed.
     * @param  string       $to     the new status.
     * @param  string|null  $by     who is doing it, for the note. Defaults to
     *                              the signed-in admin, then to 'system'.
     * @param  string|null  $reason a few words for the note: which screen or
     *                              which provider asked for this.
     * @param  array        $also   other columns to write in the same save,
     *                              for the callers whose status change carries
     *                              one fact (paid_at, a transaction reference).
     *                              Present so those stay one atomic write.
     * @param  array        $only   preconditions, as column => required current
     *                              value, checked on the LOCKED row. This is how
     *                              PaymentConfirmer keeps "apply at most once"
     *                              without a second claim of its own.
     * @return string|null  the status the order was in before this write, or
     *                      null when NOTHING was written — no such order, a
     *                      precondition that did not hold, or it was already
     *                      there with nothing else to record. A caller that
     *                      needs to know whether the status itself moved
     *                      compares the return with $to; a caller that only
     *                      needs to know whether its write landed (a webhook
     *                      applying at most once) tests it against null.
     */
    public function moveTo(
        Order|int $order,
        string $to,
        ?string $by = null,
        ?string $reason = null,
        array $also = [],
        array $only = [],
    ): ?string {
        $id = $order instanceof Order ? (int) $order->getKey() : $order;

        if ($id <= 0) {
            return null;
        }

        $author = $by ?? (auth('admin')->user()?->name ?: 'system');

        $from = DB::transaction(function () use ($id, $to, $author, $reason, $also, $only): ?string {
            $locked = Order::query()->whereKey($id)->lockForUpdate()->first();

            if ($locked === null) {
                return null;
            }

            foreach ($only as $column => $required) {
                if ($locked->getAttribute($column) !== $required) {
                    return null;
                }
            }

            $from = (string) $locked->status;
            $moves = $from !== $to;

            // A save with nothing to write. $also is checked as well as the
            // status because a payment confirmation on an order that some other
            // path has already moved still has to record `paid_at`.
            if (! $moves && $also === []) {
                return null;
            }

            $locked->forceFill(['status' => $to] + $also)->save();

            if (! $moves) {
                // Other columns were written against a status that was already
                // right — a payment confirmation landing on an order some other
                // path had already moved. A write happened, so the caller is
                // told so, but nothing transitioned: no note, no release.
                return $from;
            }

            // Consequences, in the order the note wants to report them.
            $released = in_array($to, self::RELEASES_COUPON, true)
                ? $this->coupons->releaseRedemptions($id)
                : 0;

            /*
             * WHAT THE TRANSITION COSTS OR CREDITS THE SHELF.
             *
             * OrderTransitionStock holds that rule and only that rule: which
             * transitions put units back, and never mind who asked. It landed
             * calling itself from each of five status writers, because when it
             * was written there was nothing else to call it from — its own
             * header says so, and says why an observer could not do the job.
             * This is the road all five of those sites now take, so the call
             * lives here once and the five copies are gone.
             *
             * INSIDE the transaction, deliberately. The call it replaces ran
             * after the commit so that a rolled-back status change could not
             * take a completed stock release with it; in here they roll back
             * together instead, which is the same worry answered the other way
             * round and is the answer the coupon release already gets. It
             * cannot throw — the class swallows and logs, because an order that
             * is already cancelled must not turn the operator's button into a
             * 500.
             */
            app(OrderTransitionStock::class)->applied($id, $from, $to);

            $this->record($locked, $from, $to, $author, $reason, $released);

            return $from;
        });

        // Keep the caller's own instance honest, so code that reads
        // $order->status after this line does not see the value it had before.
        if ($from !== null && $order instanceof Order && $order->exists) {
            $order->refresh();
        }

        return $from;
    }

    /**
     * The transition, in the history the order screen already shows.
     *
     * One note per transition, carrying everything about it in a single
     * sentence a non-technical owner can read: what it moved between, who did
     * it, why, and what it cost or returned. Two notes for one event would
     * read as two events.
     *
     * The coupon line names the code off `orders.coupon_code`, which is the
     * order's own snapshot of what was applied, so the note still says which
     * code was released long after the coupon itself has been edited.
     */
    private function record(
        Order $order,
        string $from,
        string $to,
        string $author,
        ?string $reason,
        int $released,
    ): void {
        $content = sprintf('Status changed from %s to %s.', $from, $to);

        if ($reason !== null && trim($reason) !== '') {
            $content .= ' ' . trim($reason);
        }

        if ($released > 0) {
            $code = trim((string) $order->coupon_code);

            $content .= sprintf(
                ' Coupon %s released — %d use%s handed back.',
                $code !== '' ? $code : 'discount',
                $released,
                $released === 1 ? '' : 's',
            );
        }

        OrderNote::create([
            'order_id' => $order->getKey(),
            'author' => $author,
            'is_customer_note' => false,
            'content' => $content,
        ]);
    }
}
