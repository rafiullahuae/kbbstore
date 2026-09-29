<?php
/* Flip one flag-bar switch in the preview, so the same page can be photographed
   and measured with the strip and without it. Nothing else is touched.

   Driven by environment rather than by $argv, because this is run through
   `artisan tinker --execute`, where $argv is artisan's own and a positional
   read there silently takes the wrong string — it reported "fb_mobile = on"
   while setting nothing of the kind. */
$key = getenv('FB_KEY') ?: 'fb_mobile';
$on = getenv('FB_ON') === '1';

app(\App\Services\HeaderSettings::class)->save([$key => $on]);
\App\Models\Setting::flushMap();
\App\Services\SettingsService::forgetMemo();
app(\App\Services\SettingsService::class)->flush();

echo $key.' = '.($on ? 'on' : 'off').'; now: '
    .json_encode(array_intersect_key(app(\App\Services\HeaderSettings::class)->all(),
        array_flip(['fb_mobile', 'fb_desktop'])))."\n";
