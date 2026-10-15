<?php
/* Lane CO's preview: two products (one about to sell out), delivery to the
   UAE, Stripe in test mode with fake keys, and cash on delivery. */
use App\Models\{Product, PaymentProvider, ShippingZone, ShippingZoneLocation, ShippingMethod};

$serum = Product::updateOrCreate(['slug' => 'co-glow-serum'], [
    'name' => 'Glow Serum', 'status' => 'publish', 'is_visible' => true,
    'price' => 12000, 'stock_status' => 'instock', 'manage_stock' => true, 'stock' => 8,
]);
$jelly = Product::updateOrCreate(['slug' => 'co-fwee-jelly-pot'], [
    'name' => 'fwee - Lip&Cheek Glowy Jelly Pot - Compote', 'status' => 'publish', 'is_visible' => true,
    'price' => 9500, 'stock_status' => 'instock',
]);

$zone = ShippingZone::firstOrCreate(['name' => 'UAE'], ['position' => 0]);
ShippingZoneLocation::firstOrCreate(['shipping_zone_id' => $zone->id, 'type' => 'country', 'code' => 'AE']);
ShippingMethod::firstOrCreate(
    ['shipping_zone_id' => $zone->id, 'type' => 'flat_rate'],
    ['title' => 'Standard delivery', 'cost' => 2000, 'enabled' => true, 'position' => 0],
);

PaymentProvider::query()->delete();
$row = PaymentProvider::create(['id' => 'stripe', 'title' => 'Credit / Debit Card', 'enabled' => true, 'mode' => 'test', 'position' => 0]);
$row->config = [
    'publishable_key' => 'pk_test_copreview', 'secret_key' => 'sk_test_copreview',
    'webhook_signing_secret' => 'whsec_copreview', 'webhook_secret' => 'whsec-url-copreview-0000000000',
];
$row->save();
PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 1]);

echo "seeded #{$serum->id} {$serum->slug}, #{$jelly->id} {$jelly->slug}\n";

/* Lane PO: mail goes to the log (the preview has no MTA); its COST is the
   front controller's PO_MAIL_MS stand-in, so where it is paid can be seen. */
app(\App\Services\SettingsService::class)->set('mail_transport', 'log');
app(\App\Services\SettingsService::class)->set('mail_from_address', 'shop@example.com');
app(\App\Services\SettingsService::class)->set('mail_merchant_address', 'owner@example.com');
