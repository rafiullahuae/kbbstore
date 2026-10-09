<?php
/*
 * Seed the Lane AN preview: an owner, a brand, a category and twelve products,
 * so the product, category and brand pages exist to be timed, and the board
 * has pages to name. Volume (100k hits, 90 days of summaries, orders by
 * source) is tools/an-volume.php, run separately and removed again.
 */
\App\Models\AdminUser::updateOrCreate(['email' => 'owner@preview.test'], [
    'name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner',
]);

$brand = \App\Models\Brand::updateOrCreate(['slug' => 'anua'], ['name' => 'Anua']);
$category = \App\Models\Category::updateOrCreate(['slug' => 'toners'], ['name' => 'Toners']);

$pic = function (string $a, string $b): string {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="240" height="240"><rect width="240" height="240" fill="'.$a.'"/><circle cx="120" cy="120" r="70" fill="'.$b.'"/></svg>';

    return 'data:image/svg+xml;base64,'.base64_encode($svg);
};

$names = ['Heartleaf 77% Soothing Toner', 'Azelaic Acid 10 Serum', 'Rice 70 Glow Milky Toner', 'Snail 96 Mucin Essence',
    'Ceramide Daily Moisturiser', 'Green Tea Seed Serum', 'Centella Ampoule', 'Birch Juice Sunscreen',
    'Mugwort Mask', 'Propolis Light Ampoule', 'Retinal Night Cream', 'Peach Slices Toner Pad'];
foreach ($names as $i => $name) {
    $p = \App\Models\Product::updateOrCreate(['slug' => 'an-'.\Illuminate\Support\Str::slug($name)], [
        'name' => $name, 'price' => 4900 + $i * 700, 'brand_id' => $brand->id, 'category_id' => $category->id,
        'image' => $pic(['#F7C6D4', '#CDE7D6', '#FFE6B8', '#D9D2F2'][$i % 4], ['#E0567B', '#3E8E62', '#E0922F', '#7B6CF0'][$i % 4]),
        'status' => 'publish', 'is_visible' => true, 'stock_status' => 'instock', 'type' => 'simple',
        'manage_stock' => true, 'stock' => 24,
    ]);
    $p->categories()->syncWithoutDetaching([$category->id]);
}
echo "seeded\n";
