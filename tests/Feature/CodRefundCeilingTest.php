<?php

/**
 * Cash on delivery that has not been collected is not refundable.
 *
 * THE DEFECT (found by Lane PU on the owner's imported orders, 2 October
 * 2026): an imported WooCommerce COD order in Processing carries `paid_at`
 * (WooCommerce stamps date_paid on Processing), so the order screen read
 * "AED 337 still refundable" beside a payment panel saying "Cash on delivery —
 * AED 337 to collect". A refund there would hand back cash the courier never
 * took. PaymentRefunder::capturedFils() now treats COD that is not Completed
 * and has no cash-received record as unpaid, the same rule as
 * App\Support\OrderPaymentPanel.
 *
 * MUTATION, RUN: make cashStillToCollect() return false and the first case is
 * red (33700 refundable on an uncollected COD order).
 */

use App\Models\Order;
use App\Services\Payments\PaymentRefunder;

function crcOrder(array $attributes): Order
{
    return Order::create(array_merge([
        'order_number' => 'CRC-'.uniqid(),
        'email' => 'buyer@example.com',
        'status' => 'processing',
        'currency' => 'AED',
        'subtotal' => 33700, 'discount_total' => 0, 'shipping_total' => 0, 'tax_total' => 0, 'fee_total' => 0,
        'total' => 33700,
        'payment_method' => 'cod',
        'paid_at' => now()->subDay(),
    ], $attributes));
}

it('offers nothing to refund on cash on delivery the courier has not collected', function () {
    $refunder = app(PaymentRefunder::class);

    expect($refunder->capturedFils(crcOrder(['status' => 'processing'])))->toBe(0)
        ->and($refunder->capturedFils(crcOrder(['status' => 'shipped'])))->toBe(0);
});

it('offers the total once the cash is collected', function () {
    $refunder = app(PaymentRefunder::class);

    // Completed: the courier has handed the cash over.
    expect($refunder->capturedFils(crcOrder(['status' => 'completed'])))->toBe(33700);

    // Or the owner pressed "Record cash received" (a capture on the order).
    expect($refunder->capturedFils(crcOrder([
        'status' => 'shipped', 'captured_at' => now(), 'captured_total' => 33700,
    ])))->toBe(33700);
});

it('leaves card payments as they were', function () {
    expect(app(PaymentRefunder::class)->capturedFils(crcOrder(['payment_method' => 'stripe', 'status' => 'processing'])))->toBe(33700);
});
