<?php

declare(strict_types=1);

/*
 * Lane AMP: server ms, queries, settings-map reads and HTML bytes for the
 * product, category and brand pages -- CLAUDE.md "Speed is frozen" (the diff
 * touches layouts/store.blade.php's title line). tools/ds-speed.php's method:
 * one warm-up, fifteen timed runs, the median. Run against the
 * tools/amp-preview.sh database:
 *   php artisan tinker --execute="require 'tools/amp-speed.php';"  (with that script's env)
 */

use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

$pages = [
    'product' => '/product/skin-lab-vitamin-c-brightening-serum/',
    'category' => '/collections/serums/',
    'brand' => '/brands/skinlab/',
];

$queries = 0;
$reads = 0;
DB::listen(function () use (&$queries) { $queries++; });
Event::listen([CacheHit::class, CacheMissed::class], function ($e) use (&$reads) {
    if ($e->key === 'kbb.settings' || $e->key === 'kbb.settings.map') {
        $reads++;
    }
});

$kernel = app(\Illuminate\Contracts\Http\Kernel::class);
$out = [];

foreach ($pages as $label => $path) {
    $times = [];
    for ($i = 0; $i < 16; $i++) {
        \App\Services\SettingsService::forgetMemo();
        app()->forgetScopedInstances();
        $queries = 0;
        $reads = 0;
        $t = hrtime(true);
        $res = $kernel->handle(Request::create('http://127.0.0.1'.$path, 'GET'));
        $ms = (hrtime(true) - $t) / 1e6;
        if ($res->getStatusCode() !== 200) {
            throw new RuntimeException("$path answered ".$res->getStatusCode());
        }
        if ($i > 0) {
            $times[] = $ms;
        }
    }
    sort($times);
    $html = (string) $res->getContent();
    $out[$label] = ['median_ms' => round($times[intdiv(count($times), 2)], 1), 'queries' => $queries, 'settings_reads' => $reads,
        'html_bytes' => strlen($html), 'scripts' => substr_count($html, '<script')];
}

echo json_encode($out, JSON_UNESCAPED_SLASHES), "\n";
