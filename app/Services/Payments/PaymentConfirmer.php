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

        // The guard and the write are one statement, so two deliveries racing
        // each other cannot both pass it. Whichever UPDATE runs second sees
        // paid_at already set and matches no rows.
        $applied = DB::transaction(function () use ($order, $provider, $providerRef, $amountFils, $currency, $summary) {
            $rows = Order::query()
                ->whereKey($order->getKey())
                ->whereNull('paid_at')
                ->update([
                    'paid_at' => now(),
                    'status' => 'processing',
                    'transaction_id' => $providerRef,
                    'payment_method' => $provider,
                    'updated_at' => now(),
                ]);

            if ($rows === 0) {
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

        $was = (string) $order->status;

        DB::transaction(function () use ($order, $provider, $providerRef, $reason, $summary) {
            Order::query()
                ->whereKey($order->getKey())
                ->whereNull('paid_at')
                ->update(['status' => 'failed', 'updated_at' => now()]);

            $this->record($order, $provider, $providerRef, null, null, $reason, $summary);
        });

        /*
         * The units go back on the shelf.
         *
         * A provider saying the payment failed means this order will never
         * ship, and it is holding stock that was claimed when it was written.
         * Same rule, same one place, as an operator cancelling it by hand — see
         * OrderTransitionStock. Deliberately AFTER the transaction above has
         * committed, so the release cannot be rolled back by it and leave the
         * ledger saying units were returned that were not.
         */
        app(\App\Services\Orders\OrderTransitionStock::class)
            ->applied((int) $order->getKey(), $was, 'failed');

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
