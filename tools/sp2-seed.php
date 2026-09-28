<?php
/*
 * Seed the Lane SP2 preview.
 *
 * Everything the screenshots have to show, and nothing else:
 *
 *   - an owner to sign in as;
 *   - four ordinary products with pictures;
 *   - THREE sets, one in each pricing mode, so the panel can be photographed
 *     in all three without editing anything between shots;
 *   - the hand-priced one ANCHORED and then one of its members marked down, so
 *     the "coming off, automatically" state is real rather than staged;
 *   - one product whose share image was chosen by hand and is a DIFFERENT
 *     picture from its main image, which is the state that must survive a main
 *     image change.
 *
 * MONEY IS INTEGER FILS here too. A fixture in major units would be the one
 * place on this path where a price was not what the column holds.
 */
\App\Models\AdminUser::updateOrCreate(['email' => 'owner@preview.test'], [
    'name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner',
]);

$brand = \App\Models\Brand::updateOrCreate(['slug' => 'anua'], ['name' => 'Anua']);

/* REAL FILES, NOT data: URIs.
 *
 * The first draft of this seed used inline SVG data URIs, the way tools/sp-seed
 * .php does, and every picture in the screenshots was a broken-image icon: the
 * editor runs an image path through its own url() helper, which is right for a
 * path and wrong for a 400-byte data: URI. A screenshot with broken images in
 * it is a screenshot nobody can read. So the preview writes real PNGs into its
 * own web root and records them in `media`, which also gives the Media Library
 * picker something to click -- and the picker is the control the share-image
 * shots have to be driven through. */
$root = getenv('KBB_PUBLIC_PATH') ?: public_path();
@mkdir($root.'/uploads/products', 0775, true);

