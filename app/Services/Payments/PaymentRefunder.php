<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The one place money goes back.
 *
 * THE CEILING IS COMPUTED HERE, FROM THE DATABASE, EVERY TIME
 *
 * A refund may never exceed what was actually captured minus what has already
 * been refunded. Both halves of that come from our own tables inside the same
 * locked transaction that writes the refund row. Nothing in the request
 * contributes to the ceiling — an admin screen can be edited in a browser
 * console, and "refund 5000 against a 250 order" is one keystroke away if the
 * figure it sends is believed.
 *
 * `captured_total` is the base when the order has been captured. Where it has
 * not — an order confirmed before this lane shipped, or a gateway that
 * captures at authorisation — the order's own `total` stands in, which is the
 * most that could ever have been taken. It is a ceiling either way, never an
 * assertion that the money is there: if it is not, the provider refuses and
 * that refusal is recorded rather than swallowed.
 *
 * IDEMPOTENCY: THE ROW IS THE LOCK
 *
 * A refund cannot use PaymentConfirmer's null-timestamp trick, because unlike
 * being paid an order can legitimately be refunded more than once. So the
 * claim is the refund row itself, carrying a unique `idempotency_key`:
 *
 *   - the row is written PENDING, inside the transaction that checked the
 *     ceiling, and pending rows count toward the ceiling. Two partial refunds
 *     racing each other therefore cannot both pass a check that only one of
 *     them should;
 *   - the key is unique at the database level, so a double-clicked button is
 *     rejected by the index rather than by a check that could be raced. The
 *     second attempt is handed the FIRST refund back and makes no second call
 *     to the provider;
 *   - a caller that supplies no key gets a derived one — the order, the
 *     amount, the reason and the number of refunds already settled — which
 *     collides for two requests in flight together, PLUS a one-minute recency
 *     check for the sequential double click the derived key cannot see (the
 *     first has settled by then, so the key has changed). See recentTwin();
 *   - a FAILED attempt releases its key (set to null) so the merchant can
 *     genuinely retry, while the row itself stays as the audit record.
 *
 * Only `pending` and `succeeded` rows count as refunded. A failed row is
 * evidence that money did not move.
 */
class PaymentRefunder
{
    public function __construct(
        private GatewayRegistry $registry,
        private PaymentLedger $ledger,
    ) {}

    /** Statuses that hold money against the ceiling. */
    public const COUNTED = ['pending', 'succeeded'];

    /**
     * @param  int          $amountFils      always fils; the caller converts.
     * @param  string|null  $idempotencyKey  supplied by the admin screen per
     *                                       form render. Derived when absent.
     */
    public function refund(
        Order $order,
        int $amountFils,
        ?string $reason = null,
        ?string $by = null,
        ?string $idempotencyKey = null,
    ): RefundOutcome {
        $providerId = (string) ($order->payment_method ?? '');
        $gateway = $this->registry->find($providerId !== '' ? $providerId : null);

        if ($amountFils <= 0) {
            return RefundOutcome::refused('invalid_amount', 'Enter an amount greater than zero.');
        }

        if ($order->trashed()) {
            return RefundOutcome::refused('order_trashed', 'This order is in the trash.');
        }

        $refund = null;
        $refused = null;

        DB::transaction(function () use ($order, $amountFils, $reason, $by, $idempotencyKey, $providerId, &$refund, &$refused) {
            // Re-read under a lock. Everything the ceiling is made of is read
            // inside this transaction, so a concurrent refund cannot be
            // decided against a total it then changes.
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                $refused = RefundOutcome::refused('not_found', 'That order no longer exists.');

                return;
            }

            $ceiling = $this->capturedFils($locked);
            $already = $this->refundedFils($locked);

            if ($ceiling <= 0) {
                $refused = RefundOutcome::refused(
                    'nothing_captured',
                    'Nothing has been captured on this order, so there is nothing to refund.',
                );

                return;
            }

            if ($already + $amountFils > $ceiling) {
                $refused = RefundOutcome::refused('over_captured', sprintf(
                    'That would refund more than was captured. %s of %s is still refundable.',
                    Money::amount(max(0, $ceiling - $already), 2),
                    Money::amount($ceiling, 2),
                ));

                return;
            }

            $supplied = $idempotencyKey !== null && trim($idempotencyKey) !== '';

            // A caller that brought no key gets one derived, AND a recency
            // check — see derivedKey() for why the derived key alone is not
            // enough once the first click has already settled.
            if (! $supplied) {
                $recent = $this->recentTwin($locked, $amountFils, $reason);

                if ($recent !== null) {
                    $refused = RefundOutcome::duplicate($recent);

                    return;
                }
            }

            $key = $supplied
                ? trim($idempotencyKey)
                : $this->derivedKey($locked, $amountFils, $reason);

            try {
                $refund = $locked->refunds()->create([
                    'amount' => $amountFils,
                    'reason' => $reason !== null && trim($reason) !== '' ? trim($reason) : null,
                    'refunded_by' => $by ?: 'Admin',
                    'status' => 'pending',
                    'provider' => $providerId,
                    'idempotency_key' => $key,
                ]);
            } catch (QueryException $e) {
                // The unique index fired. Somebody clicked twice, or the same
                // call was retried. Hand back the refund that already exists
                // rather than making a second one.
                $existing = Refund::query()
                    ->where('order_id', $locked->getKey())
                    ->where('idempotency_key', $key)
                    ->first();

                if ($existing === null) {
                    throw $e;
                }

                $refused = RefundOutcome::duplicate($existing);
            }
        });

