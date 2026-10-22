<?php

declare(strict_types=1);

/*
 * Lane FG: server ms (median of 15 warm requests) and query count for the
 * product, category, brand, cart and checkout pages, with a basket in the
 * AED 500 band. Run inside an application tree against the FG preview
 * database:
 *
 *   DB_CONNECTION=sqlite DB_DATABASE=.../preview.sqlite CACHE_STORE=array \
 *     php artisan tinker --execute="require 'tools/fg-measure.php';"
 *
 * Prints one JSON line per page. The cart cookie is minted the way
 * EncryptCookies expects it, so the pages see a real basket.
 */

use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

$token = 'fg-measure-'.getmypid();
DB::table('carts')->where('token', $token)->delete();
$cartId = DB::table('carts')->insertGetId(['token' => $token, 'currency' => 'AED', 'status' => 'active', 'shipping_country' => 'AE', 'last_activity_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
$pid = (int) DB::table('products')->where('slug', 'fg-band-650')->value('id');
DB::table('cart_items')->insert(['cart_id' => $cartId, 'product_id' => $pid, 'quantity' => 1, 'unit_price' => 65000, 'created_at' => now(), 'updated_at' => now()]);

$key = app('encrypter')->getKey();
$cookie = encrypt(CookieValuePrefix::create('kbb_cart', $key).$token, false);
$product = (string) DB::table('products')->where('slug', 'mac-anua')->value('slug');
$category = (string) DB::table('categories')->where('slug', 'mac-cat-0')->value('slug');
$brand = (string) DB::table('brands')->where('slug', 'mac-0')->value('slug');

$pages = [
    'product' => '/product/'.$product.'/',
    'category' => '/collections/'.$category.'/',
    'brand' => '/brands/'.$brand.'/',
    'cart' => '/cart/',
    'checkout' => '/checkout/',
];

$kernel = app(\Illuminate\Contracts\Http\Kernel::class);
$ua = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

foreach ($pages as $name => $uri) {
    $times = [];
    $queries = null;
    $status = null;
    $bytes = null;

    for ($i = 0; $i < 16; $i++) {
        $request = Request::create($uri, 'GET', [], ['kbb_cart' => $cookie], [], ['HTTP_USER_AGENT' => $ua, 'HTTP_ACCEPT' => 'text/html']);
        $count = 0;
        DB::flushQueryLog();
        DB::enableQueryLog();
        $t = hrtime(true);
        $response = $kernel->handle($request);
        $ms = (hrtime(true) - $t) / 1e6;
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();
        $kernel->terminate($request, $response);
        $status = $response->getStatusCode();
        if ($status >= 500 && $i === 0) { echo "ERR ", substr((string) ($response->exception?->getMessage() ?? ""), 0, 300), "\n"; }
        $bytes = strlen((string) $response->getContent());
        if ($i > 0) {
            $times[] = $ms;
            $queries = $count;
        }
        app()->forgetScopedInstances();
    }

    sort($times);
    echo json_encode(['page' => $name, 'uri' => $uri, 'status' => $status, 'ms_median' => round($times[intdiv(count($times), 2)], 1), 'queries' => $queries, 'html_bytes' => $bytes]), "\n";
}

DB::table('cart_items')->where('cart_id', $cartId)->delete();
DB::table('carts')->where('id', $cartId)->delete();
