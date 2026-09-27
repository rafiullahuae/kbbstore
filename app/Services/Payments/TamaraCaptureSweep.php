<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\Order;
use App\Services\Payments\Gateways\TamaraGateway;
use Carbon\CarbonImmutable;

/**
 * The orders this shop has shipped and never been paid for.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * ── WHAT IS BROKEN WITHOUT THIS ─────────────────────────────────────────────
 *
 * Tamara AUTHORISES at checkout and pays nothing. PaymentConfirmer writes
 * `paid_at` for that authorisation, which is correct and is also the most
 * dangerous sentence in this part of the application: the order reads PAID on
 * every screen in the console, the invoice says paid, and not one fil has
 * moved. The money moves when somebody captures, and capture on this shop is
 * one button on one order page that a human has to find.
 *
 * Tamara voids an authorisation nobody captured after about 180 days. So an
 * order that ships and is never captured is an order the shop gave the goods
 * away for, and the evidence is an absence — there is no failed payment, no
 * error, no unhappy customer. TamaraSweep is the same shape one step earlier
 * (an approval whose callback never arrived); this is the step after it, and it
 * is the expensive one, because that one loses a sale and this one loses the
 * goods as well.
 *
 * The merchant's own WooCommerce plugin has this job and calls it
 * `forceCaptureTamaraOrder`. This shop had nothing.
 *
 * ── OFF UNTIL THE OWNER SAYS OTHERWISE, AND THAT IS THE POINT ───────────────
 *
 * WHETHER CAPTURE SHOULD FOLLOW FULFILMENT AUTOMATICALLY IS A COMMERCIAL
 * DECISION, not an engineering one. It is the decision about when this shop
 * takes a customer's money, it interacts with the merchant's own returns
 * policy, and it is the owner's to make. So this class ships complete and
 * ASLEEP: `run()` reads TamaraGateway::autoCapture(), which is false on every
 * install and stays false until somebody sets the switch on the payments screen
 * to On. Until then run() makes no call to Tamara, writes nothing, and reports
 * `ran: false` with the reason.
 *
 * `candidates()` is NOT gated, deliberately — it is one SELECT and no writes,
 * and `payments:tamara-capture --dry` is how the owner sees exactly which
 * orders would be captured BEFORE he decides. A switch whose consequences can
 * only be discovered by turning it on is a switch nobody should be asked to
 * turn on.
 *
 * ── WHY A SWEEP RATHER THAN A HOOK ON THE STATUS CHANGE ─────────────────────
 *
 * Capture could be fired from Orders\OrderStatus::moveTo(), which is the one
 * writer of `orders.status`. A sweep is better here for three reasons, and the
 * third is the one that decides it:
 *
 *   - IT CATCHES FULFILMENT HOWEVER IT HAPPENED. The order screen, the bulk
 *     status change on the list, the importer, a future API. A hook catches the
 *     paths that go through the hook.
 *   - IT IS RETRYABLE BY CONSTRUCTION. Tamara being unreachable at the moment
 *     an operator pressed Shipped must not be the reason an order is never
 *     captured; the next run picks it up, and the run after that.
 *   - A NETWORK CALL MUST NOT BE INSIDE THAT TRANSACTION. moveTo() does its
 *     work inside DB::transaction() against a row held under SELECT ... FOR
 *     UPDATE, with the coupon release and the stock return in there with it.
 *     Hanging two HTTP calls to a third party off that would hold a row lock
 *     open for the length of somebody else's bad morning, and a capture that
 *     succeeded at Tamara inside a transaction that then rolled back would be
 *     money taken with no record of taking it.
 *
 * A 180-day window does not care whether capture happens in the same second or
 * the same hour, so nothing is lost by being late. See the command's docblock
 * for the cron line.
 *
 * ── IT CANNOT DOUBLE-CAPTURE, AND THAT IS NOT THIS CLASS'S DOING ────────────
 *
 * Two guards, and the second one is the real one:
 *
 *   - the candidate query asks for `captured_at IS NULL`, so a captured order is
 *     never fetched; and
 *   - PaymentCapturer CLAIMS `captured_at` with one conditional UPDATE before it
 *     calls the provider, so of two callers holding the same order exactly one
 *     wins the claim and the other is answered `already_captured` WITHOUT a
 *     second call to Tamara.
 *
 * The first is a cost saving. The second is the correctness, and it holds for
 * two sweeps racing, for a sweep racing the Capture button, and for a sweep
 * racing itself after a run that overlapped. THIS CLASS ADDS NO IDEMPOTENCY OF
 * ITS OWN, on purpose: a second mechanism here would be a second place for the
 * rule to be nearly right.
 *
 * ── THE COLUMN THAT MUST NOT GO MISSING ─────────────────────────────────────
 *
 * The candidates come back as WHOLE Order MODELS. No `select()`, and that is a
 * rule rather than a preference. PaymentCapturer reads `voided_at`, `paid_at`,
 * `captured_at`, `status`, `total`, `payment_method` and `currency` off the
 * model it is handed, and TamaraGateway::capture() reads `transaction_id` and
 * `shipping_method` as well — and AN ATTRIBUTE THAT WAS NEVER SELECTED READS
 * NULL WITH NO ERROR. A select list that forgot `voided_at` would leave the
 * "the authorisation has been given back" refusal silently inert, which is
 * exactly the shape PaymentRefunder::gatewayPosition() was found in this round:
 * the rule was right, the second call site's explicit select list was short, and
 * nothing raised. TamaraAutoCaptureTest pins the attribute list on a candidate
 * for that reason.
 */
