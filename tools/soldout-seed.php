<?php
/*
 * Seed the Lane SX preview: one category, "Toners", whose own curated order
 * puts sold-out products in the middle of the grid -- the owner's situation --
 * and one brand, "Glowtest", the same. Ten products each, so one batch holds
 * them all and the before/after shots compare whole pages. Written into the
 * preview's database and web root only.
 *
 *   curated   T1 T2 T3 T4 T5 T6 T7 T8 T9 T10
 *   sold out      T2       T5    T7          (T7 on backorder -- the card says
 *                                            "Sold out" and the cart refuses it)
 */

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;

\App\Models\AdminUser::updateOrCreate(['email' => 'owner@preview.test'], ['name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner']);

@mkdir(public_path('uploads/products'), 0755, true);

$picture = static function (string $rel, array $rgb, int $seed): void {
    mt_srand($seed);
    $im = imagecreatetruecolor(600, 600);
    imagefilledrectangle($im, 0, 0, 600, 600, imagecolorallocate($im, ...$rgb));
    for ($i = 0; $i < 90; $i++) {
        $c = imagecolorallocate($im, mt_rand(150, 255), mt_rand(120, 220), mt_rand(140, 230));
        imagefilledellipse($im, mt_rand(0, 600), mt_rand(0, 600), mt_rand(20, 140), mt_rand(20, 140), $c);
    }
    imagejpeg($im, public_path($rel), 85);
};

$brand = Brand::query()->updateOrCreate(['slug' => 'glowtest'], ['name' => 'Glowtest', 'description' => 'Gentle K-beauty basics.']);
$toners = Category::query()->updateOrCreate(['slug' => 'toners'], ['name' => 'Toners', 'path' => 'toners']);

// Only these twenty are live, so every listing in the shots is this seed.
Product::query()->update(['status' => 'draft']);

$sold = [2 => 'outofstock', 5 => 'outofstock', 7 => 'onbackorder'];

foreach (range(1, 10) as $i) {
    $slug = 'toner-t'.$i;
    $rel = 'uploads/products/'.$slug.'.jpg';
    $picture($rel, [244, 236, 240], 100 + $i);
    $p = Product::query()->updateOrCreate(['slug' => $slug], [
        'name' => 'Toner T'.$i.(isset($sold[$i]) ? ' (sold out)' : ''), 'type' => 'simple', 'status' => 'publish', 'is_visible' => true,
        'price' => 4500 + $i * 100, 'stock_status' => $sold[$i] ?? 'instock', 'image' => '/'.$rel,
    ]);
    $p->categories()->syncWithoutDetaching([$toners->id => ['category_position' => $i - 1]]);

    $bslug = 'glow-g'.$i;
    $brel = 'uploads/products/'.$bslug.'.jpg';
    $picture($brel, [236, 240, 246], 200 + $i);
    Product::query()->updateOrCreate(['slug' => $bslug], [
        'name' => 'Glow G'.$i.(isset($sold[$i]) ? ' (sold out)' : ''), 'type' => 'simple', 'status' => 'publish', 'is_visible' => true,
        'price' => 6000 + $i * 100, 'stock_status' => $sold[$i] ?? 'instock', 'image' => '/'.$brel,
        'brand_id' => $brand->id, 'brand_position' => $i - 1,
    ]);
}

echo "seeded Toners (10, 3 sold out) and Glowtest (10, 3 sold out)\n";
