<?php
/*
 * Seed for the Lane PM preview: "Unfinished (N)" in the admin top bar.
 *
 *   an owner        to sign in as
 *   a banner set    named `Spring <b>sale</b> & "more"`, so the Unfinished list
 *                   is photographed holding markup it must print as text
 *   a category      with six products, so Catalog -> Reorder has a page to drag
 *
 * Money is integer fils.
 */
\App\Models\AdminUser::updateOrCreate(['email' => 'owner@preview.test'], [
    'name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner',
]);

\App\Models\BannerSet::updateOrCreate(['slug' => 'pm-spring'], [
    'name' => 'Spring <b>sale</b> & "more"', 'status' => 'publish', 'position' => 0,
]);

$brand = \App\Models\Brand::updateOrCreate(['slug' => 'pm-anua'], ['name' => 'Anua']);
$category = \App\Models\Category::updateOrCreate(['slug' => 'pm-toners'], ['name' => 'Toners']);

foreach (['Heartleaf 77% Toner', 'Rice Toner', 'Peach Toner', 'Green Tea Toner', 'Snail Toner', 'Pore Toner'] as $i => $name) {
    $p = \App\Models\Product::updateOrCreate(['slug' => 'pm-toner-'.$i], [
        'name' => $name, 'brand_id' => $brand->id, 'status' => 'publish', 'is_visible' => true,
        'type' => 'simple', 'price' => 6900 + $i * 500, 'stock_status' => 'instock', 'total_sales' => 100 - $i,
    ]);
    $p->categories()->syncWithoutDetaching([$category->id]);
}

echo "Seeded: owner, 1 banner set, 6 products in Toners\n";
