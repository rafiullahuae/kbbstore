<?php
/*
 * Lane RJ — the fixture every "before" email render and the admin screenshots
 * are taken against. PREVIEW DATABASE ONLY (tools/rj-preview.sh points
 * DB_DATABASE at a throwaway SQLite file); nothing here touches a real shop.
 *
 * The order the owner asked to see: three products, AED prices, a coupon,
 * Tabby, a UAE address. Money is integer fils, as the columns hold it.
 */

use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\Order;
use App\Services\Mail\MailSettings;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;

AdminUser::updateOrCreate(['email' => 'owner@preview.test'], [
    'name' => 'Rafi', 'password' => 'preview-secret-1', 'role' => 'owner',
]);

$settings = app(SettingsService::class);
$settings->set('store_name', 'K Beauty Bliss');
$settings->set('brand_whatsapp', '+971 58 505 2611');
$settings->set('social_instagram', 'kbeauty.bliss');

app(MailSettings::class)->save([
    'mail_from_address' => 'hello@extrabeauty.ae',
    'mail_from_name' => 'K Beauty Bliss',
    'mail_reply_to' => 'care@extrabeauty.ae',
    'mail_signature' => 'With love,|the K Beauty Bliss team',
    'mail_merchant_address' => 'orders@extrabeauty.ae',
]);

\App\Models\Setting::flushMap();
SettingsService::forgetMemo();

DB::table('coupons')->updateOrInsert(['code' => 'GLOW10'], [
    'type' => 'percent', 'amount' => 1000, 'description' => 'Preview coupon: 10% off',
    'created_at' => now(), 'updated_at' => now(),
]);

$address = [
    'first_name' => 'Aisha', 'last_name' => 'Khan',
    'line1' => 'Apartment 1204, Marina Heights Tower', 'line2' => 'Al Marsa Street, Dubai Marina',
    'city' => 'Dubai', 'state' => 'Dubai', 'country' => 'AE', 'phone' => '+971 50 123 4567',
];

$aisha = Customer::updateOrCreate(['email' => 'aisha.khan@example.com'], [
    'name' => 'Aisha Khan', 'first_name' => 'Aisha', 'last_name' => 'Khan', 'phone' => '+971 50 123 4567',
]);

/* 3 lines: 2 × 89.00 + 1 × 115.00 + 1 × 62.50 = 355.50; GLOW10 takes 35.55. */
$order = Order::updateOrCreate(['order_number' => 'KBB-10427'], [
    'customer_id' => $aisha->id,
    'email' => 'aisha.khan@example.com',
    'phone' => '+971 50 123 4567',
    'status' => 'processing',
    'currency' => 'AED',
    'billing_address' => $address,
    'shipping_address' => $address,
    'subtotal' => 35550,
    'discount_total' => 3555,
    'coupon_code' => 'GLOW10',
    'shipping_total' => 0,
    'fee_total' => 0,
    'tax_total' => 0,
    'total' => 35550 - 3555,
    'shipping_method' => 'Free UAE delivery (1–3 working days)',
    'payment_method' => 'tabby',
    'payment_method_title' => 'Tabby — pay in 4, interest-free',
    'transaction_id' => 'tabby_pay_PREVIEW',
    'paid_at' => now()->subHours(2),
    'created_at' => '2026-10-02 09:41:00',
]);

$order->items()->delete();
foreach ([
    ['Heartleaf 77% Soothing Toner 250ml', 'Anua', 'AN-HL-250', null, 2, 8900],
    ['Glow Deep Serum Rice + Alpha-Arbutin 30ml', 'Beauty of Joseon', 'BOJ-GD-30', null, 1, 11500],
    ['Low pH Good Morning Gel Cleanser', 'COSRX', 'CX-LPH-150', ['150ml'], 1, 6250],
] as [$name, $brand, $sku, $variant, $qty, $unit]) {
    $order->items()->create([
        'name' => $name, 'brand' => $brand, 'sku' => $sku, 'variant_attributes' => $variant,
        'quantity' => $qty, 'unit_price' => $unit, 'subtotal' => $qty * $unit, 'total' => $qty * $unit,
    ]);
}

/* A handful of customers so the Customers screen and any segment count are not
   empty. Guest buyers stay out of `customers`, as on the live shop. */
foreach ([
    ['Mariam Al Hashimi', 'mariam@example.com'], ['Priya Nair', 'priya@example.com'],
    ['Sara Haddad', 'sara@example.com'], ['Noor Aziz', 'noor@example.com'],
] as [$name, $email]) {
    Customer::updateOrCreate(['email' => $email], ['name' => $name]);
}

DB::table('subscribers')->updateOrInsert(['email' => 'fan@example.com'], [
    'source' => 'homepage', 'status' => 'subscribed', 'confirmed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
]);

echo "rj-seed: order {$order->order_number} total {$order->total} fils, items " . $order->items()->count() . PHP_EOL;
