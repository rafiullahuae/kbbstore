<?php

declare(strict_types=1);

namespace App\Services\Payments\Reconciliation;

use App\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * Cash on delivery, which cannot be reconciled and must not be left ambiguous.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS IS A SEPARATE REPORT AND NOT A FOURTH COLUMN IN THE OTHER ONE
 * ---------------------------------------------------------------------------
 * Reconciliation compares two sets of books. Cash on delivery has one. There is
 * no provider holding a record of money handed to a courier, no API to page, no
 * reference to match on — so every question the Reconciler asks is
 * unanswerable here, and an answer of "no discrepancies found" against COD
 * would be a lie told by omission. COD deliberately does not implement
 * ListsTransactions; that absence is the honest statement, and this class is
 * where it is said out loud rather than inferred from a gateway missing off a
 * list.
 *
 * ---------------------------------------------------------------------------
 * WHAT THIS DOES ANSWER, AND WHAT IT CANNOT
 * ---------------------------------------------------------------------------
 * The owner's real question about COD is "cash collected versus cash banked",
 * and only ONE HALF OF IT is knowable from this database.
 *
 *   KNOWABLE — what the shop is owed, and what it has been told it collected.
 *     A COD order that has shipped is cash the courier should be holding.
 *     `captured_at` on a COD order is somebody in this admin panel pressing
 *     Capture, which for cash on delivery means "the money came back to us"
 *     (CashOnDelivery::capture() calls nobody; it agrees that the cash
 *     arrived). So the gap between "shipped" and "marked collected" is real
 *     and this report shows it.
 *
 *   NOT KNOWABLE — what was BANKED. Nothing in this application has ever seen
 *     a courier remittance advice or a bank statement. There is no table for
 *     one and no import for one. Producing a "cash banked" figure would mean
 *     inventing the number, and an invented number in a money report is worse
 *     than a missing one: it agrees with itself every time it is run.
 *
 * So this reports one side and says plainly that the other side has to come off
 * the courier's statement, by eye. That is a smaller promise than the gateway
 * reconciliation makes, and saying so is the point. If the owner ever starts
 * importing remittance advices, the second half becomes possible and this is
 * where it belongs.
 *
 * Read-only, like everything else here. It writes nothing at all — not even a
 * finding — because there is no second source for a finding to be a
 * disagreement WITH.
 */
final class CashOnDeliveryPosition
{
    /**
     * COD money in a window, split by how far along it is.
     *
     * @return array<string, mixed>
     */
    public function forWindow(ReconcileWindow $window): array
    {
        $base = fn () => DB::table('orders')
            ->where('payment_method', 'cod')
            ->whereNull('deleted_at')
            ->whereBetween('created_at', [$window->localFrom(), $window->localTo()]);

        /*
         * `captured_total`, not `total`, for the collected figure. PaymentCapturer
         * writes what was actually taken, and it is allowed to be less than the
         * order total. Reporting the order total as collected would overstate
         * the drawer by the difference on every short collection.
         */
        $collected = $base()->whereNotNull('captured_at');
        $outstanding = $base()->whereNull('captured_at')->whereIn('status', Order::REAL_STATUSES);
        $abandoned = $base()->whereNull('captured_at')->whereNotIn('status', Order::REAL_STATUSES);

        return [
            'window' => ['from' => $window->from->toDateString(), 'to' => $window->to->toDateString()],

            'collected' => [
                'orders' => (int) $collected->count(),
                'fils' => (int) ($collected->sum('captured_total') ?? 0),
            ],

            'outstanding' => [
                'orders' => (int) $outstanding->count(),
                'fils' => (int) ($outstanding->sum('total') ?? 0),
            ],

            'closed_uncollected' => [
                'orders' => (int) $abandoned->count(),
                'fils' => (int) ($abandoned->sum('total') ?? 0),
            ],

            /*
             * Said in the payload rather than only in this comment, because the
             * screen renders what it is given and the person reading it is
             * being shown numbers next to a reconciliation report. He has to
             * know that these two are not the same kind of claim.
             */
            'reconcilable' => false,
            'note' => 'Cash on delivery has no provider to check against — there is no second set of books, so '
                . 'nothing here can be reconciled the way Stripe, Tabby and Tamara are. What this shows is one '
                . 'side only: what this shop believes it is owed, and what somebody here has marked as collected. '
                . 'Nothing in this application has ever seen a courier remittance or a bank statement, so the '
                . 'money actually BANKED has to be read off the courier\'s own statement and compared with the '
                . 'collected figure by eye.',
        ];
    }
}
