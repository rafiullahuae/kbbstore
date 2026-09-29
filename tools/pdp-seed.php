<?php
/*
 * Seed the Lane PDP preview — the fixture the five designs are photographed on.
 *
 * ── WHAT IT HAS TO CARRY, AND WHY EACH ONE IS NOT OPTIONAL ─────────────────
 *
 *  1. A MAIN IMAGE AND THREE GALLERY IMAGES. partials/product-gallery.blade.php
 *     draws the thumbnail strip only when `$shotCount > 1`, so a one-image
 *     fixture shows no strip at all — and "image, then beautiful gallery" is the
 *     second thing he asked for. A previous round of previews was shot on a
 *     one-image fixture and the whole round had to be taken again.
 *
 *  2. BUNDLE BARS. "and then bundles purchase bars (we have that already on the
 *     product page)". BundleService::forProduct() produces them from the tier
 *     table for every ordinary product, so this needs no per-product setup —
 *     but it DOES need the product not to be a set, because a set is
 *     deliberately given an empty bundle array and shows its contents instead.
 *     Hence two products below, and both are photographed.
 *
 *  3. FIVE TABS. Three built-ins (description, ingredients, how to use) plus two
 *     global ones the owner would have written in Catalog → Product tabs. Five
 *     is what makes the row actually overflow at 390px, which is the whole point
 *     of the scroll-left-to-right requirement: a three-tab row fits and proves
 *     nothing.
 *
 *  4. REAL REVIEWS. The thin rating bar draws only when the reviews table has
 *     approved, non-demo rows — the shipped page refuses to print a rating it
 *     does not have, with no fallback to the denormalised column. So these rows
 *     carry a `source` that is not 'demo' and are written outside the demo seed
 *     log, which is what App\Support\DemoReviews asks about.
 *
 *  5. A MARKDOWN. "right side cut price and actual price" needs a cut price to
 *     draw, so the ordinary product is on sale and the set is not — one of each
 *     in the round.
 *
 *  6. A LONG SHORT_DESCRIPTION. The fade and the "read more" are meaningless on
 *     a blurb that stops after two lines; this one runs to about six so the mask
 *     has something to dissolve.
 *
 *  7. A SOLD-OUT PRODUCT, so the owner can see what each design does with a
 *     disabled button and a red stock line without having to imagine it.
 *
 * MONEY IS INTEGER FILS, like every other fixture in this repository.
 */

/* ── PICTURES ────────────────────────────────────────────────────────────────
 *
 * Inline SVG, so the seed needs neither the network nor the media library, and
 * DISTINGUISHABLE from one another — four copies of one gradient photograph as
 * "four shots" in a screenshot, which tells the owner nothing about whether his
 * gallery strip works. Each is a bottle silhouette on a tinted ground so the
 * square frame reads as a product photograph rather than as a colour swatch.
 */
$shot = function (string $ground, string $glass, string $cap, string $mark): string {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="900" height="900" viewBox="0 0 900 900">'
        .'<rect width="900" height="900" fill="'.$ground.'"/>'
        .'<ellipse cx="450" cy="742" rx="196" ry="26" fill="rgba(0,0,0,.07)"/>'
        .'<rect x="392" y="150" width="116" height="74" rx="14" fill="'.$cap.'"/>'
        .'<rect x="330" y="214" width="240" height="512" rx="44" fill="'.$glass.'"/>'
        .'<rect x="330" y="214" width="76" height="512" rx="38" fill="rgba(255,255,255,.22)"/>'
        .'<rect x="368" y="404" width="164" height="150" rx="10" fill="rgba(255,255,255,.88)"/>'
        .'<text x="450" y="500" font-family="Helvetica,Arial,sans-serif" font-size="74" font-weight="700"'
        .' fill="'.$cap.'" text-anchor="middle">'.$mark.'</text>'
        .'</svg>';

    return 'data:image/svg+xml;base64,'.base64_encode($svg);
};

\App\Models\AdminUser::updateOrCreate(['email' => 'owner@preview.test'], [
    'name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner',
]);

$anua = \App\Models\Brand::updateOrCreate(['slug' => 'anua'], ['name' => 'Anua']);
$boj = \App\Models\Brand::updateOrCreate(['slug' => 'beauty-of-joseon'], ['name' => 'Beauty of Joseon']);

$blurb = 'A gentle daily toner built around 77% heartleaf extract, formulated for '
    .'skin that reacts to everything. It calms redness, loosens what has settled in '
    .'the pores overnight and leaves the barrier where it found it — no alcohol, no '
    .'added fragrance and no essential oils. Use it morning and night on damp skin, '
    .'before your serum, and give it thirty seconds to sink in before the next step. '
    .'Suitable for sensitive, blemish-prone and post-procedure skin.';

