<?php
/* Preview seed — scratchpad only, never part of a package. */
use App\Models\{AdminUser, PaymentProvider};

AdminUser::updateOrCreate(
    ['email' => 'owner@pg1.test'],
    ['name' => 'PG1 Owner', 'password' => bcrypt('pg1-preview-secret'), 'role' => 'owner'],
);

PaymentProvider::query()->delete();

PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 3]);
PaymentProvider::create(['id' => 'stripe', 'title' => 'Credit / Debit Card', 'enabled' => false, 'mode' => 'test', 'position' => 0]);
PaymentProvider::create(['id' => 'tabby', 'title' => 'Tabby', 'enabled' => false, 'mode' => 'test', 'position' => 1]);

$row = PaymentProvider::create([
    'id' => 'tamara', 'title' => 'Pay later with Tamara',
    'enabled' => true, 'mode' => 'test', 'position' => 2,
]);

// The BEFORE state: the five settings that already existed, filled; the five
// new ones EMPTY, which is how the package ships them (rule 1).
$row->config = [
    'api_token' => 'preview-tamara-api-token',
    'notification_token' => 'preview-tamara-notification-token',
    'webhook_secret' => 'whsec-tamara-preview-0123456789ab',
    'public_key' => 'pk_preview_tamara',
    'capture_days' => '180',
];
$row->save();

echo "seeded admin owner@pg1.test / pg1-preview-secret\n";
