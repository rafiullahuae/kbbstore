<?php

declare(strict_types=1);

/*
 * Lane MN: tools/mac-seed.php's shop with the Arabic storefront switched on, so
 * the side panel can be photographed opening from the right in Arabic. Run
 * through tools/mn-preview.sh; never against a real database.
 */

require __DIR__.'/mac-seed.php';

app(\App\Services\SettingsService::class)->set('language_ar_enabled', '1');
app(\App\Services\SettingsService::class)->set('language_rtl_enabled', '1');
\Illuminate\Support\Facades\Cache::flush();