$ingredients = '<p>Houttuynia Cordata Extract 77%, Water, Butylene Glycol, '
    .'1,2-Hexanediol, Glycerin, Panthenol, Betaine, Sodium Hyaluronate, '
    .'Allantoin, Madecassoside, Centella Asiatica Extract, Zanthoxylum '
    .'Piperitum Fruit Extract, Pulsatilla Koreana Extract, Usnea Barbata '
    .'Extract, Ethylhexylglycerin.</p><p>Free from alcohol, added fragrance, '
    .'essential oils and artificial colour.</p>';

$howTo = '<ol><li>Cleanse and pat the skin until it is damp, not dry.</li>'
    .'<li>Decant two or three pumps into the palm — a cotton pad drinks more than '
    .'your face does.</li><li>Press it in rather than wiping it on.</li>'
    .'<li>Wait thirty seconds, then follow with serum and moisturiser.</li>'
    .'<li>Morning and night. On a bad day, twice over.</li></ol>';

/* ── 1. THE ORDINARY PRODUCT: on sale, four shots, bundle bars, five tabs ─── */
$toner = \App\Models\Product::updateOrCreate(['slug' => 'pdp-heartleaf-toner'], [
    'name' => 'Heartleaf 77% Soothing Toner 250ml',
    'brand_id' => $anua->id,
    'price' => 9900,
    'sale_price' => 7425,
    'sale_starts_at' => null,
    'sale_ends_at' => null,
    'status' => 'publish',
    'is_visible' => true,
    'stock_status' => 'instock',
    'type' => 'simple',
    'short_description' => $blurb,
    'description' => '<p>Anua built this around a single idea: that a toner should '
        .'take something away without taking the barrier with it. 77% of the bottle '
        .'is heartleaf extract, cold-brewed rather than heated, and the rest is the '
        .'shortest list the formula would tolerate.</p><p>It is the product most '
        .'often recommended in this shop for skin that has been over-treated, and it '
        .'is the one people come back for.</p>',
    'ingredients' => $ingredients,
    'how_to_use' => $howTo,
    'total_sales' => 12400,
    'manage_stock' => false,
    'sku' => 'ANU-HL77-250',
    'image' => $shot('#F7EEF1', '#DCE9E1', '#3E8E62', '01'),
    'images' => [
        $shot('#EFF3F7', '#E3E9F4', '#3C4FA8', '02'),
        $shot('#FAF2E8', '#F6E3C4', '#B8792F', '03'),
        $shot('#F3EFF8', '#E7DFF6', '#6F5BC6', '04'),
    ],
]);

/* ── 2. THE SET: no bundle bars by design; it shows its contents instead ──── */
$set = \App\Models\Product::updateOrCreate(['slug' => 'pdp-glow-ritual-set'], [
    'name' => 'Glow Ritual Set',
    'brand_id' => $boj->id,
    'price' => 21500,
    'status' => 'publish',
    'is_visible' => true,
    'stock_status' => 'instock',
    'type' => 'set',
    'short_description' => 'Three steps and about four minutes: the rice cleanser, '
        .'the ginseng essence and the relief sun stick, boxed together at less than '
        .'the three of them cost apart. Built for skin that wants brightening without '
        .'being stripped, and the set most people in this shop start with.',
    'description' => '<p>A three-step routine chosen so that nothing in it fights '
        .'anything else in it.</p>',
    'ingredients' => $ingredients,
    'how_to_use' => $howTo,
    'image' => $shot('#FBF1F4', '#F6D9E2', '#C13E63', 'S1'),
    'images' => [
        $shot('#F1F6F3', '#D9EDE2', '#2E9E6B', 'S2'),
        $shot('#F6F4EE', '#EEE6D2', '#A89A3C', 'S3'),
        $shot('#F2F0F8', '#E0DAF2', '#7B6CF0', 'S4'),
    ],
]);

$member = function (string $name, string $slug, int $fils, array $c, string $mark) use ($shot, $boj) {
    return \App\Models\Product::updateOrCreate(['slug' => $slug], [
        'name' => $name,
        'price' => $fils,
        'brand_id' => $boj->id,
        'image' => $shot($c[0], $c[1], $c[2], $mark),
        'status' => 'publish',
        'is_visible' => true,
        'stock_status' => 'instock',
        'type' => 'simple',
    ]);
};

