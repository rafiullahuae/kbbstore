<?php
/*
 * Seed the Lane FBT preview: tools/pdp-seed.php's catalogue (an Anua Heartleaf
 * toner with pictures, a set, reviews), plus what "Frequently bought together"
 * draws from -- a set that holds the toner, two more products of the Heartleaf
 * range, an Anua eye cream from another range, and bigger-selling products of
 * another brand on the moisturiser, eye-care, serum and sunscreen shelves, so a
 * missing "matching first" would show them instead. The section is switched on.
 */
require __DIR__.'/pdp-seed.php';

$cat = function (string $name) {
    return \App\Models\Category::query()->where('name', $name)->first()
        ?? \App\Models\Category::create(['slug' => \Illuminate\Support\Str::slug($name).'-fbt', 'name' => $name]);
};

$toners = $cat('Toners');
$moist = $cat('Moisturizers');
$eye = $cat('Eye Care');
$serum = $cat('Face Serums');
$sun = $cat('Sunscreens');
$oil = $cat('Cleansing Oils');
$sets = $cat('Skincare Sets');

$toner->categories()->syncWithoutDetaching([$toners->id]);
$toner->forceFill(['category_id' => $toners->id])->save();

$other = \App\Models\Brand::updateOrCreate(['slug' => 'fbt-other'], ['name' => 'Round Lab']);

$make = function (string $slug, string $name, $brand, $category, int $sales, string $bg, string $mark, array $extra = []) use ($shot) {
    $p = \App\Models\Product::updateOrCreate(['slug' => $slug], array_merge([
        'name' => $name, 'brand_id' => $brand->id, 'price' => 8900, 'status' => 'publish',
        'is_visible' => true, 'stock_status' => 'instock', 'type' => 'simple', 'total_sales' => $sales,
        'manage_stock' => false, 'category_id' => $category?->id,
        'image' => $shot($bg, '#FFFFFF', '#555555', $mark),
    ], $extra));

    if ($category !== null) {
        $p->categories()->syncWithoutDetaching([$category->id]);
    }

    return $p;
};

$oilP = $make('fbt-heartleaf-oil', 'Heartleaf Pore Control Cleansing Oil 200ml', $anua, $oil, 900, '#EAF4EE', 'HO');
$cream = $make('fbt-heartleaf-cream', 'Heartleaf 70% Intense Calming Cream 50ml', $anua, $moist, 800, '#EEF6F0', 'HC');
$make('fbt-peach-eye', 'Peach 77 Niacin Eye Cream 30ml', $anua, $eye, 700, '#FCEFE8', 'PE');
$make('fbt-other-moist', '1025 Dokdo Lotion 200ml', $other, $moist, 990000, '#E8F0FA', 'DL');
$make('fbt-other-eye', 'Birch Juice Eye Cream 30ml', $other, $eye, 980000, '#EAF2FB', 'BE');
$make('fbt-other-serum', 'Mugwort Calming Serum 50ml', $other, $serum, 970000, '#EDF5EA', 'MS');
$make('fbt-other-sun', 'Birch Moisture Sunscreen SPF45', $other, $sun, 960000, '#FFF6DE', 'BS');

$duo = $make('fbt-heartleaf-duo', 'Heartleaf Soothing Duo Set', $anua, $sets, 600, '#F2EAF7', 'SET', ['type' => 'set', 'price' => 15900]);
\App\Models\ProductSetItem::query()->where('set_product_id', $duo->id)->delete();
\App\Models\ProductSetItem::create(['set_product_id' => $duo->id, 'member_product_id' => $toner->id, 'quantity' => 1, 'position' => 0]);
\App\Models\ProductSetItem::create(['set_product_id' => $duo->id, 'member_product_id' => $cream->id, 'quantity' => 1, 'position' => 1]);

app(\App\Services\BuyTogetherSettings::class)->save(['on' => true]);
\Illuminate\Support\Facades\Cache::flush();

echo "fbt seed done\n";
