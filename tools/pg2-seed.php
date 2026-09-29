<?php
/*
 * Seed the Lane PG2 preview — a real catalogue row for the showcase family.
 *
 * ── WHAT THE FIXTURE HAS TO BE ABLE TO SHOW ─────────────────────────────────
 *
 * The four treatments are being chosen from a CONTACT SHEET, so the row behind
 * them has to carry every case the card can be in, or the sheet answers a
 * question narrower than the one the owner is asking:
 *
 *   a marked-down product      the struck original in grey and the sale price
 *                              in pink — the pair his screenshot shows
 *   a product at one price     which is most of this catalogue, and which the
 *                              pink rule must NOT repaint
 *   a reviewed product         the rating row, which he asked to be an ADDITION
 *   an unreviewed product      no rating row at all, which this shop has done
 *                              since the round that built the tile
 *   a one-word name            "Toner"
 *   a ninety-character name    so equal card heights can be seen and measured
 *                              rather than asserted
 *   a product with no photo    the gradient placeholder still fills the frame
 *
 * TWELVE products, so a 5-column desktop row is full with a second row under
 * it and a 2-column phone row is six deep.
 *
 * THE WISHLIST IS SWITCHED ON HERE and it is OFF on the shop. The heart is the
 * one part of the owner's card that this shop draws only when Catalogue →
 * Wishlist is on, so a preview taken with it off would be a picture of a card
 * with a piece missing. The shots are taken both ways for that reason and the
 * report says which is which.
 *
 * Money is integer fils, like every other fixture in this repository.
 */

/* Distinguishable inline pictures, so a contact sheet can be READ: twelve
   copies of one gradient prove the grid renders and nothing about the card. No
   network, no media library. */
$pic = function (string $a, string $b, string $tag): string {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="900" height="900">'
        .'<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1">'
        .'<stop offset="0" stop-color="'.$a.'"/><stop offset="1" stop-color="'.$b.'"/>'
        .'</linearGradient></defs><rect width="900" height="900" fill="url(#g)"/>'
        .'<circle cx="450" cy="400" r="210" fill="#ffffff" opacity=".22"/>'
        .'<text x="450" y="470" font-family="sans-serif" font-size="150" font-weight="700"'
        .' fill="#ffffff" text-anchor="middle" opacity=".92">'.$tag.'</text></svg>';

    return 'data:image/svg+xml;base64,'.base64_encode($svg);
};

\App\Models\AdminUser::updateOrCreate(['email' => 'owner@preview.test'], [
    'name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner',
]);

$brand = \App\Models\Brand::updateOrCreate(['slug' => 'beauty-of-joseon'], ['name' => 'Beauty of Joseon']);
$other = \App\Models\Brand::updateOrCreate(['slug' => 'medicube'], ['name' => 'Medicube']);

$category = \App\Models\Category::updateOrCreate(['slug' => 'skincare-sets'], ['name' => 'Skincare sets']);

/*
 * `price` IS THE REGULAR FIGURE AND `sale_price` IS THE MARKDOWN, in that order,
 * and getting it the other way round is how a fixture renders a card with no
 * struck price at all — which is the half of the owner's screenshot the pink
 * rule is about. Product::compareAtPrice() reads `price`, ownPrice() prefers
 * `sale_price` while its window is open, and isOnSale() is the comparison of
 * the two. No window is set here, so the markdown is always live.
 */
$rows = [
    // slug, name, brand, REGULAR fils, MARKDOWN fils (0 = not on sale), rating, reviews, picture
    ['pg2-relief-sun', 'Relief Sun Rice + Probiotics SPF50+', $brand, 21000, 14700, 4.8, 3204, ['#9BE3C4', '#5BC79A', 'A']],
    ['pg2-glow-serum', 'Glow Deep Serum Rice + Alpha Arbutin', $brand, 18300, 0, 4.6, 812, ['#C9B6F5', '#9B7FE8', 'B']],
    ['pg2-toner', 'Toner', $other, 7900, 0, 0.0, 0, ['#FFD9A0', '#F5B45C', 'C']],
    ['pg2-barrier-cream', 'Barrier Repair Cream', $other, 26000, 20800, 4.9, 1580, ['#FFC1AD', '#F58F72', 'D']],
    [
        'pg2-night-cream',
        'Ultra Hydrating Ceramide Barrier Repair Night Cream With Panthenol and Squalane 100ml',
        $brand, 24500, 0, 4.4, 96, ['#A8D8FF', '#6FB4F2', 'E'],
    ],
    ['pg2-cleansing-oil', 'Ginseng Cleansing Oil 210ml', $brand, 14000, 11200, 0.0, 0, ['#FFE7A8', '#F2CE5E', 'F']],
    ['pg2-eye-serum', 'Revive Eye Serum Ginseng + Retinal', $brand, 16900, 0, 4.7, 421, ['#F9B8D0', '#E9749F', 'G']],
    ['pg2-peach-mask', 'Peach 77% Niacinamide Sleeping Mask', $other, 9800, 0, 0.0, 0, ['#FFCBC1', '#F58E87', 'H']],
    ['pg2-booster-set', 'Medicube Booster Set', $other, 82000, 70000, 4.2, 58, ['#D6E9A8', '#A8CC6C', 'I']],
    ['pg2-green-plum', 'Green Plum Refreshing Cleanser 150ml', $brand, 8600, 0, 0.0, 0, ['#B7E4D8', '#6FC3B0', 'J']],
    ['pg2-apricot-gel', 'Apricot Gentle Exfoliating Gel 120ml', $other, 12000, 9400, 4.1, 33, ['#FFD1E8', '#F08CC0', 'K']],
    // NO PICTURE AT ALL: the gradient placeholder is a real state of this card
    // and a sheet that never shows it hides the one tile that can look broken.
    ['pg2-no-photo', 'Centella Ampoule 100ml', $brand, 12600, 0, 4.0, 12, null],
];

foreach ($rows as [$slug, $name, $b, $fils, $markdown, $rating, $reviews, $art]) {
    $product = \App\Models\Product::updateOrCreate(['slug' => $slug], [
        'name' => $name,
        'brand_id' => $b->id,
        'price' => $fils,
        'sale_price' => $markdown > 0 ? $markdown : null,
        'image' => $art === null ? null : $pic($art[0], $art[1], $art[2]),
        'status' => 'publish',
        'is_visible' => true,
        'stock_status' => 'instock',
        'type' => 'simple',
        'rating' => $rating,
        'review_count' => $reviews,
        'created_at' => now()->subYear(),
        'updated_at' => now()->subYear(),
    ]);

    $product->categories()->syncWithoutDetaching([$category->id]);
}

/*
 * ── THE MODULES THE CARD DRAWS ──────────────────────────────────────────────
 *
 * Wishlist ON, because the heart is part of the design being chosen. Product
 * Labels stays OFF, which is how the shop ships: with it on the plugin owns the
 * badge entirely, including deciding there is none, and the sheet would then be
 * a picture of that plugin rather than of the card.
 *
 * `created_at` a year back on every row above is deliberate too — a product
 * created in the last thirty days draws a NEW pill, and twelve NEW pills is a
 * picture of the fixture's age rather than of the catalogue.
 */
app(\App\Services\SettingsService::class)->setModule('wishlist', true);

\App\Models\Setting::flushMap();
\App\Services\SettingsService::forgetMemo();
app(\App\Services\SettingsService::class)->flush();

echo "pg2: ".count($rows)." products in /collections/skincare-sets/\n";
