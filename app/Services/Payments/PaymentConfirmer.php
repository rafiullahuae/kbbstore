<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentEvent;
use Illuminate\Support\Facades\DB;

/**
 * The one place an order becomes paid.
 *
 * Every gateway routes through here, so the three rules that matter are
 * written once instead of three times with one of them subtly different:
 *
 *  1. **The amount is checked.** The order's own `total` is the truth; it was
 *     computed server-side from the cart when the order was placed and has
 *     not been near the browser since. If the provider reports a different
 *     figure the order is NOT marked paid — someone either tampered with a
 *     hosted session or the basket changed underneath it, and both need a
 *     human.
 *  2. **It applies at most once.** Same idea as `pixels_fired_at`: a single
 *     conditional UPDATE against a null timestamp column. `paid_at` was
 *     already in the schema for this. A second delivery of the same webhook
 *     matches zero rows and reports "already applied" rather than adding a
 *     second payment row or re-running anything.
 *  3. **Nothing sensitive is stored or logged.** Webhook bodies carry buyer
 *     names, emails, phone numbers and addresses. `payment_events.payload`
 *     gets a summary built from named fields, never the raw body.
 */
class PaymentConfirmer
{
    /**
     * Statuses on which a confirmation must NOT be applied.
     *
     * Every one of these has already handed back what the order was holding:
     * OrderStatus::RELEASES_COUPON gives the coupon use back on all three, and
     * OrderTransitionStock::RETURNS_STOCK puts the units back on the shelf for
     * `cancelled` and `failed`. Those units have since been sold to somebody
     * else.
     *
     * Until this existed a verified callback arriving afterwards moved the
     * order to `processing` and set `paid_at`, and NOTHING re-took either. The
     * warehouse got a live, paid order for stock the shop no longer had, the
     * coupon had been handed back, and the only trace was a status note. It is
     * reachable on all three remote gateways: a shopper who abandons the
     * redirect and an order the merchant then cancels, followed by the
     * provider's own retry of the delivery; or, because webhook delivery is
     * not ordered, an `expired` notice overtaking the `completed` one that
     * `fail()` turned into this very status.
     *
     * The money is real, so it is not thrown away: the attempt is written to
     * `payments`/`payment_events` and to an order note naming the amount and
     * the provider's reference, which is what the merchant needs in order to
     * give it back at the provider's end. What does not happen is the order
     * silently coming back to life.
     */
    private const VOID = ['cancelled', 'refunded', 'failed'];

    /**
     * Statuses a confirmation records against but must not move.
     *
     * `moveTo` was always handed the literal 'processing'. On an order that
     * had already shipped that is a DOWNGRADE — an order out with the courier
     * reverting to "processing" the moment its (perfectly valid, merely late)
     * payment callback lands. The payment still has to be recorded; the status
     * is simply already further along than anything this class should set.
     */
    private const HOLDS_PLACE = ['shipped', 'completed'];

