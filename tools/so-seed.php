<?php
/*
 * Seed the Lane SO preview: the owner's own situation, small.
 *
 *   - the brand Medicube, with a banner, eight products
 *   - the category Super Sale, holding five of those Medicube products and
 *     three of another brand's
 *   - a category "Toners" sharing products with both
 *   - an order already in products.position, with ties and featured products,
 *     the way a live shop has one before Lane SO
 *
 * Seeded on the BASE code (before Lane SO) by tools/so-preview.sh, so the
 * "after" preview reaches its state the way the live shop will: by running the
 * migration over this database. Written into the preview's database and web
 * root only.
 */

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;

\App\Models\AdminUser::updateOrCreate(['email' => 'owner@preview.test'], ['name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner']);

$base = rtrim((string) config('app.url'), '/');
@mkdir(public_path('uploads/products'), 0755, true);
@mkdir(public_path('uploads/brands'), 0755, true);

$picture = static function (string $rel, int $w, int $h, array $rgb, int $seed): void {
    mt_srand($seed);
    $im = imagecreatetruecolor($w, $h);
    imagefilledrectangle($im, 0, 0, $w, $h, imagecolorallocate($im, ...$rgb));
    for ($i = 0; $i < 160; $i++) {
        $c = imagecolorallocate($im, mt_rand(150, 255), mt_rand(120, 220), mt_rand(140, 230));
        imagefilledellipse($im, mt_rand(0, $w), mt_rand(0, $h), mt_rand(20, 140), mt_rand(20, 140), $c);
    }
    imagejpeg($im, public_path($rel), 88);
};

$picture('uploads/brands/so-medicube-banner.jpg', 1600, 420, [236, 222, 230], 11);

$medicube = Brand::query()->updateOrCreate(['slug' => 'medicube'], [
    'name' => 'Medicube',
    'description' => 'Korean derma-cosmetics, clinically tested.',
    'banner' => ['enabled' => true, 'image' => '/uploads/brands/so-medicube-banner.jpg', 'heading' => 'Medicube'],
]);
$anua = Brand::query()->updateOrCreate(['slug' => 'anua'], ['name' => 'Anua']);

$sale = Category::query()->updateOrCreate(['slug' => 'super-sale'], ['name' => 'Super Sale', 'path' => 'super-sale']);
$toners = Category::query()->updateOrCreate(['slug' => 'toners'], ['name' => 'Toners', 'path' => 'toners']);

// Medicube A..H: global positions with a tie (B/C at 2), D featured.
$mc = [];
foreach (['A' => 5, 'B' => 2, 'C' => 2, 'D' => 9, 'E' => 1, 'F' => 7, 'G' => 3, 'H' => 4] as $letter => $pos) {
    $slug = 'medicube-product-'.strtolower($letter);
    $rel = 'uploads/products/'.$slug.'.jpg';
    $picture($rel, 600, 600, [246, 238, 241], ord($letter));
    $mc[$letter] = Product::query()->updateOrCreate(['slug' => $slug], [
        'name' => 'Medicube Product '.$letter, 'type' => 'simple', 'status' => 'publish', 'is_visible' => true,
        'price' => 12000 + ord($letter) * 10, 'sale_price' => in_array($letter, ['A', 'C', 'E', 'G'], true) ? 8900 : null,
        'stock_status' => 'instock', 'brand_id' => $medicube->id, 'position' => $pos, 'featured' => $letter === 'D',
        'image' => $base.'/'.$rel,
    ]);
}

$an = [];
foreach (['X' => 0, 'Y' => 6, 'Z' => 8] as $letter => $pos) {
    $slug = 'anua-product-'.strtolower($letter);
    $rel = 'uploads/products/'.$slug.'.jpg';
    $picture($rel, 600, 600, [238, 244, 240], ord($letter));
    $an[$letter] = Product::query()->updateOrCreate(['slug' => $slug], [
        'name' => 'Anua Product '.$letter, 'type' => 'simple', 'status' => 'publish', 'is_visible' => true,
        'price' => 9900, 'sale_price' => 6900, 'stock_status' => 'instock', 'brand_id' => $anua->id, 'position' => $pos,
        'image' => $base.'/'.$rel,
    ]);
}

foreach (['A', 'B', 'C', 'D', 'E'] as $l) {
    $mc[$l]->categories()->syncWithoutDetaching([$sale->id]);
}
foreach ($an as $p) {
    $p->categories()->syncWithoutDetaching([$sale->id, $toners->id]);
}
foreach (['B', 'F', 'G'] as $l) {
    $mc[$l]->categories()->syncWithoutDetaching([$toners->id]);
}

\Illuminate\Support\Facades\Cache::flush();
echo "so seed done\n";
