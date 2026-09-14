<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentEvent;

/**
 * Where a capture or refund attempt is written down — successful or not.
 *
 * PaymentConfirmer keeps its own private record() for confirmation, and that
 * method is not reused here on purpose: it does `updateOrCreate` keyed on
 * (provider, provider_ref) so a second webhook for the same payment updates
 * one row, which is exactly right for confirmation and exactly wrong for
 * settlement. Four partial refunds against one payment are four events, not
 * one row overwritten three times.
 *
 * The rule this class exists to enforce is the one that is easiest to skip
 * when a call fails: **a failed attempt is recorded too**. A refund that
 * errored and left no trace is money the merchant believes has gone back to
 * the customer and has not. Every path through PaymentCapturer and
 * PaymentRefunder lands here before it returns, including the ones that
 * return an error.
 *
 * What goes into `payload`: the gateway's SettlementResult summary, which the
 * gateway built from NAMED fields of the provider's response. Not the
 * response. Not the request. A Tabby capture response echoes the item list, a
 * Tamara one echoes the consumer block, and neither belongs in an audit table
 * that is dumped with every database backup.
 */
class PaymentLedger
{
    /**
     * Record one settlement attempt.
     *
     * @param  string  $type    capture | capture_failed | refund | refund_failed
     * @param  int     $amountFils  always positive; the type says the direction
     */
    public function record(
        Order $order,
        string $provider,
        string $type,
        int $amountFils,
        ?string $providerRef = null,
        array $summary = [],
    ): PaymentEvent {
        // One `payments` row per provider transaction, which is what the
        // unique (provider, provider_ref) index from the Phase 11 migration
        // already guarantees. A settlement with no provider reference — a COD
        // capture, a call that failed before it got an id — hangs off the
        // order's own number plus the event id, so it still has a row of its
        // own rather than colliding with the last failure.
        $ref = $providerRef !== null && trim($providerRef) !== ''
            ? trim($providerRef)
            : $type . ':' . $order->order_number . ':' . (string) \Illuminate\Support\Str::uuid();

        $payment = Payment::updateOrCreate(
            ['provider' => $provider, 'provider_ref' => $ref],
            [
                'order_id' => $order->getKey(),
                'amount' => $amountFils,
                'currency' => strtoupper((string) ($order->currency ?: 'AED')),
                'status' => $type,
            ],
        );

        return PaymentEvent::create([
            'payment_id' => $payment->getKey(),
            'provider' => $provider,
            'external_id' => $providerRef,
            'type' => $type,
            'payload' => $summary,
            'received_at' => now(),
        ]);
    }

    /** An internal note on the order, so the trail is visible without SQL. */
    public function note(Order $order, string $content, ?string $author = null): void
    {
        $order->notes()->create([
            'author' => $author !== null && trim($author) !== '' ? trim($author) : 'system',
            'is_customer_note' => false,
            'content' => $content,
        ]);
    }
}
