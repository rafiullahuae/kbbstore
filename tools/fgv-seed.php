<?php

declare(strict_types=1);

/*
 * Lane FGV: tools/fg5-seed.php's shop (gifts in TESTING for 127.0.0.1) plus a
 * product with options -- "Glow Lip Tint" in Red, Pink and Black, each with its
 * own photo and shelf -- whose PINK variation is a main gift in every range,
 * as the owner described it. The admin shots pick the Black one by hand.
 *   FG_SEED=fgv-seed.php sh tools/fg-preview.sh 11780 -- never against a real database.
 */

use Illuminate\Support\Facades\DB;

require __DIR__.'/fg5-seed.php';

(require __DIR__.'/../database/migrations/2027_12_27_100000_free_gift_variant.php')->up();

$url = rtrim((string) config('app.url'), '/').'/uploads/gifts/';
$brand = DB::table('brands')->where('name', 'Rom&nd')->value('id')
    ?? DB::table('brands')->insertGetId(['name' => 'Rom&nd', 'slug' => 'fgv-romand', 'created_at' => now(), 'updated_at' => now()]);

$tint = DB::table('products')->insertGetId([
    'slug' => 'fgv-glow-lip-tint', 'name' => 'Glow Lip Tint', 'brand_id' => $brand, 'type' => 'variable',
    'status' => 'publish', 'is_visible' => true, 'price' => 4500, 'manage_stock' => false, 'stock' => null,
    'stock_status' => 'instock', 'image' => $url.'cream.webp', 'created_at' => now(), 'updated_at' => now(),
]);

$axis = DB::table('attributes')->where('slug', 'colour')->value('id')
    ?? DB::table('attributes')->insertGetId(['slug' => 'colour', 'name' => 'Colour', 'is_variation_axis' => true, 'position' => 0, 'created_at' => now(), 'updated_at' => now()]);

$ids = [];
foreach ([['Red', 'b-serum', 12], ['Pink', 'deo', 40], ['Black', 'b-aqua', 6]] as $pos => [$colour, $img, $stock]) {
    $value = DB::table('attribute_values')->where('attribute_id', $axis)->where('slug', strtolower($colour))->value('id')
        ?? DB::table('attribute_values')->insertGetId(['attribute_id' => $axis, 'slug' => strtolower($colour), 'name' => $colour, 'position' => $pos, 'created_at' => now(), 'updated_at' => now()]);
    $ids[$colour] = DB::table('product_variants')->insertGetId([
        'product_id' => $tint, 'sku' => 'FGV-TINT-'.strtoupper($colour), 'price' => 4500, 'manage_stock' => true, 'stock' => $stock,
        'stock_status' => 'instock', 'image' => $url.$img.'.webp', 'position' => $pos, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('product_variant_attribute_value')->insert(['product_variant_id' => $ids[$colour], 'attribute_value_id' => $value]);
}

// The pink one, first in every range, in the range's first type.
foreach (DB::table('free_gift_ranges')->get() as $r) {
    DB::table('free_gift_range_items')->where('range_id', $r->id)->increment('sort');
    $kind = DB::table('free_gift_range_items')->where('range_id', $r->id)->whereNotNull('kind')->orderBy('sort')->value('kind');
    DB::table('free_gift_range_items')->insert(['range_id' => $r->id, 'product_id' => $tint, 'variant_id' => $ids['Pink'], 'quantity' => 1,
        'kind' => $kind, 'role' => 'primary', 'sort' => 0, 'created_at' => now(), 'updated_at' => now()]);
    if ($r->need_mask !== null && $kind !== null) {
        DB::table('free_gift_ranges')->where('id', $r->id)->increment('need_'.$kind);
    }
}

app(\App\Services\FreeGifts::class)->compile();
\Illuminate\Support\Facades\Cache::flush();
echo "fgv: Glow Lip Tint #$tint, Pink #{$ids['Pink']} in every range\n";