class TamaraCaptureSweep
{
    /**
     * Fulfilment: the order has left the building.
     *
     * WRITTEN OUT HERE RATHER THAN BORROWED, the way PaymentCapturer::VOID is
     * written out rather than pointing at PaymentConfirmer's copy. Three lists
     * in this application have these two strings in them today and all three
     * answer a different question:
     *
     *   Order::REAL_STATUSES          — did this order count as a sale?
     *                                   `processing` and `onhold` are in it, and
     *                                   an order somebody has not packed yet is
     *                                   the last order in the shop that should
     *                                   have its money taken.
     *   PaymentConfirmer::HOLDS_PLACE — does this order still hold its stock?
     *   this                          — have the goods gone?
     *
     * Only the third one licences taking the money, and tying it to either of
     * the others would mean a future change to their question silently changing
     * whose card gets charged.
     */
    public const FULFILLED = ['shipped', 'completed'];

    /**
     * How long an order must have sat untouched before it is captured.
     *
     * An operator who marks the wrong order Shipped notices within a minute or
     * two, and a capture that lands in that minute is a customer charged for
     * goods that are still on the shelf. Refunding it afterwards works, and is
     * a worse thing to do to a buyer than waiting half an hour.
     *
     * MEASURED ON `updated_at`, WHICH CAN ONLY EVER DELAY. There is no column
     * recording when an order became `shipped` — `completed_at` is a different
     * fact and is null on a shipped order — so this is a proxy, and the honest
     * thing to say about it is which way it is wrong: ANY edit to the order
     * bumps `updated_at`, so an order that is being worked on waits longer than
     * thirty minutes and an order nobody has touched waits exactly thirty. It
     * can never bring a capture forward. Against a 180-day window, waiting is
     * free.
     *
     * ONE CONSEQUENCE, MEASURED RATHER THAN DISCOVERED LATER: a capture that
     * FAILS releases PaymentCapturer's claim, which is a write to the row, so the
     * failed order waits another grace period before it is retried. One cron tick
     * on an hourly schedule, and the alternative — a proxy that ignored its own
     * rule for writes this class caused — would be a proxy with an exception in
     * it. `--minutes=0` retries immediately when somebody is watching.
     */
    public const DEFAULT_MINUTES = 30;

    /** One run's ceiling on round trips to Tamara. */
    public const DEFAULT_LIMIT = 50;

    public function __construct(
        private GatewayRegistry $registry,
        private PaymentCapturer $capturer,
    ) {}

