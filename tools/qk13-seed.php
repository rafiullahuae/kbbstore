<?php

declare(strict_types=1);

/*
 * Lane QK13: tools/mac-seed.php's shop, plus what the two screens in the brief
 * need to be photographed with real volume. Run through tools/qk13-preview.sh;
 * never against a real database.
 *
 *   - 650 more products in one category, so Catalog -> Products has seven pages
 *     at 100, four at 200 and three at 300 (650 + mac-seed's own rows);
 *   - a customer, a UAE delivery zone and cash on delivery (as tools/se-seed.php),
 *     so Store -> New Order can be filled and its totals computed.
 *
 *   admin  owner@example.com / preview-password
 */
require __DIR__.'/mac-seed.php';

use Illuminate\Support\Facades\DB;

$cat = DB::table('categories')->orderBy('id')->value('id');
$brand = DB::table('brands')->orderBy('id')->value('id');
$rows = [];
for ($i = 1; $i <= 650; $i++) {
    $rows[] = [
        'slug' => 'qk13-bulk-'.$i, 'name' => sprintf('QK13 Bulk Product %03d', $i), 'sku' => sprintf('QK13-%03d', $i),
        'brand_id' => $brand, 'type' => 'simple', 'status' => 'publish', 'is_visible' => true,
        'price' => (50 + $i % 90) * 100, 'manage_stock' => true, 'stock' => 10 + $i % 30, 'stock_status' => 'instock',
        // Newest first is the list's default sort; 001 is the newest row.
        'created_at' => now()->subMinutes($i), 'updated_at' => now(),
    ];
}
foreach (array_chunk($rows, 200) as $chunk) {
    DB::table('products')->insert($chunk);
}
$ids = DB::table('products')->where('slug', 'like', 'qk13-bulk-%')->pluck('id');
foreach ($ids->chunk(300) as $chunk) {
    DB::table('category_product')->insert($chunk->map(fn ($id) => ['product_id' => $id, 'category_id' => $cat])->values()->all());
}

$customer = \App\Models\Customer::updateOrCreate(['email' => 'aisha.khan@example.com'], [
    'first_name' => 'Aisha', 'last_name' => 'Khan', 'phone' => '+971501234567',
]);
$zone = \App\Models\ShippingZone::updateOrCreate(['name' => 'All UAE'], ['position' => 0]);
\App\Models\ShippingZoneLocation::updateOrCreate(['shipping_zone_id' => $zone->id, 'type' => 'country', 'code' => 'AE'], []);
\App\Models\ShippingMethod::updateOrCreate(['shipping_zone_id' => $zone->id, 'title' => 'Delivery Charges'],
    ['type' => 'flat_rate', 'cost' => 2000, 'enabled' => true, 'position' => 0]);
\App\Models\PaymentProvider::updateOrCreate(['id' => 'cod'], ['title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0]);

echo 'qk13: ', DB::table('products')->count(), " products, customer #{$customer->id}, UAE zone, COD\n";
