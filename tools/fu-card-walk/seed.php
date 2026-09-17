<?php
use App\Models\{Product, PaymentProvider, ShippingZone, ShippingZoneLocation, ShippingMethod};

$p = Product::firstOrCreate(['slug' => 'preview-serum'], [
    'name' => 'Preview Serum', 'status' => 'publish', 'is_visible' => true,
    'price' => 200, 'stock_status' => 'instock',
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
    'publishable_key' => 'pk_test_previewkey',
    'secret_key' => 'sk_test_previewsecret',
    'webhook_signing_secret' => 'whsec_preview',
    'webhook_secret' => 'whsec-url-preview-000000000000',
];
$row->save();
PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 1]);

echo "seeded product #{$p->id}\n";
