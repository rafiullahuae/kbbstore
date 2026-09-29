<?php
/*
 * Seed the Lane SG preview.
 *
 * WHAT THE SHOTS HAVE TO SHOW, AND NOTHING ELSE:
 *
 *   - four ordinary products with pictures, so a set is photographed BESIDE
 *     ordinary products rather than on its own;
 *   - THREE sets, one in each pricing mode, all in one category so one /shop
 *     grid carries all three;
 *   - `products.price` written on each of them the way
 *     Admin\ProductEditorApiController writes it at save time -- because the
 *     defect this lane fixes is that the grids read THAT column, and a fixture
 *     that does not have a stale one in it cannot show the defect;
 *   - and then a member repriced AFTERWARDS, with nothing saved on any set,
 *     which is what makes the stale column and the derived price diverge.
 *
 * MONEY IS INTEGER FILS. Every figure below is the column's own value.
 */
\App\Models\AdminUser::updateOrCreate(['email' => 'owner@preview.test'], [
    'name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner',
]);

$brand = \App\Models\Brand::updateOrCreate(['slug' => 'anua'], ['name' => 'Anua']);
$category = \App\Models\Category::updateOrCreate(['slug' => 'sets'], ['name' => 'Sets']);

$root = getenv('KBB_PUBLIC_PATH') ?: public_path();
@mkdir($root.'/uploads/products', 0775, true);

/* Real PNG files, not data: URIs — tools/sp2-seed.php records why a data URI
   photographs as a broken-image icon on these pages. */
$pic = function (string $name, string $hex) use ($root): string {
    [$r, $g, $b] = [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    $im = imagecreatetruecolor(600, 600);
    imagefilledrectangle($im, 0, 0, 600, 600, imagecolorallocate($im, $r, $g, $b));
    ob_start();
    imagepng($im);
    file_put_contents($root.'/uploads/products/'.$name, (string) ob_get_clean());

    return '/uploads/products/'.$name;
};

$product = function (string $name, string $slug, int $fils, string $image) use ($brand, $category) {
    $row = \App\Models\Product::updateOrCreate(['slug' => $slug], [
        'name' => $name, 'price' => $fils, 'brand_id' => $brand->id, 'image' => $image,
        'status' => 'publish', 'is_visible' => true, 'stock_status' => 'instock',
        'type' => 'simple', 'manage_stock' => true, 'stock' => 24,
    ]);
    $row->categories()->syncWithoutDetaching([$category->id]);

    return $row;
};

$toner = $product('Heartleaf 77% Soothing Toner 250ml', 'sg-heartleaf-toner', 12000, $pic('sg-toner.png', 'E0567B'));
$serum = $product('Azelaic Acid 10 Serum 30ml', 'sg-azelaic-serum', 8000, $pic('sg-serum.png', '3E8E62'));
$product('Rice 70 Glow Milky Toner 250ml', 'sg-milky-toner', 6900, $pic('sg-milky.png', 'E0922F'));
$product('Ceramide Daily Moisturiser 100ml', 'sg-ceramide', 12900, $pic('sg-ceramide.png', '7B6CF0'));

$members = [[$toner, 1], [$serum, 1]];   // parts total 20000 fils at this moment

$set = function (string $name, string $slug, array $extra) use ($brand, $category, $pic, $members) {
    $row = \App\Models\Product::updateOrCreate(['slug' => $slug], array_merge([
        'name' => $name,
        'type' => 'set',
        'brand_id' => $brand->id,
        'image' => $pic('sg-box-'.substr(md5($slug), 0, 6).'.png', 'C9587F'),
        'short_description' => 'Two steps, one box.',
        'status' => 'publish', 'is_visible' => true, 'stock_status' => 'instock',
    ], $extra));

    $row->categories()->syncWithoutDetaching([$category->id]);

    \App\Models\ProductSetItem::where('set_product_id', $row->id)->delete();

    foreach ($members as $i => [$member, $quantity]) {
        \App\Models\ProductSetItem::create([
            'set_product_id' => $row->id,
            'member_product_id' => $member->id,
            'quantity' => $quantity,
            'position' => $i,
        ]);
    }

    \App\Support\SetPricing::forget();

    return $row->fresh();
};

/* ── 1 AND 2. THE TWO DERIVED MODES ────────────────────────────────────────
 *
 * `products.price` is written from the rule at save time, exactly as
 * Admin\ProductEditorApiController::applySetPricing() does it. That is the
 * SNAPSHOT the grids were reading.
 */
$percent = $set('Night Repair Set', 'sg-night-repair-set', [
    'set_price_mode' => \App\Support\SetPricing::MODE_PERCENT,
    'set_discount' => 1000,          // 10.00%
    'price' => 0,
]);
$percent->price = \App\Support\SetPricing::derived($percent) ?? 0;   // 18000
$percent->save();

$amount = $set('Barrier Rescue Set', 'sg-barrier-rescue-set', [
    'set_price_mode' => \App\Support\SetPricing::MODE_AMOUNT,
    'set_discount' => 2500,          // AED 25.00 off the parts total
    'price' => 0,
]);
$amount->price = \App\Support\SetPricing::derived($amount) ?? 0;     // 17500
$amount->save();

/* ── 3. THE HAND-TYPED ONE, ANCHORED ──────────────────────────────────────*/
$fixed = $set('Glow Starter Set', 'sg-glow-starter-set', [
    'price' => 18000,
    'sale_price' => 16000,
    'set_price_mode' => \App\Support\SetPricing::MODE_FIXED,
    'set_discount' => null,
    'set_price_basis' => 20000,
]);

/* ── AND THE MEMBER COMES DOWN AFTERWARDS ─────────────────────────────────
 *
 * The toner drops 1500 fils. Nothing is saved on any set, which is the feature
 * and also the moment the three `products.price` columns above go stale.
 */
$toner->price = 10500;
$toner->save();

\App\Support\SetPricing::forget();

foreach ([$percent, $amount, $fixed] as $row) {
    $s = \App\Models\Product::find($row->id);
    echo str_pad($s->slug, 28), ' column price ', str_pad((string) (int) $s->getAttributes()['price'], 6),
        ' parts ', str_pad((string) \App\Support\SetPricing::partsTotal($s), 6),
        ' charged ', str_pad((string) $s->effectivePrice(), 6),
        ' compare ', (string) $s->compareAtPrice(), " fils\n";
}

echo 'members: toner ', $toner->id, ' (now 10500) serum ', $serum->id, " (8000)\n";
