<?php
/*
 * Seed the Lane FBT3 preview: tools/fbt-seed.php's catalogue, plus the live
 * Dr. Althea 345 row the owner photographed on 14 November 2026 -- the cream,
 * "Dr. Althea 345 Relief Cream + Mist Spray Set" with its contents NOT
 * recorded (a plain product, as every set on kbeautybliss.com is; its
 * description lists what is inside, worded as the live one is), the mist
 * spray, the 0.1% Gentle Retinol Serum, and the rest of the brand's shelf.
 *
 *   KBB_FBT_SEED=tools/fbt3-seed.php sh tools/fbt-preview.sh
 *   then /product/fbt3-345-relief-cream/
 */
require __DIR__.'/fbt-seed.php';

$althea = \App\Models\Brand::updateOrCreate(['slug' => 'dr-althea'], ['name' => 'Dr. Althea']);
$c3 = fn (string $name) => \App\Models\Category::query()->where('name', $name)->first()
    ?? \App\Models\Category::create(['slug' => \Illuminate\Support\Str::slug($name).'-fbt3', 'name' => $name]);

$mk = function (string $slug, string $name, $category, int $sales, string $bg, string $mark, array $extra = []) use ($shot, $althea) {
    $p = \App\Models\Product::updateOrCreate(['slug' => $slug], array_merge([
        'name' => $name, 'brand_id' => $althea->id, 'price' => 9900, 'status' => 'publish',
        'is_visible' => true, 'stock_status' => 'instock', 'type' => 'simple', 'total_sales' => $sales,
        'manage_stock' => false, 'category_id' => $category->id,
        'image' => $shot($bg, '#FFFFFF', '#555555', $mark),
    ], $extra));
    $p->categories()->syncWithoutDetaching([$category->id]);

    return $p;
};

$desc = '<p><strong>What’s Included in the Set:</strong></p><ul>'
    .'<li><strong>Dr Althea 345 Relief Cream:</strong> A lightweight, soothing gel-cream enriched with Niacinamide, Panthenol, and Centella Asiatica.</li>'
    .'<li><strong>Dr.Althea – 345 Relief Cream Mist Spray:</strong> The Dr. Althea 345 Relief Cream Mist features a dual-layer formula.</li>'
    .'</ul><p><strong>Key Benefits:</strong></p><ul><li>Rapid barrier repair.</li></ul>';

$mk('fbt3-345-relief-cream', 'Dr.Althea - 345 Relief Cream', $c3('Moisturizers'), 999990, '#EAF2F6', '345C', ['price' => 9900]);
$mk('fbt3-345-cream-mist-set', 'Dr. Althea 345 Relief Cream + Mist Spray Set', $c3('Skincare Sets'), 999980, '#F2EAF7', 'SET', ['price' => 17900, 'description' => $desc]);
$mk('fbt3-345-cream-mist', 'Dr.Althea - 345 Relief Cream Mist Spray', $c3('Toners'), 999970, '#EEF6F0', 'MIST');
$mk('fbt3-retinol', 'Dr. Althea - 0.1% Gentle Retinol Serum', $c3('Face Serums'), 999960, '#FCEFE8', 'RET');
$mk('fbt3-mist-mini', 'Dr. Althea - 345 Relief Cream Mist Mini', $c3('Toners'), 999950, '#E8F0FA', 'MINI', ['price' => 6500]);
$mk('fbt3-eye', 'Dr. Althea - Pro Lab Eye Cream', $c3('Eye Care'), 999940, '#FFF6DE', 'EYE');
$mk('fbt3-sun', 'Dr. Althea - Pro Lab Sunscreen SPF50', $c3('Sunscreens'), 999930, '#EDF5EA', 'SUN');

\Illuminate\Support\Facades\Cache::flush();

echo "fbt3 seed done\n";
