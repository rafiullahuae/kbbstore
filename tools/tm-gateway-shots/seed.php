<?php
/* Preview seed — scratchpad tooling, never part of a package.

   The BEFORE state is the shipped state: Tamara and Tabby configured (the owner
   has pasted his keys) and NEITHER webhook registered, which is exactly what
   this shop looks like today because nothing in the console could register one. */
use App\Models\{AdminUser, Order, PaymentProvider};

AdminUser::updateOrCreate(
    ['email' => 'owner@tm.test'],
    ['name' => 'TM Owner', 'password' => bcrypt('tm-preview-secret'), 'role' => 'owner'],
);

PaymentProvider::query()->delete();

PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 3]);
PaymentProvider::create(['id' => 'stripe', 'title' => 'Credit / Debit Card', 'enabled' => false, 'mode' => 'test', 'position' => 0]);

$tabby = PaymentProvider::create([
    'id' => 'tabby', 'title' => 'Tabby', 'enabled' => true, 'mode' => 'test', 'position' => 1,
]);

$tabby->config = [
    'public_key' => 'pk_test_11111111-2222-3333-4444-555555555555',
    'secret_key' => 'sk_test_11111111-2222-3333-4444-555555555555',
    'merchant_code' => 'AE',
    'webhook_secret' => 'whsec-tabby-preview-0123456789ab',
];
$tabby->save();

$row = PaymentProvider::create([
    'id' => 'tamara', 'title' => 'Pay later with Tamara',
    'enabled' => true, 'mode' => 'test', 'position' => 2,
]);

/* No webhook_id, no min_limit, no max_limit. That is the live shop's state and
   the whole finding: the keys are in, the gateway takes money, and nothing has
   ever registered the endpoint that carries a decline. */
$row->config = [
    'api_token' => 'preview-tamara-api-token',
    'notification_token' => 'preview-tamara-notification-token',
    'webhook_secret' => 'whsec-tamara-preview-0123456789ab',
    'public_key' => 'pk_preview_tamara',
    'capture_days' => '180',
];
$row->save();

/* ONE ORDER THE SWEEP WILL FIND: `pending`, paid via Tamara, no paid_at, placed
   three hours ago so it is past the sweep's lower bound. This is what an
   approval whose notification never arrived looks like in the database — stock
   claimed, coupon spent, and the buyer holding a live payment plan. */
Order::where('order_number', 'TM-PREVIEW-MISSED')->forceDelete();

Order::create([
    'order_number' => 'TM-PREVIEW-MISSED',
    'email' => 'missed@tm.test',
    'phone' => '+971500000000',
    'status' => 'pending',
    'currency' => 'AED',
    'subtotal' => 25000,
    'discount_total' => 0,
    'shipping_total' => 0,
    'tax_total' => 0,
    'fee_total' => 0,
    'total' => 25000,
    'payment_method' => 'tamara',
    'transaction_id' => 'tam_preview_missed',
    'created_at' => now()->subHours(3),
]);

echo "seeded admin owner@tm.test / tm-preview-secret\n";
