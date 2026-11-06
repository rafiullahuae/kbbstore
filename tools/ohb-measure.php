<?php

declare(strict_types=1);

/*
 * Lane OHB: switch the preview's occasion banner and measure the homepage
 * in-process -- server ms (median of 9 after 2 warm-ups), queries, settings
 * reads are the memo's, HTML bytes. Run through tools/ohb-preview.sh's env:
 *   OHB_SET='{"display":"both","layout":"H","template":"uae"}' php artisan tinker --execute="require 'tools/ohb-measure.php';"
 * OHB_PATHS (comma list, default "/") are measured after the switch.
 */

use App\Services\SettingsService;
use App\Support\OccasionBanner;
use Illuminate\Support\Facades\DB;

$s = app(SettingsService::class);
$keep = base_path('storage/ohb-logs/ohb-setting.json');
if (getenv('OHB_SET') === 'none') { // the shipped state: no row at all
    file_put_contents($keep, json_encode($s->get(OccasionBanner::KEY)));
    \App\Models\Setting::query()->where('key', OccasionBanner::KEY)->delete();
    $s->set('ohb_probe', null);
    \App\Models\Setting::query()->where('key', 'ohb_probe')->delete();
    echo "setting removed\n";
} elseif (getenv('OHB_SET') === 'restore') {
    $s->set(OccasionBanner::KEY, json_decode((string) file_get_contents($keep), true));
    echo "setting restored\n";
}
if (getenv('OHB_AR') === '1') { // the Arabic shop on /ar, mirrored
    $s->set(\App\Support\Locale::SETTING_ENABLED, '1');
    $s->set(\App\Support\Locale::SETTING_RTL, '1');
}
$set = json_decode((string) getenv('OHB_SET'), true);
if (is_array($set) && $set !== []) {
    $c = OccasionBanner::config([OccasionBanner::KEY => $s->get(OccasionBanner::KEY)]);
    if (isset($set['template']) && $set['template'] !== $c['template']) {
        $d = OccasionBanner::defaults($set['template']);
        $c['en'] = $d['en'];
        $c['ar'] = $d['ar'];
        $c['colours'] = $d['colours'];
    }
    $s->set(OccasionBanner::KEY, array_merge($c, $set));
    echo 'set '.json_encode($set).PHP_EOL;
}

$kernel = app(\Illuminate\Contracts\Http\Kernel::class);
foreach (explode(',', getenv('OHB_PATHS') ?: '/') as $path) {
    $ms = [];
    $q = 0;
    $bytes = 0;
    for ($i = 0; $i < 11; $i++) {
        SettingsService::forgetMemo();
        \App\Models\Setting::flushMap();
        $n = 0;
        DB::listen(function () use (&$n) { $n++; });
        $t = microtime(true);
        $res = $kernel->handle($req = \Illuminate\Http\Request::create($path, 'GET'));
        $el = (microtime(true) - $t) * 1000;
        $kernel->terminate($req, $res);
        if ($i >= 2) {
            $ms[] = $el;
            $q = $n;
            $bytes = strlen((string) $res->getContent());
        }
        app('db')->getEventDispatcher()->forget(\Illuminate\Database\Events\QueryExecuted::class);
    }
    sort($ms);
    printf("%s  status %d  median %.1f ms  queries %d  html %d bytes  ohb %s\n", $path, $res->getStatusCode(), $ms[4], $q, $bytes,
        str_contains((string) $res->getContent(), 'class="ohb ') ? 'on' : 'off');
}
