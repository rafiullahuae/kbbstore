<?php

declare(strict_types=1);

/*
 * Lane GBX, the real feature: tools/gbx-seed.php's shop with free gifts LIVE
 * (so an address that is not on the Testing list still sees the gift box)
 * and Gift box popups at their shipped default, Off -- the shots switch them
 * from the admin. FG_SEED=gbx-feature-seed.php sh tools/fg-preview.sh 12380
 */

require __DIR__.'/gbx-seed.php';

$fg = app(\App\Services\FreeGifts::class);
$config = $fg->freshConfig();
$config['mode'] = 'live';
$config['on'] = true;
// GBX_X='{"mode":"testing"}' saves the popups' settings too (through extrasOf()).
$x = json_decode((string) getenv('GBX_X'), true);
if (is_array($x)) {
    $config['extras'] = \App\Services\FreeGifts::extrasOf($x);
}
$fg->saveConfig($config);
$fg->compile();
\Illuminate\Support\Facades\Cache::flush();
echo 'gbx-feature: free gifts live, popups '.$fg->freshConfig()['extras']['mode']."\n";
