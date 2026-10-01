<?php
/*
 * Seed for the Lane PI-A item 10 preview: converting an imported product to a
 * set on the product editor.                                   (Lane PI-A)
 *
 *   the imported product   "Medicube - PDRN Glow Booster Set (Pink Edition)",
 *                          a SIMPLE product the way WooCommerce sold it: wc_id,
 *                          SKU, regular AED 950 and sale AED 850 with dates,
 *                          its own stock count, a gallery, copy, an imported
 *                          tab, SEO, a review, a category
 *   its future members     the booster (simple), the capsule cream (VARIABLE,
 *                          two sizes) and a serum (simple)
 *   a native set           built as a set with the same three, so every set
 *                          behaviour of the converted one can be compared with
 *                          one that never was anything else
 *
 * Money is integer fils.
 */
\App\Models\AdminUser::updateOrCreate(['email' => 'owner@preview.test'], [
    'name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner',
]);

$brand = \App\Models\Brand::updateOrCreate(['slug' => 'medicube'], ['name' => 'Medicube']);
$category = \App\Models\Category::updateOrCreate(['slug' => 'gift-sets'], ['name' => 'Gift sets']);

$root = getenv('KBB_PUBLIC_PATH') ?: public_path();
@mkdir($root.'/uploads/products', 0775, true);

$pic = function (string $name, string $hex) use ($root): string {
    [$r, $g, $b] = [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    $im = imagecreatetruecolor(600, 600);
    imagefilledrectangle($im, 0, 0, 600, 600, imagecolorallocate($im, $r, $g, $b));
    ob_start();
    imagepng($im);
    file_put_contents($root.'/uploads/products/'.$name, (string) ob_get_clean());

    return '/uploads/products/'.$name;
};

$simple = function (string $name, string $slug, int $fils, string $image) use ($brand, $category) {
    $p = \App\Models\Product::updateOrCreate(['slug' => $slug], [
        'name' => $name, 'price' => $fils, 'brand_id' => $brand->id, 'category_id' => $category->id,
        'image' => $image, 'status' => 'publish', 'is_visible' => true, 'stock_status' => 'instock',
        'type' => 'simple', 'manage_stock' => true, 'stock' => 20,
    ]);
    $p->categories()->sync([$category->id]);

    return $p;
};

$booster = $simple('Medicube AGE-R Booster Pro', 'pia-age-r-booster-pro', 75000, $pic('booster.png', 'E0567B'));
$serum = $simple('Medicube PDRN Pink Peptide Serum 30ml', 'pia-pdrn-serum', 9900, $pic('serum.png', 'F2A1B8'));

$cream = \App\Models\Product::updateOrCreate(['slug' => 'pia-pdrn-capsule-cream'], [
    'name' => 'Medicube PDRN Pink Collagen Capsule Cream', 'price' => null, 'brand_id' => $brand->id,
    'category_id' => $category->id, 'image' => $pic('cream.png', 'C94F7C'), 'status' => 'publish',
    'is_visible' => true, 'stock_status' => 'instock', 'type' => 'variable',
]);
$cream->categories()->sync([$category->id]);
\App\Models\ProductVariant::where('product_id', $cream->id)->delete();
$cream50 = \App\Models\ProductVariant::create(['product_id' => $cream->id, 'sku' => 'MED-CREAM-50', 'price' => 15000,
    'stock_status' => 'instock', 'manage_stock' => true, 'stock' => 12, 'position' => 0]);
\App\Models\ProductVariant::create(['product_id' => $cream->id, 'sku' => 'MED-CREAM-100', 'price' => 25000,
    'stock_status' => 'instock', 'manage_stock' => true, 'stock' => 6, 'position' => 1]);

/* The product the owner will convert, exactly as an import leaves it. */
$imported = \App\Models\Product::updateOrCreate(['slug' => 'medicube-pdrn-glow-booster-set-pink-edition'], [
    'wc_id' => 98765,
    'name' => 'Medicube - PDRN Glow Booster Set (Pink Edition)',
    'sku' => 'MED-PDRN-SET-PINK',
    'type' => 'simple',
    'price' => 95000,
    'sale_price' => 85000,
    'sale_starts_at' => now()->subDays(3),
    'sale_ends_at' => now()->addDays(30),
    'manage_stock' => true,
    'stock' => 7,
    'stock_status' => 'instock',
    'brand_id' => $brand->id,
    'category_id' => $category->id,
    'status' => 'publish',
    'is_visible' => true,
    'image' => $pic('pink-set-front.png', 'F7C6D4'),
    'images' => [$pic('pink-set-open.png', 'EFA3BC'), $pic('pink-set-device.png', 'D46A8E')],
    'short_description' => 'The booster device, the capsule cream and the serum, in one pink box.',
    'description' => "<p><strong>WHAT'S INCLUDED IN THE SET :</strong></p>\n<p>1. AGE-R Booster Pro<br>\n2. PDRN Capsule Cream<br>\n3. PDRN Peptide Serum</p>",
    'seo' => ['title' => 'PDRN Glow Booster Set (Pink) | K-Beauty Bliss', 'desc' => 'The pink PDRN set.'],
    'total_sales' => 420,
    // Back to exactly what an import leaves, so the seed can be re-run after a
    // conversion and the next run starts from the unconverted product.
    'set_price_mode' => null, 'set_discount' => null, 'set_price_basis' => null,
]);
$imported->categories()->sync([$category->id]);
\App\Models\ProductSetItem::where('set_product_id', $imported->id)->delete();

\App\Models\ProductTab::query()->where('product_id', $imported->id)->delete();
(new \App\Models\ProductTab)->forceFill([
    'product_id' => $imported->id, 'import_key' => 'wc:1', 'title' => 'Major Ingredients',
    'body' => '<p>PDRN, collagen, peptides.</p>', 'position' => 50, 'is_enabled' => true,
])->save();

\App\Models\Review::query()->where('product_id', $imported->id)->delete();
\App\Models\Review::create([
    'product_id' => $imported->id, 'author_name' => 'Layla', 'author_email' => 'layla@example.test',
    'rating' => 5, 'title' => 'Love it', 'content' => 'The device is brilliant.', 'status' => 'approved',
    'verified' => true,
]);

/* A set built as a set, with the same three, for comparison. */
$native = \App\Models\Product::updateOrCreate(['slug' => 'pia-native-pdrn-set'], [
    'name' => 'Native PDRN Set (built as a set)', 'type' => 'set', 'price' => 95000, 'sale_price' => 85000,
    'sale_starts_at' => now()->subDays(3), 'sale_ends_at' => now()->addDays(30),
    'brand_id' => $brand->id, 'category_id' => $category->id, 'status' => 'publish', 'is_visible' => true,
    'stock_status' => 'instock', 'manage_stock' => false, 'image' => $pic('native-set.png', 'B8E0D2'),
]);
$native->categories()->sync([$category->id]);
\App\Models\ProductSetItem::where('set_product_id', $native->id)->delete();
foreach ([[$booster->id, null, 1], [$cream->id, $cream50->id, 1], [$serum->id, null, 2]] as $i => [$pid, $vid, $qty]) {
    \App\Models\ProductSetItem::create(['set_product_id' => $native->id, 'member_product_id' => $pid,
        'member_variant_id' => $vid, 'quantity' => $qty, 'position' => $i]);
}

\Illuminate\Support\Facades\Cache::flush();

echo "pia-set seed: imported #{$imported->id}, native set #{$native->id}, booster #{$booster->id}, cream #{$cream->id}/{$cream50->id}, serum #{$serum->id}\n";
