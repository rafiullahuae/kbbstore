<?php
/*
 * Lane GST preview seed: the six live free gifts with the shop's REAL product
 * photographs (fetched by tools/gst-preview.sh from kbeautybliss.com into the
 * preview web root), one running gift range giving all six, gifts live, and
 * a few shop products so the homepage has something under the strip. The
 * strip itself is left OFF, as the package ships it.
 */
use Illuminate\Support\Facades\DB;

\App\Models\AdminUser::updateOrCreate(['email' => 'owner@preview.test'], [
    'name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner',
]);

$settings = app(\App\Services\SettingsService::class);
$settings->set('store_name', 'K-Beauty Bliss');
$settings->set(\App\Support\Locale::SETTING_ENABLED, '1');
$settings->set('language_ar_enabled', '1');
$settings->set(\App\Support\Locale::SETTING_RTL, '1');

$brand = fn (string $name) => \App\Models\Brand::updateOrCreate(['slug' => \Illuminate\Support\Str::slug($name)], ['name' => $name]);
$cat = \App\Models\Category::updateOrCreate(['slug' => 'skincare'], ['name' => 'Skincare']);
$make = function (string $name, string $slug, int $fils, string $brandName, string $img, array $o = []) use ($brand, $cat) {
    $p = \App\Models\Product::updateOrCreate(['slug' => $slug], array_merge([
        'name' => $name, 'price' => $fils, 'sale_price' => null, 'brand_id' => $brand($brandName)->id, 'category_id' => $cat->id,
        'image' => $img, 'status' => 'publish', 'is_visible' => true, 'stock_status' => 'instock', 'type' => 'simple',
        'manage_stock' => true, 'stock' => 40,
    ], $o));
    $p->categories()->sync([$cat->id]);

    return $p;
};

$gifts = [
    ['Arencia Vitamin C Booster Shot', 'Arencia', '/wp-content/uploads/2026/04/Arencia-Vitamin-C-Booster-Shot.webp', 'full'],
    ['AXIS-Y Dark Spot Correcting Glow Serum', 'AXIS-Y', '/wp-content/uploads/2024/10/AXIS-Y-Dark-Spot-Correcting-Glow-Serum.webp', 'full'],
    ['Medicube Vanilla & Pistachio Deodorant', 'Medicube', '/wp-content/uploads/2026/09/medicube-Vanilla-Pistachio-Deodorant-1.webp', 'full'],
    ['Medicube PDRN Collagen Gua Sha Neck Wrinkle Cream', 'Medicube', '/wp-content/uploads/2026/09/medicube-PDRN-Collagen-Gua-Sha-Neck-Wrinkle-Cream.webp', 'full'],
    ['Medicube PDRN Pink Collagen Jelly Eye Mask (6 pairs)', 'Medicube', '/uploads/products/medicube-medicube-pdrn-pink-collagen-jelly-eye-mask-6-pairs.webp', 'full'],
    ['Medicube Red Succinic Acid Peeling Pad', 'Medicube', '/wp-content/uploads/2024/12/Medicube-Red-Succinic-Acid-Peeling-Pad.webp', 'full'],
];

DB::table('free_gift_range_items')->delete();
DB::table('free_gift_ranges')->delete();
$fg = DB::table('free_gift_ranges')->insertGetId(['min_fils' => 0, 'max_fils' => null, 'label_en' => 'Free gifts', 'label_ar' => 'هدايا مجانية', 'sort' => 0, 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
foreach ($gifts as $i => [$name, $b, $img, $kind]) {
    $p = $make($name, 'gst-gift-' . $i, 9900, $b, $img, ['gift_kind' => $kind]);
    DB::table('free_gift_range_items')->insert(['range_id' => $fg, 'product_id' => $p->id, 'quantity' => 1, 'sort' => $i]);
}
$settings->set(\App\Services\FreeGifts::KEY, ['mode' => 'live']);
app(\App\Services\FreeGifts::class)->compile();

// A few products for the rails under the strip (reusing the gift photos).
foreach ($gifts as $i => [$name, $b, $img]) {
    $make($name . ' (shop)', 'gst-shop-' . $i, 4900 + 1000 * $i, $b, $img);
}

echo "seeded " . count($gifts) . " gifts\n";
