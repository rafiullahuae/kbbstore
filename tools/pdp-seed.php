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

/* ── 3b. THE THREE CASES THE PREVIEW NEVER HAD TO DRAW. (Lane PDP2) ────────
 *
 * The five drawings were shot on ONE ordinary product, one set and one sold-out
 * row, and that was enough to choose between five layouts. It is not enough to
 * say the chosen one holds on the real catalogue, which is what TASK 3 asks:
 * the two dimensions a product page actually varies in are HOW LONG THE NAME IS
 * and HOW MUCH RATING THERE IS TO PRINT, and both of them land in the title row
 * the owner has just complained about.
 *
 *  · NO REVIEWS AT ALL is already covered — pdp-sold-out-serum has none, and
 *    the set has none either, so the "no rating row is drawn" case is shot
 *    twice without another fixture.
 *  · 96 REVIEWS is the other end: a four-digit count in the same row, and the
 *    denormalised columns refreshed off real rows rather than guessed.
 *  · A VERY LONG NAME is the one that breaks a two-track grid. `minmax(0,1fr)`
 *    on the title track is what stops it pushing the price off the inline edge;
 *    without a fixture that tests it, that claim is a comment rather than a
 *    measurement.
 */
$manyBlurb = 'A ceramide-and-panthenol cream for a barrier that has been through '
    .'something: retinoid, acid, sun, a long flight, a change of water. Thick in the '
    .'jar and thin on the skin, with no fragrance to react to and nothing that '
    .'pills under sunscreen. Use it as the last step at night, and as the step '
    .'before sunscreen when the day is going to be cold.';

$many = \App\Models\Product::updateOrCreate(['slug' => 'pdp-many-reviews-cream'], [
    'name' => 'Ceramide Barrier Repair Cream 80ml',
    'brand_id' => $boj->id,
    'price' => 13900,
    'status' => 'publish',
    'is_visible' => true,
    'stock_status' => 'instock',
    'type' => 'simple',
    'short_description' => $manyBlurb,
    'description' => '<p>Five ceramides in the ratio the skin makes them, plus '
        .'panthenol and squalane, in a base that spreads far enough that one jar is '
        .'a season rather than a month.</p>',
    'ingredients' => $ingredients,
    'how_to_use' => $howTo,
    'total_sales' => 4200,
    'manage_stock' => false,
    'image' => $shot('#F2F6F4', '#DCEDE6', '#2F7F60', '05'),
    'images' => [
        $shot('#F7F1F6', '#EEDCEA', '#8E3C78', '06'),
        $shot('#F5F3EC', '#EAE4D2', '#8A7A32', '07'),
    ],
]);

/* NINETY-SIX, WRITTEN ONCE AND NOT NINETY-SIX TIMES. `insert()` takes the
   whole array in one statement; ninety-six create() calls is ninety-six
   round trips and the same rows. The stars are spread so the average is a
   real fraction rather than a flat 5.0 — a 5.0 bar is full and says nothing
   about whether the fill is arithmetic at all. */
\App\Models\Review::where('product_id', $many->id)->delete();

$manyRows = [];

for ($i = 0; $i < 96; $i++) {
    $manyRows[] = [
        'product_id' => $many->id,
        'author_name' => 'Reviewer '.($i + 1),
        'rating' => [5, 5, 5, 4, 5, 4, 5, 3][$i % 8],
        'title' => 'Bought it again',
        'content' => 'Third jar. Nothing else has kept my cheeks from flaking in winter.',
        'status' => 'approved',
        'verified' => true,
        'source' => 'import',
        'created_at' => now()->subDays(200 - $i),
        'updated_at' => now()->subDays(200 - $i),
    ];
}

\App\Models\Review::insert($manyRows);
\App\Support\ProductRating::refresh([$many->id]);

/* A NAME AT THE LENGTH THIS CATALOGUE REALLY REACHES. Not an invented
   pathological string: this is the shape of an imported WooCommerce title —
   brand, actives, a claim, a volume and a pack note — and it is 118
   characters, which is longer than anything in the demo catalogue and
   shorter than the longest real one. */
