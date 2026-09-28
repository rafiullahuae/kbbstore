<?php

/*
 * Lane URL preview seed. Run through `artisan tinker --execute` by
 * tools/url-preview.sh, on top of DemoCatalogueSeeder.
 *
 * What the shots need, and nothing else:
 *
 *   a NESTED category, because a flat one cannot show the difference between a
 *   one-hop redirect and a two-hop one — /collections/toners/ is already
 *   canonical for a top-level `toners`;
 *
 *   a brand with copy and products, so /brands/{slug}/ is photographed with
 *   something on it rather than as an empty shell;
 *
 *   a published article, for /blog/ and /blog/{slug}/.
 */

use App\Models\Brand;
use App\Models\Category;
use App\Models\Post;
use App\Models\Product;

$skincare = Category::updateOrCreate(
    ['slug' => 'skincare'],
    ['name' => 'Skincare', 'parent_id' => null, 'path' => 'skincare', 'depth' => 0, 'position' => 0]
);

$toners = Category::updateOrCreate(
    ['slug' => 'toners'],
    ['name' => 'Toners', 'parent_id' => $skincare->id, 'path' => 'skincare/toners', 'depth' => 1, 'position' => 0]
);

$brand = Brand::updateOrCreate(['slug' => 'round-lab'], [
    'name' => 'Round Lab',
    'description' => 'Minimal Korean skincare built around one hero ingredient per range — '
        .'Dokdo seawater, birch sap, mugwort. Gentle, fragrance-free and made for daily use.',
]);

foreach ([
    ['dokdo-toner', 'Round Lab 1025 Dokdo Toner', 6900],
    ['birch-juice-moisturizer', 'Round Lab Birch Juice Moisturizing Cream', 8900],
    ['mugwort-calming-toner', 'Round Lab Mugwort Calming Toner', 7400],
    ['dokdo-cleanser', 'Round Lab 1025 Dokdo Cleanser', 5400],
] as [$slug, $name, $price]) {
    $product = Product::updateOrCreate(['slug' => $slug], [
        'name' => $name,
        'status' => 'publish',
        'is_visible' => true,
        'price' => $price,
        'stock_status' => 'instock',
        'type' => 'simple',
        'brand_id' => $brand->id,
    ]);

    $product->categories()->syncWithoutDetaching([$toners->id]);
}

Post::updateOrCreate(['slug' => 'heartleaf-extract-transforming-k-beauty-skincare'], [
    'title' => 'Heartleaf extract: transforming K-beauty skincare',
    'excerpt' => 'Why houttuynia cordata turns up in every calming serum on the shelf.',
    'body' => '<p>Heartleaf is the quiet workhorse of a calming routine. It does not exfoliate, '
        .'it does not brighten, and it does not smell of anything much — it simply stops a '
        .'reactive barrier from getting worse while the rest of the routine does its work.</p>'
        .'<p>That is why it appears in so many toners, essences and sheet masks at once.</p>',
    'tag' => 'Ingredients',
    'status' => 'published',
    'published_at' => now()->subDays(3),
]);

Post::updateOrCreate(['slug' => 'how-to-layer-a-k-beauty-routine'], [
    'title' => 'How to layer a K-beauty routine',
    'excerpt' => 'Thinnest to thickest, and where sunscreen actually goes.',
    'body' => '<p>The order is simpler than the shelf suggests: cleanse, tone, treat, moisturise, protect.</p>',
    'tag' => 'Routines',
    'status' => 'published',
    'published_at' => now()->subDays(10),
]);

echo "seeded\n";
