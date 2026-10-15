<?php

declare(strict_types=1);

/*
 * 2.60.512 preview: the coupon ticket (design A) on the cart panel, the cart
 * page and the checkout. Lane QK6's seed (catalogue, UAE delivery, cash on
 * delivery, the GLOW coupon chosen) plus Arabic on, so /ar/ can be shot.
 * Preview database only; nothing here reaches a package.
 */
require __DIR__.'/qk6-seed.php';

foreach ([\App\Support\Locale::SETTING_ENABLED => '1', \App\Support\Locale::SETTING_RTL => '1'] as $k => $v) {
    \App\Models\Setting::query()->updateOrCreate(['key' => $k], ['value' => $v, 'autoload' => true]);
}
\App\Models\Setting::query()->updateOrCreate(['key' => 'translation_highlight_missing'], ['value' => '0', 'autoload' => true]);
\App\Models\Setting::flushMap();
\Illuminate\Support\Facades\Cache::flush();
echo "c512 seed done\n";
