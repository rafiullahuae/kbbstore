<?php
/* Seed the Lane PC preview: an owner, a configured Tamara gateway, and TWO
   orders that differ only in `captured_total` — the figure this lane changes.

   Both are the same shop event: a 300.00 order that Tamara reports as
   `partially_captured` at 120.00.

     PC-BEFORE   captured_total = 30000  — what PaymentCapturer wrote before
                 this lane: the whole order total, so 300.00 reads as refundable.
     PC-AFTER    captured_total = 12000  — what it writes now: the provider's
                 own figure, so only 120.00 is refundable.

   Integer fils throughout; no float is constructed here. */
\App\Models\AdminUser::create([
    'name' => 'Preview Owner', 'email' => 'owner@preview.test',
    'password' => 'preview-secret-1', 'role' => 'owner',
]);

$row = \App\Models\PaymentProvider::create([
    'id' => 'tamara', 'title' => 'Tamara', 'enabled' => true, 'mode' => 'test', 'position' => 2,
]);
$row->config = [
    'api_token' => 'preview-tamara-api-token',
    'notification_token' => 'preview-tamara-notification-token',
    'webhook_secret' => 'whsec-tamara-preview-0123456789ab',
    'capture_days' => '180',
];
$row->save();

$make = function (string $number, int $capturedFils, string $note) {
    $order = \App\Models\Order::create([
        'order_number' => $number,
        'email' => 'buyer@preview.test', 'phone' => '+971500000000',
        'status' => 'processing', 'currency' => 'AED',
        'subtotal' => 30000, 'discount_total' => 0, 'shipping_total' => 0,
        'tax_total' => 0, 'fee_total' => 0, 'total' => 30000,
        'payment_method' => 'tamara', 'paid_at' => now()->subDays(4),
        'transaction_id' => 'tam_preview_' . strtolower($number),
        'captured_at' => now()->subDays(1),
        'captured_total' => $capturedFils,
        'capture_ref' => 'cap_preview_' . strtolower($number),
    ]);

    $order->items()->create([
        'name' => 'Cellmazing Fit Serum', 'sku' => 'SKU-PREVIEW-1',
        'quantity' => 1, 'unit_price' => 30000, 'subtotal' => 30000, 'total' => 30000,
    ]);

    $order->notes()->create(['author' => 'Admin', 'is_customer_note' => false, 'content' => $note]);

    echo $number, ' id=', $order->getKey(), "\n";
};

$make('PC-BEFORE', 30000, 'Captured 300.00 AED via tamara. Capture reference cap_preview_pc-before.');
$make('PC-AFTER', 12000, 'Captured 120.00 AED via tamara. Capture reference cap_preview_pc-after.'
    . ' PARTIAL: tamara reports 120.00 taken against an order of 300.00, so only the captured amount is refundable.');
