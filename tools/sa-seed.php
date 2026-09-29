<?php
/*
 * Seed the Lane SA preview.                                          (Lane SA)
 *
 * What the shots need, and why each piece is here:
 *
 *   an owner              to sign into Appearance -> Set
 *   twelve pictured
 *     products            the members, with real (inline SVG) pictures so the
 *                         fan of 26px circles is judged on pictures and not on
 *                         grey gradients
 *   a THREE-member set    the ordinary case, and the one the owner screenshotted
 *   a TWELVE-member set   the case the brief asks for by name, and the one that
 *                         proves the fan cannot widen a 390px page
 *   a MEMBER-LESS set     a set whose contents are empty: the box and the panel
 *                         must both draw nothing at all rather than an empty
 *                         shell
 *   an ordinary product   so one screenshot shows the set row beside the plain
 *                         rows it has to sit between without disturbing them
 *   one basket with
 *     all of them         the cart page, the cart drawer and the checkout
 *                         summary all read the same basket, so one cookie shoots
 *                         five surfaces
 *
 * MONEY IS INTEGER FILS. A fixture that used major units would be the one place
 * on this path where a price was not what the column holds.
 *
 * Adapted from tools/set-seed.php, which Lane SET wrote; the shape is
 * deliberately the same so the two harnesses read alike.
 */
\App\Models\AdminUser::updateOrCreate(['email' => 'owner@preview.test'], [
    'name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner',
]);

$brand = \App\Models\Brand::updateOrCreate(['slug' => 'anua'], ['name' => 'Anua']);
$category = \App\Models\Category::updateOrCreate(['slug' => 'sets'], ['name' => 'Sets']);

/* Tiny inline SVG pictures, so the circles have real images in them without
   this script needing the network or a media library. */
$pic = function (string $a, string $b): string {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="120" height="120">'
        .'<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1">'
        .'<stop offset="0" stop-color="'.$a.'"/><stop offset="1" stop-color="'.$b.'"/>'
        .'</linearGradient></defs><rect width="120" height="120" fill="url(#g)"/></svg>';

    return 'data:image/svg+xml;base64,'.base64_encode($svg);
};

$palette = [
    ['#F7C6D4', '#E0567B'], ['#CDE7D6', '#3E8E62'], ['#FFE6B8', '#E0922F'],
    ['#D9D2F2', '#7B6CF0'], ['#C9E6F2', '#2E86A8'], ['#F2D5C9', '#C2603A'],
    ['#E6D9F2', '#8E5BC2'], ['#D9F2E6', '#2FA37A'], ['#F2E6C9', '#B39B3A'],
    ['#F2C9E6', '#C23A8E'], ['#C9F2D9', '#3AB36B'], ['#D5C9F2', '#5B3AC2'],
];

$names = [
    'Heartleaf 77% Soothing Toner 250ml',
    'Azelaic Acid 10 Serum 30ml',
    'Rice 70 Glow Milky Toner 250ml',
    'Peach 70 Niacinamide Serum 30ml',
    'Birch Juice Moisturising Cream 50ml',
    'Green Lemon Vita C Blemish Serum 30ml',
    'Cleansing Oil Heartleaf Pore Control 200ml',
    'Rice Enzyme Cleansing Powder 70g',
    'Centella Ampoule Soothing Gel 100ml',
    'Hyaluronic Acid Deep Hydrating Mist 120ml',
    'Snail Mucin Repair Essence 100ml',
    'Panthenol Barrier Sleeping Mask 80g',
];

$ids = [];

foreach ($names as $i => $name) {
    $p = \App\Models\Product::updateOrCreate(['slug' => 'lanesa-member-'.($i + 1)], [
        'name' => $name,
        'price' => 5900 + ($i * 450),
        'brand_id' => $brand->id,
        'image' => $pic($palette[$i][0], $palette[$i][1]),
        'status' => 'publish', 'is_visible' => true, 'stock_status' => 'instock', 'type' => 'simple',
    ]);
    $ids[] = $p->id;
}

