<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\Order;
use App\Services\Payments\Gateways\TabbyGateway;
use Carbon\CarbonImmutable;

/**
 * The authorised Tabby orders this shop has not yet been paid for.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * This is TamaraCaptureSweep's sibling, and almost all of that class's docblock
 * applies here word for word — read it. The one difference is the one that
 * matters, so it is stated here rather than left to be inferred from the code.
 *
 * ── WHAT IS BROKEN WITHOUT THIS ─────────────────────────────────────────────
 *
 * Tabby AUTHORISES at checkout and pays nothing. PaymentConfirmer writes
 * `paid_at` for that authorisation, so the order reads PAID on every screen
 * while not one fil has moved; the money moves when somebody captures, and
 * Tabby voids an authorisation nobody captured after about 30 days. Until the
 * capture, Tabby does not count the order in the merchant's payout — which is
 * exactly the loss the owner reported: "until capture tabby is not counting
 * those orders and not count in payout". The WooCommerce store this shop is
 * migrating off captured Tabby automatically; the Laravel shop had nothing but
 * a button on each order and, now, Store → Uncaptured orders.
 *
 * ── CAPTURE FOLLOWS AUTHORISATION, NOT FULFILMENT — THE OWNER'S OWN POLICY ──
 *
 * TamaraCaptureSweep captures only an order that has reached `shipped` or
 * `completed` (its FULFILLED constant): take the money when the goods go. That
 * was the owner's answer for Tamara. For Tabby he asked for the opposite and in
 * as many words — "the tabby orders must be captured automatically ... before
 * in wordpress website the orders were auto capturing" — which is capture on
 * AUTHORISATION, the moment the order is live, exactly as the WooCommerce
 * plugin did it. So CAPTURABLE below is every live status, not just the
 * fulfilled two. Whether capture follows authorisation or fulfilment is a
 * commercial decision and it is the owner's; this is the decision he made.
 *
 * ── IT IS STILL ASLEEP UNTIL THE SWITCH SAYS OTHERWISE ──────────────────────
 *
 * `run()` reads TabbyGateway::autoCapture() and does nothing while it is off.
 * The switch ships ON (the migration tabby_auto_capture_on_by_default), because
 * the owner asked for it — but Store → Payments → Tabby → Settings → "Capture
 * automatically" turns it off, and then this makes no call to Tabby and writes
 * nothing. `candidates()` is NOT gated, deliberately: it is one SELECT and no
 * writes, and `payments:tabby-capture --dry` is how the owner sees what would
 * be captured without turning anything on.
 *
 * ── IT CANNOT DOUBLE-CAPTURE, AND THAT IS PaymentCapturer's DOING ───────────
 *
 * The candidate query asks for `captured_at IS NULL` to save a call, but the
 * correctness is PaymentCapturer claiming `captured_at` in one conditional
 * UPDATE before it calls Tabby: of two callers holding the same order exactly
 * one wins the claim and the other is answered `already_captured` without a
 * second call. This class adds no idempotency of its own, on purpose.
 *
 * ── WHOLE MODELS, NO SELECT LIST ────────────────────────────────────────────
 *
 * candidates() returns whole Order models and eager-loads `items`, because
 * TabbyGateway::capture() sends the line items, tax and shipping on a full
 * capture and PaymentCapturer reads `voided_at`, `paid_at`, `captured_at`,
 * `status`, `total`, `payment_method` and `currency` off the model. An
 * attribute that was never selected reads null with no error, which is how a
 * correct rule goes silently inert — the defect TamaraCaptureSweep names and
 * TabbyAutoCaptureTest pins here too.
 */
class TabbyCaptureSweep
{
    /**
     * The order is live and the money is the shop's to take.
     *
     * WRITTEN OUT HERE RATHER THAN BORROWED from Order::REAL_STATUSES, the way
     * TamaraCaptureSweep::FULFILLED is written out rather than pointing at
     * PaymentConfirmer's copy. The two lists happen to hold the same four
     * strings today and answer different questions: REAL_STATUSES asks "did this
     * count as a sale?", this asks "may its Tabby authorisation be captured
     * now?". Tying capture to REAL_STATUSES would mean a future change to that
     * question silently changing whose card gets charged.
     *
     * `cancelled`, `refunded`, `failed` and `draft` are the ones left out:
     * PaymentCapturer refuses the first three anyway (its VOID), and a draft was
     * never a real order. Capturing any of them would charge a customer for an
     * order the shop is not fulfilling.
     */
    public const CAPTURABLE = ['processing', 'onhold', 'shipped', 'completed'];

    /**
     * How long an order must have sat before it is captured.
     *
     * ZERO, unlike Tamara's thirty. Tamara's grace exists because an operator
     * can mark the wrong order Shipped and a capture in that minute charges for
     * goods still on the shelf — fulfilment is a human action that can be a
     * mistake. Here the trigger is the shopper's own authorisation at checkout,
     * not anything an operator typed, and the owner asked for capture to be
     * immediate. The candidate query's own guards — authorised, live, not
     * voided, not already captured — are what keep a cancelled-in-the-first-
     * minute order out, and a fail notice moves the order to `failed` (out of
     * CAPTURABLE) before this would see it. A caller may still pass --minutes to
     * hold new orders back.
     */
    public const DEFAULT_MINUTES = 0;

