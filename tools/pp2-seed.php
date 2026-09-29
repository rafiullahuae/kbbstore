<?php
/*
 * Seed the Lane PP2 preview.
 *
 * ── WHY THIS EXISTS BESIDE tools/pp-seed.php ───────────────────────────────
 *
 * Lane PP's fixture gives every set exactly ONE image, so `$shotCount` is 1 on
 * every set page it can produce and partials/product-gallery.blade.php's
 * `@if ($shotCount > 1)` is false. Its six screenshots therefore show no
 * thumbnail strip on a set -- which reads as "a set cannot carry gallery
 * images" and is not what the code says.
 *
 * So the set below carries a MAIN IMAGE AND THREE GALLERY IMAGES, which is the
 * fixture that can tell those two apart: if the strip draws, the absence was
 * the fixture; if it does not, it is a defect in the gallery and a test goes
 * with the fix.
 *
 * It also carries THREE MEMBERS, ALL WITH PICTURES, because the set contents
 * list in the buy column is what a set page is mostly made of and a re-shoot
 * that shows none of it is not a re-shoot.
 *
 * MONEY IS INTEGER FILS, like every other fixture in this repository.
 */

/* Distinguishable inline SVG pictures, so the strip can be READ in a
   screenshot: four visibly different shots means "these are four shots", where
   four copies of one gradient means nothing. No network, no media library. */
$pic = function (string $a, string $b, string $tag): string {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="800" height="800">'
        .'<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1">'
        .'<stop offset="0" stop-color="'.$a.'"/><stop offset="1" stop-color="'.$b.'"/>'
        .'</linearGradient></defs><rect width="800" height="800" fill="url(#g)"/>'
        .'<text x="400" y="430" font-family="sans-serif" font-size="150" font-weight="700"'
        .' fill="#ffffff" text-anchor="middle" opacity=".85">'.$tag.'</text></svg>';

    return 'data:image/svg+xml;base64,'.base64_encode($svg);
};

\App\Models\AdminUser::updateOrCreate(['email' => 'owner@preview.test'], [
    'name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner',
]);

$brand = \App\Models\Brand::updateOrCreate(['slug' => 'anua'], ['name' => 'Anua']);

$member = function (string $name, string $slug, int $fils, array $colours, string $tag) use ($pic, $brand) {
    return \App\Models\Product::updateOrCreate(['slug' => $slug], [
        'name' => $name,
        'price' => $fils,
        'brand_id' => $brand->id,
        'image' => $pic($colours[0], $colours[1], $tag),
        'status' => 'publish',
        'is_visible' => true,
        'stock_status' => 'instock',
        'type' => 'simple',
    ]);
};

/* ── THE SET: a main shot, three more in the strip, three pictured members ── */

$set = \App\Models\Product::updateOrCreate(['slug' => 'pp2-glow-ritual-set'], [
    'name' => 'Glow Ritual Set',
    'brand_id' => $brand->id,
    'price' => 18500,
    'status' => 'publish',
    'is_visible' => true,
    'stock_status' => 'instock',
    'type' => 'set',
    'short_description' => 'Three steps, one box — cleanse, tone and seal in about four minutes.',
    /* THE MAIN SHOT, and then the strip. Store\ProductController::gallery()
       merges [image] with images and de-duplicates, so this is a four-shot
       gallery and `$shotCount` is 4. */
    'image' => $pic('#F7C6D4', '#E0567B', '1'),
    'images' => [
        $pic('#CDE7D6', '#3E8E62', '2'),
        $pic('#FFE6B8', '#E0922F', '3'),
        $pic('#D9D2F2', '#7B6CF0', '4'),
    ],
]);

$members = [
    $member('Heartleaf Cleansing Foam', 'pp2-member-cleanser', 6900, ['#C9E6F2', '#2F84A8'], 'A'),
    $member('Rice Toner 150ml', 'pp2-member-toner', 7900, ['#F2D5C9', '#B8613C'], 'B'),
    $member('Ginseng Eye Serum', 'pp2-member-serum', 8900, ['#E3F2C9', '#7A9E35'], 'C'),
];

\App\Models\ProductSetItem::where('set_product_id', $set->id)->delete();

foreach ($members as $i => $m) {
    \App\Models\ProductSetItem::create([
        'set_product_id' => $set->id,
        'member_product_id' => $m->id,
        'quantity' => 1,
        'position' => $i,
    ]);
}

/* ── THE CONTROL: an ordinary product, same four-shot gallery ───────────────
   The page that must not have moved at all. It carries a gallery of the same
   depth so the strip can be compared between the two kinds of product. */
\App\Models\Product::updateOrCreate(['slug' => 'pp2-rice-toner'], [
    'name' => 'Rice Toner 150ml',
    'brand_id' => $brand->id,
    'price' => 7900,
    'status' => 'publish',
    'is_visible' => true,
    'stock_status' => 'instock',
    'type' => 'simple',
    'short_description' => 'A milky rice toner for dull, uneven skin. Fragrance free.',
    'image' => $pic('#F2C9E8', '#A83C8C', '1'),
    'images' => [
        $pic('#C9D2F2', '#3C4FA8', '2'),
        $pic('#F2E8C9', '#A89A3C', '3'),
        $pic('#D2F2C9', '#4CA83C', '4'),
    ],
]);

/* ── ARABIC ON, AND THE MIRROR WITH IT ─────────────────────────────────────
 *
 * Two switches, not one, and the second is the one that is easy to miss.
 * App\Support\Locale::enabled() decides whether the /ar prefix is stripped at
 * all -- without it the router 404s and there is no Arabic page to photograph.
 * Locale::direction() then reads a SEPARATE setting and answers 'ltr' without
 * it, so with only the first line every mirrored shot comes back
 * <html lang="ar" dir="ltr"> and proves nothing about a logical property.
 *
 * Both are PREVIEW fixture. Nothing in this lane's diff seeds either, and the
 * shop's own defaults stay off. */
app(\App\Services\SettingsService::class)->set(\App\Support\Locale::SETTING_ENABLED, true);
app(\App\Services\SettingsService::class)->set(\App\Support\Locale::SETTING_RTL, true);

echo "pp2 seed: set=pp2-glow-ritual-set (4 shots, 3 members)  product=pp2-rice-toner (4 shots)  arabic+mirror on\n";
