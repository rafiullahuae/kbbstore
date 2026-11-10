<?php
/*
 * Seed for the Lane RP preview:
 *   GO_LANE=lane-rp GO_SEED=rpx-seed.php sh tools/go-preview.sh 9640
 * Two products, COD and a UAE zone, Arabic on. The mist starts at AED 85;
 * tools/rpx-price.php moves it the way the owner's product editor would.
 */
\App\Models\AdminUser::updateOrCreate(['email' => 'owner@preview.test'], [
    'name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner',
]);
$settings = app(\App\Services\SettingsService::class);
$settings->set('store_name', 'K-Beauty Bliss');
$settings->set(\App\Support\Locale::SETTING_ENABLED, '1');

$anua = \App\Models\Brand::firstOrCreate(['slug' => 'anua'], ['name' => 'Anua']);
$boj = \App\Models\Brand::firstOrCreate(['slug' => 'beauty-of-joseon'], ['name' => 'Beauty of Joseon']);
\App\Models\Product::updateOrCreate(['slug' => 'rp-anua-pdrn-capsule-mist-mini'], [
    'name' => 'Anua PDRN Capsule Mist Mini', 'brand_id' => $anua->id, 'type' => 'simple', 'status' => 'publish',
    'is_visible' => true, 'price' => 8500, 'sale_price' => null, 'stock_status' => 'instock', 'manage_stock' => false,
]);
\App\Models\Product::updateOrCreate(['slug' => 'rp-relief-sun'], [
    'name' => 'Relief Sun Rice + Probiotics SPF50+', 'brand_id' => $boj->id, 'type' => 'simple', 'status' => 'publish',
    'is_visible' => true, 'price' => 6900, 'stock_status' => 'instock', 'manage_stock' => false,
]);
\App\Models\PaymentProvider::updateOrCreate(['id' => 'cod'], ['title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0]);
if (! \App\Models\ShippingZone::query()->where('name', 'UAE')->exists()) {
    $zone = \App\Models\ShippingZone::create(['name' => 'UAE', 'position' => 0]);
    \App\Models\ShippingZoneLocation::create(['shipping_zone_id' => $zone->id, 'type' => 'country', 'code' => 'AE']);
    \App\Models\ShippingMethod::create(['shipping_zone_id' => $zone->id, 'type' => 'flat_rate', 'title' => 'Standard delivery', 'cost' => 2000, 'enabled' => true, 'position' => 0]);
}
echo 'seeded: products ' . \App\Models\Product::count() . "\n";
