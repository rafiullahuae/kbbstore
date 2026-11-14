<?php

declare(strict_types=1);

/*
 * Lane GS2: server ms, queries, settings-map reads and HTML bytes for the
 * product, category and brand pages -- CLAUDE.md "Speed is frozen". The
 * tools/vpa-speed.php method (one warm-up, fifteen timed runs, the median),
 * against the tools/pdp-preview.sh database, with one category added so the
 * category page has rows:
 *   php artisan tinker --execute="require 'tools/gs2-speed.php';"  (with that script's env)
 */

use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

$cat = \App\Models\Category::query()->firstOrCreate(['slug' => 'gs2-toners'], ['name' => 'Toners']);
$cat->products()->syncWithoutDetaching(\App\Models\Product::query()->where('slug', 'like', 'pdp-%')->pluck('id')->all());

$pages = [
    'product' => '/product/pdp-heartleaf-toner/',
    'category' => '/collections/gs2-toners/',
    'brand' => '/brands/beauty-of-joseon/',
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
        'html_bytes' => strlen($html), 'html_gzip' => strlen(gzencode($html, 6)), 'scripts' => substr_count($html, '<script')];
}

echo json_encode($out, JSON_UNESCAPED_SLASHES), "\n";