    /** One run's ceiling on round trips to Tabby. */
    public const DEFAULT_LIMIT = 50;

    public function __construct(
        private GatewayRegistry $registry,
        private PaymentCapturer $capturer,
    ) {}

    /**
     * Capture every authorised, live, uncaptured Tabby order.
     *
     * @param  string|null  $by  who is credited in the order note. Null on the
     *                           schedule, which PaymentLedger records as `system`.
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
        $gateway = $this->registry->find('tabby');

        if (! $gateway instanceof TabbyGateway) {
            return $this->nothing('This build does not ship the Tabby gateway.');
        }

        if (! $gateway->configured()) {
            // Not an error, the same posture TamaraCaptureSweep takes: a shop
            // with no Tabby keys has no uncaptured Tabby orders, and a job that
            // reported failure here would cry wolf every few minutes.
            return $this->nothing('Tabby has no API keys stored, so there is nothing to capture.');
        }

        if (! $gateway->autoCapture()) {
            return $this->nothing(
                'Automatic capture is off, so nothing was captured. Turn it on in '
                . 'Store → Payments → Tabby → Settings ("Capture automatically"). '
                . 'Until then, capture stays a button on each order and on Store → Uncaptured orders.',
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
            // Read the amount before the call, in fils, summed as an int: a
            // figure the owner reconciles against a Tabby statement has no
            // business near a float. PaymentCapturer captures `(int) $order
            // ->total` and this is the same integer.
            $amountFils = (int) $order->total;

            $result = $this->capturer->capture($order, $by);

            $report['examined']++;

            if (! $result->ok) {
                $report['failed']++;
            } elseif ($result->code === 'already_captured') {
                // A bucket of its own, not a success to brag about: either the
                // claim was lost to a concurrent capture (the guard working) or
                // Tabby reports a capture this shop had no record of — which is
                // what Store → Payments → Reconcile exists to find. Counting it
                // as `captured` would hide the second.
                $report['already']++;
            } else {
                $report['captured']++;
                $report['captured_fils'] += $amountFils;
            }

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
     * The orders this sweep would capture — ONE query, always, whole models.
     *
     * Public because `--dry` prints exactly this list; a second copy written
     * for printing would drift from the one the sweep captures from.
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
            ->where('payment_method', 'tabby')
            /*
             * LIVE. The licence for taking the money without a human in the
             * loop: a cancelled, refunded, failed or draft order is one the
             * shop is not fulfilling, and capturing it charges a customer for
             * nothing. See CAPTURABLE.
             */
            ->whereIn('status', self::CAPTURABLE)
            /*
             * AUTHORISED. `paid_at` is the authorisation being captured;
             * PaymentCapturer refuses an order without it, so this keeps those
             * out of the batch rather than spending a round trip on them.
             */
            ->whereNotNull('paid_at')
            // NOT CAPTURED — the cheap half of the double-capture guard. The
            // correct half is PaymentCapturer's conditional UPDATE.
            ->whereNull('captured_at')
            /*
             * THE HOLD HAS NOT BEEN GIVEN BACK. PaymentVoider releases the
             * authorisation at Tabby and records `voided_at`; nothing recreates
             * it, and an operator may revive such an order back into a live
             * status. PaymentCapturer refuses it too; this is the cheaper guard
             * and the one that means it is never fetched.
             */
            ->whereNull('voided_at')
            // Nothing to take. PaymentCapturer's `nothing_to_capture`, kept out
            // of the batch rather than counted a failure.
            ->where('total', '>', 0)
            /*
             * INSIDE THE CAPTURE WINDOW, MEASURED FROM THE AUTHORISATION.
             *
             * `paid_at`, not `created_at`: Tabby's clock starts when the hold is
             * taken. Load-bearing, not tidiness — the order is OLDEST FIRST and
             * the query is SLICED, so without this an install carrying old
             * expired authorisations would spend every run's limit asking Tabby
             * about orders it voided weeks ago and never reach the ones it can
             * still capture. The window is the gateway's own `capture_days`, so
             * the number that warns on the order screen and the number that
             * bounds this scan cannot disagree.
             */
            ->where('paid_at', '>=', $now->subDays($days))
            // Settled for at least $minutes (0 by default; see DEFAULT_MINUTES).
            // Measured on `updated_at`, a proxy that can only ever delay a
            // capture, never bring one forward.
            ->where('updated_at', '<=', $now->subMinutes($minutes))
            /*
             * OLDEST AUTHORISATION FIRST so a capped run works on the orders
             * closest to being voided; `id` after it because `paid_at` CAN TIE
             * and the query is SLICED — a busy minute of imports gives rows the
             * same timestamp and a limit would otherwise hand back an
             * overlapping page every run and never reach the last few.
             */
            ->orderBy('paid_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /**
     * The gateway's capture window in days, or this sweep's floor of 30.
     *
     * captureWindowDays() is nullable on the interface (cash on delivery holds
     * no authorisation and reports null), so this cannot assume a number even
     * though Tabby's implementation always returns one.
     */
    private function windowDays(): int
    {
        $gateway = $this->registry->find('tabby');

        $days = $gateway instanceof TabbyGateway ? $gateway->captureWindowDays() : null;

        return $days !== null && $days > 0 ? $days : 30;
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
