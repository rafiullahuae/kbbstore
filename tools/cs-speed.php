<?php

declare(strict_types=1);

/*
 * Lane CS: server ms (median of 15 after one warm-up), queries, settings-map
 * reads (`kbb.settings`, what SettingsRequestMemoTest counts) and HTML bytes for
 * the product, category and brand pages, in-process with OPcache on -- the
 * method of tools/ds-speed.php. Run inside the cs preview:
 *
 *   . storage/framework/testing/lane-cs-preview/env.sh
 *   CS_MODE=off php -d opcache.enable_cli=1 artisan tinker --execute="require 'tools/cs-speed.php';"
 *
 * CS_MODE: off (Coming Soon off, or not built yet), on-other (ON for
 * kbeautybliss.com, page asked on extrabeauty.ae), on-hidden (ON, asked on
 * kbeautybliss.com by a visitor: the 503 page). CS_HOST overrides the host.
 */

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

$mode = getenv('CS_MODE') ?: 'off';
$host = getenv('CS_HOST') ?: ($mode === 'on-hidden' ? 'kbeautybliss.com' : 'extrabeauty.ae');

if (class_exists(\App\Support\ComingSoon::class)) {
    $sv = app(\App\Services\SettingsService::class);
    $sv->set(\App\Support\ComingSoon::KEY_ON, $mode === 'off' ? '0' : '1');
    $sv->set(\App\Support\ComingSoon::KEY_SCOPE, 'host');
    $sv->set(\App\Support\ComingSoon::KEY_HOST, 'kbeautybliss.com');
    $sv->flush();
}

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
$out = ['mode' => $mode, 'host' => $host];

foreach ($pages as $label => $path) {
    $times = [];
    for ($i = 0; $i < 16; $i++) {
        \App\Services\SettingsService::forgetMemo();
        app()->forgetScopedInstances();
        $queries = 0;
        $reads = 0;
        $t = hrtime(true);
        $res = $kernel->handle(Request::create('https://'.$host.$path, 'GET'));
        $ms = (hrtime(true) - $t) / 1e6;
        $kernel->terminate(Request::create('https://'.$host.$path, 'GET'), $res);
        if ($i > 0) {
            $times[] = $ms;
        }
    }
    sort($times);
    $html = (string) $res->getContent();
    $out[$label] = ['status' => $res->getStatusCode(), 'median_ms' => round($times[intdiv(count($times), 2)], 1), 'queries' => $queries,
        'settings_reads' => $reads, 'html_bytes' => strlen($html), 'md5' => md5((string) preg_replace('/name="csrf-token" content="[^"]*"|_token" value="[^"]*"|nonce="[^"]*"/', '', $html))];
}

echo json_encode($out, JSON_UNESCAPED_SLASHES), "\n";
