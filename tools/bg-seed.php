<?php
/*
 * Seed the Lane BG preview: Appearance -> Page background.            (Lane BG)
 *
 * The owner asked to see the wash "on our site", so the preview is the REAL
 * pages and this has to put a real shop behind them:
 *
 *   an owner            the preview is gated on an ADMIN SESSION, so the shot
 *                       script signs in as this account before it asks for a
 *                       single frame. A signed-out request gets the shop
 *                       exactly as it is today, which is also shot.
 *   sixteen pictured
 *     products          enough to fill the /shop grid at 1280 and to leave the
 *                       homepage rails with something to rail
 *   one long product    /product/{slug}/ with a description, tabs and a price,
 *                       because a product page is one of the five he named
 *   three articles      /skincare-guide/ is the fifth page, and a journal index
 *                       with one card on it would not show what the wash does
 *                       behind a grid of white cards
 *   a basket            /cart/ is the fourth
 *
 * MONEY IS INTEGER FILS, as everywhere else on this project.
 *
 * Adapted from tools/sa-seed.php; the shape is deliberately the same so the two
 * harnesses read alike.
 */
\App\Models\AdminUser::updateOrCreate(['email' => 'owner@preview.test'], [
    'name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner',
]);

$brand = \App\Models\Brand::updateOrCreate(['slug' => 'anua'], ['name' => 'Anua']);
$category = \App\Models\Category::updateOrCreate(['slug' => 'serums'], ['name' => 'Serums']);

/* Tiny inline SVG pictures, so the cards carry real images without this script
   needing the network or a media library. Deliberately NOT pale: a card has to
   read as a card against the wash, and grey gradients would flatter it. */
$pic = function (string $a, string $b): string {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="300" height="300">'
        .'<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1">'
        .'<stop offset="0" stop-color="'.$a.'"/><stop offset="1" stop-color="'.$b.'"/>'
        .'</linearGradient></defs><rect width="300" height="300" fill="url(#g)"/>'
        .'<circle cx="150" cy="150" r="70" fill="rgba(255,255,255,.55)"/></svg>';

    return 'data:image/svg+xml;base64,'.base64_encode($svg);
};

$palette = [
    ['#F7C6D4', '#E0567B'], ['#CDE7D6', '#3E8E62'], ['#FFE6B8', '#E0922F'],
    ['#D9D2F2', '#7B6CF0'], ['#C9E6F2', '#2E86A8'], ['#F2D5C9', '#C2603A'],
    ['#E6D9F2', '#8E5BC2'], ['#D9F2E6', '#2FA37A'], ['#F2E6C9', '#B39B3A'],
    ['#F2C9E6', '#C23A8E'], ['#C9F2D9', '#3AB36B'], ['#D5C9F2', '#5B3AC2'],
    ['#FFD9C9', '#E06A3A'], ['#C9D9F2', '#3A6AC2'], ['#F2F2C9', '#B3B33A'],
    ['#E6C9F2', '#A03AC2'],
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
    'Mugwort Calming Clay Mask 110g',
    'Propolis Glow Lightweight Ampoule 30ml',
    'Ceramide Barrier Night Cream 60ml',
    'Vitamin B5 Rescue Balm 25g',
];

$ids = [];

foreach ($names as $i => $name) {
    $p = \App\Models\Product::updateOrCreate(['slug' => 'lanebg-'.($i + 1)], [
        'name' => $name,
        'price' => 5900 + ($i * 450),
        'brand_id' => $brand->id,
        'category_id' => $category->id,
        'image' => $pic($palette[$i][0], $palette[$i][1]),
        'short_description' => 'A step that goes with the others.',
        'description' => '<p>'.$name.' is the one this routine is built around. '
            .'It layers under a cream and over a toner, and it is the step most '
            .'people notice first.</p><p>Use morning and night on clean skin.</p>',
        'status' => 'publish', 'is_visible' => true, 'stock_status' => 'instock', 'type' => 'simple',
    ]);
    $ids[] = $p->id;
}

/* The article index. Three cards, so /skincare-guide/ shows a grid of white
   cards over the wash rather than one lonely card. */
$covers = [['#F7C6D4', '#E0567B'], ['#CDE7D6', '#3E8E62'], ['#D9D2F2', '#7B6CF0']];

foreach ([
    ['lanebg-double-cleansing', 'Double cleansing, without the sermon'],
    ['lanebg-what-niacinamide-does', 'What niacinamide actually does'],
    ['lanebg-spf-in-the-gulf', 'SPF in the Gulf: the honest version'],
] as $i => [$slug, $title]) {
    \App\Models\Post::updateOrCreate(['slug' => $slug], [
        'title' => $title,
        'excerpt' => 'A short, plain answer to a question people keep asking us in the shop.',
        'body' => '<p>The short version is that most routines are two steps longer than they need to be.</p>'
            .'<p>Here is the part that matters, and the part that does not.</p>',
        'cover' => $pic($covers[$i][0], $covers[$i][1]),
        'tag' => 'Guides',
        'status' => 'published',
        'published_at' => now()->subDays(3 + $i),
    ]);
}

/* One basket, under a fixed token the shot script sets as its cookie. */
$cart = \App\Models\Cart::updateOrCreate(['token' => 'lanebg-preview-cart-token'], [
    'currency' => 'AED', 'status' => 'active', 'shipping_country' => 'AE', 'last_activity_at' => now(),
]);

$cart->items()->delete();
$cart->items()->create(['product_id' => $ids[0], 'quantity' => 2, 'unit_price' => 5900]);
$cart->items()->create(['product_id' => $ids[3], 'quantity' => 1, 'unit_price' => 7250]);
$cart->items()->create(['product_id' => $ids[9], 'quantity' => 1, 'unit_price' => 9950]);

app(\App\Services\SettingsService::class)->set('free_shipping_threshold', 200000);

echo 'seeded ', count($ids), " products, 3 articles and a basket of 4 items\n";

/* ── ARABIC AND THE MIRROR, WHICH ARE TWO SWITCHES ─────────────────────────
 *
 * /ar 404s without the first one. And Locale::direction() reads a SECOND
 * setting: with only the first, /ar renders in Arabic and left to right, so
 * every mirrored shot comes back dir="ltr" and proves nothing. Copied from
 * tools/sa-seed.php rather than rediscovered.
 *
 * It is a PREVIEW fixture. Nothing in this lane's diff seeds either setting and
 * the shop's own defaults stay off.
 */
app(\App\Services\SettingsService::class)->set(\App\Support\Locale::SETTING_ENABLED, true);
app(\App\Services\SettingsService::class)->set(\App\Support\Locale::SETTING_RTL, true);

echo "arabic and the mirror switched on for the preview\n";
