<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\Order;
use App\Services\Payments\Gateways\TamaraGateway;
use Carbon\CarbonImmutable;

/**
 * The orders Tamara approved and this shop never heard about.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * ── WHAT IS BROKEN WITHOUT THIS ─────────────────────────────────────────────
 *
 * Every Tamara approval reaches this shop as a callback and nothing else. When
 * the callback lands, the shop is correct. When it does not, NOTHING ASKS — and
 * TamaraGateway::reconcileAuthorisation() sets out at length what that costs:
 * an order stuck `pending` for ever, holding stock and a coupon use, while at
 * Tamara's end the buyer was approved, has a payment plan, and believes they
 * have bought something. The shop never ships and never gets paid.
 *
 * Callbacks go missing for dull reasons — this host restarting mid-POST, a blip
 * at the egress, Tamara's retry budget running out, a webhook secret rotated
 * between session creation and approval. The merchant's own plugin does not
 * trust the callback either: `forceAuthoriseTamaraOrder` sweeps on cron. This
 * is that sweep.
 *
 * ── WHAT IT TOUCHES, WHICH IS AS LITTLE AS POSSIBLE ─────────────────────────
 *
 * It decides nothing. For each candidate it asks Tamara what the order is and
 * hands the answer to the SAME code a verified webhook runs, so the amount and
 * the currency are compared by PaymentConfirmer exactly as they are on a
 * callback, `paid_at` is claimed exactly once, and an order Tamara still reports
 * as `new` is left completely alone. Running it twice in a row changes nothing
 * the first run did not.
 *
 * ── THE TWO BOUNDS, AND WHY EACH ONE EXISTS ─────────────────────────────────
 *
 *   A LOWER BOUND IN MINUTES. An order placed ninety seconds ago is a shopper
 *   currently looking at Tamara's hosted page. Sweeping it would ask Tamara
 *   about an approval that has not happened yet, every run, for nothing. The
 *   default leaves the ordinary flow — redirect, approve, callback — a quarter
 *   of an hour to finish on its own before anything else looks.
 *
 *   AN UPPER BOUND IN DAYS, and a batch LIMIT. Past the window Tamara has
 *   expired the authorisation itself and there is no money left to rescue. The
 *   limit is what keeps one run's cost knowable: this makes one or two HTTP
 *   calls per candidate against somebody else's rate-limited API, so a shop
 *   with four hundred stale pending orders must not turn a cron tick into four
 *   hundred round trips. Oldest first, so a capped run always makes progress on
 *   the orders closest to expiring and nothing starves.
 *
 * ── ONE QUERY FOR THE CANDIDATES ────────────────────────────────────────────
 *
 * The candidate list is ONE select, bounded by the limit, and the loop adds no
 * query per order beyond the writes a settlement actually makes. The slope that
 * matters is measured in TamaraGatewayParityTest: the query count for the
 * candidate scan does not move as the number of stale orders grows, which is
 * the N+1 rule 4 forbids and the shape this sweep would most easily have had.
 */
class TamaraSweep
{
    /** Leave the ordinary redirect-approve-callback flow this long to work. */
    public const DEFAULT_MINUTES = 15;

    /**
     * How far back to look, in days.
     *
     * Not the 180 of the capture window on purpose. 180 days is how long an
     * AUTHORISATION lives once it exists; this sweep is hunting orders that were
     * never authorised at all, and a `pending` order from five months ago is one
     * Tamara expired long ago. Thirty days keeps the run cheap and still covers
     * every outage this shop could plausibly have had; the bound is an argument,
     * so a one-off catch-up can be told to look further.
     */
    public const DEFAULT_DAYS = 30;

    /** One run's ceiling on round trips to Tamara. */
    public const DEFAULT_LIMIT = 50;

    public function __construct(
        private GatewayRegistry $registry,
        private PaymentLedger $ledger,
    ) {}

