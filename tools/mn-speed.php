<?php

declare(strict_types=1);

/*
 * Lane MN: server time, query count and settings-map reads for the product,
 * category and brand pages (CLAUDE.md "Speed is frozen"). Run inside the
 * preview's environment, before and after:
 *
 *   (env of tools/mn-preview.sh) php artisan tinker --execute="require 'tools/mn-speed.php';"
 *
 * Each page is requested through the HTTP kernel in this process: one warm-up,
 * then seven timed runs; the median is reported. Queries and settings reads are
 * counted on the last run.
 */

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

$product = Product::query()->visible()->firstOrFail();
$category = Category::query()->whereHas('products')->firstOrFail();
$brand = Brand::query()->whereHas('products')->firstOrFail();

$pages = [
    'product' => '/product/'.$product->slug.'/',
    'category' => '/collections/'.$category->slug.'/',
    'brand' => '/brands/'.$brand->slug.'/',
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
    for ($i = 0; $i < 8; $i++) {
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
    $out[$label] = ['path' => $path, 'median_ms' => round($times[intdiv(count($times), 2)], 1), 'queries' => $queries, 'settings_reads' => $reads, 'bytes' => strlen($res->getContent())];
}

echo json_encode($out, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), "\n";
