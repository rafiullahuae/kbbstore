<?php

declare(strict_types=1);

/*
 * Lane AR round 2: server ms (median of 15 after one warm-up), queries,
 * settings-map reads and HTML bytes for home, category, product, brand, cart and
 * checkout -- English and /ar -- on the tools/arr-preview.sh database. The
 * method is tools/amp-speed.php's. Run once on the base commit's tree and once
 * on this branch, with the same fixture, and compare.
 *
 *   php artisan tinker --execute="require 'tools/arr-speed.php';"   (with arr-preview.sh's env)
 */

use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

$ids = json_decode((string) file_get_contents(base_path('storage/ar-logs/preview-ids.json')), true);

// A basket of three lines, behind the shop's own encrypted cookie.
$cart = \App\Models\Cart::query()->create(['token' => $token = bin2hex(random_bytes(16)), 'currency' => 'AED', 'status' => 'active']);
foreach ([[$ids['ids'][0], 2], [$ids['ids'][17], 1], [$ids['ids'][21], 1]] as [$pid, $q]) {
    $p = \App\Models\Product::find($pid);
    $cart->items()->create(['product_id' => $pid, 'quantity' => $q, 'unit_price' => (int) ($p->sale_price ?: $p->price)]);
}
$cookie = Crypt::encrypt(CookieValuePrefix::create(\App\Services\CartService::COOKIE, Crypt::getKey()).$token, false);

$pages = [
    'home' => '/', 'category' => '/collections/toners/', 'product' => '/product/'.$ids['hero'].'/',
    'brand' => '/brands/anua/', 'cart' => '/cart/', 'checkout' => '/checkout/',
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

foreach (['en' => '', 'ar' => '/ar'] as $lang => $pre) {
    foreach ($pages as $label => $path) {
        $times = [];
        for ($i = 0; $i < 16; $i++) {
            \App\Services\SettingsService::forgetMemo();
            \App\Services\Translation\TranslationStore::forgetMemo();
            app()->forgetScopedInstances();
            $queries = 0;
            $reads = 0;
            $req = Request::create('http://127.0.0.1'.$pre.$path, 'GET', [], [\App\Services\CartService::COOKIE => $cookie], [], [
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36',
                'HTTP_ACCEPT' => 'text/html', 'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.9',
            ]);
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
        $css = [];
        preg_match_all('#<link rel="stylesheet" href="(/build/assets/[^"]+\.css)"#', $html, $m);
        $out[$lang][$label] = [
            'status' => $res->getStatusCode(),
            'median_ms' => round($times[intdiv(count($times), 2)], 1),
            'queries' => $queries,
            'settings_reads' => $reads,
            'html_bytes' => strlen($html),
            'html_gzip' => strlen(gzencode($html, 6)),
            'css' => $m[1],
            'scripts' => substr_count($html, '<script'),
        ];
    }
}

$file = base_path('storage/ar-logs/speed-'.(getenv('KBB_SPEED_TAG') ?: 'run').'.json');
file_put_contents($file, json_encode($out, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
echo 'wrote ', $file, "\n";
