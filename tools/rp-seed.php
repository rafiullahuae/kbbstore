<?php

/*
 * Lane RP preview seed, run by tools/rp-preview.sh through `artisan tinker`
 * on top of DemoCatalogueSeeder (24 products, 6 shelves, 8 brands).
 *
 * What the three blocks need to be photographed honestly:
 *   - a brand with more than a slider's worth of products (Anua: 14),
 *   - a shelf with more than that (Toners: 16), under a parent (Skincare),
 *   - every routine shelf stocked (cleanser, toner, serum, moisturiser,
 *     sunscreen, mask), a few sold out, some on sale,
 *   - skin-concern tags (Hydration / Acne / Brightening),
 *   - PICTURES AS THE LIVE SHOP HAS THEM: a 1000px webp per product with its
 *     200/400/800 img-cache copies, so every card carries a srcset and the
 *     network log shows what a phone would really fetch.
 * Written into the PREVIEW's database and web root only.
 */

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Tag;
use Illuminate\Support\Str;

mt_srand(427);

$skin = Category::firstOrCreate(['slug' => 'skincare'], ['name' => 'Skincare', 'position' => 0, 'depth' => 0, 'path' => 'skincare']);
foreach (Category::query()->where('id', '!=', $skin->id)->get() as $c) {
    $c->parent_id = $skin->id;
    $c->depth = 1;
    $c->path = 'skincare/'.$c->slug;
    $c->save();
}

$tags = [];
foreach (['Hydration', 'Acne', 'Brightening'] as $t) {
    $tags[] = Tag::firstOrCreate(['slug' => Str::slug($t)], ['name' => $t])->id;
}

$cat = fn (string $slug) => Category::where('slug', $slug)->firstOrFail();
$brand = fn (string $slug) => Brand::where('slug', $slug)->firstOrFail();

$extra = [
    // Anua: 14 in all with the demo's three.
    ['Anua Heartleaf Pore Control Cleansing Oil', 'anua', 'cleansers'],
    ['Anua Heartleaf Quercetinol Pore Deep Cleansing Foam', 'anua', 'cleansers'],
    ['Anua Heartleaf 77% Clear Pad', 'anua', 'toners'],
    ['Anua Rice 70 Glow Milky Toner', 'anua', 'toners'],
    ['Anua Niacinamide 10% + TXA 4% Serum', 'anua', 'serums'],
    ['Anua Peach 70% Niacin Serum', 'anua', 'serums'],
    ['Anua Heartleaf 70% Intense Calming Cream', 'anua', 'moisturisers'],
    ['Anua Birch 70% Moisture Boosting Cream', 'anua', 'moisturisers'],
    ['Anua Heartleaf Silky Moisture Sun Cream', 'anua', 'sunscreens'],
    ['Anua Heartleaf Soothing Sheet Mask', 'anua', 'masks'],
    ['Anua Azelaic Acid 10 Hyaluron Redness Soothing Serum', 'anua', 'serums'],
    // Toners: 16 in all.
    ['Round Lab Birch Juice Moisturizing Toner', 'round-lab', 'toners'],
    ['Torriden Dive-In Low Molecular Toner', 'torriden', 'toners'],
    ['Isntree Hyaluronic Acid Toner', 'isntree', 'toners'],
    ['COSRX AHA/BHA Clarifying Treatment Toner', 'cosrx', 'toners'],
    ['Beauty of Joseon Glow Replenishing Rice Milk', 'beauty-of-joseon', 'toners'],
    ['SKIN1004 Centella Toning Toner', 'skin1004', 'toners'],
    ['Medicube Zero Pore One Day Toner', 'medicube', 'toners'],
    ['Torriden Balanceful Toner', 'torriden', 'toners'],
    ['Isntree Green Tea Fresh Toner', 'isntree', 'toners'],
    ['Round Lab Mugwort Calming Toner', 'round-lab', 'toners'],
    // The rest of a routine.
    ['Beauty of Joseon Green Plum Cleanser', 'beauty-of-joseon', 'cleansers'],
    ['Round Lab Dokdo Cleanser', 'round-lab', 'cleansers'],
    ['Torriden Dive-In Serum', 'torriden', 'serums'],
    ['SKIN1004 Centella Ampoule', 'skin1004', 'serums'],
    ['Isntree Hyaluronic Acid Aqua Gel Cream', 'isntree', 'moisturisers'],
    ['COSRX Advanced Snail 92 Cream', 'cosrx', 'moisturisers'],
    ['Beauty of Joseon Relief Sun Aqua-fresh', 'beauty-of-joseon', 'sunscreens'],
    ['Round Lab Birch Juice Sun Cream', 'round-lab', 'sunscreens'],
    ['Medicube Collagen Jelly Cream', 'medicube', 'moisturisers'],
    ['SKIN1004 Hyalu-Cica Water-fit Sun Serum', 'skin1004', 'sunscreens'],
];

