<?php

declare(strict_types=1);

/*
 * Lane FG5: tools/fg4-seed.php's shop, with Free gifts in TESTING for this
 * machine's address (127.0.0.1, the preview's own shopper) -- the state the
 * owner tests in. FG5_LOOK, a JSON object, saves a "Gift box look" over the
 * defaults, so the shots can be taken at each control without the admin.
 *   FG_SEED=fg5-seed.php sh tools/fg-preview.sh 10960 -- never against a real database.
 */

require __DIR__.'/fg4-seed.php';

// The owner's screenshot: long titles running up to FREE. Two real-length names.
\Illuminate\Support\Facades\DB::table('products')->where('name', 'Senka Perfect Whip Fresh Beauty Face Foam')
    ->update(['name' => 'Senka Perfect Whip Fresh Beauty Face Foam Cleanser for Normal to Dry Skin 120g']);
\Illuminate\Support\Facades\DB::table('products')->where('name', 'Black Rice Hyaluronic Toner Mini')
    ->update(['name' => 'Black Rice Hyaluronic Toner for Sensitive Skin Mini 30ml']);

// Arabic drawn right to left (Languages -> "Mirrored layout"), so the RTL box is the one photographed.
app(\App\Services\SettingsService::class)->set(\App\Support\Locale::SETTING_RTL, '1');

$fg = app(\App\Services\FreeGifts::class);
$config = $fg->freshConfig();
$config['mode'] = 'testing';
$config['testers'] = [['ip' => '127.0.0.1', 'label' => 'Lane FG5 preview']];
$look = json_decode((string) getenv('FG5_LOOK'), true);
if (is_array($look)) {
    $config['look'] = $look + ($config['look'] ?? []);
}
$fg->saveConfig($config);
\Illuminate\Support\Facades\Cache::flush();
echo 'free gifts: testing for 127.0.0.1'.(is_array($look) ? ', look '.json_encode($look) : '')."\n";
