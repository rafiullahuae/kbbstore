<?php
/* Lane CP: /checkout server time, query count and HTML bytes, in-process,
   against a preview's database (tools/cp-shots/measure.sh sets the env).
   A real basket (two lines), the cart cookie encrypted the way the browser
   sends it, 3 warm-up renders then the median of 15. Prints one JSON line. */
use App\Models\{Cart, Product};
use App\Services\CartService;
use Illuminate\Support\Facades\DB;

$withCoupon = (bool) getenv('CP_WITH_COUPON');
$cart = Cart::create(['token' => (string) Illuminate\Support\Str::uuid(), 'currency' => 'AED', 'status' => 'active', 'shipping_country' => 'AE', 'last_activity_at' => now()]);
foreach (Product::whereIn('slug', ['co-glow-serum', 'co-fwee-jelly-pot'])->get() as $p) {
    $cart->items()->create(['product_id' => $p->id, 'quantity' => 1, 'unit_price' => $p->price]);
}
if ($withCoupon) {
    $cart->forceFill(['coupon_id' => App\Models\Coupon::code('SAVE10')->value('id')])->save();
}
$enc = app('encrypter');
$cookie = $enc->encrypt(Illuminate\Cookie\CookieValuePrefix::create(CartService::COOKIE, $enc->getKey()).$cart->token, false);
$kernel = app(Illuminate\Contracts\Http\Kernel::class);
$q = 0;
DB::listen(function () use (&$q) { $q++; });
$ms = []; $queries = []; $bytes = 0; $status = 0;
for ($i = 0; $i < 18; $i++) {
    $q = 0;
    $req = Illuminate\Http\Request::create('/checkout/', 'GET', [], [CartService::COOKIE => $cookie], [], ['HTTP_USER_AGENT' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/141.0 Safari/537.36', 'HTTP_ACCEPT' => 'text/html']);
    $t = hrtime(true);
    $res = $kernel->handle($req);
    $el = (hrtime(true) - $t) / 1e6;
    $kernel->terminate($req, $res);
    app('auth')->forgetGuards();
    if ($i >= 3) { $ms[] = $el; $queries[] = $q; }
    $bytes = strlen((string) $res->getContent()); $status = $res->getStatusCode();
}
sort($ms);
echo json_encode(['status' => $status, 'median_ms' => round($ms[intdiv(count($ms), 2)], 1), 'queries' => max($queries), 'html_bytes' => $bytes, 'coupon' => $withCoupon]), "\n";
