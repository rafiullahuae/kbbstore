<?php
/* Seed the Lane RPL preview: Catalog → Products → edit, replace a picture.
 *
 * Anua Heartleaf 77% Soothing Toner with FIVE pictures, two of them "texty"
 * (big sale words drawn across the photograph — the kind the owner wants off
 * the server), and a second Anua product that shares one gallery picture, so
 * the "kept: still used by …" case has something to say. Two clean pictures sit
 * in the Media Library ready to be chosen. Pictures are drawn with GD here and
 * go through MediaRegistrar + ImageVariants exactly as an upload does.
 */

\App\Models\AdminUser::create([
    'name' => 'Preview Owner', 'email' => 'owner@preview.test',
    'password' => 'preview-secret-1', 'role' => 'owner',
]);

$settings = app(\App\Services\SettingsService::class);
$settings->set('site_url', getenv('RPL_SITE_URL') ?: 'http://127.0.0.1:9143');

$dir = public_path('uploads/products');
@mkdir($dir, 0775, true);

/** A 900x900 "product photograph": a bottle shape on a tint, optionally with sale words across it. */
$picture = function (string $name, array $rgb, ?string $words = null): string {
    $img = imagecreatetruecolor(900, 900);
    [$r, $g, $b] = $rgb;
    imagefilledrectangle($img, 0, 0, 900, 900, imagecolorallocate($img, $r, $g, $b));
    imagefilledrectangle($img, 360, 220, 540, 760, imagecolorallocate($img, 245, 245, 240));
    imagefilledrectangle($img, 400, 150, 500, 225, imagecolorallocate($img, 40, 120, 80));
    imagefilledellipse($img, 450, 480, 120, 120, imagecolorallocate($img, 120, 190, 140));

    if ($words !== null) {
        // Chunky text: draw small, scale up.
        $small = imagecreatetruecolor(300, 40);
        imagefill($small, 0, 0, imagecolorallocate($small, 220, 30, 60));
        imagestring($small, 5, 8, 4, $words, imagecolorallocate($small, 255, 255, 255));
        imagestring($small, 3, 8, 22, 'LIMITED OFFER - FREE GIFT', imagecolorallocate($small, 255, 240, 120));
        imagecopyresized($img, $small, 0, 330, 0, 0, 900, 120, 300, 40);
        imagecopyresized($img, $small, 0, 620, 0, 0, 900, 120, 300, 40);
        imagedestroy($small);
    }

    $path = 'uploads/products/'.$name.'.jpg';
    imagejpeg($img, public_path($path), 86);
    imagedestroy($img);

    \App\Support\MediaRegistrar::record($path);
    \App\Support\ImageVariants::generate('/'.$path);

    return $path;
};

$site = rtrim((string) (getenv('RPL_SITE_URL') ?: 'http://127.0.0.1:9143'), '/');
$u = static fn (string $rel): string => $site.'/'.$rel;

$main = $picture('anua-heartleaf-toner-bottle', [236, 226, 214]);
$g1 = $picture('anua-heartleaf-toner-texture', [222, 236, 226]);
$g2 = $picture('anua-toner-sale-50-off', [250, 214, 214], '50% OFF TODAY ONLY');
$g3 = $picture('anua-toner-promo-banner', [214, 226, 250], 'BUY 1 GET 1 FREE');
$g4 = $picture('anua-heartleaf-shelf', [230, 230, 210]);
$clean1 = $picture('anua-heartleaf-toner-front-clean', [240, 238, 232]);
$clean2 = $picture('anua-heartleaf-toner-back-clean', [228, 238, 240]);

$brand = \App\Models\Brand::firstOrCreate(['slug' => 'anua'], ['name' => 'Anua']);
$cat = \App\Models\Category::firstOrCreate(['slug' => 'toner'], ['name' => 'Toner']);

$p = \App\Models\Product::create([
    'name' => 'Anua Heartleaf 77% Soothing Toner', 'slug' => 'rpl-anua-heartleaf-toner', 'sku' => 'ANUA-HL-250',
    'status' => 'publish', 'is_visible' => 1, 'brand_id' => $brand->id, 'category_id' => $cat->id,
    'price' => 7600, 'type' => 'simple', 'stock_status' => 'instock',
    'image' => $u($main),
    'images' => [$u($g1), $u($g2), $u($g3), $u($g4)],
    'image_alts' => [$u($g2) => 'Anua toner 50% off sale banner'],
]);
$p->categories()->sync([$cat->id]);

\App\Models\Product::create([
    'name' => 'Anua Heartleaf Pore Control Cleansing Oil', 'slug' => 'rpl-anua-cleansing-oil',
    'status' => 'publish', 'is_visible' => 1, 'brand_id' => $brand->id, 'price' => 8400, 'type' => 'simple',
    'stock_status' => 'instock', 'image' => $u($clean2), 'images' => [$u($g4)],
]);

\App\Support\MediaUsageWriter::rebuild();

echo "seeded product {$p->id}\n";