/* AND ONE WITH NO BREAK OPPORTUNITY IN IT AT ALL, which is the other half of the
 * same risk: `minmax(0,1fr)` lets the title TRACK shrink, and a single token
 * longer than the track spills out of it and drags the document's scrollWidth
 * sideways, because a grid does not clip.
 *
 * ▲ NO HYPHENS, AND THE FIRST VERSION OF THIS FIXTURE HAD THEM. A hyphen is a
 *   break opportunity whatever `overflow-wrap` says, so
 *   "Anua-Heartleaf-77-Percent-..." wrapped perfectly well with the guard
 *   switched off and measured scrollWidth 390 either way -- a fixture that
 *   proved the rule was unnecessary because it never exercised it. Measured in
 *   Chromium at 390 with the run-together name below: 390 with
 *   `overflow-wrap:anywhere` and 672 without it, which is 282px of sideways
 *   scroll on the whole document.
 *
 * ▲ AND IT IS A SYNTHETIC WORST CASE, said plainly. No name in this catalogue
 *   looks like this; what does happen is an import that loses its spaces, and
 *   the rule costs one declaration whether or not it ever fires. */
\App\Models\Product::updateOrCreate(['slug' => 'pdp-unbreakable-name'], [
    'name' => 'ANUAHEARTLEAF77SOOTHINGTONER250MLDOUBLEPACKREFILLEDITION',
    'brand_id' => $anua->id,
    'price' => 10400,
    'status' => 'publish',
    'is_visible' => true,
    'stock_status' => 'instock',
    'type' => 'simple',
    'short_description' => 'One token, no spaces to break at.',
    'description' => '<p>A fixture for the narrowest thing the title row has to hold.</p>',
    'image' => $shot('#F4F1F7', '#E3DCF0', '#4A3A82', '14'),
]);

\App\Models\Product::updateOrCreate(['slug' => 'pdp-very-long-name-ampoule'], [
    'name' => 'Advanced Snail 96 Mucin Power Repairing Ampoule with Niacinamide and '
        .'Peptides for Dull, Uneven Skin 100ml (Double Pack)',
    'brand_id' => $anua->id,
    'price' => 11900,
    'sale_price' => 8330,
    'status' => 'publish',
    'is_visible' => true,
    'stock_status' => 'instock',
    'type' => 'simple',
    'short_description' => $blurb,
    'description' => '<p>A 96% mucin ampoule with niacinamide and five peptides.</p>',
    'ingredients' => $ingredients,
    'how_to_use' => $howTo,
    'manage_stock' => true,
    'stock' => 3,
    'image' => $shot('#F6F2F7', '#E6DCEF', '#5B3C8E', '08'),
    'images' => [
        $shot('#F2F7F5', '#DDEEE7', '#2F8060', '09'),
        $shot('#FAF3EC', '#F3E4CC', '#A97A33', '10'),
    ],
]);

/* ── 3c. A VARIABLE PRODUCT WHOSE FIRST OPTION IS SOLD OUT. (Lane PDP2) ─────
 *
 * The five drawings never had one: parts/options.blade.php in the preview is a
 * sketch and the ordinary fixture product has BUNDLE bars, which
 * BundleService generates from the tier table and which are not variations at
 * all. The real page's `@if ($isVar)` branch is a different strip with a hidden
 * `variation_id` behind it, and it carries the defect the shipped template's own
 * `$buyable` exists to answer: `$variants->first()` regardless of stock left a
 * product whose FIRST size was sold out with no option highlighted, an enabled
 * Add to cart, and a hidden field pointing at the sold-out row.
 *
 * So the first option here is out of stock on purpose. What the shots have to
 * show is the SECOND row highlighted, the first one struck through and tagged
 * Sold out, and a live button.
 *
 * ▲ THE LABELS COME FROM ATTRIBUTE VALUES, which is what
 *   ProductVariant::label() reads -- `$this->attributeValues->pluck('name')`.
 *   Without them every row falls back to "Option 1 / Option 2 / Option 3",
 *   which renders and proves nothing about a strip whose whole job is to say
 *   what the options ARE.
 */
$sizeAttr = \App\Models\Attribute::updateOrCreate(['slug' => 'size'], [
    'name' => 'Size', 'is_variation_axis' => true, 'is_filterable' => true, 'position' => 10,
]);

$sizes = [];

foreach ([['30ml', 0], ['50ml', 1], ['100ml', 2]] as [$label, $position]) {
    $sizes[$label] = \App\Models\AttributeValue::updateOrCreate(
        ['attribute_id' => $sizeAttr->id, 'slug' => \Illuminate\Support\Str::slug($label)],
        ['name' => $label, 'position' => $position]
    );
}

