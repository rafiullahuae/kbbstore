<?php

declare(strict_types=1);

/*
 * Lane RP: server ms, queries and settings-map reads for the product,
 * category and brand pages (CLAUDE.md "Speed is frozen"), before and after.
 * tools/mn-speed.php's method — one warm-up, fifteen timed runs, the median —
 * plus the product page COLD (this product's choice caches dropped before
 * every run) and WITH a recently-viewed cookie. Run by tools/rp-speed.sh.
 */

use App\Models\Product;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

$product = Product::query()->where('slug', 'anua-heartleaf-77-clear-pad')->firstOrFail();
$viewed = Product::query()->whereIn('slug', ['round-lab-birch-juice-sun-cream', 'cosrx-advanced-snail-92-cream', 'isntree-hyaluronic-acid-toner'])->pluck('id')->implode(',');
$cookie = Crypt::encryptString(CookieValuePrefix::create('kbb_viewed', Crypt::getKey()).$viewed, false);

$pages = [
    'product' => ['/product/'.$product->slug.'/', [], false],
    'product_cold' => ['/product/'.$product->slug.'/', [], true],
    // Every cache flushed before every run (settings, translations, choices,
    // card fragments): the first view after an update applies.
    'product_flushed' => ['/product/'.$product->slug.'/', [], 'flush'],
    'product_viewed' => ['/product/'.$product->slug.'/', ['kbb_viewed' => $cookie], false],
    'category' => ['/collections/skincare/toners/', [], false],
    'brand' => ['/brands/anua/', [], false],
];

$queries = 0;
$reads = 0;
DB::listen(function () use (&$queries) { $queries++; });
Event::listen([CacheHit::class, CacheMissed::class], function ($e) use (&$reads) {
    if ($e->key === 'kbb.settings') {
        $reads++;
    }
});

$kernel = app(\Illuminate\Contracts\Http\Kernel::class);
$out = [];

foreach ($pages as $label => [$path, $cookies, $cold]) {
    $times = [];
    $cpus = [];
    for ($i = 0; $i < 16; $i++) {
        if ($cold === 'flush') {
            Cache::flush();
        } elseif ($cold) {
            foreach (['kbb.recs.', 'kbb.ymal.', 'kbb.bt.'] as $k) {
                Cache::forget($k.$product->id);
            }
        }
        \App\Services\SettingsService::forgetMemo();
        \App\Support\SetPricing::forget();
        app()->forgetScopedInstances();
        $queries = 0;
        $reads = 0;
        $ru = getrusage();
        $t = hrtime(true);
        $res = $kernel->handle(Request::create($path, 'GET', [], $cookies));
        $ms = (hrtime(true) - $t) / 1e6;
        $ru2 = getrusage();
        // CPU time as well as wall time: other lanes share this machine, and
        // CPU time is what the page costs whoever else is running.
        $cpu = (($ru2['ru_utime.tv_sec'] - $ru['ru_utime.tv_sec']) * 1e6 + ($ru2['ru_utime.tv_usec'] - $ru['ru_utime.tv_usec'])
            + ($ru2['ru_stime.tv_sec'] - $ru['ru_stime.tv_sec']) * 1e6 + ($ru2['ru_stime.tv_usec'] - $ru['ru_stime.tv_usec'])) / 1e3;
        if ($res->getStatusCode() !== 200) {
            throw new RuntimeException("$path answered ".$res->getStatusCode());
        }
        if ($i > 0) {
            $times[] = $ms;
            $cpus[] = $cpu;
        }
    }
    sort($times);
    sort($cpus);
    $html = $res->getContent();
    $out[$label] = ['median_ms' => round($times[intdiv(count($times), 2)], 1), 'cpu_ms' => round($cpus[intdiv(count($cpus), 2)], 1), 'queries' => $queries, 'settings_reads' => $reads,
        'html_bytes' => strlen($html), 'html_gzip' => strlen(gzencode($html, 6)), 'cards' => substr_count($html, 'class="kbb-card kbb-tile')];
}

echo json_encode($out, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), "\n";