    /**
     * Sweep, and report what happened to each order by number.
     *
     * @return array{
     *     ran: bool,
     *     reason: string|null,
     *     examined: int,
     *     paid: int,
     *     failed: int,
     *     untouched: int,
     *     errors: int,
     *     orders: array<int, array{order: string, outcome: string, message: string}>
     * }
     */
    public function run(
        ?int $minutes = null,
        ?int $days = null,
        ?int $limit = null,
        ?string $by = null,
    ): array {
        $gateway = $this->registry->find('tamara');

        if (! $gateway instanceof TamaraGateway) {
            return $this->nothing('This build does not ship the Tamara gateway.');
        }

        if (! $gateway->configured()) {
            // Not an error. A shop that does not use Tamara has no stale Tamara
            // orders, and a sweep that reported failure here would cry wolf on
            // every cron tick for ever.
            return $this->nothing('Tamara has no API token stored, so there is nothing to sweep.');
        }

        $candidates = $this->candidates($minutes, $days, $limit);

        $report = [
            'ran' => true,
            'reason' => null,
            'examined' => 0,
            'paid' => 0,
            'failed' => 0,
            'untouched' => 0,
            'errors' => 0,
            'orders' => [],
        ];

        foreach ($candidates as $order) {
            /*
             * READ THE STATUS BEFORE THE CALL, NOT AFTER IT.
             *
             * PaymentConfirmer::fail() writes the cancellation onto THIS MODEL
             * INSTANCE, not just onto the row — it is handed $order and saves it.
             * So by the time reconcileAuthorisation() returns, $order->status has
             * already become `cancelled`, and a "did the status move?" test taken
             * afterwards compares the new value with itself.
             *
             * That is exactly the bug this line fixes, and it was not
             * theoretical: every order Tamara had DECLINED was counted in the
             * `untouched` bucket, so the sweep's own report said "still waiting"
             * about orders it had just closed. The work was done correctly and
             * the summary the operator reads was wrong, which is the worst
             * combination — nobody looks for a bug in a job that says it did
             * nothing.
             */
            $before = (string) $order->status;

            $outcome = $gateway->reconcileAuthorisation($order);

            $report['examined']++;

            /*
             * WHICH BUCKET — READ OFF THE ORDER, NEVER OFF THE OUTCOME'S TEXT.
             *
             * WebhookOutcome carries a bool, an HTTP status and a sentence, and
             * the sentence is written for a provider's delivery log. `applied`
             * covers a payment AND a cancellation (PaymentConfirmer returns it
             * for both), and `ignored` covers "already paid" as well as "nothing
             * to do" — so no combination of those three fields says which way an
             * order went. The order itself does, unambiguously.
             *
             * Matching on the message would have compiled, passed a
             * hand-written fake, and mis-bucketed every real settlement the day
             * somebody rephrased a string in PaymentConfirmer. Refreshing is one
             * primary-key read on a row already in memory.
             */
            if (! $outcome->accepted) {
                // refused() or failed(): either Tamara could not be reached, or
                // it has no such order. Neither changed anything.
                $report['errors']++;
            } else {
                $order->refresh();

                if ($order->paid_at !== null) {
                    $report['paid']++;

                    $this->ledger->note($order, sprintf(
                        'Tamara had approved this order and the notification never arrived. '
                        . 'The scheduled check found it and marked it paid.%s',
                        $order->transaction_id !== null ? ' Tamara order ' . $order->transaction_id . '.' : '',
                    ), $by);
                } elseif ((string) $order->status !== $before) {
                    $report['failed']++;
                } else {
                    $report['untouched']++;
                }
            }

            $report['orders'][] = [
                'order' => (string) $order->order_number,
                'outcome' => $outcome->accepted ? 'settled' : 'error',
                'message' => $outcome->message,
            ];
        }

        return $report;
    }

    /**
     * The orders this sweep would ask Tamara about — ONE query, always.
     *
     * Public because the `--dry` flag on payments:tamara-sweep prints exactly
     * this list, and a second copy of the query written for printing would drift
     * from the one the sweep loops over. It is also what the slope test in
     * TamaraGatewayParityTest measures: the count here does not move with the
     * number of stale orders, which is the N+1 rule 4 forbids and the easiest
     * shape for this method to have had.
     *
     * NO EAGER LOADS. reconcileAuthorisation() reads columns off the order and
     * touches no relation, so `with('items')` here would fetch line items for
     * fifty orders to use none of them.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Order>
     */
    public function candidates(?int $minutes = null, ?int $days = null, ?int $limit = null)
    {
        $minutes = max(0, $minutes ?? self::DEFAULT_MINUTES);
        $days = max(1, $days ?? self::DEFAULT_DAYS);
        $limit = max(1, min(500, $limit ?? self::DEFAULT_LIMIT));

        $now = CarbonImmutable::now();

        return Order::query()
            ->where('payment_method', 'tamara')
            /*
             * `pending` ONLY, and this is the guard that keeps the sweep safe.
             *
             * An order that has moved on — processing, shipped, cancelled,
             * refunded — has been decided by somebody, and a background job that
             * reopened those decisions on the strength of a provider's status
             * would be the worst possible thing in this file. PaymentConfirmer
             * refuses them too; this is the cheaper of the two guards and the one
             * that means they are never fetched at all.
             */
            ->where('status', 'pending')
            ->whereNull('paid_at')
            ->where('created_at', '<=', $now->subMinutes($minutes))
            ->where('created_at', '>=', $now->subDays($days))
            // Oldest first: a capped run works on the orders closest to being
            // expired by Tamara, which are the ones where waiting costs money.
            ->orderBy('created_at')
            ->limit($limit)
            ->get();
    }

    /** @return array<string, mixed> */
    private function nothing(string $reason): array
    {
        return [
            'ran' => false,
            'reason' => $reason,
            'examined' => 0,
            'paid' => 0,
            'failed' => 0,
            'untouched' => 0,
            'errors' => 0,
            'orders' => [],
        ];
    }
}