$members = [
    $member('Green Plum Refreshing Cleanser 150ml', 'pdp-member-cleanser', 6900, ['#F1F6F3', '#D9EDE2', '#2E9E6B'], 'A'),
    $member('Ginseng Essence Water 150ml', 'pdp-member-essence', 8900, ['#F6F4EE', '#EEE6D2', '#A89A3C'], 'B'),
    $member('Relief Sun Rice + Probiotics SPF50+', 'pdp-member-sun', 5900, ['#F2F0F8', '#E0DAF2', '#7B6CF0'], 'C'),
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

/* ── 3. THE SOLD-OUT ONE, so the disabled state is photographed and not
 *      imagined. Same gallery depth so only the one variable differs. ─────── */
\App\Models\Product::updateOrCreate(['slug' => 'pdp-sold-out-serum'], [
    'name' => 'Azelaic Acid 10 Serum 30ml',
    'brand_id' => $anua->id,
    'price' => 8900,
    'status' => 'publish',
    'is_visible' => true,
    'stock_status' => 'outofstock',
    'type' => 'simple',
    'short_description' => $blurb,
    'description' => '<p>A 10% azelaic acid serum for texture and post-blemish marks.</p>',
    'ingredients' => $ingredients,
    'how_to_use' => $howTo,
    'image' => $shot('#F7EEF1', '#F3DCE3', '#A82F53', '01'),
    'images' => [
        $shot('#EFF3F7', '#E3E9F4', '#3C4FA8', '02'),
        $shot('#FAF2E8', '#F6E3C4', '#B8792F', '03'),
    ],
]);

/* ── 4. TWO GLOBAL TABS, so the row is FIVE long and actually overflows ─────
 *
 * `source_key` null and `product_id` null is what App\Support\ProductTabs calls
 * a global: a tab the owner wrote once in Catalog → Product tabs that appears on
 * every product. Positioned after the three built-ins (10/20/30) so the row
 * reads description, ingredients, how to use, and then his own two.
 */
foreach ([
    ['Shipping & returns', '<p>Dispatched from Dubai within one working day. '
        .'Delivery across the UAE in one to three days, tracked. Unopened items may '
        .'be returned within fourteen days of delivery.</p>', 40],
    ['Authenticity', '<p>Every unit in this shop is bought from the brand or its '
        .'appointed Gulf distributor, shipped in temperature-controlled freight and '
        .'stored in Dubai. Batch codes are checked on arrival and on dispatch.</p>', 50],
] as [$title, $body, $position]) {
    \App\Models\ProductTab::updateOrCreate(
        ['product_id' => null, 'title' => $title],
        ['body' => $body, 'position' => $position, 'is_enabled' => true, 'audience' => 'all']
    );
}

/* ── 5. REVIEWS, so the thin rating bar has something true to draw ──────────
 *
 * `source` is 'import' and nothing is written to `demo_seed_log`, which are the
 * two questions App\Support\DemoReviews asks. A row stamped 'demo' would be
 * excluded from the storefront summary and the bar would not draw at all — the
 * exact failure this fixture exists to avoid.
 */
\App\Models\Review::where('product_id', $toner->id)->delete();

$reviews = [
    ['Layla A.', 5, 'Finally something that does not sting', 'Three weeks in and the redness across my cheeks has gone down more than with anything else I have tried. It does not sting on freshly exfoliated skin, which is the whole reason I bought it.'],
    ['Mariam K.', 5, 'Repurchased twice', 'I decant it and press it in rather than using a pad. Bottle lasts about seven weeks at twice a day.'],
    ['Sara H.', 4, 'Good, but slow', 'It works, it is just not dramatic. Give it a month before you decide.'],
    ['Noura S.', 5, 'The one I recommend', 'I have put four people onto this. Nobody has come back to complain.'],
    ['Aisha R.', 5, 'Calm skin, no fragrance', 'No scent at all, which I appreciate. Absorbs in under a minute.'],
];

foreach ($reviews as $i => [$who, $stars, $title, $body]) {
    \App\Models\Review::create([
        'product_id' => $toner->id,
        'author_name' => $who,
        'rating' => $stars,
        'title' => $title,
        'content' => $body,
        'status' => 'approved',
        'verified' => true,
        'source' => 'import',
        'created_at' => now()->subDays(60 - ($i * 9)),
    ]);
}

\App\Support\ProductRating::refresh([$toner->id]);

/* ── 6. THE OWNER'S OWN SENTENCES, so the assurance lines are not blank ─────
 *
 * Both are settings, both ship empty, and an empty one draws no line at all —
 * which is correct on the shop and useless in a drawing the owner is being asked
 * to choose from. These are written into the PREVIEW's database only; nothing
 * here reaches a package.
 */
$settings = app(\App\Services\SettingsService::class);
$settings->set('product_authentic_text', '100% authentic, sourced from the brand');
$settings->set('delivery_line_ae', '1–3 day delivery across the UAE');

echo "pdp seed done\n";
