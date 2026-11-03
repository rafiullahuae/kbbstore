<?php
/*
 * Lane LT4 preview seed: the four products the 3 November Arabic package
 * covers, with their LIVE English name, short description and description
 * (storage/lt4-logs/lt4-fixture.json, fetched from the shop's own pages) and
 * their brands, Arabic on with RTL -- then the brands' Arabic names as the live
 * shop has them and this package are run against them, exactly as on the server.
 */
$fixture = json_decode((string) file_get_contents(base_path('storage/lt4-logs/lt4-fixture.json')), true);
$sv = app(\App\Services\SettingsService::class);

$i = 0;
foreach ($fixture as $slug => $p) {
    $brandId = null;
    if (! empty($p['brand'])) {
        $b = \App\Models\Brand::firstOrCreate(['slug' => \Illuminate\Support\Str::slug($p['brand'])], ['name' => $p['brand']]);
        $b->forceFill(['name' => $p['brand']])->save();
        $brandId = $b->id;
    }
    $file = '/uploads/products/'.$slug.'.webp';
    \App\Models\Product::updateOrCreate(['slug' => $slug], [
        'name' => $p['name'], 'short_description' => $p['short'] ?: null, 'description' => $p['desc'] ?: null,
        // The live page reads "87.00" AED; the column is in fils.
        'price' => (int) round(((float) ($p['price'] ?: 99)) * 100),
        'image' => is_file(public_path($file)) ? $file : null,
        'brand_id' => $brandId,
        'status' => 'publish', 'is_visible' => true, 'stock_status' => 'instock', 'type' => 'simple',
        'created_at' => now()->subMinutes(100 - $i++),
    ]);
}

$sv->set(\App\Support\Locale::SETTING_ENABLED, true);
$sv->set(\App\Support\Locale::SETTING_RTL, true);

// The brands' Arabic names as the live shop has them (earlier packages), then this package.
$brandAr = ['Dr.Althea' => 'الدكتورة ألثيا', 'Medicube' => 'ميديكوب'];
foreach (\App\Models\Brand::whereIn('name', array_keys($brandAr))->get() as $b) {
    \Illuminate\Support\Facades\DB::table('translations')->updateOrInsert(['locale' => 'ar', 'group' => 'brands', 'item_id' => $b->id, 'field' => 'name'],
        ['value' => $brandAr[$b->name], 'status' => 'published', 'source' => 'manual', 'created_at' => now(), 'updated_at' => now()]);
}
(require base_path('database/migrations/2028_01_06_100000_arabic_new_products_3_november.php'))->up();

$sv->flush();
\App\Models\Setting::flushMap();
\App\Services\SettingsService::forgetMemo();
\App\Services\Translation\TranslationStore::flush();

echo 'lt4 seeded: products=', \App\Models\Product::count(),
    ' ar rows=', \Illuminate\Support\Facades\DB::table('translations')->where('locale', 'ar')->count(), "\n";