    /**
     * Confirm a payment.
     *
     * @param  int    $amountFils  what the PROVIDER says was paid, in fils
     * @param  array  $summary     safe, already-filtered fields for the audit row
     */
    public function confirm(
        Order $order,
        string $provider,
        string $providerRef,
        int $amountFils,
        string $currency,
        array $summary = [],
    ): WebhookOutcome {
        $expected = (int) $order->total;

        // Currency first: a 100 SAR payment against a 100 AED order matches on
        // the number and is not the same money.
        if (strtoupper(trim($currency)) !== strtoupper((string) ($order->currency ?: 'AED'))) {
            $this->record($order, $provider, $providerRef, $amountFils, $currency, 'currency_mismatch', $summary);

            return WebhookOutcome::refused('currency does not match the order');
        }

        if ($amountFils !== $expected) {
            $this->record($order, $provider, $providerRef, $amountFils, $currency, 'amount_mismatch', $summary);

            return WebhookOutcome::refused('amount does not match the order');
        }

        /*
         * IS THERE STILL AN ORDER TO PAY FOR?
         *
         * Checked after the amount and the currency deliberately: those two
         * say the callback is about a different sum of money and nothing here
         * is true of it. This one says the money is right and the order is
         * gone, which is a different problem with a different answer — a human
         * has to give it back.
         *
         * 422 rather than 503, so the provider stops retrying. There is
         * nothing a further delivery could change, and a provider retrying a
         * cancelled order for a day would write the note below once per
         * attempt if it could get that far.
         */
        $status = (string) $order->status;

        if ($order->trashed() || in_array($status, self::VOID, true)) {
            $this->recordLate($order, $provider, $providerRef, $amountFils, $currency, $summary);

            return WebhookOutcome::refused('order is no longer live');
        }

        /*
         * Where the order should end up. Its own status when that is already
         * past `processing`, so a late callback records the payment without
         * winding a shipped order backwards; `processing` otherwise, which is
         * what every caller has always got.
         */
        $target = in_array($status, self::HOLDS_PLACE, true) ? $status : 'processing';

        /*
         * The guard and the write are still one indivisible act, and it is
         * still true that a second delivery of the same webhook applies
         * nothing. What changed is where the claim is taken: the status moves
         * through App\Services\Orders\OrderStatus, which re-reads this order
         * under SELECT ... FOR UPDATE and checks `$only` against the LOCKED
         * row. A second delivery blocks on that lock until the first commits,
         * then reads the `paid_at` the first wrote and is refused — which is
         * the same outcome the conditional UPDATE gave, reached by a stronger
         * route, because the lock also holds while the payment row and the note
         * below are written.
         *
         * `paid_at`, the reference and the method ride along in the same save
         * rather than in a second statement, so an order can never be marked
         * paid without recording what paid it.
         */
        $applied = DB::transaction(function () use ($order, $provider, $providerRef, $amountFils, $currency, $summary, $status, $target) {
            $moved = app(\App\Services\Orders\OrderStatus::class)->moveTo(
                $order,
                $target,
                by: 'system',
                reason: sprintf('Payment confirmed via %s.', $provider),
                also: [
                    'paid_at' => now(),
                    'transaction_id' => $providerRef,
                    'payment_method' => $provider,
                ],
                /*
                 * The status is a precondition as well as `paid_at`, and that
                 * is what closes the window between the read above and this
                 * lock. Without it an order cancelled in those microseconds
                 * would still be resurrected — the check would simply have
                 * been made against a row that no longer said what it says
                 * now. `$only` is tested against the LOCKED row, so pinning
                 * the status we decided on means a decision taken against a
                 * stale read cannot be acted on.
                 */
                only: ['paid_at' => null, 'status' => $status],
            );

            if ($moved === null) {
                return false;
            }

            $this->record($order, $provider, $providerRef, $amountFils, $currency, 'paid', $summary);

            $order->notes()->create([
                'author' => 'system',
                'is_customer_note' => false,
                'content' => sprintf(
                    'Payment confirmed via %s. Reference %s, %s %s.',
                    $provider,
                    $providerRef,
                    \App\Support\Money::amount($amountFils, 2),
                    strtoupper($currency),
                ),
            ]);

            return true;
        });

        $order->refresh();

        if ($applied) {
            return WebhookOutcome::applied('payment applied');
        }

        /*
         * The claim was not taken, and there are now two reasons for that.
         *
         * `paid_at` set means a second delivery of a webhook that has already
         * been applied — the ordinary replay, a 200, nothing to do.
         *
         * Otherwise the status moved between the read above and the lock, so
         * this delivery was decided against a row that no longer exists in
         * that shape. Nothing was written. 503 asks the provider to deliver it
         * again, and the next attempt reads the current status and reaches the
         * right answer — which may well be the refusal above. Reporting this
         * as "already applied" would end the retries on an order that is NOT
         * paid, which is the one outcome a payment system may not produce.
         */
        return $order->paid_at !== null
            ? WebhookOutcome::ignored('already applied')
            : WebhookOutcome::failed('order changed while the callback was being applied');
    }

