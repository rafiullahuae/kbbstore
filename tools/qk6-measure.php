<?php
/* Lane QK6: server time, query count and HTML bytes, in-process, against the
   preview's database (tools/qk6-measure.sh sets the env). A basket with the
   AED 119 product, the cart cookie encrypted the way a browser sends it, three
   warm-up renders then the median of 15, for /checkout/ and the product,
   category and brand pages. One JSON line per page. */
use App\Models\{Cart, Product};
use App\Services\CartService;
use Illuminate\Support\Facades\DB;

$cart = Cart::create(['token' => (string) Illuminate\Support\Str::uuid(), 'currency' => 'AED', 'status' => 'active', 'shipping_country' => 'AE', 'last_activity_at' => now()]);
$p = Product::where('slug', 'qk6-serum')->first();
$cart->items()->create(['product_id' => $p->id, 'quantity' => 1, 'unit_price' => $p->price]);
$enc = app('encrypter');
$cookie = $enc->encrypt(Illuminate\Cookie\CookieValuePrefix::create(CartService::COOKIE, $enc->getKey()).$cart->token, false);
$kernel = app(Illuminate\Contracts\Http\Kernel::class);
$q = 0;
DB::listen(function () use (&$q) { $q++; });
foreach (['/checkout/', '/product/barrier-repair-cream/', '/collections/cleansers/', '/brands/anua/'] as $path) {
    $ms = []; $queries = []; $bytes = 0; $status = 0;
    for ($i = 0; $i < 18; $i++) {
        $q = 0;
        $req = Illuminate\Http\Request::create($path, 'GET', [], [CartService::COOKIE => $cookie], [], ['HTTP_USER_AGENT' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/141.0 Safari/537.36', 'HTTP_ACCEPT' => 'text/html']);
        $t = hrtime(true);
        $res = $kernel->handle($req);
        $el = (hrtime(true) - $t) / 1e6;
        $kernel->terminate($req, $res);
        app('auth')->forgetGuards();
        app(CartService::class)->forget();
        if ($i >= 3) { $ms[] = $el; $queries[] = $q; }
        $bytes = strlen((string) $res->getContent()); $status = $res->getStatusCode();
    }
    sort($ms);
    echo json_encode(['path' => $path, 'status' => $status, 'median_ms' => round($ms[intdiv(count($ms), 2)], 1), 'queries' => max($queries), 'html_bytes' => $bytes]), "\n";
}
