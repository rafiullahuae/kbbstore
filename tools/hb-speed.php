<?php

declare(strict_types=1);

/*
 * Lane HB: server ms, queries, settings-map reads and HTML bytes for the home
 * page (EN and AR) and the product, category and brand pages, before and after
 * -- CLAUDE.md "Speed is frozen" and "Every front-end change". The method is
 * tools/rp-speed.php's: one warm-up, fifteen timed runs, the median, OPcache on.
 * Run by tools/hb-speed.sh against a running tools/hb-preview.sh copy.
 */

use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

$pages = [
    'home' => '/',
    'home_ar' => '/ar/',
    'product' => '/product/glow-deep-serum-rice-alpha-arbutin/',
    'category' => '/collections/toners/',
    'brand' => '/brands/anua/',
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

foreach ($pages as $label => $path) {
    $times = [];
    for ($i = 0; $i < 16; $i++) {
        \App\Services\SettingsService::forgetMemo();
        app()->forgetScopedInstances();
        $queries = 0;
        $reads = 0;
        $t = hrtime(true);
        $res = $kernel->handle(Request::create($path, 'GET'));
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
    preg_match_all('#<style[^>]*>(.*?)</style>#s', $html, $css);
    $out[$label] = ['median_ms' => round($times[intdiv(count($times), 2)], 1), 'queries' => $queries, 'settings_reads' => $reads,
        'html_bytes' => strlen($html), 'html_gzip' => strlen(gzencode($html, 6)),
        'inline_css_bytes' => array_sum(array_map('strlen', $css[1])), 'scripts' => substr_count($html, '<script')];
}

echo json_encode($out, JSON_UNESCAPED_SLASHES), "\n";