    /**
     * Record a terminal failure reported by the provider — declined, expired,
     * cancelled. Never touches `paid_at`, and never downgrades an order that
     * has already been paid: a late "expired" notice arriving after a
     * successful capture must not cancel a real sale.
     */
    public function fail(
        Order $order,
        string $provider,
        string $providerRef,
        string $reason,
        array $summary = [],
    ): WebhookOutcome {
        if ($order->paid_at !== null) {
            return WebhookOutcome::ignored('order is already paid; failure notice ignored');
        }

        DB::transaction(function () use ($order, $provider, $providerRef, $reason, $summary) {
            /*
             * Through the funnel, which does three things this could not.
             *
             * The `$only` precondition is the same "never downgrade a paid
             * order" rule the whereNull() clause expressed, now checked against
             * the locked row rather than as part of a blind update — so a
             * capture that commits a microsecond before this notice arrives is
             * still seen.
             *
             * And a `failed` order hands its coupon use back. That is not new
             * policy: Store\CheckoutController has released the use since the
             * day a gateway could refuse a payment synchronously. A decline
             * that arrives by webhook instead of in the response means exactly
             * the same thing to the shopper's code, and until now got a
             * different answer purely because of which door it came through.
             */
            app(\App\Services\Orders\OrderStatus::class)->moveTo(
                $order,
                'failed',
                by: 'system',
                reason: sprintf('%s reported the payment as %s.', $provider, $reason),
                only: ['paid_at' => null],
            );

            $this->record($order, $provider, $providerRef, null, null, $reason, $summary);
        });

        /*
         * The units go back on the shelf too, and the funnel above is what does
         * it — the call that stood here has moved inside OrderStatus with the
         * other four copies of it. It now runs in the same transaction as the
         * status write rather than after it, which is the stronger position of
         * the two: a release that cannot be rolled back by the write it belongs
         * to is a release that can outlive it.
         */

        $order->refresh();

        return WebhookOutcome::applied('failure recorded');
    }

    /**
     * One `payments` row per (provider, reference) — the migration adds the
     * unique index that makes that true — plus an event row for the audit
     * trail. `$summary` has already been reduced to named, non-personal
     * fields by the caller; nothing raw reaches this method.
     */
    private function record(
        Order $order,
        string $provider,
        string $providerRef,
        ?int $amountFils,
        ?string $currency,
        string $status,
        array $summary,
    ): void {
        $payment = Payment::updateOrCreate(
            ['provider' => $provider, 'provider_ref' => $providerRef],
            [
                'order_id' => $order->getKey(),
                'amount' => $amountFils,
                'currency' => $currency ? strtoupper($currency) : $order->currency,
                'status' => $status,
            ],
        );

        PaymentEvent::create([
            'payment_id' => $payment->getKey(),
            /*
             * The two columns the Phase 11 idempotency migration added to this
             * table for exactly this, and which this method was the only
             * writer never to fill in. PaymentLedger has always set them;
             * confirmation events came out with both NULL, so the audit rows
             * for the one thing that matters most — an order being marked
             * paid — were the only ones that could not be traced back to a
             * provider transaction without joining through `payments`. That
             * join is precisely what is unavailable when a webhook arrives for
             * an order we cannot match, which is the case the columns were
             * added for.
             */
            'provider' => $provider,
            'external_id' => $providerRef !== '' ? $providerRef : null,
            'type' => $status,
            'payload' => $summary,
            'received_at' => now(),
        ]);
    }

    /**
     * Money arrived for an order that is no longer live.
     *
     * Recorded rather than applied, and said out loud on the order. The
     * merchant is the only one who can finish this: the funds are sitting at
     * the provider against an order whose stock went back on the shelf, and
     * somebody has to refund them there. A silent 422 would leave that money
     * discoverable only by reconciling the provider's dashboard against this
     * database by hand.
     *
     * The note is written once. `payments` is keyed (provider, provider_ref)
     * by a unique index, so a provider that retries in spite of the 422 finds
     * its own row already carrying this status and adds no second note — the
     * test for it is in PaymentInvariantsTest.
     */
    private function recordLate(
        Order $order,
        string $provider,
        string $providerRef,
        int $amountFils,
        string $currency,
        array $summary,
    ): void {
        $seen = Payment::query()
            ->where('provider', $provider)
            ->where('provider_ref', $providerRef)
            ->where('status', 'late_confirmation')
            ->exists();

        $this->record($order, $provider, $providerRef, $amountFils, $currency, 'late_confirmation', $summary);

        if ($seen) {
            return;
        }

        $order->notes()->create([
            'author' => 'system',
            'is_customer_note' => false,
            'content' => sprintf(
                'ACTION NEEDED — %s confirmed a payment of %s %s (reference %s) for this order, '
                . 'but the order is %s and its stock and coupon have already been released. '
                . 'The order has NOT been marked paid. Refund this payment in the %s dashboard.',
                $provider,
                \App\Support\Money::amount($amountFils, 2),
                strtoupper($currency),
                $providerRef,
                $order->trashed() ? 'in the trash' : (string) $order->status,
                $provider,
            ),
        ]);
    }
}
