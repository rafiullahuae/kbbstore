<?php
/*
 * Seed the Lane PS preview (product review snippets): a brand, a category and
 * twelve products. `ps-heartleaf-toner` carries three approved reviews and one
 * pending one; `ps-snail-essence` has none; `ps-birch-sun` is on sale;
 * `ps-mugwort-mask` is sold out. Run by tools/psn-preview.sh.
 */
$brand = \App\Models\Brand::updateOrCreate(['slug' => 'anua'], ['name' => 'Anua']);
$category = \App\Models\Category::updateOrCreate(['slug' => 'toners'], ['name' => 'Toners', 'path' => 'toners']);

$pic = function (string $a, string $b): string {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="240" height="240"><rect width="240" height="240" fill="'.$a.'"/><circle cx="120" cy="120" r="70" fill="'.$b.'"/></svg>';

    return 'data:image/svg+xml;base64,'.base64_encode($svg);
};

$names = ['Heartleaf Toner', 'Azelaic Acid 10 Serum', 'Rice 70 Glow Milky Toner', 'Snail Essence',
    'Ceramide Daily Moisturiser', 'Green Tea Seed Serum', 'Centella Ampoule', 'Birch Sun',
    'Mugwort Mask', 'Propolis Light Ampoule', 'Retinal Night Cream', 'Peach Slices Toner Pad'];
foreach ($names as $i => $name) {
    $slug = 'ps-'.\Illuminate\Support\Str::slug($name);
    $extra = match ($slug) {
        'ps-birch-sun' => ['sale_price' => 3900, 'sale_starts_at' => now()->subDay(), 'sale_ends_at' => now()->addDays(10)],
        'ps-mugwort-mask' => ['stock_status' => 'outofstock', 'stock' => 0],
        default => [],
    };
    $p = \App\Models\Product::updateOrCreate(['slug' => $slug], array_merge([
        'name' => $name, 'price' => 4900 + $i * 700, 'brand_id' => $brand->id, 'sku' => 'PS-'.($i + 1),
        'image' => $pic(['#F7C6D4', '#CDE7D6', '#FFE6B8', '#D9D2F2'][$i % 4], ['#E0567B', '#3E8E62', '#E0922F', '#7B6CF0'][$i % 4]),
        'short_description' => 'A gentle '.$name.' for calm, hydrated skin.',
        'status' => 'publish', 'is_visible' => true, 'stock_status' => 'instock', 'type' => 'simple',
        'manage_stock' => true, 'stock' => 24,
    ], $extra));
    $p->categories()->syncWithoutDetaching([$category->id]);
}

$toner = \App\Models\Product::where('slug', 'ps-heartleaf-toner')->first();
\App\Models\Review::where('product_id', $toner->id)->delete();
foreach ([
    ['Fatima Al Mansoori', 5, 'Calm skin in a week', 'My redness is down and it layers well under sunscreen.', 'approved', 9],
    ['Omar Khalid', 4, '', 'Good toner, a little watery. Would buy again.', 'approved', 5],
    ['Sara', 5, 'Holy grail', 'Third bottle. Nothing else comes close for my skin.', 'approved', 2],
    ['Pending Person', 1, 'not yet moderated', 'This must not appear anywhere.', 'pending', 1],
] as [$name, $stars, $title, $body, $status, $ago]) {
    (new \App\Models\Review)->forceFill([
        'product_id' => $toner->id, 'author_name' => $name, 'author_email' => strtolower(str_replace(' ', '.', $name)).'@example.test',
        'ip' => '203.0.113.9', 'rating' => $stars, 'title' => $title, 'content' => $body, 'status' => $status,
        'created_at' => now()->subDays($ago), 'updated_at' => now()->subDays($ago),
    ])->save();
}
\App\Support\ProductRating::refresh([$toner->id]);
// The Arabic pages exist only while the Arabic storefront is switched on.
app(\App\Services\SettingsService::class)->set(\App\Support\Locale::SETTING_ENABLED, '1');
\App\Services\SettingsService::forgetMemo();
\Illuminate\Support\Facades\Cache::flush();
echo "seeded\n";
