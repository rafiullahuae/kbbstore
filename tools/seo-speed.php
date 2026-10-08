<?php

declare(strict_types=1);

/*
 * Lane SEO: server ms (median of 9 timed runs after one warm-up), query count
 * and settings-map reads for home, product, category and brand -- the numbers
 * CLAUDE.md "Speed is frozen" asks for, before and after. Plus the new URLs
 * (sitemap, feed, a /wp-content/uploads miss) so their own cost is on record.
 *
 *   (env of tools/seo-env.sh) php artisan tinker --execute="require 'tools/seo-speed.php';"
 *
 * A settings-map read is a cache hit or miss on `kbb.settings` or
 * `kbb.settings.map`, the two keys the map lives under.
 */

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;

$product = Product::query()->visible()->where('slug', 'seo-product-5')->first() ?? Product::query()->visible()->firstOrFail();
$category = Category::query()->where('slug', 'seo-toners')->first() ?? Category::query()->whereHas('products')->firstOrFail();
$brand = Brand::query()->where('slug', 'seo-lab')->first() ?? Brand::query()->whereHas('products')->firstOrFail();

$pages = [
    'home' => '/',
    'product' => '/product/'.$product->slug.'/',
    'category' => \App\Support\CategoryPath::url(\App\Support\CategoryPath::canonicalPath($category)),
    'brand' => '/brands/'.$brand->slug.'/',
    'sitemap' => '/sitemap.xml',
];

$extra = getenv('SEO_EXTRA');
if ($extra) {
    foreach (explode(',', $extra) as $one) {
        [$label, $path] = explode('=', $one, 2);
        $pages[$label] = $path;
    }
}

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
    $path = preg_replace('#^https?://[^/]+#', '', $path);
    $times = [];
    for ($i = 0; $i < 10; $i++) {
        \App\Services\SettingsService::forgetMemo();
        app()->forgetScopedInstances();
        $queries = 0;
        $reads = 0;
        $t = hrtime(true);
        $res = $kernel->handle(Request::create($path, 'GET'));
        $ms = (hrtime(true) - $t) / 1e6;
        if ($i > 0) {
            $times[] = $ms;
        }
    }
    sort($times);
    $out[$label] = [
        'path' => $path,
        'status' => $res->getStatusCode(),
        'median_ms' => round($times[intdiv(count($times), 2)], 1),
        'queries' => $queries,
        'settings_reads' => $reads,
        'bytes' => strlen((string) $res->getContent()),
    ];
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
