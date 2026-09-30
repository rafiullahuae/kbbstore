<?php
/*
 * Seed the Lane CARD preview — a catalogue row built to BREAK equal height.
 *
 * ── WHY IT IS NOT tools/pg2-seed.php ────────────────────────────────────────
 *
 * That fixture is twelve products and Lane PG2 measured all twelve at 454px on
 * a desktop and 392px on a phone, which reads as "every card in this grid is
 * the same height". Re-measured on this branch before a line was changed, it
 * still does. But the grid orders by name (ShopController::applyDefaultOrder —
 * featured, then `products.name`), and under that order EVERY ROW of that
 * fixture happens to contain at least one reviewed product, at BOTH widths:
 *
 *   1280, 5 columns   Apricot✓ Barrier✓ Centella✓ Ginseng✗ Glow✓
 *                     GreenPlum✗ Medicube✓ Peach✗ Relief✓ Revive✓
 *                     Toner✗ Ultra✓
 *   390, 2 columns    (Apricot✓ Barrier✓) (Centella✓ Ginseng✗)
 *                     (Glow✓ GreenPlum✗) (Medicube✓ Peach✗)
 *                     (Relief✓ Revive✓) (Toner✗ Ultra✓)
 *
 * `height:100%` equalises a card against the OTHER CARDS IN ITS OWN ROW and
 * says nothing about the row above it. So a fixture with a reviewed product in
 * every row cannot tell "equal" from "equal by accident" — it measures twelve
 * identical numbers whether or not the rating row is reserved.
 *
 * ── WHAT THIS FIXTURE DOES INSTEAD ──────────────────────────────────────────
 *
 * The names are chosen so that ALPHABETICAL ORDER — which is the order the shop
 * actually uses — puts five unreviewed products on one desktop row and two of
 * them on each of two phone rows:
 *
 *   1280, 5 columns   row 1  Barrier✓ Centella✓ Glow✓ Medicube✓ Relief✓
 *                     row 2  Rice✗ Sagging✗ Salicylic✗ Snail✗ Squalane✗   ← ALL
 *                     row 3  Toner✗ Ultra✓
 *   390, 2 columns    (Barrier✓ Centella✓) (Glow✓ Medicube✓) (Relief✓ Rice✗)
 *                     (Sagging✗ Salicylic✗)  ← ALL
 *                     (Snail✗ Squalane✗)     ← ALL
 *                     (Toner✗ Ultra✓)
 *
 * With the rating row unreserved, row 2 is shorter than rows 1 and 3 by the
 * height of that row plus its margin, on a desktop and on a phone. That is the
 * defect, and it is the whole reason this file exists rather than the other one.
 *
 * `Toner` and the eighty-five-character night cream are still side by side in
 * the last row at BOTH widths (indices 11 and 12 of 12 land together whether
 * the row is five wide or two), which is the picture the owner's sentence about
 * long names asks for — and one of the pair carries 96 reviews and the other
 * none, so the same frame shows the rating row appearing and disappearing.
 *
 * ── THE OTHER STATES A CARD CAN BE IN, ALL PRESENT ──────────────────────────
 *
 *   a marked-down product      the struck original and the sale price
 *   a product at one price     which the pink sale rule must NOT repaint
 *   a one-word name            `Toner`
 *   an 85-character name       two lines' worth and then some
 *   a 129-character name       four lines' worth, for the clamp
 *   a product with no photo    the gradient placeholder fills the frame
 *   a long category name       the eyebrow, when it is switched back on
 *
 * Money is integer fils, like every other fixture in this repository.
 */

/* Distinguishable inline pictures, so a contact sheet can be READ. No network,
   no media library. Copied in spirit from tools/pg2-seed.php. */
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

$joseon = \App\Models\Brand::updateOrCreate(['slug' => 'beauty-of-joseon'], ['name' => 'Beauty of Joseon']);
$medicube = \App\Models\Brand::updateOrCreate(['slug' => 'medicube'], ['name' => 'Medicube']);
$cosrx = \App\Models\Brand::updateOrCreate(['slug' => 'cosrx'], ['name' => 'COSRX']);

/* A DELIBERATELY LONG CATEGORY NAME. The eyebrow is the archive's category on
   /shop and a category archive, and it has no clamp of its own in the shipped
   sheet — a name this long wrapped to two lines inside a 199px text column,
   which is a second way for one card to be taller than its neighbour. */
$category = \App\Models\Category::updateOrCreate(
    ['slug' => 'skincare-sets'],
    ['name' => 'Skincare Sets and Barrier Repair Routines']
);

/*
 * `price` IS THE REGULAR FIGURE AND `sale_price` IS THE MARKDOWN, in that
 * order. Product::compareAtPrice() reads `price`, ownPrice() prefers
 * `sale_price` while its window is open, and isOnSale() is the comparison of
 * the two. No window is set here, so every markdown is live.
 *
 * `created_at` a year back on every row: a product created in the last thirty
 * days draws a NEW pill, and twelve NEW pills is a picture of the fixture's age
 * rather than of the catalogue.
 */