$plain = \App\Models\Product::updateOrCreate(['slug' => 'lanesa-ceramide-moisturiser'], [
    'name' => 'Ceramide Daily Moisturiser 100ml', 'price' => 12900, 'brand_id' => $brand->id,
    'image' => $pic('#D9D2F2', '#7B6CF0'),
    'status' => 'publish', 'is_visible' => true, 'stock_status' => 'instock', 'type' => 'simple',
]);

/*
 * THE SETS. A `products` row with type='set' and nothing else special: it
 * publishes, gets a page and goes in a basket with no other change anywhere.
 */
$makeSet = function (string $slug, string $name, int $price, array $memberIds) use ($brand, $category, $pic) {
    $set = \App\Models\Product::updateOrCreate(['slug' => $slug], [
        'name' => $name,
        'type' => 'set',
        'price' => $price,
        'brand_id' => $brand->id,
        'category_id' => $category->id,
        'image' => $pic('#FBD9E4', '#C9587F'),
        'short_description' => 'Everything in one box.',
        'description' => '<p>The steps that go together.</p>',
        'status' => 'publish', 'is_visible' => true, 'stock_status' => 'instock',
    ]);

    \App\Models\ProductSetItem::where('set_product_id', $set->id)->delete();

    foreach ($memberIds as $i => $memberId) {
        \App\Models\ProductSetItem::create([
            'set_product_id' => $set->id,
            'member_product_id' => $memberId,
            'quantity' => $i === 0 ? 2 : 1,
            'position' => $i,
        ]);
    }

    return $set;
};

$set3 = $makeSet('lanesa-glow-starter-set', 'Glow Starter Set', 19900, array_slice($ids, 0, 3));
$set12 = $makeSet('lanesa-full-routine-set', 'Full Routine Twelve Step Set', 59900, $ids);

/* A set with NO members at all. SetContents::fromProduct() answers NONE for it,
   and both the box and the buy-column panel must draw nothing whatever — not an
   empty bordered shell, not a footing reading "You save AED 0.00". */
$setEmpty = $makeSet('lanesa-empty-set', 'Empty Set', 9900, []);

/* One basket holding all of them, under a fixed token the shot script sets as
   its cookie. The plain product sits BETWEEN the two sets on purpose: the
   owner's complaint is about the spacing between a set row and the rows either
   side of it, so one screenshot has to show all three arrangements. */
$cart = \App\Models\Cart::updateOrCreate(['token' => 'lanesa-preview-cart-token'], [
    'currency' => 'AED', 'status' => 'active', 'shipping_country' => 'AE', 'last_activity_at' => now(),
]);

$cart->items()->delete();
$cart->items()->create(['product_id' => $set3->id, 'quantity' => 1, 'unit_price' => 19900]);
$cart->items()->create(['product_id' => $plain->id, 'quantity' => 1, 'unit_price' => 12900]);
$cart->items()->create(['product_id' => $set12->id, 'quantity' => 1, 'unit_price' => 59900]);

app(\App\Services\SettingsService::class)->set('free_shipping_threshold', 200000);

echo 'seeded set #', $set3->id, ' (3), #', $set12->id, ' (12), #', $setEmpty->id,
    " (0) and a basket holding two sets and a plain product\n";

/* ── ARABIC, AND THE MIRROR, WHICH ARE TWO SWITCHES ────────────────────────
 *
 * /ar 404s without the first one, which is what this preview did before this
 * line. And Locale::direction() reads a SECOND setting: with only the first,
 * /ar renders in Arabic and left to right — <html lang="ar" dir="ltr"> — so
 * every mirrored shot comes back dir="ltr" and proves nothing about a logical
 * property at all. Measured by Lane PP, copied here rather than rediscovered.
 *
 * It is a PREVIEW fixture. Nothing in this lane's diff seeds either setting and
 * the shop's own defaults stay off.
 */
app(\App\Services\SettingsService::class)->set(\App\Support\Locale::SETTING_ENABLED, true);
app(\App\Services\SettingsService::class)->set(\App\Support\Locale::SETTING_RTL, true);

echo "arabic and the mirror switched on for the preview\n";
