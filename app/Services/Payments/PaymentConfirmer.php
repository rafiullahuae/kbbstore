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
        $applied = DB::transaction(function () use ($order, $provider, $providerRef, $amountFils, $currency, $summary) {
            $moved = app(\App\Services\Orders\OrderStatus::class)->moveTo(
                $order,
                'processing',
                by: 'system',
                reason: sprintf('Payment confirmed via %s.', $provider),
                also: [
                    'paid_at' => now(),
                    'transaction_id' => $providerRef,
                    'payment_method' => $provider,
                ],
                only: ['paid_at' => null],
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

        return $applied
            ? WebhookOutcome::applied('payment applied')
            : WebhookOutcome::ignored('already applied');
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
            'type' => $status,
            'payload' => $summary,
            'received_at' => now(),
        ]);
    }
}