    /**
     * Capture every fulfilled Tamara order that has not been captured yet.
     *
     * @param  string|null  $by  who is credited in the order note. Null on cron,
     *                           which PaymentLedger records as `system`.
     * @return array{
     *     ran: bool,
     *     reason: string|null,
     *     examined: int,
     *     captured: int,
     *     captured_fils: int,
     *     already: int,
     *     failed: int,
     *     orders: array<int, array{order: string, outcome: string, code: string, message: string}>
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
            // Not an error, for the reason TamaraSweep gives in the same place:
            // a shop that does not use Tamara has no uncaptured Tamara orders,
            // and a job that reported failure here would cry wolf every hour.
            return $this->nothing('Tamara has no API token stored, so there is nothing to capture.');
        }

        /*
         * THE SWITCH, AND IT IS CHECKED HERE RATHER THAN IN THE CALLER.
         *
         * Both callers — the console command and anything that follows it — get
         * the same answer from the same read, so there is no route into this
         * shop's money that skips the owner's decision by being written later.
         * The message names the screen, because "it did nothing" with no reason
         * is the report that gets ignored.
         */
        if (! $gateway->autoCapture()) {
            return $this->nothing(
                'Automatic capture is off, so nothing was captured. Turn it on in '
                . 'Store → Payments → Tamara → Settings ("Capture automatically when an order ships"). '
                . 'Until then, capture stays a button on each order.',
            );
        }

        $report = [
            'ran' => true,
            'reason' => null,
            'examined' => 0,
            'captured' => 0,
            'captured_fils' => 0,
            'already' => 0,
            'failed' => 0,
            'orders' => [],
        ];

        foreach ($this->candidates($minutes, $days, $limit) as $order) {
            /*
             * READ THE AMOUNT BEFORE THE CALL. PaymentCapturer captures
             * `(int) $order->total` and refreshes the model afterwards, so this
             * is the same integer it sent — in FILS, like every amount that
             * crosses this interface. Summed as an int and never as a float:
             * a total in this report is a figure the owner reconciles against a
             * Tamara statement, and 0.1 + 0.2 has no business near it.
             */
            $amountFils = (int) $order->total;

            $result = $this->capturer->capture($order, $by);

            $report['examined']++;

            if (! $result->ok) {
                $report['failed']++;
            } elseif ($result->code === 'already_captured') {
                /*
                 * A BUCKET OF ITS OWN, AND NOT A SUCCESS TO BRAG ABOUT.
                 *
                 * `already_captured` reaches here two ways and both are worth
                 * seeing in the report: the claim was lost to a concurrent
                 * capture (fine, and the proof the double-capture guard worked),
                 * or Tamara reports the order captured while this shop's own
                 * `captured_at` was null (not fine — it means a capture landed at
                 * the provider and the record of it did not, which is the state
                 * the reconcile screen exists to find). Counting either as
                 * `captured` would hide the second.
                 */
                $report['already']++;
            } else {
                $report['captured']++;
                $report['captured_fils'] += $amountFils;
            }

            /*
             * ORDER NUMBER, MACHINE CODE AND THE MESSAGE PaymentCapturer WROTE.
             *
             * No email, no total, no address. This answers "did the sweep work";
             * an operator who wants an order opens the order. SettlementResult
             * messages are written for the admin and carry no API body, no key
             * and no buyer field — PaymentSettlementController returns them to
             * the browser on the same grounds.
             */
            $report['orders'][] = [
                'order' => (string) $order->order_number,
                'outcome' => $result->ok ? ($result->code === 'already_captured' ? 'already' : 'captured') : 'failed',
                'code' => $result->code,
                'message' => (string) ($result->message ?? ''),
            ];
        }

