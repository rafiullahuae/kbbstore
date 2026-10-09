<?php

declare(strict_types=1);

/*
 * Lane MP: server ms (median of 9 after one warm-up), queries, settings-map
 * reads, HTML bytes and render-blocking resources for product, category and
 * brand -- before/after, with the pixels unconfigured and configured.
 *
 *   . storage/mp-logs/env-<label>/env.sh
 *   MP_MODE=off|ids|all php artisan tinker --execute="require 'tools/mp-speed.php';"
 *
 *   off  nothing configured (the shipped state)
 *   ids  module on + Meta, GA4 and TikTok IDs (works on the base commit too)
 *   all  ids + the three server tokens, Google Ads + consent mode, Meta
 *        verification, and a typical tag in each custom-code box (deferred)
 *
 * Render-blocking = a <script src> without async/defer/type=module, or a
 * <link rel=stylesheet> without a non-screen media, before </head>.
 */

use App\Models\Brand;
use App\Models\Category;
use App\Models\ModuleToggle;
use App\Models\Product;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

$mode = getenv('MP_MODE') ?: 'off';

if ($mode !== 'off') {
    ModuleToggle::query()->updateOrCreate(['module' => 'marketing_pixels'], ['enabled' => true]);
    app(\App\Services\MarketingPixels::class)->save(['meta_id' => '111122223333444', 'ga4_id' => 'G-TESTMP12', 'tiktok_id' => 'CQ1ABCDEFGHIJ']);
}

if ($mode === 'all') {
    app(\App\Services\Pixels\PixelConfig::class)->save([
        'meta_capi_token' => 'EAAGtokenAbCdEfGhIjKlMnOpQrStUvWxYz0123WXYZ', 'ga4_api_secret' => 'gaSecretAbCd1234',
        'tiktok_token' => 'tiktokTokenAbCdEfGhIjKlMn9876', 'ads_id' => 'AW-123456789', 'ads_label' => 'AbCdEfGh',
        'consent_mode' => 'eea', 'facebook_domain_verification' => 'abc123def456ghi789',
    ]);
    app(\App\Services\Pixels\CustomCode::class)->save([
        'head' => ['on' => true, 'code' => "<script>(function(c,l,a,r,i,t,y){c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};t=l.createElement(r);t.async=1;t.src=\"https://www.clarity.ms/tag/\"+i;y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y);})(window, document, \"clarity\", \"script\", \"abcdefghij\");</script>"],
        'body' => ['on' => true, 'code' => '<noscript><img height="1" width="1" style="display:none" src="https://ct.pinterest.com/v3/?event=init&tid=2612345678901&noscript=1" /></noscript>'],
        'footer' => ['on' => true, 'code' => "<script type=\"text/javascript\">(function(e,t,n){if(e.snaptr)return;var a=e.snaptr=function(){a.handleRequest?a.handleRequest.apply(a,arguments):a.queue.push(arguments)};a.queue=[];var s='script';r=t.createElement(s);r.async=!0;r.src=n;var u=t.getElementsByTagName(s)[0];u.parentNode.insertBefore(r,u);})(window,document,'https://sc-static.net/scevent.min.js');snaptr('init','11111111-2222-3333-4444-555555555555',{});snaptr('track','PAGE_VIEW');</script>"],
    ], 'mp-speed');
}

\App\Models\Setting::flushMap();
\App\Services\SettingsService::forgetMemo();
app(\App\Services\SettingsService::class)->flush();
\Illuminate\Support\Facades\Cache::flush();

$product = Product::query()->visible()->where('slug', 'seo-product-5')->first() ?? Product::query()->visible()->firstOrFail();
$category = Category::query()->where('slug', 'seo-toners')->first() ?? Category::query()->whereHas('products')->firstOrFail();
$brand = Brand::query()->where('slug', 'seo-lab')->first() ?? Brand::query()->whereHas('products')->firstOrFail();

$pages = [
    'product' => '/product/'.$product->slug.'/',
    'category' => \App\Support\CategoryPath::url(\App\Support\CategoryPath::canonicalPath($category)),
    'brand' => '/brands/'.$brand->slug.'/',
];

$queries = 0;
$reads = 0;
DB::listen(function () use (&$queries) { $queries++; });
Event::listen([CacheHit::class, CacheMissed::class], function ($e) use (&$reads) {
    if ($e->key === 'kbb.settings' || $e->key === 'kbb.settings.map') {
        $reads++;
    }
});

$blocking = function (string $html): int {
    $head = substr($html, 0, (int) (strpos($html, '</head>') ?: strlen($html)));
    $n = 0;
    preg_match_all('/<script\b([^>]*)>/i', $head, $m);
    foreach ($m[1] as $a) {
        if (preg_match('/\bsrc\s*=/i', $a) && ! preg_match('/\b(async|defer)\b|type\s*=\s*["\']?module/i', $a)) {
            $n++;
        }
    }
    preg_match_all('/<link\b([^>]*)>/i', $head, $m);
    foreach ($m[1] as $a) {
        if (preg_match('/rel\s*=\s*["\']?stylesheet/i', $a) && ! preg_match('/media\s*=\s*["\']?(print|\(max|\(min|not)/i', $a)) {
            $n++;
        }
    }

    return $n;
};

$kernel = app(\Illuminate\Contracts\Http\Kernel::class);
$out = ['mode' => $mode];

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
    $html = (string) $res->getContent();
    $out[$label] = [
        'status' => $res->getStatusCode(),
        'median_ms' => round($times[intdiv(count($times), 2)], 1),
        'queries' => $queries,
        'settings_reads' => $reads,
        'html_bytes' => strlen($html),
        'render_blocking' => $blocking($html),
    ];
}

echo json_encode($out, JSON_UNESCAPED_SLASHES), "\n";
