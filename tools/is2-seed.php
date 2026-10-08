<?php

declare(strict_types=1);

/*
 * Lane IS2: Lane IR's Image SEO preview shop, plus what the owner's screenshot
 * had and what "select all across pages" needs. Run through
 * tools/is2-preview.sh, never against a real database.
 *
 *   - IR's five products (tools/ir-seed.php), unchanged;
 *   - two more products with eight pictures each, so a search for "Glow"
 *     plus IR's five give the owner's "7 product(s) · 27 picture(s)";
 *   - 1,234 products under the brand "Bulk Beauty", two small real pictures
 *     each, so the Find tab has 62 pages and "Select all 1,234" means it.
 */

use App\Support\MediaRegistrar;
use App\Support\MediaUsageWriter;
use Illuminate\Support\Facades\DB;

require __DIR__.'/ir-seed.php';

$site = rtrim((string) config('app.url'), '/');
$small = static function (string $rel, int $seed): void {
    $img = imagecreatetruecolor(160, 160);
    imagefilledrectangle($img, 0, 0, 159, 159, imagecolorallocate($img, 120 + $seed * 37 % 120, 140 + $seed * 53 % 100, 160 + $seed * 71 % 90));
    imagefilledrectangle($img, 60, 40, 100, 130, imagecolorallocate($img, 250, 250, 248));
    $file = public_path($rel);
    @mkdir(dirname($file), 0775, true);
    imagejpeg($img, $file, 80);
};

$brandId = static fn (string $name, string $slug) => DB::table('brands')->insertGetId(['name' => $name, 'slug' => $slug, 'created_at' => now(), 'updated_at' => now()]);
$glowBrand = $brandId('Glowery', 'is2-glowery');
$bulkBrand = $brandId('Bulk Beauty', 'is2-bulk-beauty');
$cat = (int) DB::table('categories')->where('slug', 'ir-toners')->value('id');

$rows = [];
$paths = [];

foreach (['Glowery Rice Glow Serum', 'Glowery Rice Glow Cream'] as $n => $name) {
    $urls = [];

    for ($i = 0; $i < 8; $i++) {
        $rel = 'uploads/products/2026100'.($n + 7).'-1200'.$i.'-glow'.$n.$i.'.jpg';
        $small($rel, $n * 8 + $i);
        $urls[] = $site.'/'.$rel;
        $paths[] = $rel;
    }

    DB::table('products')->insert(['name' => $name, 'slug' => 'is2-glow-'.$n, 'sku' => 'IS2-GLOW-'.$n, 'brand_id' => $glowBrand, 'category_id' => $cat,
        'price' => 5000, 'status' => 'publish', 'is_visible' => true, 'stock_status' => 'instock',
        'image' => $urls[0], 'images' => json_encode(array_slice($urls, 1)), 'created_at' => now(), 'updated_at' => now()]);
}

$words = ['Hydrating', 'Calming', 'Brightening', 'Barrier', 'Daily', 'Gentle', 'Deep', 'Vital', 'Pure', 'Dewy', 'Soft', 'Clear'];
$things = ['Toner', 'Serum', 'Cream', 'Essence', 'Ampoule', 'Mask', 'Cleanser', 'Balm', 'Mist', 'Gel', 'Lotion'];

for ($n = 1; $n <= 1234; $n++) {
    $name = sprintf('Bulk Beauty %s %s %s %04d', $words[$n % 12], $words[($n * 5) % 12], $things[$n % 11], $n);
    $a = sprintf('uploads/bulk/%04d/IMG_%05d.jpg', intdiv($n, 100), $n * 2);
    $b = sprintf('uploads/bulk/%04d/IMG_%05d.jpg', intdiv($n, 100), $n * 2 + 1);
    $small($a, $n);
    $small($b, $n + 7);
    $paths[] = $a;
    $paths[] = $b;
    $rows[] = ['name' => $name, 'slug' => 'is2-bulk-'.$n, 'sku' => 'IS2-B'.$n, 'brand_id' => $bulkBrand, 'category_id' => $cat, 'price' => 3000 + $n,
        'status' => 'publish', 'is_visible' => true, 'stock_status' => 'instock', 'image' => '/'.$a, 'images' => json_encode(['/'.$b]),
        'created_at' => now(), 'updated_at' => now()];

    if (count($rows) === 200) {
        DB::table('products')->insert($rows);
        $rows = [];
    }
}

if ($rows !== []) {
    DB::table('products')->insert($rows);
}

foreach ($paths as $rel) {
    MediaRegistrar::record($rel);
}

MediaUsageWriter::rebuild();
\Illuminate\Support\Facades\Cache::flush();

echo 'seeded Lane IS2: +2 Glowery products (16 pictures), +1,234 Bulk Beauty products ('.(count($paths) - 16)." pictures)\n";
