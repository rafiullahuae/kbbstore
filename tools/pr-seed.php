<?php
/*
 * Seed the Lane PR preview.
 *
 * tools/card-seed.php's twelve products (the card states: sale, plain, no
 * photo, long names, reviewed and not), PLUS:
 *
 *   - two of them created TWO DAYS AGO, so the NEW pill is on the page in the
 *     BEFORE shots and its absence in the AFTER shots means something;
 *   - thirty more in the same category and one brand, so the category, /shop/
 *     and the brand landing page all run past one batch of twelve and past the
 *     old twenty-four — a listing that fits on one page cannot show a loader,
 *     a pager or the grey placeholders at all.
 *
 * The probe slug tools/pr-preview.sh asks for (card-relief-sun) is card-seed's.
 */
require __DIR__.'/card-seed.php';

$prCategory = \App\Models\Category::where('slug', 'skincare-sets')->firstOrFail();
$prBrand = \App\Models\Brand::where('slug', 'cosrx')->firstOrFail();

// NEW pills in the first row, on a reviewed product with a markdown (both
// pills on one tile) and on one at a single price.
\App\Models\Product::whereIn('slug', ['card-barrier-cream', 'card-centella'])
    ->update(['created_at' => now()->subDays(2)]);

$prTints = [['#FFC1AD', '#F58F72'], ['#C9B6F5', '#9B7FE8'], ['#9BE3C4', '#5BC79A'], ['#FFE7A8', '#F2CE5E'], ['#A8D8FF', '#6FB4F2']];

for ($i = 1; $i <= 30; $i++) {
    [$a, $b] = $prTints[$i % count($prTints)];
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="600" height="600"><rect width="600" height="600" fill="'.$a.'"/>'
        .'<circle cx="300" cy="300" r="170" fill="'.$b.'"/><text x="300" y="350" font-family="sans-serif" font-size="140" font-weight="700" fill="#fff" text-anchor="middle">'.$i.'</text></svg>';

    $product = \App\Models\Product::updateOrCreate(['slug' => 'pr-extra-'.$i], [
        'name' => sprintf('Zz Extra %02d Cica Calming Gel Cream', $i),
        'brand_id' => $prBrand->id,
        'price' => 9000 + $i * 100,
        'sale_price' => $i % 3 === 0 ? 7000 + $i * 100 : null,
        'image' => 'data:image/svg+xml;base64,'.base64_encode($svg),
        'status' => 'publish',
        'is_visible' => true,
        'stock_status' => 'instock',
        'type' => 'simple',
        'rating' => $i % 2 ? 4.5 : 0,
        'review_count' => $i % 2 ? 10 + $i : 0,
        'created_at' => now()->subYear(),
        'updated_at' => now()->subYear(),
    ]);

    $product->categories()->syncWithoutDetaching([$prCategory->id]);
}

\App\Models\Setting::flushMap();
\App\Services\SettingsService::forgetMemo();
app(\App\Services\SettingsService::class)->flush();

echo "pr: 30 extra COSRX products in /collections/skincare-sets/, /brands/cosrx/ and /shop/\n";
