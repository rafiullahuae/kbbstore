<?php
/*
 * Seed the Lane AMP preview: the owner's two cards side by side.
 *
 *   "SKIN&amp;LAB - Vitamin C Brightening Serum"   stored the way WordPress stores
 *                                                   a post_title, and the way the
 *                                                   importer copied it
 *   "SKIN&LAB - Barrierderm Intensive Cream 50ml"   stored plain (an admin save)
 *
 * The brand is "SKIN&LAB" plain: brands were already decoded by Lane FP's
 * 2027_08_19 migration on the live shop. Written into the PREVIEW's database
 * only; nothing here reaches a package.
 *   php artisan tinker --execute="require 'tools/amp-seed.php';"   (from amp-preview.sh)
 */
\App\Models\AdminUser::updateOrCreate(['email' => 'owner@preview.test'], [
    'name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner',
]);

$brand = \App\Models\Brand::updateOrCreate(['slug' => 'skinlab'], ['name' => 'SKIN&LAB']);
$category = \App\Models\Category::updateOrCreate(['slug' => 'serums'], ['name' => 'Serums &amp; Ampoules', 'depth' => 0, 'position' => 0, 'path' => 'serums']);

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

$rows = [
    'skin-lab-vitamin-c-brightening-serum' => ['SKIN&amp;LAB - Vitamin C Brightening Serum', [250, 226, 196], 7900],
    'skin-lab-barrierderm-intensive-cream-50ml' => ['SKIN&LAB - Barrierderm Intensive Cream 50ml', [214, 230, 246], 8900],
    'skin-lab-vitamin-c-mask' => ['SKIN&amp;LAB &#8211; Vitamin C Mask &#8220;Glow&#8221;', [246, 214, 222], 2900],
];

foreach ($rows as $slug => [$name, $rgb, $price]) {
    $p = \App\Models\Product::updateOrCreate(['slug' => $slug], [
        'name' => $name, 'brand_id' => $brand->id, 'price' => $price,
        'image' => $pic($slug.'.png', $rgb), 'status' => 'publish', 'is_visible' => true,
        'stock_status' => 'instock', 'type' => 'simple', 'manage_stock' => true, 'stock' => 20,
        'short_description' => '<p>Brightens &amp; evens tone.</p>',
        'category_id' => $category->id,
    ]);
    $p->categories()->syncWithoutDetaching([$category->id]);
}

/* The shop's rows were imported BEFORE the fix, so the repair runs over them
   exactly as it will on his server: the migration, once more, after the seed.
   Absent on the base branch, which is the "before" picture. */
foreach (glob(base_path('database/migrations/*_decode_html_entities_in_plain_text.php')) ?: [] as $fix) {
    (require $fix)->up();
}

\App\Http\Controllers\Store\ShopController::flushSidebarCache();
echo "amp seed done\n";
