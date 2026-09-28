<?php
/*
 * Seed the Lane SP preview.
 *
 * An owner, four ordinary products with pictures, one SET made of three of them
 * priced as 10% off its parts, and one member left as a DRAFT so the product
 * page's set panel can be photographed with a linked member and an unlinked
 * one side by side.
 *
 * MONEY IS INTEGER FILS here too. A fixture in major units would be the one
 * place on this path where a price was not what the column holds.
 */
\App\Models\AdminUser::updateOrCreate(['email' => 'owner@preview.test'], [
    'name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner',
]);

$brand = \App\Models\Brand::updateOrCreate(['slug' => 'anua'], ['name' => 'Anua']);
$category = \App\Models\Category::updateOrCreate(['slug' => 'sets'], ['name' => 'Sets']);

/* Tiny inline SVG pictures, so the panel has real images in it without this
   script needing the network or a media library. */
$pic = function (string $a, string $b): string {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="240" height="240">'
        .'<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1">'
        .'<stop offset="0" stop-color="'.$a.'"/><stop offset="1" stop-color="'.$b.'"/>'
        .'</linearGradient></defs><rect width="240" height="240" fill="url(#g)"/></svg>';

    return 'data:image/svg+xml;base64,'.base64_encode($svg);
};

$members = [
    ['Heartleaf 77% Soothing Toner 250ml', 'lanesp-heartleaf-toner', 9000, $pic('#F7C6D4', '#E0567B'), 'publish'],
    ['Azelaic Acid 10 Serum 30ml', 'lanesp-azelaic-serum', 7550, $pic('#CDE7D6', '#3E8E62'), 'publish'],
    // DRAFT on purpose: the set page names it and must NOT link to it, because
    // its own page 404s. That is the half of the panel worth photographing.
    ['Rice 70 Glow Milky Toner 250ml', 'lanesp-milky-toner', 6900, $pic('#FFE6B8', '#E0922F'), 'draft'],
];

$ids = [];

foreach ($members as [$name, $slug, $fils, $image, $status]) {
    $p = \App\Models\Product::updateOrCreate(['slug' => $slug], [
        'name' => $name, 'price' => $fils, 'brand_id' => $brand->id, 'image' => $image,
        'status' => $status, 'is_visible' => true, 'stock_status' => 'instock', 'type' => 'simple',
        'manage_stock' => true, 'stock' => 24,
    ]);
    $ids[] = $p->id;
}

$plain = \App\Models\Product::updateOrCreate(['slug' => 'lanesp-ceramide-moisturiser'], [
    'name' => 'Ceramide Daily Moisturiser 100ml', 'price' => 12900, 'brand_id' => $brand->id,
    'image' => $pic('#D9D2F2', '#7B6CF0'),
    'status' => 'publish', 'is_visible' => true, 'stock_status' => 'instock', 'type' => 'simple',
]);

/* THE SET. A `products` row with type='set', priced as a RULE rather than a
   number: 10% off whatever its members cost today. */
$set = \App\Models\Product::updateOrCreate(['slug' => 'lanesp-glow-starter-set'], [
    'name' => 'Glow Starter Set',
    'type' => 'set',
    'brand_id' => $brand->id,
    'category_id' => $category->id,
    'image' => $pic('#FBD9E4', '#C9587F'),
    'short_description' => 'Three steps, one box.',
    'description' => '<p>The toner, the serum and the milky toner that go together.</p>',
    'status' => 'publish', 'is_visible' => true, 'stock_status' => 'instock',
    'set_price_mode' => \App\Support\SetPricing::MODE_PERCENT,
    'set_discount' => 1000,
    'price' => 0,
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

/* The cached price column, refreshed the way the editor refreshes it. Parts are
   2x9000 + 7550 + 6900 = 32450; 10% off is 29205. */
\App\Support\SetPricing::forget((int) $set->id);
$set->price = \App\Support\SetPricing::derived($set->fresh()) ?? 0;
$set->save();

/* A second, fixed-price set, so the Sets list and the Catalog chip have two
   rows and the "Priced from the box" pill is visibly only on one. */
$fixed = \App\Models\Product::updateOrCreate(['slug' => 'lanesp-night-repair-set'], [
    'name' => 'Night Repair Set',
    'type' => 'set',
    'brand_id' => $brand->id,
    'image' => $pic('#D6E4F7', '#3B6FB0'),
    'status' => 'publish', 'is_visible' => true, 'stock_status' => 'instock',
    'price' => 17900,
    'set_price_mode' => \App\Support\SetPricing::MODE_FIXED,
    'set_discount' => null,
]);

\App\Models\ProductSetItem::where('set_product_id', $fixed->id)->delete();
\App\Models\ProductSetItem::create([
    'set_product_id' => $fixed->id,
    'member_product_id' => $plain->id,
    'quantity' => 1,
    'position' => 0,
]);

echo 'seeded set #', $set->id, ' (', count($ids), ' members, 10% off the box, price ',
    $set->fresh()->price, " fils)\n";
echo 'seeded fixed set #', $fixed->id, ', plain product #', $plain->id, "\n";
