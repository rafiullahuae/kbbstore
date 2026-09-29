<?php
/* Lane SA2 — reset everything, then switch the panel OFF, so the shots can show
   that "today's bare list" is still reachable. */
$s = app(\App\Services\SettingsService::class);
foreach (array_keys(\App\Services\SetAppearance::SCHEMA) as $k) {
    $s->set(\App\Services\SetAppearance::PREFIX.$k, null);
}
app(\App\Services\SetAppearance::class)->save(['p_panel_on' => false]);
echo "bare\n";
