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
}

echo $set->slider_style."\n";