        return $report;
    }

    /**
     * The orders this sweep would capture — ONE query, always, and whole models.
     *
     * Public because `--dry` prints exactly this list, and a second copy of the
     * query written for printing would drift from the one the sweep captures
     * from. Drift in that direction is a dry run that reassures somebody about a
     * list the real sweep does not use.
     *
     * EAGER-LOADS `items`, which is the opposite of what TamaraSweep does and is
     * right for the opposite reason. reconcileAuthorisation() touches no
     * relation, so eager loading there would fetch line items for fifty orders
     * to use none of them; TamaraGateway::capture() sends the LINE ITEMS to
     * Tamara on every call — the amount identity it validates is built out of
     * them — so every candidate needs them and `with('items')` makes that one
     * query for the batch instead of one per order.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Order>
     */
    public function candidates(?int $minutes = null, ?int $days = null, ?int $limit = null)
    {
        $minutes = max(0, $minutes ?? self::DEFAULT_MINUTES);
        $days = max(1, $days ?? $this->windowDays());
        $limit = max(1, min(500, $limit ?? self::DEFAULT_LIMIT));

        $now = CarbonImmutable::now();

        return Order::query()
            ->with('items')
            ->where('payment_method', 'tamara')
            /*
             * THE GOODS HAVE GONE. This is the whole licence for taking the
             * money without a human in the loop, and there is no other one: a
             * `processing` order has been paid for by nobody and packed by
             * nobody, and capturing it would charge a customer for something
             * still on the shelf.
             */
            ->whereIn('status', self::FULFILLED)
            /*
             * AUTHORISED. `paid_at` is the authorisation, which is the thing
             * being captured; PaymentCapturer refuses an order without it and
             * this keeps those out of the batch rather than spending a round
             * trip discovering it.
             */
            ->whereNotNull('paid_at')
            /*
             * NOT CAPTURED. The cheap half of the double-capture guard — the
             * half that saves a call. The half that is CORRECT is
             * PaymentCapturer's conditional UPDATE, because between this SELECT
             * and the capture below another caller can win the claim.
             */
            ->whereNull('captured_at')
            /*
             * THE HOLD HAS NOT BEEN GIVEN BACK. PaymentVoider releases the
             * authorisation at Tamara and records `voided_at`, and nothing
             * recreates it. An operator may revive such an order out of
             * `cancelled` and on to `shipped` — OrderStatus permits it, on
             * purpose — and this shop would then be asking Tamara to capture a
             * hold it cancelled itself. PaymentCapturer refuses it too; this is
             * the cheaper of the two guards and the one that means the order is
             * never fetched.
             */
            ->whereNull('voided_at')
            // Nothing to take. PaymentCapturer's `nothing_to_capture`, kept out
            // of the batch rather than counted as a failure in the report.
            ->where('total', '>', 0)
            /*
             * INSIDE THE CAPTURE WINDOW, MEASURED FROM THE AUTHORISATION.
             *
             * Not from `created_at`, which is what TamaraSweep bounds on: that
             * sweep hunts orders that were never authorised, and this one hunts
             * authorisations. The clock Tamara runs starts when the hold is
             * taken, so `paid_at` is the column with the meaning.
             *
             * AND THE BOUND IS LOAD BEARING, not tidiness. The order below is
             * OLDEST FIRST and the query is SLICED, so without this an install
             * carrying two hundred long-expired authorisations would spend every
             * run's whole limit asking Tamara about orders it voided months ago
             * and never reach the ones it can still capture. The orders this
             * sweep exists for would starve behind the ones it can do nothing
             * about.
             *
             * The window is the gateway's own `capture_days`, so the number that
             * warns the operator on the order screen and the number that bounds
             * this scan cannot disagree.
             */
            ->where('paid_at', '>=', $now->subDays($days))
            /*
             * SETTLED FOR AT LEAST $minutes. See DEFAULT_MINUTES: a proxy on
             * `updated_at` that can only ever delay a capture, never bring one
             * forward, which is the only direction a proxy is allowed to be
             * wrong in when the subject is somebody's money.
             */
            ->where('updated_at', '<=', $now->subMinutes($minutes))
            /*
             * OLDEST AUTHORISATION FIRST: a capped run works on the orders
             * closest to being voided by Tamara, which are the ones where
             * waiting costs the whole order.
             *
             * AND `id` AFTER IT, because `paid_at` CAN TIE and this query is
             * SLICED — the same defect StableOrderingTest caught on TamaraSweep.
             * An import, or one busy minute, gives sixty rows the same timestamp,
             * and a limit of fifty may then hand back an overlapping fifty every
             * run and never reach the last ten. Those ten are exactly the orders
             * this sweep exists to rescue.
             */
            ->orderBy('paid_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /**
     * The gateway's capture window in days, or the sweep's own floor.
     *
     * captureWindowDays() is nullable on the interface — cash on delivery holds
     * no authorisation and reports null — so this cannot assume a number even
     * though Tamara's implementation always returns one.
     */
    private function windowDays(): int
    {
        $gateway = $this->registry->find('tamara');

        $days = $gateway instanceof TamaraGateway ? $gateway->captureWindowDays() : null;

        return $days !== null && $days > 0 ? $days : 180;
    }

    /** @return array<string, mixed> */
    private function nothing(string $reason): array
    {
        return [
            'ran' => false,
            'reason' => $reason,
            'examined' => 0,
            'captured' => 0,
            'captured_fils' => 0,
            'already' => 0,
            'failed' => 0,
            'orders' => [],
        ];
    }
}
