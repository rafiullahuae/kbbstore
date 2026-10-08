<?php

declare(strict_types=1);

/*
 * Lane RP: server ms, queries and settings-map reads for the product,
 * category and brand pages (CLAUDE.md "Speed is frozen"), before and after.
 * tools/mn-speed.php's method — one warm-up, seven timed runs, the median —
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
    for ($i = 0; $i < 8; $i++) {
        if ($cold) {
            foreach (['kbb.recs.', 'kbb.ymal.', 'kbb.bt.'] as $k) {
                Cache::forget($k.$product->id);
            }
        }
        \App\Services\SettingsService::forgetMemo();
        \App\Support\SetPricing::forget();
        app()->forgetScopedInstances();
        $queries = 0;
        $reads = 0;
        $t = hrtime(true);
        $res = $kernel->handle(Request::create($path, 'GET', [], $cookies));
        $ms = (hrtime(true) - $t) / 1e6;
        if ($res->getStatusCode() !== 200) {
            throw new RuntimeException("$path answered ".$res->getStatusCode());
        }
        if ($i > 0) {
            $times[] = $ms;
        }
    }
    sort($times);
    $html = $res->getContent();
    $out[$label] = ['median_ms' => round($times[intdiv(count($times), 2)], 1), 'queries' => $queries, 'settings_reads' => $reads,
        'html_bytes' => strlen($html), 'html_gzip' => strlen(gzencode($html, 6)), 'cards' => substr_count($html, 'class="kbb-card kbb-tile')];
}

echo json_encode($out, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), "\n";