$pic = function (string $name, string $hex) use ($root): string {
    [$r, $g, $b] = [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    $im = imagecreatetruecolor(600, 600);
    imagefilledrectangle($im, 0, 0, 600, 600, imagecolorallocate($im, $r, $g, $b));
    ob_start();
    imagepng($im);
    file_put_contents($root.'/uploads/products/'.$name, (string) ob_get_clean());

    \App\Models\Media::updateOrCreate(['path' => 'uploads/products/'.$name], [
        'filename' => $name,
        'mime' => 'image/png',
        'size' => (int) filesize($root.'/uploads/products/'.$name),
        'width' => 600, 'height' => 600,
        'alt' => '',
    ]);

    return '/uploads/products/'.$name;
};

$product = function (string $name, string $slug, int $fils, string $image) use ($brand) {
    return \App\Models\Product::updateOrCreate(['slug' => $slug], [
        'name' => $name, 'price' => $fils, 'brand_id' => $brand->id, 'image' => $image,
        'status' => 'publish', 'is_visible' => true, 'stock_status' => 'instock',
        'type' => 'simple', 'manage_stock' => true, 'stock' => 24,
    ]);
};

$toner = $product('Heartleaf 77% Soothing Toner 250ml', 'sp2-heartleaf-toner', 12000, $pic('toner-front.png', 'E0567B'));
$serum = $product('Azelaic Acid 10 Serum 30ml', 'sp2-azelaic-serum', 8000, $pic('serum-packshot.png', '3E8E62'));
$milky = $product('Rice 70 Glow Milky Toner 250ml', 'sp2-milky-toner', 6900, $pic('milky-toner-front.png', 'E0922F'));

/* The share-image case. Its main image is the packshot; its SEO image is a
   different picture, chosen by hand, and a main-image change must not touch it. */
$hand = $product('Ceramide Daily Moisturiser 100ml', 'sp2-ceramide-moisturiser', 12900, $pic('ceramide-packshot.png', '7B6CF0'));
$hand->seo = ['og_image' => $pic('ceramide-share-card.png', 'F59E0B')];
$hand->save();

/* Three more pictures with nothing attached to them, so the Media Library has
   something to choose that is not already in use. */
foreach ([['serum-front-2026.png', 'C7E9D3'], ['serum-lifestyle.png', '2F6F4E'],
          ['ceramide-front-2026.png', '9C8CF5']] as [$name, $hex]) {
    $pic($name, $hex);
}

$members = [[$toner, 1], [$serum, 1]];

$set = function (string $name, string $slug, array $extra) use ($brand, $pic, $members) {
    $row = \App\Models\Product::updateOrCreate(['slug' => $slug], array_merge([
        'name' => $name,
        'type' => 'set',
        'brand_id' => $brand->id,
        'image' => $pic('set-box.png', 'C9587F'),
        'short_description' => 'Two steps, one box.',
        'status' => 'publish', 'is_visible' => true, 'stock_status' => 'instock',
    ], $extra));

    \App\Models\ProductSetItem::where('set_product_id', $row->id)->delete();

    foreach ($members as $i => [$member, $quantity]) {
        \App\Models\ProductSetItem::create([
            'set_product_id' => $row->id,
            'member_product_id' => $member->id,
            'quantity' => $quantity,
            'position' => $i,
        ]);
    }

    \App\Support\SetPricing::forget((int) $row->id);

    return $row->fresh();
};

/* ── 1. THE HAND-TYPED ONE, ANCHORED ───────────────────────────────────────
 *
 * Box worth 12000 + 8000 = 20000 fils. Typed: 18000, on sale at 16000, and the
 * anchor recorded at 20000 the way the editor records it.
 */
$fixed = $set('Glow Starter Set', 'sp2-glow-starter-set', [
    'price' => 18000,
    'sale_price' => 16000,
    'set_price_mode' => \App\Support\SetPricing::MODE_FIXED,
    'set_discount' => null,
    'set_price_basis' => 20000,
]);

/* ── 2 AND 3. THE TWO DERIVED MODES, UNCHANGED BY THIS LANE ────────────────*/
$percent = $set('Night Repair Set', 'sp2-night-repair-set', [
    'set_price_mode' => \App\Support\SetPricing::MODE_PERCENT,
    'set_discount' => 1000,
    'price' => 18000,
]);
$percent->price = \App\Support\SetPricing::derived($percent) ?? 0;
$percent->save();

$amount = $set('Barrier Rescue Set', 'sp2-barrier-rescue-set', [
    'set_price_mode' => \App\Support\SetPricing::MODE_AMOUNT,
    'set_discount' => 2500,
    'price' => 18000,
]);
$amount->price = \App\Support\SetPricing::derived($amount) ?? 0;
$amount->save();

/* ── AND THE MEMBER COMES DOWN, WHICH IS THE WHOLE POINT ───────────────────
 *
 * The toner drops 1500 fils AFTER the anchor was taken. Nothing is saved on any
 * set. The hand-priced one must now charge 16000 − 1500 = 14500, with 18000 −
 * 1500 = 16500 struck through, and say so on the screen.
 */
$toner->price = 10500;
$toner->save();

\App\Support\SetPricing::forget();

$s = \App\Models\Product::find($fixed->id);

echo 'fixed set #', $fixed->id, ' basis ', (int) $s->set_price_basis,
    ' parts ', \App\Support\SetPricing::partsTotal($s),
    ' adjustment ', \App\Support\SetPricing::adjustment($s),
    ' price ', $s->effectivePrice(), ' compare ', $s->compareAtPrice(), " fils\n";
echo 'percent set #', $percent->id, ' price ', \App\Models\Product::find($percent->id)->effectivePrice(), " fils\n";
echo 'amount set #', $amount->id, ' price ', \App\Models\Product::find($amount->id)->effectivePrice(), " fils\n";
echo 'hand-picked share image on product #', $hand->id, ', main image is a different picture', "\n";
echo 'plain member ids: toner ', $toner->id, ' serum ', $serum->id, ' milky ', $milky->id, "\n";
