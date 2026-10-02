<?php
/*
 * Seed the Lane PW preview: one product page with a photograph, a blurb and a
 * price, in a UAE shop whose free delivery starts at AED 199 — so the delivery
 * box's second line has the checkout's own figure to quote.
 */
\App\Models\AdminUser::updateOrCreate(['email' => 'owner@preview.test'], [
    'name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner',
]);

app(\App\Services\SettingsService::class)->set('store_country', 'AE');

$zone = \App\Models\ShippingZone::firstOrCreate(['name' => 'All UAE'], ['position' => 0]);
\App\Models\ShippingZoneLocation::firstOrCreate(['shipping_zone_id' => $zone->id, 'type' => 'country', 'code' => 'AE']);
\App\Models\ShippingMethod::firstOrCreate(['shipping_zone_id' => $zone->id, 'type' => 'flat_rate'], [
    'title' => 'Delivery Charges', 'cost' => 2000, 'enabled' => true, 'position' => 0,
]);
\App\Models\ShippingMethod::firstOrCreate(['shipping_zone_id' => $zone->id, 'type' => 'free_shipping'], [
    'title' => 'Free delivery', 'cost' => 0, 'min_amount' => 19900, 'enabled' => true, 'position' => 1,
]);

$brand = \App\Models\Brand::updateOrCreate(['slug' => 'anua'], ['name' => 'Anua']);
$category = \App\Models\Category::updateOrCreate(['slug' => 'toners'], ['name' => 'Toners']);

$root = getenv('KBB_PUBLIC_PATH') ?: public_path();
@mkdir($root.'/uploads/products', 0775, true);

$pic = function (string $name, array $rgb) use ($root): string {
    $im = imagecreatetruecolor(800, 800);
    imagefilledrectangle($im, 0, 0, 800, 800, imagecolorallocate($im, ...$rgb));
    imagefilledellipse($im, 400, 420, 300, 520, imagecolorallocate($im, 255, 255, 255));
    ob_start();
    imagepng($im);
    file_put_contents($root.'/uploads/products/'.$name, (string) ob_get_clean());

    return '/uploads/products/'.$name;
};

$p = \App\Models\Product::updateOrCreate(['slug' => 'pw-heartleaf-toner'], [
    'name' => 'Heartleaf 77% Soothing Toner 250ml',
    'brand_id' => $brand->id,
    'price' => 8500, 'sale_price' => 6500,
    'image' => $pic('pw-toner.png', [246, 214, 222]),
    'short_description' => '<p>A gentle daily toner with 77% heartleaf extract that calms redness, balances oil &amp; water, and preps skin for everything after it.</p>',
    'description' => '<p>Built around a single idea: calm first.</p>',
    'status' => 'publish', 'is_visible' => true, 'stock_status' => 'instock',
    'type' => 'simple', 'manage_stock' => true, 'stock' => 40,
]);
$p->categories()->syncWithoutDetaching([$category->id]);

foreach (['Rice Toner' => [233, 222, 200], 'Ceramide Cream' => [210, 226, 246], 'Azelaic Serum' => [214, 238, 222]] as $name => $rgb) {
    $slug = 'pw-'.\Illuminate\Support\Str::slug($name);
    $r = \App\Models\Product::updateOrCreate(['slug' => $slug], [
        'name' => $name, 'brand_id' => $brand->id, 'price' => 6900,
        'image' => $pic($slug.'.png', $rgb), 'status' => 'publish', 'is_visible' => true,
        'stock_status' => 'instock', 'type' => 'simple',
    ]);
    $r->categories()->syncWithoutDetaching([$category->id]);
}

echo "seeded product {$p->id} /product/pw-heartleaf-toner/\n";
