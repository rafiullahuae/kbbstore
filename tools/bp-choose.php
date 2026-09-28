<?php
/* Point the homepage at one of the preview's four sets.
 *
 * Run through `artisan tinker --execute` with BP_SET in the environment. It
 * exists so the shot script can photograph four sets on the REAL homepage
 * rather than four admin previews — a preview inside an iframe is a different
 * layout question from a section inside `.wrap`, and the negative-margin bleed
 * this round adds is only meaningful against the page's own gutters.
 */
$id = (int) (getenv('BP_SET') ?: 1);

app(\App\Services\SettingsService::class)->setModuleSetting(\App\Services\Banners::MODULE, 'set', (string) $id);

echo "homepage set = {$id}\n";
