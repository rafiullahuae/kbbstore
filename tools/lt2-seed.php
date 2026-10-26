<?php
/*
 * Lane LT2 preview seed: the 32 products the 26 October Arabic package covers,
 * with their LIVE English name, short description, description and INCI list
 * (storage/lt2-logs/lt2-fixture.json, fetched from the shop's own pages), the
 * seven Shop by Skin Concern collections, Arabic on with RTL -- then the
 * package's migration is run against them, exactly as on the server.
 */
$fixture = json_decode((string) file_get_contents(base_path('storage/lt2-logs/lt2-fixture.json')), true);
$sv = app(\App\Services\SettingsService::class);

foreach (\App\Services\ConcernSync\ConcernSync::CONCERNS as $slug => $name) {
    \App\Models\Category::updateOrCreate(['slug' => $slug], ['name' => $name, 'path' => $slug]);
}

$brands = ['arencia' => 'Arencia', 'celimax' => 'celimax', 'abib' => 'Abib', 'centellian24' => 'Centellian24',
    'numbuzin' => 'numbuzin', 'medi-peel' => 'MEDIPEEL', 'sungboon-editor' => 'SUNGBOON EDITOR'];
foreach ($brands as $slug => $name) {
    \App\Models\Brand::updateOrCreate(['slug' => $slug], ['name' => $name]);
}

$i = 0;
foreach ($fixture as $slug => $p) {
    $brandSlug = null;
    foreach ($brands as $b => $n) {
        if (str_starts_with($slug, explode('-', $b)[0])) {
            $brandSlug = $b;
        }
    }
    $file = '/uploads/products/'.$slug.'.webp';
    \App\Models\Product::updateOrCreate(['slug' => $slug], [
        'name' => $p['name'], 'short_description' => $p['short'], 'description' => $p['desc'],
        'ingredients' => $p['ingr'] ?: null, 'price' => (int) ($p['price'] ?: 9900),
        'image' => is_file(public_path($file)) ? $file : null,
        'brand_id' => $brandSlug ? \App\Models\Brand::where('slug', $brandSlug)->value('id') : null,
        'status' => 'publish', 'is_visible' => true, 'stock_status' => 'instock', 'type' => 'simple',
        'created_at' => now()->subMinutes(100 - $i++),
    ]);
}

$sv->set(\App\Support\Locale::SETTING_ENABLED, true);
$sv->set(\App\Support\Locale::SETTING_RTL, true);

// The 23 October package first (it gave these products their Arabic names on
// the live shop), then this one.
(require base_path('database/migrations/2027_11_29_100000_arabic_new_products_23_october.php'))->up();
(require base_path('database/migrations/2027_12_25_100000_arabic_and_skin_concerns_26_october.php'))->up();

$sv->flush();
\App\Models\Setting::flushMap();
\App\Services\SettingsService::forgetMemo();
\App\Services\Translation\TranslationStore::flush();

echo 'lt2 seeded: products=', \App\Models\Product::count(),
    ' ar rows=', \Illuminate\Support\Facades\DB::table('translations')->where('locale', 'ar')->count(),
    ' concern links=', \Illuminate\Support\Facades\DB::table('category_product')->count(), "\n";
