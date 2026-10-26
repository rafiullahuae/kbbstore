<?php

declare(strict_types=1);

/*
 * Lane GU2: inside tools/fg-preview.sh's environment (a preview database,
 * never a real one), switch the homepage free-gifts section on and set the
 * homepage line from GU2_HOME (JSON merged into `home`) and GU2_LOOK (JSON
 * merged into `look`). Used by tools/gu2-shots.cjs between shots.
 */

$fg = app(\App\Services\FreeGifts::class);
$config = $fg->freshConfig();
$config['home']['on'] = true;
$config['home'] = (json_decode((string) getenv('GU2_HOME'), true) ?: []) + $config['home'];
$config['look'] = \App\Services\FreeGifts::lookOf((json_decode((string) getenv('GU2_LOOK'), true) ?: []) + $config['look']);
$fg->saveConfig($config);
\Illuminate\Support\Facades\Cache::flush();
echo 'gu2: home.urgency=' . json_encode($config['home']['urgency']) . ' look=' . json_encode(array_intersect_key($config['look'], array_flip(['u_px', 'u_px_m', 'u_weight', 'u_speed']))) . "\n";
