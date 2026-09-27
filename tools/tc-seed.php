<?php
/* Seed the Lane TC preview: an owner, a configured Tamara gateway so the card
   draws its Settings column, and one shipped-but-uncaptured Tamara order so
   `payments:tamara-capture --dry` has something real to list. */
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
    // NOTE: `auto_capture` is deliberately ABSENT, which is the state a real
    // install is in the moment the package is applied. The screenshot has to
    // show the switch as the owner first meets it: Off.
];
$row->save();

$order = \App\Models\Order::create([
    'order_number' => 'TC-PREVIEW-1',
    'email' => 'buyer@preview.test', 'phone' => '+971500000000',
    'status' => 'shipped', 'currency' => 'AED',
    'subtotal' => 24900, 'discount_total' => 0, 'shipping_total' => 0,
    'tax_total' => 0, 'fee_total' => 0, 'total' => 24900,
    'payment_method' => 'tamara', 'paid_at' => now()->subDays(9),
    'transaction_id' => 'tam_preview_1',
]);
$order->items()->create([
    'name' => 'Cellmazing Fit Serum', 'sku' => 'SKU-PREVIEW-1',
    'quantity' => 1, 'unit_price' => 24900, 'subtotal' => 24900, 'total' => 24900,
]);
\App\Models\Order::query()->whereKey($order->getKey())->update(['updated_at' => now()->subHours(3)]);

echo "seeded tamara + order ", $order->order_number, "\n";