        if ($refused !== null) {
            return $refused;
        }

        if ($refund === null) {
            return RefundOutcome::refused('not_recorded', 'That refund could not be recorded.');
        }

        // Cash on delivery has no API to call and no gateway class implementing
        // settlement would be honest about pretending otherwise, so a gateway
        // this build cannot settle through still gets a recorded refund — the
        // ledger entry a manual bank transfer needs — and says so.
        if (! $gateway instanceof SettlesPayments) {
            return $this->settle(
                $order,
                $refund,
                $providerId,
                SettlementResult::ok('recorded_only', null, ['provider' => $providerId],
                    'Recorded. This gateway has no refund API — return the money by hand.'),
                $by,
            );
        }

        $result = $gateway->refund(
            $order,
            $amountFils,
            $refund->reason,
            $order->capture_ref !== null && $order->capture_ref !== '' ? $order->capture_ref : $order->transaction_id,
            $refund->idempotency_key,
        );

        return $this->settle($order, $refund, $providerId, $result, $by);
    }

    /* ------------------------------------------------------------- amounts */

    /**
     * The most that could ever be refunded on this order, in fils.
     *
     * Not a float anywhere on this path: `captured_total`, `payments.amount`
     * and `total` are all integer columns and none is divided before it is
     * compared.
     *
     * WHY THIS DOES NOT READ `orders.total` FIRST
     *
     * It used to, whenever the order was confirmed but not captured through
     * this lane — which is every Stripe, Tabby and Tamara order between the
     * callback landing and somebody pressing Capture, and every Stripe order
     * that was captured at Stripe and so never needs the button at all.
     *
     * `orders.total` is not evidence of anything. It is a mutable column, and
     * `processing` is in AdminOrderController::EDITABLE_STATUSES, so the
     * admin can add and remove lines on a paid order and the ceiling moved
     * with them in BOTH directions:
     *
     *   - edit UP, and a refund larger than the payment is accepted. An order
     *     paid 200.00 with a 100.00 line added refunded 300.00 and was marked
     *     `refunded`. 100.00 the shop never took went back to the customer.
     *   - edit DOWN, and the customer cannot be given back what they paid. The
     *     same order edited to 100.00 refunded 100.00, reported nothing
     *     further refundable, and moved to `refunded` — a full-refund claim
     *     while the shop still held 100.00 of the buyer's money.
     *
     * So the ceiling is the amount the PROVIDER confirmed, on the `payments`
     * rows PaymentConfirmer writes inside the same transaction that sets
     * `paid_at`. Those rows are never rewritten by an order edit.
     *
     * `orders.total` survives only as the fallback for a paid order carrying
     * no payment row at all — a WooCommerce order brought in by
     * Import\Entities\OrderImporter, which sets `paid_at` from `date_paid`
     * and has no provider record to offer. There is no better evidence for
     * those, and refusing to refund them would be worse than a soft ceiling.
     */
    public function capturedFils(Order $order): int
    {
        return self::ceilingFrom(
            (int) ($order->captured_total ?? 0),
            $order->captured_at !== null,
            $order->paid_at !== null,
            // Only `paid` rows. A row whose status is `amount_mismatch`,
            // `currency_mismatch` or a provider failure reason records a
            // payment that was REFUSED, and summing it would build the ceiling
            // out of money that never arrived.
            //
            // A CLOSURE so the query is not made when the two lines above have
            // already answered — an order captured through this lane never
            // needed it and must not start paying for it.
            fn (): int => (int) Payment::query()
                ->where('order_id', $order->getKey())
                ->where('status', 'paid')
                ->sum('amount'),
            (int) $order->total,
        );
    }

    /**
     * The ceiling rule itself, in one place, taking facts rather than a model.
     *
     * Extracted so that a caller asking the same question about MANY orders at
     * once — StripeConnect's disconnect warning does, over every order this
     * gateway ever took money for — gets the identical answer without either
     * re-deriving it or paying for one query per order. Two implementations of
     * "how much of this could still come back" would drift, and the one that
     * drifts is the one on the screen nobody is looking at.
     *
     * @param  \Closure(): int  $confirmedPaid  summed `paid` payment rows, asked
     *                                          for only when it is needed.
     */
    private static function ceilingFrom(
        int $capturedTotal,
        bool $captured,
        bool $paid,
        \Closure $confirmedPaid,
        int $total,
    ): int {
        if ($captured && $capturedTotal > 0) {
            return $capturedTotal;
        }

        if (! $paid) {
            return 0;
        }

        $confirmed = $confirmedPaid();

        return $confirmed > 0 ? $confirmed : $total;
    }

    /** Fils already refunded or reserved against this order. */
    public function refundedFils(Order $order): int
    {
        return (int) Refund::query()
            ->where('order_id', $order->getKey())
            ->whereIn('status', self::COUNTED)
            ->sum('amount');
    }


    /**
     * Money this gateway has taken and not given back, across the whole shop.
     *
     * ---------------------------------------------------------------------
     * What it is for
     * ---------------------------------------------------------------------
     *
     * StripeConnect::disconnect() shows a confirm dialog built from
     * inFlight(), which counts orders with `paid_at IS NULL` — shoppers who may
     * be on Stripe's payment page right now. It had NO notion of refundable
     * money at all, so a shop holding a fully captured AED 250.00 Stripe order
     * was told `in_flight.count = 0` and pressed Disconnect on a clean-looking
     * dialog. The refund afterwards comes back `not_configured`: no key, no
     * Stripe refund, and no way to return that money through the panel at all.
     *
     * The structural half of that is unavoidable — a refund needs a key. The
     * WARNING is the fixable half, and this is it.
     *
     * ---------------------------------------------------------------------
     * IT IS THIS CLASS'S OWN ARITHMETIC, NOT A SECOND OPINION
     * ---------------------------------------------------------------------
     *
     * "Captured" means what capturedFils() means — the sum of `paid` payment
     * rows the provider confirmed, `captured_total` when the order went
     * through this lane, and `orders.total` only for an imported order with no
     * payment row. It deliberately does NOT mean `orders.total`, which is a
     * column an operator can edit and which used to be the ceiling; the header
     * on capturedFils() sets out what that cost. "Refunded" means what
     * refundedFils() means — `pending` and `succeeded` refunds both, because a
     * refund in flight is money already spoken for.
     *
     * Both come from ceilingFrom() and COUNTED, the same two things the
     * single-order path uses. What is different here is only HOW the inputs are
     * gathered: two grouped queries per chunk instead of two queries per order,
     * because this runs on a screen the owner opens rather than on a button he
     * presses once.
     *
     * ---------------------------------------------------------------------
     * WHAT IS COUNTED, AND WHAT IS NOT
     * ---------------------------------------------------------------------
     *
     * Every order on this gateway that has either a `paid_at` or a
     * `captured_at`, whatever its status. NOT bounded to a recent window, which
     * is the one thing this must not copy from inFlight(): an order from March
     * that the shop still holds money for is exactly as unrefundable after a
     * disconnect as one from this morning, and hiding it would make the
     * warning a lie in the direction that costs the owner money.
     *
     * Trashed orders are included on purpose. A soft-deleted order's money is
     * still in the owner's Stripe account and still the buyer's.
     *
     * @param  int  $sample  how many to name on the dialog, oldest first. The
     *                       COUNT and the TOTAL are over everything; only the
     *                       list is cut.
     * @return array{count: int, amount: int, amount_display: string, currency: string, orders: array<int, array<string, mixed>>}
     */
    public function gatewayPosition(string $gateway, int $sample = 10): array
    {
        $count = 0;
        $amount = 0;
        $orders = [];

        Order::withTrashed()
            ->where('payment_method', $gateway)
            ->where(fn ($q) => $q->whereNotNull('paid_at')->orWhereNotNull('captured_at'))
            ->select(['id', 'order_number', 'status', 'total', 'currency', 'paid_at', 'captured_at', 'captured_total', 'created_at'])
            /*
             * By id rather than by page: the set is being read while nothing
             * stops an order being paid underneath it, and an offset walk would
             * skip or repeat a row when that happens.
             *
             * chunkById imposes ascending id and no ordering of ours survives
             * it, so the named list is the OLDEST of them. That is deliberate
             * rather than merely accepted: an order from March the shop is
             * still holding money for is the one the owner has stopped
             * thinking about, and it is the one this dialog exists to put in
             * front of him. The count and the total are over everything either
             * way.
             */
            ->chunkById(500, function ($chunk) use (&$count, &$amount, &$orders, $sample) {
                $ids = $chunk->map(fn (Order $o) => (int) $o->getKey())->all();

                $paid = Payment::query()
                    ->whereIn('order_id', $ids)
                    ->where('status', 'paid')
                    ->groupBy('order_id')
                    ->selectRaw('order_id, SUM(amount) as total')
                    ->pluck('total', 'order_id');

                $back = Refund::query()
                    ->whereIn('order_id', $ids)
                    ->whereIn('status', self::COUNTED)
                    ->groupBy('order_id')
                    ->selectRaw('order_id, SUM(amount) as total')
                    ->pluck('total', 'order_id');

                foreach ($chunk as $order) {
                    $id = (int) $order->getKey();

                    $captured = self::ceilingFrom(
                        (int) ($order->captured_total ?? 0),
                        $order->captured_at !== null,
                        $order->paid_at !== null,
                        fn (): int => (int) ($paid[$id] ?? 0),
                        (int) $order->total,
                    );

                    $outstanding = $captured - (int) ($back[$id] ?? 0);

                    if ($outstanding <= 0) {
                        continue;
                    }

                    $count++;
                    $amount += $outstanding;

                    if (count($orders) < $sample) {
                        $orders[] = [
                            'order_number' => (string) $order->order_number,
                            'status' => (string) $order->status,
                            'refundable' => $outstanding,
                            'refundable_display' => Money::plain($outstanding),
                            'currency' => strtoupper((string) ($order->currency ?: Money::currency())),
                            'created_at' => optional($order->created_at)->toIso8601String(),
                        ];
                    }
                }
            });

        return [
            'count' => $count,
            'amount' => $amount,
            'amount_display' => Money::plain($amount),
            'currency' => Money::currency(),
            'orders' => $orders,
        ];
    }

    /* ------------------------------------------------------------ internals */

    /**
     * Turn a pending refund into a settled or failed one, recording the
     * attempt either way.
     */
    private function settle(
        Order $order,
        Refund $refund,
        string $providerId,
        SettlementResult $result,
        ?string $by,
    ): RefundOutcome {
        $currency = strtoupper((string) ($order->currency ?: 'AED'));
        $amount = (int) $refund->amount;

        if (! $result->ok) {
            // Key released so a real retry is possible; the row stays as the
            // record that an attempt was made and did not work.
            $refund->forceFill([
                'status' => 'failed',
                'failure_code' => $result->code,
                'idempotency_key' => null,
            ])->save();

            $this->ledger->record($order, $providerId, 'refund_failed', $amount, null, $result->summary);

            $this->ledger->note($order, sprintf(
                'Refund of %s %s via %s FAILED (%s). No money has been returned to the customer.',
                Money::amount($amount, 2),
                $currency,
                $providerId !== '' ? $providerId : 'the payment method on file',
                $result->code,
            ), $by);

            $order->refresh();

            return RefundOutcome::failed($refund, $result);
        }

        $refund->forceFill([
            'status' => 'succeeded',
            'provider_ref' => $result->reference,
            'failure_code' => null,
        ])->save();

        $this->ledger->record($order, $providerId, 'refund', $amount, $result->reference, $result->summary);

        $this->ledger->note($order, sprintf(
            'Refunded %s %s via %s.%s%s',
            Money::amount($amount, 2),
            $currency,
            $providerId !== '' ? $providerId : 'the payment method on file',
            $result->reference !== null ? ' Reference ' . $result->reference . '.' : '',
            $result->code === 'recorded_only' ? ' Recorded only — settle this by hand.' : '',
        ), $by);

        // A fully refunded order moves status; a partial one does not, because
        // the rest of it is still a live order that has to ship.
        $order->refresh();

        if ($this->refundedFils($order) >= $this->capturedFils($order) && $order->status !== 'refunded') {
            /*
             * Through App\Services\Orders\OrderStatus, and this is the path
             * that most needed it: a query-builder update fires no model events
             * at all, so the one status change in this application with real
             * money behind it was the one nothing could observe.
             *
             * What the funnel adds is that the sale being undone undoes the
             * coupon use that paid for it. A PARTIAL refund still changes
             * nothing here — it does not reach this branch, because the status
             * only moves when everything captured has gone back — and that is
             * the right answer as well as the existing one: the customer keeps
             * the goods and the order, so they keep having used the code. The
             * reasoning is written out in full on OrderStatus::RELEASES_COUPON.
             */
            app(\App\Services\Orders\OrderStatus::class)->moveTo(
                $order,
                'refunded',
                by: $by ?: 'system',
                reason: 'Everything captured on this order has been refunded.',
            );
        }

        return RefundOutcome::applied($refund, $result);
    }

    /**
     * How long two identical keyless refunds are assumed to be one double
     * click rather than two intentions.
     *
     * Only ever consulted when the caller supplied NO idempotency key. A
     * caller that brings its own key has stated its intent and gets exactly
     * what it asked for.
     */
    public const KEYLESS_WINDOW_SECONDS = 60;

    /**
     * An identical refund made moments ago, or null.
     *
     * The derived key below covers the CONCURRENT double click — two requests
     * in flight together, neither settled, both deriving the same key, one
     * rejected by the unique index. It does not cover the SEQUENTIAL one,
     * where the first click completes and the second arrives 300ms later: the
     * first has settled by then, so the derived key is different and the
     * second refund goes through. Which is real money, twice.
     *
     * So a keyless caller also gets a short recency window. Same order, same
     * amount, same reason, inside a minute: treated as the same refund and
     * answered `duplicate`. Deliberately narrow, and deliberately not applied
     * to a caller with its own key — the admin screen sends one per form
     * render, and a merchant who genuinely wants to refund AED 50 twice in a
     * minute can, because the second render brings a new key.
     */
    private function recentTwin(Order $order, int $amountFils, ?string $reason): ?Refund
    {
        $normalised = $reason !== null && trim($reason) !== '' ? trim($reason) : null;

        return Refund::query()
            ->where('order_id', $order->getKey())
            ->whereIn('status', self::COUNTED)
            ->where('amount', $amountFils)
            ->where(fn ($q) => $normalised === null
                ? $q->whereNull('reason')
                : $q->where('reason', $normalised))
            ->where('created_at', '>=', now()->subSeconds(self::KEYLESS_WINDOW_SECONDS))
            ->latest('id')
            ->first();
    }

    /**
     * A key for a caller that did not bring one.
     *
     * The count of settled refunds is in the hash on purpose: two clicks in
     * flight at the same time collide, because neither has settled and both
     * derive the same key — and the unique index, not a check, is what rejects
     * the second. Once one HAS settled the count changes and the key with it,
     * which is what recentTwin() above exists to catch instead.
     */
    private function derivedKey(Order $order, int $amountFils, ?string $reason): string
    {
        $settled = Refund::query()
            ->where('order_id', $order->getKey())
            ->whereIn('status', self::COUNTED)
            ->count();

        return 'auto:' . hash('sha256', implode('|', [
            (string) $order->getKey(),
            (string) $amountFils,
            (string) ($reason ?? ''),
            (string) $settled,
        ]));
    }
}
