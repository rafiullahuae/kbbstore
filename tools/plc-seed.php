<?php
/* Seed the Lane PLC preview: an owner, a product, a UAE shipping zone with a
   flat rate, and two payment methods — Cash on delivery (the `placed` journey)
   and Tamara (the `redirect` journey), so the checkout can be photographed
   with both a method that finishes here and one that leaves the shop.

   TAMARA'S CREDENTIALS ARE FAKE AND ARE NEVER USED. The preview never reaches
   Tamara: tools/plc-shots.cjs intercepts POST /checkout/place in the browser
   and answers with each of the shapes CheckoutController::place() really
   returns, so every branch — placed, redirect, a 422 refusal, a 419, a dropped
   network — can be photographed deterministically. The keys exist only so that
   configured() is true and the radio button is drawn with its real title. */
\App\Models\AdminUser::create([
    'name' => 'Preview Owner', 'email' => 'owner@preview.test',
    'password' => 'preview-secret-1', 'role' => 'owner',
]);

$product = \App\Models\Product::updateOrCreate(['slug' => 'plc-glass-skin-serum'], [
    'name' => 'Glass Skin Refining Serum',
    'price' => 189,
    'status' => 'publish', 'stock_status' => 'instock', 'type' => 'simple',
    'is_visible' => true,
]);

$zone = \App\Models\ShippingZone::create(['name' => 'UAE', 'position' => 0]);
\App\Models\ShippingZoneLocation::create(['shipping_zone_id' => $zone->id, 'type' => 'country', 'code' => 'AE']);
\App\Models\ShippingMethod::create([
    'shipping_zone_id' => $zone->id, 'type' => 'flat_rate', 'title' => 'Standard delivery',
    'cost' => 2000, 'enabled' => true, 'position' => 0,
]);

\App\Models\PaymentProvider::updateOrCreate(['id' => 'cod'], [
    'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'live', 'position' => 0,
]);
app(\App\Services\SettingsService::class)->set('cod_fee', 0);

\App\Models\PaymentProvider::updateOrCreate(['id' => 'tamara'], [
    'title' => 'Tamara', 'enabled' => true, 'mode' => 'live', 'position' => 1,
    'config' => ['api_token' => 'preview-not-a-real-token', 'notification_token' => 'preview-not-a-real-token'],
]);

/* One basket, and the cookie tools/plc-shots.cjs sets to reach it. */
$cart = \App\Models\Cart::create([
    'token' => 'plc-preview-cart', 'currency' => 'AED', 'status' => 'active',
    'shipping_country' => 'AE', 'last_activity_at' => now(),
]);
$cart->items()->create(['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 18900]);

/* The customer the two seeded Tamara orders belong to. mayView() has two doors
   — the browser that just placed the order, and a signed-in customer looking at
   their own — and this is the second, which is the one a screenshot harness can
   walk through. tools/plc-shots.cjs signs in as this customer. */
$customer = \App\Models\Customer::create([
    'name' => 'Aisha Khan', 'email' => 'buyer@preview.test', 'password' => 'preview-secret-1',
]);

/* Two orders for the return leg: one Tamara order the shop has confirmed, and
   one it has not heard about yet. */
foreach ([['PLCPAID01', 'processing', now()], ['PLCWAIT01', 'pending', null]] as [$number, $status, $paid]) {
    $order = \App\Models\Order::create([
        'order_number' => $number, 'customer_id' => $customer->id, 'email' => 'buyer@preview.test', 'status' => $status,
        'currency' => 'AED', 'subtotal' => 18900, 'discount_total' => 0, 'shipping_total' => 2000,
        'fee_total' => 0, 'gift_fee' => 0, 'tax_total' => 0, 'total' => 20900,
        'shipping_method' => 'Standard delivery', 'payment_method' => 'tamara',
        'payment_method_title' => 'Pay later with Tamara', 'paid_at' => $paid,
        'shipping_address' => [
            'first_name' => 'Aisha', 'last_name' => 'Khan', 'line1' => '12 Marina Walk',
            'city' => 'Dubai', 'state' => 'Dubai', 'country' => 'AE', 'phone' => '+971500000000',
        ],
    ]);
    $order->items()->create([
        'name' => $product->name, 'product_id' => $product->id, 'brand' => 'K-Beauty Bliss',
        'quantity' => 1, 'unit_price' => 18900, 'subtotal' => 18900, 'total' => 18900,
    ]);
}

echo "seeded\n";

/*
 * ARABIC, ON, WITH THIS LANE'S OWN STRINGS PUBLISHED — IN THE PREVIEW ONLY.
 *
 * The package ships all nineteen as DRAFTS, exactly like the 1,026 before them,
 * so a shopper on /ar sees the English until the owner presses Publish all.
 * That is the shipped behaviour and it is what ArabicInterfaceDraftsTest pins.
 *
 * A screenshot of the English overlay on /ar would prove nothing about the
 * Arabic, so the preview publishes these keys and nothing else: the pair of
 * /ar shots is what the owner gets the moment he approves them.
 */
\App\Models\Setting::query()->updateOrCreate(
    ['key' => \App\Support\Locale::SETTING_ENABLED],
    ['value' => '1', 'autoload' => true]
);

foreach (\App\Services\Translation\ArabicInterfaceDrafts::all() as $key => $arabic) {
    if (! str_contains($key, '.placing_') && ! str_contains($key, '.return_not_completed')
        && ! str_contains($key, 'order_received.placed_') && ! str_contains($key, 'order_received.confirming_')) {
        continue;
    }

    \App\Services\Translation\TranslationStore::put(
        'ar', \App\Models\Translation::GROUP_UI, 0, $key, $arabic,
        \App\Models\Translation::STATUS_PUBLISHED, \App\Models\Translation::SOURCE_MANUAL,
    );
}

/*
 * AND THE MIRRORED LAYOUT ON.
 *
 * Locale::direction() returns 'ltr' for Arabic until Locale::SETTING_RTL is
 * switched on — two separate switches, deliberately, and this shop ships with
 * the second one off. The overlay is the same either way, because it is centred
 * and carries no [dir] selector at all; the point of turning it on for the
 * preview is to PROVE that rather than assert it.
 */
\App\Models\Setting::query()->updateOrCreate(
    ['key' => \App\Support\Locale::SETTING_RTL],
    ['value' => '1', 'autoload' => true]
);

\App\Models\Setting::flushMap();
\App\Services\SettingsService::forgetMemo();
\App\Services\Translation\TranslationStore::flush();

echo "arabic published for the overlay keys\n";
