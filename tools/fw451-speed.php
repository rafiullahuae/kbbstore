<?php

declare(strict_types=1);

/*
 * Lane FW: the firewall's cost, in-process with OPcache on (the method of
 * tools/cs-speed.php). Run inside the fw451 preview's database:
 *
 *   FW_MODE=off|monitor|enforce FW_IP=94.200.10.20 php -d opcache.enable_cli=1 artisan tinker --execute="require 'tools/fw451-speed.php';"
 *
 * Prints, for home, product, category and brand: median server ms of 15 after
 * one warm-up, queries, settings-map reads, HTML bytes and an md5 of the page
 * with the per-session tokens taken out (so "identical" is a fact). Then the
 * firewall's own cost: BlockGate around a no-op $next, 5,000 times, as µs per
 * request — the number that is the firewall and nothing else.
 */

use App\Http\Middleware\BlockGate;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Services\Security\FirewallConfig;
use App\Services\Security\IpBlockList;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

// FW_FILEPATH puts the file cache on another disk (e.g. /dev/shm) to tell a
// slow sandbox disk from the firewall's own cost.
if (getenv('FW_FILEPATH')) {
    config(['cache.stores.file.path' => getenv('FW_FILEPATH')]);
    \Illuminate\Support\Facades\Cache::forgetDriver('file');
}

$mode = getenv('FW_MODE') ?: 'off';
$ip = getenv('FW_IP') ?: '94.200.10.20';
// FW_COUNTERS puts the counter table on another disk (e.g. /dev/shm).
if (getenv('FW_COUNTERS')) {
    \App\Services\Security\FirewallCounters::useDir(getenv('FW_COUNTERS'));
}
FirewallConfig::save(['mode' => $mode]);
IpBlockList::forget();

$product = Product::query()->visible()->firstOrFail();
$category = Category::query()->whereHas('products')->firstOrFail();
$brand = Brand::query()->whereHas('products')->firstOrFail();

$pages = [
    'home' => '/',
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
$out = ['mode' => $mode, 'ip' => $ip, 'counters' => \App\Services\Security\FirewallCounters::dir()];
$ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36';

foreach ($pages as $label => $path) {
    $times = [];
    for ($i = 0; $i < 16; $i++) {
        \App\Services\SettingsService::forgetMemo();
        app()->forgetScopedInstances();
        $queries = 0;
        $reads = 0;
        $req = Request::create('https://extrabeauty.ae'.$path, 'GET', [], [], [], ['REMOTE_ADDR' => $ip, 'HTTP_USER_AGENT' => $ua]);
        $t = hrtime(true);
        $res = $kernel->handle($req);
        $ms = (hrtime(true) - $t) / 1e6;
        $kernel->terminate($req, $res);
        if ($i > 0) {
            $times[] = $ms;
        }
    }
    sort($times);
    $html = (string) $res->getContent();
    $out[$label] = ['status' => $res->getStatusCode(), 'median_ms' => round($times[intdiv(count($times), 2)], 1), 'queries' => $queries,
        'settings_reads' => $reads, 'html_bytes' => strlen($html),
        'proof_cookie' => collect($res->headers->getCookies())->contains(fn ($c) => $c->getName() === 'kbb_pv'),
        'md5' => md5((string) preg_replace('/name="csrf-token" content="[^"]*"|_token" value="[^"]*"|"csrf":"[^"]*"/', '', $html))];
}

// The firewall alone: BlockGate around a $next that does nothing.
$req = Request::create('https://extrabeauty.ae'.$pages['product'], 'GET', [], [], [], ['REMOTE_ADDR' => $ip, 'HTTP_USER_AGENT' => $ua]);
$route = app('router')->getRoutes()->match($req);
$req->setRouteResolver(fn () => $route);
$gate = new BlockGate;
$ok = new \Illuminate\Http\Response('ok', 200, ['Content-Type' => 'text/html']);
$n = 5000;
for ($i = 0; $i < 200; $i++) {
    $gate->handle($req, fn () => $ok);
}
$t = hrtime(true);
for ($i = 0; $i < $n; $i++) {
    $gate->handle($req, fn () => $ok);
}
$out['gate_us_per_request'] = round((hrtime(true) - $t) / 1e3 / $n, 1);

// What a banned request costs to refuse, enforce only.
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
