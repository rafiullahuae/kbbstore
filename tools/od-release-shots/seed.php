<?php
/* Preview seed — scratchpad tooling, never part of a package.
   `tools/` is not on UpdateGuard::ALLOWED_PREFIXES and BuildPackage::NEVER_SHIP
   blocks it twice over, which is the point: the fake Tamara API in
   preview-index.php must never run anywhere near a real shop.

   THE BEFORE STATE IS THE SHIPPED STATE. Three orders, each one a case the
   release panel has to answer differently, and none of them touched by anything
   this shop can currently click. */
use App\Models\{AdminUser, Order, PaymentProvider};

AdminUser::updateOrCreate(
    ['email' => 'owner@od.test'],
    ['name' => 'OD Owner', 'password' => bcrypt('od-preview-secret'), 'role' => 'owner'],
);

PaymentProvider::query()->delete();

PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 3]);

$row = PaymentProvider::create([
    'id' => 'tamara', 'title' => 'Pay later with Tamara',
    'enabled' => true, 'mode' => 'test', 'position' => 2,
]);

$row->config = [
    'api_token' => 'preview-tamara-api-token',
    'notification_token' => 'preview-tamara-notification-token',
    'webhook_secret' => 'whsec-tamara-preview-0123456789ab',
    'capture_days' => '180',
];
$row->save();

$make = function (string $number, array $attributes, int $totalFils = 25000): void {
    Order::withTrashed()->where('order_number', $number)->forceDelete();

    $order = Order::create(array_merge([
        'order_number' => $number,
        'email' => 'buyer@od.test',
        'phone' => '+971500000000',
        'currency' => 'AED',
        'subtotal' => $totalFils,
        'discount_total' => 0,
        'shipping_total' => 0,
        'tax_total' => 0,
        'fee_total' => 0,
        'total' => $totalFils,
        'billing_address' => ['first_name' => 'Aisha', 'last_name' => 'Al Marri', 'city' => 'Dubai', 'country' => 'AE'],
        'created_at' => now()->subDays(2),
    ], $attributes));

    $order->items()->create([
        'name' => 'Hydrating Serum No. 360',
        'sku' => 'OD-SKU-1',
        'quantity' => 1,
        'unit_price' => $totalFils,
        'subtotal' => $totalFils,
        'total' => $totalFils,
    ]);
};

/* 1. THE CASE THIS LANE EXISTS FOR. Cancelled, authorised by Tamara, never
      captured -- so Tamara is still holding AED 250.00 of this buyer's credit
      against an order this shop has abandoned, and until now nothing in the
      console could give it back. */
$make('OD-RELEASE-1', [
    'status' => 'cancelled',
    'payment_method' => 'tamara',
    'transaction_id' => 'tam_od_release',
    'payment_method_title' => 'Pay later with Tamara',
    'paid_at' => now()->subDays(2),
]);

/* 2. THE SAME GATEWAY, STILL LIVE. The sale is on, so releasing is the most
      expensive mistake available here and the panel must refuse it and say why
      -- which is the question an operator actually asks of this screen. */
$make('OD-LIVE-1', [
    'status' => 'processing',
    'payment_method' => 'tamara',
    'transaction_id' => 'tam_od_live',
    'payment_method_title' => 'Pay later with Tamara',
    'paid_at' => now()->subDays(1),
], 41000);

/* 3. A PAYMENT METHOD THAT HOLDS NOTHING. Cash on delivery gets no panel at
      all: a box on every order saying "nothing is held here" is noise on the
      majority of this shop's orders. */
$make('OD-COD-1', [
    'status' => 'cancelled',
    'payment_method' => 'cod',
    'payment_method_title' => 'Cash on delivery',
], 12500);

echo "seeded admin owner@od.test / od-preview-secret\n";