foreach ($extra as $i => [$name, $b, $c]) {
    $price = mt_rand(45, 160) * 100;
    $p = Product::firstOrCreate(['slug' => Str::slug($name)], [
        'name' => $name, 'sku' => 'RP-'.($i + 1), 'brand_id' => $brand($b)->id, 'category_id' => $cat($c)->id,
        'type' => 'simple', 'status' => 'publish', 'is_visible' => true, 'price' => $price,
        'sale_price' => $i % 5 === 0 ? (int) (floor($price * 0.8 / 100) * 100) : null,
        'stock_status' => $i % 11 === 7 ? 'outofstock' : 'instock',
        'total_sales' => mt_rand(5, 900), 'rating' => 0, 'review_count' => 0, 'position' => 100 + $i,
    ]);
    $p->categories()->syncWithoutDetaching([$cat($c)->id]);
}

// Concern tags on every product, two of three, by id.
foreach (Product::query()->orderBy('id')->get() as $i => $p) {
    $p->tags()->sync([$tags[$i % 3], $tags[($i + 1) % 3]]);
}

/* Pictures: 1000px webp + the 200/400/800 copies ImageVariants serves. */
$pal = [[0xF8, 0xBB, 0xD0], [0xBB, 0xDE, 0xFB], [0xC8, 0xE6, 0xC9], [0xFF, 0xE0, 0xB2], [0xE1, 0xBE, 0xE7], [0xB2, 0xEB, 0xF2]];
$draw = function (string $abs, int $w, array $rgb, string $label): void {
    @mkdir(dirname($abs), 0775, true);
    $im = imagecreatetruecolor($w, $w);
    [$r, $g, $b] = $rgb;
    for ($y = 0; $y < $w; $y += 2) {
        $t = $y / $w;
        imagefilledrectangle($im, 0, $y, $w, $y + 2, imagecolorallocate($im,
            (int) ($r * (1 - $t) + 250 * $t), (int) ($g * (1 - $t) + 244 * $t), (int) ($b * (1 - $t) + 246 * $t)));
    }
    imagefilledrectangle($im, (int) ($w * .38), (int) ($w * .2), (int) ($w * .62), (int) ($w * .86), imagecolorallocate($im, 255, 255, 255));
    imagefilledrectangle($im, (int) ($w * .42), (int) ($w * .12), (int) ($w * .58), (int) ($w * .2), imagecolorallocate($im, 60, 50, 56));
    imagestring($im, 5, (int) ($w * .40), (int) ($w * .5), $label, imagecolorallocate($im, 90, 70, 80));
    imagewebp($im, $abs, 82);
    imagedestroy($im);
};

foreach (Product::query()->orderBy('id')->get() as $i => $p) {
    $rel = 'uploads/rp/'.$p->slug.'.webp';
    $draw(public_path($rel), 1000, $pal[$i % 6], (string) $p->id);
    foreach ([200, 400, 800] as $w) {
        $draw(public_path('img-cache/'.$w.'/'.$rel), $w, $pal[$i % 6], (string) $p->id);
    }
    $p->image = '/'.$rel;
    $p->images = [];
    $p->save();
}

echo 'rp-seed: '.Product::count().' products, '.Brand::count().' brands, '.Category::count()." categories\n";