$variable = \App\Models\Product::updateOrCreate(['slug' => 'pdp-variable-ampoule'], [
    'name' => 'Niacinamide 10% + Zinc Ampoule',
    'brand_id' => $anua->id,
    // NULL on the parent, exactly as WooCommerce leaves it -- the figures live
    // on the variations and App\Services\VariantPricing is what answers the
    // headline. A parent with a price of its own would hide the defect.
    'price' => null,
    'status' => 'publish',
    'is_visible' => true,
    'stock_status' => 'instock',
    'type' => 'variable',
    /* ONE SENTENCE, ON PURPOSE. Every other product in this fixture has a
       six-line blurb, which is what the fade and the "Read more" are for -- and
       which means nothing here ever exercised the OTHER case. A mask whose stops
       are written as `100% - x` dissolves a one-line blurb too, and that defect
       is invisible until a fixture has a short one. (Lane PDP2) */
    'short_description' => 'A 10% niacinamide ampoule with 1% zinc PCA, for texture and tone.',
    'description' => '<p>A 10% niacinamide ampoule with 1% zinc PCA.</p>',
    'ingredients' => $ingredients,
    'how_to_use' => $howTo,
    'image' => $shot('#F1F4F8', '#DEE6F2', '#33528E', '11'),
    'images' => [
        $shot('#F8F2F4', '#EFD9E1', '#9E3358', '12'),
        $shot('#F3F7F1', '#DEEEDB', '#3C8040', '13'),
    ],
]);

\App\Models\ProductVariant::where('product_id', $variable->id)->delete();

foreach ([
    ['30ml', 6900, null, 'outofstock', null],
    ['50ml', 9900, 7920, 'instock', null],
    ['100ml', 16900, 12675, 'instock', 'Best value'],
] as $n => [$label, $regular, $sale, $stock, $tag]) {
    $v = \App\Models\ProductVariant::create([
        'product_id' => $variable->id,
        'price' => $regular,
        'sale_price' => $sale,
        'stock_status' => $stock,
        'position' => $n,
        'tag' => $tag,
    ]);

    $v->attributeValues()->sync([$sizes[$label]->id]);
}

/* ── 3d. THE LONGEST NAME IN HIS OWN CATALOGUE. (Lane PDP2, round 2) ───────
 *
 * The mobile type sheet was first drawn on `pdp-heartleaf-toner`, whose name is
 * 34 characters and comes out at two line-boxes in every option offered -- so
 * the sheet showed him three pictures that differed by a type size and not by
 * the thing he complained about, which is a THIRD LINE. The option that matters
 * is the one that survives the longest name he actually sells.
 *
 * ▲ IT IS COPIED FROM storage/catalog/products.json, WHICH IS REAL. The README
 *   says so in as many words -- "real source data (the product catalogue
 *   import)" -- and tests/Feature/SeoPreviewsTest calls it the only real
 *   catalogue data in this repository. 22 products; the median name is 39
 *   characters and this one is 82, which makes it the worst case he owns rather
 *   than the worst case anybody can imagine.
 *
 * ▲ THE ™ AND THE & ARE KEPT. They are in his data, they are wider than a
 *   letter in most faces, and an ampersand is a break opportunity a fixture
 *   without one would not have. A fixture that tidies its own input is a
 *   fixture that measures something else -- the round before this one lost a
 *   claim to exactly that, with hyphens.
 *
 * ▲ AND ITS PRICE IS HIS TOO. AED 2,450 is four figures plus a separator, which
 *   is the widest price box in the catalogue and therefore the narrowest title
 *   track. Option C raises the price, so the pair has to be drawn together.
 */
$shark = \App\Models\Brand::updateOrCreate(['slug' => 'shark-beauty'], ['name' => 'Shark Beauty']);

\App\Models\Product::updateOrCreate(['slug' => 'pdp-longest-real-name'], [
    'name' => 'Shark™ CryoGlow™ Under-Eye Cooling + LED Anti-Aging Red Light & Skin Clearing Mask',
    'brand_id' => $shark->id,
    'price' => 245000,
    'status' => 'publish',
    'is_visible' => true,
    'stock_status' => 'instock',
    'type' => 'simple',
    'short_description' => $blurb,
    'description' => '<p>An under-eye device combining cooling and red light.</p>',
    'ingredients' => $ingredients,
    'how_to_use' => $howTo,
    'image' => $shot('#EDF1F7', '#D9E2EF', '#2F4A7A', '15'),
    'images' => [
        $shot('#F7EFF2', '#EDDAE2', '#8E3352', '16'),
        $shot('#F1F7F2', '#DCEEE0', '#2F7F44', '17'),
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
