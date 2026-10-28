<?php

/*
 * Lane OAG: Free gifts for the owner-app preview, on top of tools/mac-seed.php.
 * Gift-only masks and minis, a Lip Tint with Colour variations (Red / Pink /
 * Black), and three spend ranges with main and backup gifts, one of them short
 * on its criteria and one gift running low, so every badge has something to
 * show. Never against a real database.
 */

use App\Services\FreeGifts;
use Illuminate\Support\Facades\DB;

$draw = static function (string $a, string $b, string $k, bool $sheet): string {
    $body = $sheet
        ? '<rect x="58" y="40" width="84" height="120" rx="14" fill="#fff" opacity=".92"/><circle cx="84" cy="84" r="7" fill="'.$k.'"/><circle cx="116" cy="84" r="7" fill="'.$k.'"/><path d="M84 120q16 12 32 0" stroke="'.$k.'" stroke-width="5" fill="none"/>'
        : '<rect x="80" y="74" width="40" height="84" rx="10" fill="#fff" opacity=".92"/><rect x="90" y="54" width="20" height="22" rx="3" fill="'.$k.'"/>';
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200"><defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="'.$a.'"/><stop offset="1" stop-color="'.$b.'"/></linearGradient></defs><rect width="200" height="200" fill="url(#g)"/>'.$body.'</svg>';

    return 'data:image/svg+xml;base64,'.base64_encode($svg);
};

// The preview shows these three ranges only (the schema migration seeds a starter set).
DB::table('free_gift_ranges')->delete();

$brand = DB::table('brands')->insertGetId(['name' => 'Mediheal', 'slug' => 'oag-mediheal', 'created_at' => now(), 'updated_at' => now()]);
$mk = static function (string $slug, string $name, array $o) use ($brand): int {
    return DB::table('products')->insertGetId(array_merge([
        'slug' => 'oag-'.$slug, 'name' => $name, 'brand_id' => $brand, 'type' => 'simple', 'status' => 'publish', 'is_visible' => false,
        'price' => 1500, 'manage_stock' => true, 'stock' => 60, 'stock_status' => 'instock', 'gift_only' => true,
        'created_at' => now(), 'updated_at' => now(),
    ], $o));
};

$g = [
    'tea' => $mk('tea', 'Mediheal Tea Tree Care Sheet Mask', ['gift_kind' => 'mask', 'image' => $draw('#E3F4E6', '#A6D8B0', '#3C8A52', true)]),
    'col' => $mk('col', 'Mediheal Collagen Impact Sheet Mask', ['gift_kind' => 'mask', 'stock' => 12, 'image' => $draw('#FDE7EE', '#F4B3C6', '#C2456B', true)]),
    'ncm' => $mk('ncm', 'Mediheal N.M.F Aquaring Sheet Mask', ['gift_kind' => 'mask', 'stock' => 0, 'stock_status' => 'outofstock', 'image' => $draw('#E2EEFB', '#A9C9F0', '#2E64B5', true)]),
    'ton' => $mk('ton', 'Anua Heartleaf Toner 40 ml (mini)', ['gift_kind' => 'mini', 'stock' => 35, 'image' => $draw('#EEF7E4', '#C7E3A6', '#6E9A5A', false)]),
    'ser' => $mk('ser', 'COSRX Snail Mucin Essence 20 ml (mini)', ['gift_kind' => 'mini', 'stock' => 90, 'image' => $draw('#FCF0DD', '#EFC58A', '#B98545', false)]),
];

// A product with options: Colour Red / Pink / Black, each its own shelf.
$axis = DB::table('attributes')->where('slug', 'colour')->value('id')
    ?? DB::table('attributes')->insertGetId(['slug' => 'colour', 'name' => 'Colour', 'is_variation_axis' => true, 'position' => 0]);
$tint = DB::table('products')->insertGetId(['slug' => 'oag-tint', 'name' => 'Peripera Ink Velvet Lip Tint', 'brand_id' => $brand, 'type' => 'variable', 'status' => 'publish',
    'is_visible' => true, 'price' => 4500, 'manage_stock' => false, 'stock_status' => 'instock', 'image' => $draw('#FBE3EA', '#F2A9BF', '#B5305A', false), 'created_at' => now(), 'updated_at' => now()]);
$vid = [];
foreach ([['Red', '#D7263D', 14], ['Pink', '#F06C9B', 6], ['Black', '#3A3340', 0]] as $i => [$c, $hex, $n]) {
    $val = DB::table('attribute_values')->insertGetId(['attribute_id' => $axis, 'slug' => 'oag-'.strtolower($c), 'name' => $c, 'position' => $i]);
    $vid[$c] = DB::table('product_variants')->insertGetId(['product_id' => $tint, 'sku' => 'OAG-TINT-'.strtoupper($c), 'price' => 4500, 'manage_stock' => true, 'stock' => $n,
        'stock_status' => $n > 0 ? 'instock' : 'outofstock', 'image' => $draw('#FFF1F4', $hex, '#2A1A20', false), 'position' => $i, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('product_variant_attribute_value')->insert(['product_variant_id' => $vid[$c], 'attribute_value_id' => $val]);
}

$range = static function (int $min, ?int $max, string $en, string $ar, bool $on, ?array $needs, array $gifts, int $sort): int {
    $id = DB::table('free_gift_ranges')->insertGetId(['min_fils' => $min * 100, 'max_fils' => $max === null ? null : $max * 100, 'label_en' => $en, 'label_ar' => $ar,
        'enabled' => $on, 'sort' => $sort, 'need_mask' => $needs['mask'] ?? null, 'need_mini' => $needs['mini'] ?? null, 'need_full' => $needs['full'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
    foreach ($gifts as $i => $x) {
        DB::table('free_gift_range_items')->insert(['range_id' => $id, 'product_id' => $x[0], 'variant_id' => $x[4] ?? null, 'quantity' => $x[1], 'kind' => $x[2], 'role' => $x[3], 'sort' => $i, 'created_at' => now(), 'updated_at' => now()]);
    }

    return $id;
};

$range(150, 299, '2 Masks', 'قناعان', true, ['mask' => 2, 'mini' => 0, 'full' => 0], [[$g['tea'], 1, 'mask', 'primary'], [$g['col'], 1, 'mask', 'primary'], [$g['ncm'], 1, 'mask', 'backup']], 0);
$range(300, 499, '3 Masks + 1 Mini Product', '3 أقنعة + منتج صغير واحد', true, ['mask' => 3, 'mini' => 1, 'full' => 0],
    [[$g['tea'], 2, 'mask', 'primary'], [$g['ton'], 1, 'mini', 'primary'], [$tint, 1, 'mini', 'backup', $vid['Pink']]], 1);
$range(500, null, 'Full routine gift', 'هدية روتين كامل', false, null, [[$g['tea'], 3, 'mask', 'primary'], [$g['ser'], 2, 'mini', 'primary']], 2);

$fg = app(FreeGifts::class);
$fg->saveConfig(array_merge($fg->freshConfig(), ['mode' => 'testing', 'on' => true, 'testers' => [['ip' => '127.0.0.1', 'label' => 'Preview']]]));
$fg->compile();

echo "Free gifts seeded: 3 ranges, ".count($g)." gift products, Lip Tint with 3 colours.\n";
