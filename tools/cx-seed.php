<?php
/*
 * Seed the Lane CX preview (Shop by skin concern, design E): Lane HA's seed —
 * the demo catalogue with product photographs, so Trending draws above the
 * section — plus the seven concern collections the live shop has, their
 * Arabic names, and Arabic with the mirrored layout on so /ar can be shot.
 * Written into the PREVIEW's database only; nothing here reaches a package.
 */
use App\Models\Category;
use App\Models\Setting;
use App\Support\Locale;

require __DIR__.'/ha-seed.php';

$seven = [
    ['Pore Care', 'pore-care', 'العناية بالمسام'],
    ['Pigmentation & Dark Spots', 'pigmentation', 'التصبّغات والبقع الداكنة'],
    ['Acne & Blemish Care', 'acne-blemish-care', 'حبّ الشباب والشوائب'],
    ['Anti-Aging', 'anti-aging', 'مكافحة الشيخوخة'],
    ['Hydration & Glow', 'hydration-glow', 'الترطيب والنضارة'],
    ['Redness & Sensitive Skin', 'redness-sensitive-skin', 'الاحمرار والبشرة الحسّاسة'],
    ['Oily Skin', 'oily-skin', 'البشرة الدهنية'],
];

foreach ($seven as [$name, $slug, $ar]) {
    $c = Category::updateOrCreate(['slug' => $slug], ['name' => $name, 'path' => $slug]);
    \Illuminate\Support\Facades\DB::table('translations')->updateOrInsert(
        ['locale' => 'ar', 'group' => 'categories', 'item_id' => $c->id, 'field' => 'name'],
        ['value' => $ar, 'status' => 'published']
    );
}

foreach ([Locale::SETTING_ENABLED => '1', Locale::SETTING_RTL => '1'] as $k => $v) {
    Setting::query()->updateOrCreate(['key' => $k], ['value' => $v, 'autoload' => true]);
}

Setting::flushMap();
\Illuminate\Support\Facades\Cache::flush();
echo 'cx seed: '.Category::query()->whereIn('slug', array_column($seven, 1))->count()." concern categories\n";
