<?php
// Lane BN2: flip the seeded slider between its four treatments, and switch
// Arabic on for the /ar half of the shoot.
require __DIR__.'/../../vendor/autoload.php';
$app = require_once __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\BannerSet;
use App\Models\Setting;

$style = $argv[1] ?? 'inset';
$set = BannerSet::query()->orderBy('id')->first();
$set->slider_style = $style;
$set->save();

if (($argv[2] ?? '') === 'ar') {
    Setting::query()->updateOrCreate(['key' => 'language_ar_enabled'], ['value' => '1']);
    Setting::query()->updateOrCreate(['key' => 'language_rtl_enabled'], ['value' => '1']);

    // The Arabic interface drafts are a SOURCE, not a seeded table: a fresh
    // database has none of them published, so /ar renders the English strings
    // for the whole shop and not only for this section. Published here so the
    // Arabic screenshot is of an Arabic shop.
    if (\App\Models\Translation::query()->where('locale', 'ar')->count() < 10) {
        foreach (\App\Services\Translation\ArabicInterfaceDrafts::all() as $key => $value) {
            \App\Services\Translation\TranslationStore::put(
                'ar', \App\Models\Translation::GROUP_UI, 0, $key, $value,
                \App\Models\Translation::STATUS_PUBLISHED,
            );
        }
    }
}

echo $set->slider_style."\n";
