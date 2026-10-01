<?php
/*
 * Seed for the Lane PL preview: "Set shown first, by brand" on Store -> Site
 * Search -> Sets in search, and the search box honouring it.   (Lane PL)
 *
 *   Anua       three fitting sets: two of its own and a Medicube set holding
 *              an Anua toner. The CHOSEN one is the least-selling of the
 *              three, "Anua Toner Duo", so neither "best" nor luck explains it.
 *   Medicube   two fitting sets of its own plus the mixed one; no choice.
 *   COSRX      one set.
 *   Dr.Althea  products, no set -- so no row in the admin list.
 *
 * Money is integer fils.
 */
\App\Models\AdminUser::updateOrCreate(['email' => 'owner@preview.test'], [
    'name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner',
]);

$root = getenv('KBB_PUBLIC_PATH') ?: public_path();
@mkdir($root.'/uploads/products', 0775, true);

$pic = function (string $name, string $hex) use ($root): string {
    [$r, $g, $b] = [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    $im = imagecreatetruecolor(300, 300);
    imagefilledrectangle($im, 0, 0, 300, 300, imagecolorallocate($im, $r, $g, $b));
    ob_start();
    imagepng($im);
    file_put_contents($root.'/uploads/products/'.$name, (string) ob_get_clean());

    return '/uploads/products/'.$name;
};

$brand = fn (string $name, string $slug) => \App\Models\Brand::updateOrCreate(['slug' => $slug], ['name' => $name]);
$anua = $brand('Anua', 'anua');
$medicube = $brand('Medicube', 'medicube');
$cosrx = $brand('COSRX', 'cosrx');
$althea = $brand('Dr.Althea', 'dr-althea');

$product = function (string $name, string $slug, $brand, int $fils, int $sales, string $hex, string $type = 'simple') use ($pic) {
    return \App\Models\Product::updateOrCreate(['slug' => $slug], [
        'name' => $name, 'price' => $fils, 'brand_id' => $brand->id, 'type' => $type,
        'image' => $pic($slug.'.png', $hex), 'status' => 'publish', 'is_visible' => true,
        'stock_status' => 'instock', 'total_sales' => $sales,
    ]);
};

$toner = $product('Anua - Heartleaf 77% Soothing Toner 250ml', 'pl-anua-toner', $anua, 9500, 900, 'BFE3C0');
$serum = $product('Anua - Niacinamide 10% + TXA 4% Serum', 'pl-anua-serum', $anua, 8900, 850, 'D9EFD6');
$foam = $product('Anua - Heartleaf Quercetinol Pore Deep Cleansing Foam', 'pl-anua-foam', $anua, 6500, 700, 'E8F5E4');
$booster = $product('Medicube - AGE-R Booster Pro', 'pl-med-booster', $medicube, 75000, 650, 'E0567B');
$pad = $product('Medicube - Zero Pore Pad 2.0', 'pl-med-pad', $medicube, 7900, 600, 'F2A1B8');
$snail = $product('COSRX - Advanced Snail 96 Mucin Power Essence', 'pl-cosrx-snail', $cosrx, 6900, 550, 'F3E3C4');
$product('Dr.Althea - 345 Relief Cream', 'pl-althea-cream', $althea, 9900, 500, 'C7D8F0');

$set = function (string $name, string $slug, $brand, array $members, int $sales, string $hex, int $fils) use ($product) {
    $s = $product($name, $slug, $brand, $fils, $sales, $hex, 'set');
    \App\Models\ProductSetItem::where('set_product_id', $s->id)->delete();

    foreach ($members as $i => $m) {
        \App\Models\ProductSetItem::create(['set_product_id' => $s->id, 'member_product_id' => $m->id, 'quantity' => 1, 'position' => $i]);
    }

    return $s;
};

$set('Anua Best Seller Set', 'pl-anua-best-set', $anua, [$toner, $serum], 50, 'A8D8A8', 16500);
$duo = $set('Anua Toner Duo', 'pl-anua-toner-duo', $anua, [$toner, $foam], 10, '8FCB9B', 14500);
$set('Medicube x Anua Glow Mix Set', 'pl-glow-mix-set', $medicube, [$booster, $toner], 30, 'E89AB4', 79900);
$set('Medicube Zero Pore Set', 'pl-med-pore-set', $medicube, [$pad], 20, 'D9708F', 12900);
$set('COSRX Snail Routine Set', 'pl-cosrx-set', $cosrx, [$snail], 15, 'E9C98B', 11900);

\App\Support\SearchSetChoices::save([$anua->id => $duo->id]);
app(\App\Services\HeaderSettings::class)->save(['search_sets_first' => true, 'search_sets_pick' => 'random']);

echo 'Seeded: '.count(\App\Support\SearchSetChoices::adminRows())." brands with sets; Anua's choice = Anua Toner Duo\n";
