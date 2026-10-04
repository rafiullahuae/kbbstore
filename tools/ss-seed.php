<?php
/*
 * Seed the Lane SS preview: the "Super Sale" category with the sixteen products
 * the catalogue export (storage/catalog/products.json) puts in it, at their
 * exported positions and prices, plus a PLACEHOLDER banner picture for the
 * desktop and the phone so the screenshots show where the owner's picture goes.
 *
 * Written into the PREVIEW's database only; nothing here reaches a package, and
 * the placeholder pictures are generated here, never shipped.
 */
use App\Models\{AdminUser, Category, Media, Product, Brand};

AdminUser::updateOrCreate(['email' => 'owner@preview.test'], ['name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner']);

$cat = Category::firstOrCreate(['slug' => 'super-sale'], ['name' => 'Super Sale', 'path' => 'super-sale', 'depth' => 0]);
@mkdir(public_path('uploads/ss'), 0755, true);

$rows = json_decode((string) file_get_contents(base_path('storage/catalog/products.json')), true);
$i = 0;
foreach ($rows as $r) {
    if (! in_array('Super Sale', $r['collections'], true)) {
        continue;
    }
    $brand = Brand::firstOrCreate(['slug' => \Illuminate\Support\Str::slug($r['brand'] ?: 'kbb')], ['name' => $r['brand'] ?: 'KBB']);
    $rel = 'uploads/ss/p'.$r['wc_id'].'.webp';
    $im = imagecreatetruecolor(600, 600);
    imagefilledrectangle($im, 0, 0, 600, 600, imagecolorallocate($im, 252, 246, 248));
    imagefilledrectangle($im, 210, 110, 390, 500, imagecolorallocate($im, 80 + ($i * 41) % 160, 110, 150));
    imagestring($im, 5, 20, 560, 'pos '.$r['position'].'  wc '.$r['wc_id'], imagecolorallocate($im, 60, 60, 60));
    imagewebp($im, public_path($rel), 80);
    imagedestroy($im);

    $p = Product::updateOrCreate(['slug' => $r['slug']], [
        'wc_id' => $r['wc_id'], 'name' => $r['name'], 'brand_id' => $brand->id,
        'type' => 'simple', 'status' => 'publish', 'is_visible' => true,
        'price' => $r['price'], 'sale_price' => $r['sale_price'], 'position' => $r['position'],
        'stock_status' => 'instock', 'image' => '/'.$rel, 'category_id' => $cat->id,
    ]);
    $p->categories()->syncWithoutDetaching([$cat->id]);
    $i++;
}
echo "ss seed: {$i} Super Sale products\n";

// PLACEHOLDER pictures for the banner slot — preview only.
foreach (['d' => [1920, 600], 'm' => [1080, 720]] as $dev => [$w, $h]) {
    $im = imagecreatetruecolor($w, $h);
    imagefilledrectangle($im, 0, 0, $w, $h, imagecolorallocate($im, 248, 200, 214));
    imagefilledrectangle($im, (int) ($w * .62), 0, $w, $h, imagecolorallocate($im, 236, 168, 190));
    $ink = imagecolorallocate($im, 120, 30, 70);
    imagestring($im, 5, 40, 40, 'PLACEHOLDER '.($dev === 'd' ? 'DESKTOP' : 'PHONE').' BANNER  '.$w.' x '.$h, $ink);
    imagestring($im, 5, 40, 70, 'The owner\'s Super Sale picture goes here (Pages -> Page banners)', $ink);
    $rel = 'uploads/ss/placeholder-'.$dev.'.png';
    imagepng($im, public_path($rel));
    imagedestroy($im);
    Media::query()->updateOrCreate(['path' => $rel], ['filename' => basename($rel), 'mime' => 'image/png', 'width' => $w, 'height' => $h, 'alt' => '']);
}
echo "ss placeholders done\n";
