<?php
/*
 * Seed the Lane PP preview.
 *
 * Everything the four set-contents designs have to be photographed against:
 *
 *   - an owner, so the admin screen can be reached;
 *   - a THREE-member set, with one member left a DRAFT (so a linked member and
 *     an unlinked one are in the same picture) and one member with NO PICTURE
 *     AT ALL (so the gradient fallback is in it too);
 *   - a TWELVE-member set, which is the count that actually tests a layout;
 *   - an UNPRICED set, so "a set with no price must not claim a saving" is a
 *     photograph rather than a claim;
 *   - an ordinary product, which is the control: its quantity-bundle strip must
 *     be exactly where it was.
 *
 * MONEY IS INTEGER FILS here too. A fixture in major units would be the one
 * place on this path where a price was not what the column holds.
 */
\App\Models\AdminUser::updateOrCreate(['email' => 'owner@preview.test'], [
    'name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner',
]);

$brand = \App\Models\Brand::updateOrCreate(['slug' => 'anua'], ['name' => 'Anua']);
$brand2 = \App\Models\Brand::updateOrCreate(['slug' => 'beauty-of-joseon'], ['name' => 'Beauty of Joseon']);

/* Tiny inline SVG pictures, so the panel has real images in it without this
   script needing the network or a media library. */
$pic = function (string $a, string $b): string {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="240" height="300">'
        .'<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1">'
        .'<stop offset="0" stop-color="'.$a.'"/><stop offset="1" stop-color="'.$b.'"/>'
        .'</linearGradient></defs><rect width="240" height="300" fill="url(#g)"/></svg>';

    return 'data:image/svg+xml;base64,'.base64_encode($svg);
};

$palette = [
    ['#F7C6D4', '#E0567B'], ['#CDE7D6', '#3E8E62'], ['#FFE6B8', '#E0922F'],
    ['#D9D2F2', '#7B6CF0'], ['#C9E6F2', '#2F84A8'], ['#F2D5C9', '#B8613C'],
    ['#E3F2C9', '#7A9E35'], ['#F2C9E8', '#A83C8C'], ['#C9D2F2', '#3C4FA8'],
    ['#F2E8C9', '#A89A3C'], ['#D2F2C9', '#4CA83C'], ['#F2C9C9', '#A83C3C'],
];

$make = function (string $name, string $slug, int $fils, ?array $colours, string $status, $brandId) use ($pic) {
    return \App\Models\Product::updateOrCreate(['slug' => $slug], [
        'name' => $name,
        'price' => $fils,
        'brand_id' => $brandId,
        // null colours = a member with NO PICTURE, which every design has to
        // draw without a broken <img>.
        'image' => $colours === null ? null : $pic($colours[0], $colours[1]),
        'status' => $status,
        'is_visible' => true,
        'stock_status' => 'instock',
        'type' => 'simple',
    ]);
};

/* ── THE THREE-MEMBER SET ───────────────────────────────────────────────────
 *
 * Member 1 is published and pictured (a link, with a photograph).
 * Member 2 has NO PICTURE (a link, drawn on the gradient fallback).
 * Member 3 is a DRAFT (named, NOT a link — its page would 404).
 */
$three = [
    ['Heartleaf 77% Soothing Toner 250ml', 'lanepp-heartleaf-toner', 9000, $palette[0], 'publish', $brand->id],
    ['Azelaic Acid 10 Serum 30ml', 'lanepp-azelaic-serum', 7550, null, 'publish', $brand->id],
    ['Rice 70 Glow Milky Toner 250ml', 'lanepp-milky-toner', 6900, $palette[2], 'draft', $brand2->id],
];

$threeIds = [];

foreach ($three as $i => [$name, $slug, $fils, $colours, $status, $brandId]) {
    $threeIds[] = $make($name, $slug, $fils, $colours, $status, $brandId)->id;
}

/* ── THE TWELVE-MEMBER SET ──────────────────────────────────────────────────
 *
 * Long names on purpose: a 40-character product name is what pushes a grid
 * track past its container, and "no horizontal overflow at 390" is only worth
 * measuring against the names this shop actually carries.
 */
