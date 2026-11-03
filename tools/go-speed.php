<?php

declare(strict_types=1);

/*
 * Lane GO: server ms (median of 15 after one warm-up), queries and settings-map
 * reads for product, category, brand, cart and checkout on the
 * tools/go-preview.sh database -- tools/as-speed.php's method. The cart and
 * checkout carry a basket WITHOUT a gift claim (the case every shopper is in
 * until a claim), through the real kernel with an encrypted cart cookie.
 *
 *   GO_SPEED_TAG=before sh tools/go-tinker.sh tools/go-speed.php
 */

use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

$cart = \App\Models\Cart::query()->where('token', 'go-speed-cart')->first() ?? \App\Models\Cart::create(['token' => 'go-speed-cart', 'currency' => 'AED', 'status' => 'active', 'last_activity_at' => now()]);
$cart->forceFill(['status' => 'active', 'gift_offer_claim_id' => null])->save();
$cart->items()->delete();
foreach (['go-glow-serum', 'go-dynasty-cream'] as $slug) {
    $p = \App\Models\Product::query()->where('slug', $slug)->first();
    $cart->items()->create(['product_id' => $p->id, 'quantity' => 1, 'unit_price' => (int) $p->price]);
}

// The popup as it ships (off) unless GO_POPUP=1 asks for its cost.
$goCfg = app(\App\Services\SettingsService::class)->get(\App\Services\GiftOffers\GiftOffers::KEY) ?: [];
$goCfg['places']['popup'] = getenv('GO_POPUP') === '1';
app(\App\Services\SettingsService::class)->set(\App\Services\GiftOffers\GiftOffers::KEY, $goCfg);

$pages = [
    'product' => [\App\Support\UrlScheme::product('go-glow-serum'), false],
    'category' => ['/collections/skincare/', false],
    'brand' => [\App\Support\UrlScheme::brand('beauty-of-joseon'), false],
    'cart' => ['/cart/', true],
    'checkout' => ['/checkout/', true],
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
$cookie = Crypt::encrypt(CookieValuePrefix::create(\App\Services\CartService::COOKIE, Crypt::getKey()) . 'go-speed-cart', false);
$out = [];

foreach ($pages as $label => [$path, $withCart]) {
    $times = [];
    for ($i = 0; $i < 16; $i++) {
        \App\Services\SettingsService::forgetMemo();
        app()->forgetScopedInstances();
        app(\App\Services\CartService::class)->forget();
        $queries = 0;
        $reads = 0;
        $req = Request::create('http://127.0.0.1' . $path, 'GET', [], $withCart ? [\App\Services\CartService::COOKIE => $cookie] : [], [], [
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36',
            'HTTP_ACCEPT' => 'text/html',
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
    $out[$label] = [
        'status' => $res->getStatusCode(),
        'median_ms' => round($times[intdiv(count($times), 2)], 1),
        'queries' => $queries,
        'settings_reads' => $reads,
        'html_bytes' => strlen($html),
        'has_gift_markup' => str_contains($html, 'kgo-'),
    ];
}

@mkdir(base_path('storage/go-logs'), 0775, true);
$file = base_path('storage/go-logs/speed-' . (getenv('GO_SPEED_TAG') ?: 'run') . '.json');
file_put_contents($file, json_encode($out, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
echo 'wrote ', $file, "\n", json_encode($out, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
