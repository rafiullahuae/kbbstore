<?php
/*
 * Seed the Lane WAL preview: an owner, a shipping zone that quotes a real
 * delivery charge, three products, and the card gateway keyed and enabled with
 * BOTH wallets switched on.
 *
 * The keys are obvious fakes. Nothing in this preview reaches Stripe — egress
 * is blocked in this sandbox, which is itself one of the states the screenshots
 * are taken in, and the deliberate one: a checkout whose js.stripe.com never
 * arrives must remove the wallet row rather than leave a button that cannot
 * work.
 */
\App\Models\AdminUser::create([
    'name' => 'Preview Owner', 'email' => 'owner@preview.test',
    'password' => 'preview-secret-1', 'role' => 'owner',
]);

$zone = \App\Models\ShippingZone::create(['name' => 'UAE', 'position' => 0]);
\App\Models\ShippingZoneLocation::create([
    'shipping_zone_id' => $zone->id, 'type' => 'country', 'code' => 'AE',
]);
\App\Models\ShippingMethod::create([
    'shipping_zone_id' => $zone->id, 'type' => 'flat_rate',
    'title' => 'Standard delivery', 'cost' => 2000, 'enabled' => true, 'position' => 0,
]);

$rows = [
    ['Cellmazing Fit Serum', 'cellmazing-fit-serum', 299],
    ['Ceramide Daily Moisturiser', 'ceramide-daily-moisturiser', 129],
    ['Hyaluronic Acid Watery Sun Gel', 'hyaluronic-acid-watery-sun-gel', 133],
];

foreach ($rows as [$name, $slug, $price]) {
    \App\Models\Product::updateOrCreate(['slug' => $slug], [
        'name' => $name, 'price' => $price,
        'status' => 'publish', 'stock_status' => 'instock', 'type' => 'simple',
    ]);
}

/*
 * The card gateway, keyed, enabled, with both wallets ON.
 *
 * `wal-shots.cjs` switches them back OFF through the same admin endpoint the
 * owner uses, so the "before" and "after" in the pictures are two states of one
 * screen rather than two different fixtures.
 */
$row = \App\Models\PaymentProvider::firstOrNew(['id' => 'stripe']);
$row->fill(['title' => 'Credit or debit card', 'enabled' => true, 'mode' => 'test', 'position' => 0]);
$row->config = [
    'publishable_key' => 'pk_test_lane_wal_preview',
    'secret_key' => 'sk_test_lane_wal_preview',
    'webhook_signing_secret' => 'whsec_lane_wal_preview',
    'webhook_secret' => 'whsec-url-lane-wal-preview',
    'wallet_apple_pay' => '1',
    'wallet_google_pay' => '1',
];
$row->save();

// Cash on delivery too, so the payment list under the express row is not a
// single option — the row has to sit above a real list to be judged.
$cod = \App\Models\PaymentProvider::firstOrNew(['id' => 'cod']);
$cod->fill(['title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 1]);
$cod->save();

echo 'seeded ', \App\Models\Product::count(), " products, wallets on\n";