$names = [
    'Heartleaf 77% Soothing Toner 250ml', 'Azelaic Acid 10 Serum 30ml',
    'Rice 70 Glow Milky Toner 250ml', 'Ceramide Daily Moisturiser 100ml',
    'Relief Sun Rice Probiotics SPF50+', 'Green Plum Refreshing Cleanser 150ml',
    'Revive Eye Serum Ginseng Retinal 30ml', 'Glow Deep Serum Rice Alpha Arbutin',
    'Dynasty Cream Rich Repair 50ml', 'Calming Cica Ampoule 40ml',
    'Radiance Cleansing Balm 100ml', 'Matte Sun Stick Mugwort 18g',
];

$twelveIds = [];

foreach ($names as $i => $name) {
    $twelveIds[] = $make(
        $name,
        'lanepp-twelve-'.($i + 1),
        4900 + ($i * 610),
        $i === 4 ? null : $palette[$i % 12],
        $i === 9 ? 'draft' : 'publish',
        $i % 2 === 0 ? $brand->id : $brand2->id
    )->id;
}

/* The control. An ordinary product keeps its quantity-bundle strip, and this is
   the page that proves it. */
$plain = $make('Ceramide Daily Moisturiser 100ml', 'lanepp-plain-moisturiser', 12900, $palette[3], 'publish', $brand->id);

/* THE ORDINARY PRODUCT NEEDS A SHORT DESCRIPTION AND A DESCRIPTION.
 *
 * Item 3 of this lane is the seam between the short description and "Choose
 * your option" -- the owner's second screenshot is of exactly that seam on an
 * ordinary product. A control with a null short_description does not render
 * the paragraph at all, so the seam it is meant to photograph does not exist.
 * The words are the owner's own from that screenshot. */
$plain->forceFill([
    'short_description' => 'Demo product for layout testing with a short descriptive blurb that runs to about two lines in the buy column.',
    'description' => '<p>A ceramide moisturiser for daily use, in the demo catalogue.</p>',
])->save();

$buildSet = function (string $slug, string $name, array $memberIds, int $price, array $quantities, $colours) use ($pic, $brand) {
    $set = \App\Models\Product::updateOrCreate(['slug' => $slug], [
        'name' => $name,
        'type' => 'set',
        // NO BRAND on any of these sets, deliberately: "the Set product type
        // will not have any brand". The photographs are of the case the owner
        // actually has.
        'brand_id' => null,
        'image' => $pic($colours[0], $colours[1]),
        'short_description' => 'Three steps, one box.',
        'description' => '<p>The products that go together, in one box.</p>',
        'status' => 'publish', 'is_visible' => true, 'stock_status' => 'instock',
        'set_price_mode' => \App\Support\SetPricing::MODE_FIXED,
        'set_discount' => null,
        'price' => $price,
    ]);

    \App\Models\ProductSetItem::where('set_product_id', $set->id)->delete();

    foreach ($memberIds as $i => $memberId) {
        \App\Models\ProductSetItem::create([
            'set_product_id' => $set->id,
            'member_product_id' => $memberId,
            'quantity' => $quantities[$i] ?? 1,
            'position' => $i,
        ]);
    }

    \App\Support\SetPricing::forget((int) $set->id);

    return $set->fresh();
};

// Parts: 2x9000 + 7550 + 6900 = 32450. The box is 26900, so it saves 5550.
$setThree = $buildSet('lanepp-glow-starter-set', 'Glow Starter Set', $threeIds, 26900, [2, 1, 1], ['#FBD9E4', '#C9587F']);

// Twelve members, one of each, priced comfortably under the parts.
$setTwelve = $buildSet('lanepp-full-routine-set', 'Full Routine Set', $twelveIds, 59900, [], ['#D6E4F7', '#3B6FB0']);

// UNPRICED. Every design must print "Set price AED 0" and NOT "You save".
$setUnpriced = $buildSet('lanepp-unpriced-set', 'Draft Ritual Box', array_slice($threeIds, 0, 2), 0, [1, 1], ['#E8E2DC', '#8C7F74']);

echo 'seeded 3-member set #', $setThree->id, ' (', $setThree->price, " fils)\n";
echo 'seeded 12-member set #', $setTwelve->id, ' (', $setTwelve->price, " fils)\n";
echo 'seeded UNPRICED set #', $setUnpriced->id, "\n";
echo 'seeded ordinary product #', $plain->id, "\n";
