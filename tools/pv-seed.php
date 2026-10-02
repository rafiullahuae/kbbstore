<?php
/*
 * Seed the Lane PV preview: the owner's Skin1004 twin pack, reproduced.
 *
 * The product he photographed is an imported WooCommerce row whose short
 * description OPENS WITH EMPTY PARAGRAPHS -- `<p>&nbsp;</p>`, which is what the
 * classic editor saves for every blank line typed above the copy. Those fill
 * the blurb's three-line cap, so the page showed the title, a blank band and
 * "Read more ↓". `pv-twin-sun-serum` carries that exact shape; `pv-control`
 * is an ordinary product whose page must not move.
 *
 * Money is integer fils. Pictures are inline SVG so the strip can be read.
 */
$pic = function (string $a, string $b, string $tag): string {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="800" height="800">'
        .'<rect width="800" height="800" fill="#ffffff"/>'
        .'<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1">'
        .'<stop offset="0" stop-color="'.$a.'"/><stop offset="1" stop-color="'.$b.'"/>'
        .'</linearGradient></defs><rect x="200" y="90" width="400" height="620" rx="40" fill="url(#g)"/>'
        .'<text x="400" y="430" font-family="sans-serif" font-size="150" font-weight="700"'
        .' fill="#ffffff" text-anchor="middle" opacity=".85">'.$tag.'</text></svg>';

    return 'data:image/svg+xml;base64,'.base64_encode($svg);
};

\App\Models\AdminUser::updateOrCreate(['email' => 'owner@preview.test'], [
    'name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner',
]);

$brand = \App\Models\Brand::updateOrCreate(['slug' => 'skin1004'], ['name' => 'SKIN1004']);

$twin = \App\Models\Product::updateOrCreate(['slug' => 'pv-twin-sun-serum'], [
    'name' => 'Skin1004 Madagascar Centella Hyalu-Cica Water-Fit Sun Serum (50ml x 2) Twin Pack',
    'brand_id' => $brand->id,
    'price' => 15000,
    'sale_price' => 12600,
    'status' => 'publish',
    'is_visible' => true,
    'stock_status' => 'instock',
    'type' => 'simple',
    'short_description' => "<p>&nbsp;</p>\r\n<p>&nbsp;</p>\r\n<p>A watery, weightless SPF50+ PA++++ sun serum with Madagascar centella and five kinds of hyaluronic acid. It sinks in like a serum, leaves no white cast and sits comfortably under make-up — two full-size 50ml tubes in one pack, one for home and one for the bag.</p>\r\n<p>&nbsp;</p>",
    'image' => $pic('#FFE9A8', '#F2B84B', '1'),
    'images' => [
        $pic('#CDE7D6', '#3E8E62', '2'),
        $pic('#FFE6B8', '#E0922F', '3'),
        $pic('#D9D2F2', '#7B6CF0', '4'),
    ],
]);

$control = \App\Models\Product::updateOrCreate(['slug' => 'pv-control'], [
    'name' => 'Madagascar Centella Toning Toner 210ml',
    'brand_id' => $brand->id,
    'price' => 8900,
    'status' => 'publish',
    'is_visible' => true,
    'stock_status' => 'instock',
    'type' => 'simple',
    'short_description' => 'A low-pH toner with centella extract that calms and preps skin for the next step. Fragrance free, alcohol free, and gentle enough for twice a day.',
    'image' => $pic('#C9E6F2', '#2F84A8', '1'),
    'images' => [
        $pic('#F2D5C9', '#B8613C', '2'),
        $pic('#E3F2C9', '#7A9E35', '3'),
    ],
]);

foreach ([$twin, $control] as $p) {
    \App\Models\Review::where('product_id', $p->id)->delete();
    foreach ([['Layla A.', 5], ['Mariam K.', 5], ['Sara H.', 4], ['Noura S.', 5]] as $i => [$who, $stars]) {
        \App\Models\Review::create([
            'product_id' => $p->id, 'author_name' => $who, 'rating' => $stars,
            'title' => 'Lovely', 'content' => 'No white cast and it layers well.',
            'status' => 'approved', 'verified' => true, 'source' => 'import',
            'created_at' => now()->subDays(40 - $i * 7),
        ]);
    }
}
\App\Support\ProductRating::refresh([$twin->id, $control->id]);

echo "pv seed: pv-twin-sun-serum (leading <p>&nbsp;</p> x2, on sale -16%, 4 shots), pv-control\n";
