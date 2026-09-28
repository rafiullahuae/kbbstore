<?php
/*
 * Seed the Lane SET preview: an owner, four ordinary products with pictures,
 * one SET made of three of them, and a basket that already holds the set plus
 * an ordinary product — so one screenshot shows the set row and the row it has
 * to sit beside without disturbing.
 *
 * MONEY IS INTEGER FILS here too. A fixture that used major units would be the
 * one place on this path where a price was not what the column holds.
 */
\App\Models\AdminUser::updateOrCreate(['email' => 'owner@preview.test'], [
    'name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner',
]);

$brand = \App\Models\Brand::updateOrCreate(['slug' => 'anua'], ['name' => 'Anua']);
$category = \App\Models\Category::updateOrCreate(['slug' => 'sets'], ['name' => 'Sets']);

/* Tiny inline SVG pictures, so the fan of circles has real images in it without
   this script needing the network or a media library. */
$pic = function (string $a, string $b): string {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="120" height="120">'
        .'<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1">'
        .'<stop offset="0" stop-color="'.$a.'"/><stop offset="1" stop-color="'.$b.'"/>'
        .'</linearGradient></defs><rect width="120" height="120" fill="url(#g)"/></svg>';

    return 'data:image/svg+xml;base64,'.base64_encode($svg);
};

$members = [
    ['Heartleaf 77% Soothing Toner 250ml', 'laneset-heartleaf-toner', 9000, $pic('#F7C6D4', '#E0567B')],
    ['Azelaic Acid 10 Serum 30ml', 'laneset-azelaic-serum', 7550, $pic('#CDE7D6', '#3E8E62')],
    ['Rice 70 Glow Milky Toner 250ml', 'laneset-milky-toner', 6900, $pic('#FFE6B8', '#E0922F')],
];

$ids = [];

foreach ($members as [$name, $slug, $fils, $image]) {
    $p = \App\Models\Product::updateOrCreate(['slug' => $slug], [
        'name' => $name, 'price' => $fils, 'brand_id' => $brand->id, 'image' => $image,
        'status' => 'publish', 'is_visible' => true, 'stock_status' => 'instock', 'type' => 'simple',
    ]);
    $ids[] = $p->id;
}

$plain = \App\Models\Product::updateOrCreate(['slug' => 'laneset-ceramide-moisturiser'], [
    'name' => 'Ceramide Daily Moisturiser 100ml', 'price' => 12900, 'brand_id' => $brand->id,
    'image' => $pic('#D9D2F2', '#7B6CF0'),
    'status' => 'publish', 'is_visible' => true, 'stock_status' => 'instock', 'type' => 'simple',
]);

/* THE SET. A `products` row with type='set' and nothing else special: it
   publishes, gets a page and goes in a basket with no other change anywhere. */
$set = \App\Models\Product::updateOrCreate(['slug' => 'laneset-glow-starter-set'], [
    'name' => 'Glow Starter Set',
    'type' => 'set',
    'price' => 19900,                       // fils. Parts are 23450 — saving 35.50.
    'brand_id' => $brand->id,
    'category_id' => $category->id,
    'image' => $pic('#FBD9E4', '#C9587F'),
    'short_description' => 'Three steps, one box.',
    'description' => '<p>The toner, the serum and the milky toner that go together.</p>',
    'status' => 'publish', 'is_visible' => true, 'stock_status' => 'instock',
]);

\App\Models\ProductSetItem::where('set_product_id', $set->id)->delete();

foreach ($ids as $i => $memberId) {
    \App\Models\ProductSetItem::create([
        'set_product_id' => $set->id,
        'member_product_id' => $memberId,
        'quantity' => $i === 0 ? 2 : 1,
        'position' => $i,
    ]);
}

/* A basket holding the set AND an ordinary product, under a fixed token the
   shot script sets as its cookie. */
$cart = \App\Models\Cart::updateOrCreate(['token' => 'laneset-preview-cart-token'], [
    'currency' => 'AED', 'status' => 'active', 'shipping_country' => 'AE', 'last_activity_at' => now(),
]);

$cart->items()->delete();
$cart->items()->create(['product_id' => $set->id, 'quantity' => 1, 'unit_price' => 19900]);
$cart->items()->create(['product_id' => $plain->id, 'quantity' => 1, 'unit_price' => 12900]);

app(\App\Services\SettingsService::class)->set('free_shipping_threshold', 20000);

echo 'seeded set #', $set->id, ' with ', count($ids), " members and a basket\n";