$rows = [
    // slug, name, brand, REGULAR fils, MARKDOWN fils (0 = none), rating, reviews, picture
    ['card-barrier-cream', 'Barrier Repair Cream', $medicube, 26000, 20800, 4.9, 1580, ['#FFC1AD', '#F58F72', 'A']],
    // NO PICTURE AT ALL: the gradient placeholder is a real state of this card
    // and a sheet that never shows it hides the one tile that can look broken.
    ['card-centella', 'Centella Ampoule 100ml', $joseon, 12600, 0, 4.0, 12, null],
    ['card-glow-serum', 'Glow Deep Serum Rice + Alpha Arbutin', $joseon, 18300, 0, 4.6, 812, ['#C9B6F5', '#9B7FE8', 'B']],
    ['card-booster-set', 'Medicube Booster Set', $medicube, 82000, 70000, 4.2, 58, ['#D6E9A8', '#A8CC6C', 'C']],
    ['card-relief-sun', 'Relief Sun Rice + Probiotics SPF50+', $joseon, 21000, 14700, 4.8, 3204, ['#9BE3C4', '#5BC79A', 'D']],

    // ── THE ROW WITH NO REVIEWS IN IT AT ALL ────────────────────────────────
    ['card-rice-water', 'Rice Water Bright Cleansing Oil', $cosrx, 19900, 0, 0.0, 0, ['#FFE7A8', '#F2CE5E', 'E']],
    ['card-sagging-care', 'Sagging Care Firming Cream', $medicube, 34000, 25500, 0.0, 0, ['#F9B8D0', '#E9749F', 'F']],
    ['card-salicylic', 'Salicylic Acid Daily Gentle Cleanser 150ml', $cosrx, 8600, 0, 0.0, 0, ['#B7E4D8', '#6FC3B0', 'G']],
    // 129 characters: four lines' worth of name in a 199px column, which is what
    // the two-line clamp has to survive without moving the button.
    [
        'card-snail-mucin',
        'Snail Mucin 96% Power Repairing Essence Concentrate With Hyaluronic Acid For Dry Dehydrated Skin Barrier Recovery 100ml Twin Pack',
        $cosrx, 11000, 0, 0.0, 0, ['#A8D8FF', '#6FB4F2', 'H'],
    ],
    ['card-squalane', 'Squalane Oil Cleanser 200ml', $cosrx, 9800, 0, 0.0, 0, ['#FFCBC1', '#F58E87', 'I']],

    // ── THE PAIR THE OWNER'S SENTENCE IS ABOUT, LAST AND TOGETHER ───────────
    ['card-toner', 'Toner', $medicube, 7900, 0, 0.0, 0, ['#FFD9A0', '#F5B45C', 'J']],
    [
        'card-night-cream',
        'Ultra Hydrating Ceramide Barrier Repair Night Cream With Panthenol and Squalane 100ml',
        $joseon, 24500, 0, 4.4, 96, ['#D9C7F5', '#A98BE0', 'K'],
    ],
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
 * Wishlist ON, because the heart is part of the card the owner chose and a
 * preview taken with it off is a picture of a card with a piece missing. It
 * ships OFF, so the shots are taken both ways and the report says which is
 * which — tools/pg2-wishlist.php takes KBB_WISHLIST=1 or 0.
 *
 * Product Labels stays OFF, which is how the shop ships: with it on the plugin
 * owns the badge entirely, including deciding there is none.
 */
app(\App\Services\SettingsService::class)->setModule('wishlist', true);

\App\Models\Setting::flushMap();
\App\Services\SettingsService::forgetMemo();
app(\App\Services\SettingsService::class)->flush();

echo 'card: '.count($rows)." products in /collections/skincare-sets/ and /shop/\n";

/*
 * ── AND A GRID SECTION, WHICH IS THE FIFTH PRODUCT GRID ON THIS SHOP ────────
 *
 * resources/views/partials/home/grid-section.blade.php opens a `.kbb-pgrid`
 * too, and it is NOT one of the four DefaultCardStyleTest enumerates: that
 * scanner matches `class="[^"]*\bkbb-pgrid\b` and this template writes
 * `class="{{ $gsClass }}"`, so a grid whose class is assembled in PHP is
 * invisible to it. It is also the one grid that does NOT make the tile a direct
 * child of the row -- every card is wrapped in a `.gs-cell` -- so `height:100%`
 * on `.kbb-tile` resolves against a different box there than it does on the
 * other four, which is exactly the kind of difference that leaves one page out
 * of "apply this everywhere".
 *
 * So the fixture builds one, from the owner's own `bestsellers` preset, and the
 * walk measures it. Nothing else in the preview depends on it.
 */
$gsValues = \App\Services\GridSections::PRESETS['bestsellers']['values'];

$section = \App\Models\GridSection::updateOrCreate(
    ['slug' => 'card-best-sellers'],
    array_merge($gsValues, [
        'slug' => 'card-best-sellers',
        'status' => 'publish',
        'position' => 1,
        // The eyebrow this grid can pass its cards, so the walk can see that it
        // is off by default here too and not only on the four known grids.
        'card_label' => 'Skincare Sets and Barrier Repair Routines',
    ])
);

echo 'card: grid section #'.$section->id." published (".$section->sectionKey().")\n";
