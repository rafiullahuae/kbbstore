<?php
/*
 * Lane LT3 preview seed: the 29 products the 31 October Arabic package covers,
 * with their LIVE English name, short description and description
 * (storage/lt3-logs/lt3-fixture.json, fetched from the shop's own pages) and
 * their brands, Arabic on with RTL -- then the earlier Arabic packages (which
 * give the brands their Arabic names on the live shop) and this one are run
 * against them, exactly as on the server.
 */
$fixture = json_decode((string) file_get_contents(base_path('storage/lt3-logs/lt3-fixture.json')), true);
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
        'price' => (int) ($p['price'] ?: 9900),
        'image' => is_file(public_path($file)) ? $file : null,
        'brand_id' => $brandId,
        'status' => 'publish', 'is_visible' => true, 'stock_status' => 'instock', 'type' => 'simple',
        'created_at' => now()->subMinutes(100 - $i++),
    ]);
}

$sv->set(\App\Support\Locale::SETTING_ENABLED, true);
$sv->set(\App\Support\Locale::SETTING_RTL, true);

// The brands' Arabic names as the live shop has them (earlier packages), then this package.
$brandAr = ['Anua' => 'أنوا', 'Axis - Y' => 'أكسيس-واي', 'BIODANCE' => 'بيودانس', 'Centellian24' => 'سنتيليان 24', 'Haruharu Wonder' => 'هاروهارو وندر',
    'Heimish' => 'هيميش', 'Isntree' => 'إيزنتري', 'Mary&May' => 'ماري آند ماي', 'Medicube' => 'ميديكوب', 'MEDI-PEEL' => 'ميدي بيل', 'Purito SEOUL' => 'بيوريتو سيول',
    'ROUND LAB' => 'راوند لاب', 'SUNGBOON EDITOR' => 'سونغبون إديتور', 'TIRTIR' => 'تيرتير', 'Torriden' => 'توريدن'];
foreach (\App\Models\Brand::whereIn('name', array_keys($brandAr))->get() as $b) {
    \Illuminate\Support\Facades\DB::table('translations')->updateOrInsert(['locale' => 'ar', 'group' => 'brands', 'item_id' => $b->id, 'field' => 'name'],
        ['value' => $brandAr[$b->name], 'status' => 'published', 'source' => 'manual', 'created_at' => now(), 'updated_at' => now()]);
}
(require base_path('database/migrations/2027_12_31_230000_arabic_new_products_31_october.php'))->up();
(require base_path('database/migrations/2027_12_31_230100_arabic_short_descriptions_31_october.php'))->up();

$sv->flush();
\App\Models\Setting::flushMap();
\App\Services\SettingsService::forgetMemo();
\App\Services\Translation\TranslationStore::flush();

echo 'lt3 seeded: products=', \App\Models\Product::count(),
    ' ar rows=', \Illuminate\Support\Facades\DB::table('translations')->where('locale', 'ar')->count(), "\n";
