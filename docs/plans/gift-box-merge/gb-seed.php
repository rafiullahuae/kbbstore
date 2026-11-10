<?php
/*
 * Lane GB preview seed ("bonus gift inside the Free gifts box" previews).
 *
 *   GO_LANE=lane-gb GO_SEED=../docs/plans/gift-box-merge/gb-seed.php sh tools/go-preview.sh 9590
 *
 * Lane GQ's seed (a claim gives Relief Sun x2 on a AED 404 bag), then three
 * more minis in the free gifts range so the box's photo row looks like the
 * live shop's rather than a single mask. Preview database only.
 */
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../../../tools/gq-seed.php';

$range = DB::table('free_gift_ranges')->value('id');
$minis = [
    ['Centella Ampoule Mini 10ml', 'gb-centella-mini', 'Skin1004', 'E4F1DA', '5E8C3A', 'AMPOULE'],
    ['Rice Water Foam Mini 30ml', 'gb-rice-foam-mini', "I'm From", 'FFF6DD', 'C9A36A', 'FOAM'],
    ['Snail Repair Cream Mini 15g', 'gb-snail-cream-mini', 'COSRX', 'F6E3EC', 'A12D4F', 'CREAM'],
];
$root = getenv('KBB_PUBLIC_PATH') ?: public_path();
$sort = 1;
foreach ($minis as [$name, $slug, $brandName, $body, $cap, $label]) {
    $c = fn ($im, $hex) => imagecolorallocate($im, hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)));
    $im = imagecreatetruecolor(600, 600);
    imagefilledrectangle($im, 0, 0, 600, 600, $c($im, 'FFF6F2'));
    imagefilledrectangle($im, 255, 120, 345, 190, $c($im, $cap));
    imagefilledrectangle($im, 220, 190, 380, 500, $c($im, $body));
    imagefilledrectangle($im, 232, 300, 368, 380, $c($im, 'FFFFFF'));
    imagestring($im, 5, 300 - strlen($label) * 4, 332, $label, $c($im, '2A2228'));
    ob_start();
    imagepng($im);
    file_put_contents($root . '/uploads/go/' . $slug . '.png', (string) ob_get_clean());
    $brand = \App\Models\Brand::updateOrCreate(['slug' => \Illuminate\Support\Str::slug($brandName)], ['name' => $brandName]);
    $p = \App\Models\Product::updateOrCreate(['slug' => $slug], [
        'name' => $name, 'price' => 1500, 'brand_id' => $brand->id, 'image' => '/uploads/go/' . $slug . '.png',
        'status' => 'publish', 'is_visible' => true, 'stock_status' => 'instock', 'type' => 'simple',
        'manage_stock' => true, 'stock' => 40, 'gift_kind' => 'mini', 'gift_only' => true,
    ]);
    DB::table('free_gift_range_items')->insert(['range_id' => $range, 'product_id' => $p->id, 'quantity' => 1, 'sort' => $sort++]);
}
app(\App\Services\FreeGifts::class)->compile();
echo 'gb: free gifts range holds ' . DB::table('free_gift_range_items')->count() . " items\n";
