<?php

declare(strict_types=1);

/*
 * THE LIVE FREE GIFTS BOX (Lane FGL). The owner, 24 October:
 *
 *   "the free gifts box must update silently at backend during customer
 *    journey, to see what's actually available as gifts and as per criteria.
 *    please check from every angle, i don't want to disturb the customer at
 *    all. and neither compromise on the page speed or server load etc."
 *
 * What it looked like: the box was drawn when the page was, and only a cart
 * write (or a country change on the checkout) drew it again. A shopper sat on
 * the checkout while the last fwee Jelly Pot went in somebody else's order and
 * kept being shown it -- then got the backup (order 56312, 2.60.595).
 *
 * Now every redrawable box carries its fingerprint (data-fgs, FreeGifts::sig())
 * and GET /api/cart/gifts?s=<print> answers 204 while it is still true, or the
 * fresh box. cart.js (gift-live.js) asks only on the shopper's own moves, at
 * most every 30 s, never on a timer.
 */

use App\Models\Cart;
use App\Models\Product;
use App\Models\Setting;
use App\Services\CartService;
use App\Services\FreeGifts;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    DB::table('free_gift_ranges')->delete();
});

function fglFresh(): void
{
    SettingsService::forgetMemo();
    Setting::flushMap();
}

function fglProduct(string $name, array $o = []): Product
{
    return Product::create(array_merge(['slug' => Str::slug($name) . '-' . Str::random(6), 'name' => $name, 'status' => 'publish', 'is_visible' => true,
        'price' => 2500, 'stock_status' => 'instock', 'manage_stock' => true, 'stock' => 5], $o));
}

/** A band AED $min..$max giving [[Product, qty], ...]; the gifts switched on. */
function fglRange(int $min, ?int $max, array $gifts): int
{
    $id = DB::table('free_gift_ranges')->insertGetId(['min_fils' => $min * 100, 'max_fils' => $max === null ? null : $max * 100,
        'label_en' => 'Gifts', 'label_ar' => 'هدايا', 'sort' => 0, 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    foreach ($gifts as $i => [$p, $q]) {
        DB::table('free_gift_range_items')->insert(['range_id' => $id, 'product_id' => $p->id, 'quantity' => $q, 'sort' => $i]);
    }
    fglOn();

    return $id;
}

function fglOn(array $o = []): void
{
    app(SettingsService::class)->set(FreeGifts::KEY, array_merge(['on' => true, 'show_value' => false, 'countries' => 'all', 'basis' => 'after_discounts'], $o));
    fglFresh();
    app(FreeGifts::class)->compile();
    fglFresh();
}

function fglCart(int $aed): Cart
{
    $cart = Cart::create(['token' => (string) Str::uuid(), 'currency' => 'AED', 'status' => 'active', 'shipping_country' => 'AE', 'last_activity_at' => now()]);
    $cart->items()->create(['product_id' => fglProduct('Serum', ['price' => $aed * 100, 'stock' => 50])->id, 'quantity' => 1, 'unit_price' => $aed * 100]);

    return $cart;
}

function fglAs(Cart $cart)
{
    app(CartService::class)->forget();
    fglFresh();

    return test()->withCredentials()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token);
}

function fglSig(string $html): ?string
{
    return preg_match('#data-fgs="([0-9a-f]{12}|0)"#', $html, $m) ? $m[1] : null;
}

it('prints the same fingerprint on the cart panel, the cart page and the checkout, and the check answers 204 for it', function () {
    // Mutation note: drop data-fgs from the box partial, or make sig() hash a
    // per-request value, and this is red -- every check would then resend the box.
    fglRange(100, 299, [[fglProduct('Jelly Pot'), 1]]);
    $cart = fglCart(150);

    $drawer = fglAs($cart)->getJson('/api/cart/drawer')->json('html');
    $page = fglAs($cart)->get('/cart/')->getContent();
    $checkout = fglAs($cart)->get('/checkout/')->getContent();
    $sig = fglSig($drawer);

    expect($sig)->toMatch('/^[0-9a-f]{12}$/')
        ->and(fglSig($page))->toBe($sig)
        ->and(fglSig($checkout))->toBe($sig);

    $r = fglAs($cart)->get('/api/cart/gifts?s=' . $sig);
    $r->assertNoContent(204);
    expect($r->getContent())->toBe('')
        ->and($r->headers->get('Cache-Control'))->toContain('no-store');
});

it('swaps in the backup the moment the main gift sells out elsewhere', function () {
    // The order-56312 shape: the shopper's page still shows the Jelly Pot after
    // its last unit went in another order. Mutation note: let linesFor() ignore
    // stock and the answer stays 204 -- red.
    $main = fglProduct('fwee Jelly Pot', ['stock' => 1]);
    $backup = fglProduct('Mixsoon Bean Essence', ['stock' => 9]);
    $id = fglRange(100, 299, [[$main, 1]]);
    DB::table('free_gift_ranges')->where('id', $id)->update(['need_full' => 1, 'need_mask' => 0, 'need_mini' => 0]);
    DB::table('free_gift_range_items')->where('range_id', $id)->update(['kind' => 'full', 'role' => 'primary']);
    DB::table('free_gift_range_items')->insert(['range_id' => $id, 'product_id' => $backup->id, 'quantity' => 1, 'kind' => 'full', 'role' => 'backup', 'sort' => 1]);
    fglOn();
    $cart = fglCart(150);

    $before = fglAs($cart)->getJson('/api/cart/drawer')->json('html');
    expect($before)->toContain('fwee Jelly Pot')->not->toContain('Mixsoon');

    // Somebody else's order takes the last one.
    $main->update(['stock' => 0, 'stock_status' => 'outofstock']);

    $r = fglAs($cart)->getJson('/api/cart/gifts?s=' . fglSig($before))->assertOk();
    expect($r->json('html'))->toStartWith('<fieldset class="fgb')
        ->toContain('Mixsoon Bean Essence')->not->toContain('fwee Jelly Pot')
        ->and($r->json('s'))->not->toBe(fglSig($before))
        ->and(fglSig($r->json('html')))->toBe($r->json('s'));

    // And once the page holds the fresh box, the next check is quiet again.
    fglAs($cart)->get('/api/cart/gifts?s=' . $r->json('s'))->assertNoContent(204);
});

it('leaves an empty marker below every band, and fills it when a band starts to cover the basket', function () {
    // Mutation note: drop the @elseif marker from the box partial and a basket
    // with no gift yet has nowhere for one to appear -- red.
    fglRange(300, 499, [[fglProduct('Toner Mini'), 1]]);
    $cart = fglCart(150);

    $drawer = fglAs($cart)->getJson('/api/cart/drawer')->json('html');
    $page = fglAs($cart)->get('/cart/')->getContent();
    expect($drawer)->toContain('<template data-fgs="0"></template>')->not->toContain('<fieldset class="fgb')
        ->and($page)->toContain('<template data-fgs="0" data-fgp></template>');
    fglAs($cart)->get('/api/cart/gifts?s=0')->assertNoContent(204);

    // The owner lowers the band to start at AED 100.
    DB::table('free_gift_ranges')->update(['min_fils' => 10000]);
    fglOn();

    $r = fglAs($cart)->getJson('/api/cart/gifts?s=0')->assertOk();
    expect($r->json('html'))->toContain('Toner Mini')->toStartWith('<fieldset class="fgb');
});

it('removes the box when the owner switches gifts off, and draws no marker at all while they are off', function () {
    // Mutation note: return '0' instead of null from box() for a shopper who
    // cannot see gifts and every page asks for nothing, forever -- red.
    fglRange(100, 299, [[fglProduct('Jelly Pot'), 1]]);
    $cart = fglCart(150);
    $sig = fglSig(fglAs($cart)->getJson('/api/cart/drawer')->json('html'));

    fglOn(['on' => false, 'mode' => 'off']);

    $r = fglAs($cart)->getJson('/api/cart/gifts?s=' . $sig)->assertOk();
    expect($r->json('s'))->toBeNull()->and($r->json('html'))->toBe('');

    $drawer = fglAs($cart)->getJson('/api/cart/drawer')->json('html');
    expect($drawer)->not->toContain('data-fgs')->not->toContain('fgb');
});

it('creates no basket, writes nothing, and costs the same for 3 gifts as for 40', function () {
    // Speed: the check is read only and its cost does not grow with the gift
    // list. Mutation note: load gift products one by one and the counts differ.
    $count = function (int $n): int {
        DB::table('free_gift_ranges')->delete();
        $gifts = [];
        for ($i = 0; $i < $n; $i++) {
            $gifts[] = [fglProduct('Gift ' . $n . '-' . $i), 1];
        }
        fglRange(100, 299, $gifts);
        $cart = fglCart(150);
        $client = fglAs($cart);
        $client->get('/api/cart/gifts?s=x');   // warm: the shipping zones are read once per process
        $client = fglAs($cart);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $client->get('/api/cart/gifts?s=x')->assertOk();
        $writes = collect(DB::getQueryLog())->filter(fn ($q) => preg_match('/^\s*(insert|update|delete)/i', $q['query']))->count();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();
        expect($writes)->toBe(0);

        return $n;
    };

    expect($count(3))->toBe($count(40));

    // And no dearer than the cart panel's own request, which draws the same box.
    $cart = fglCart(150);
    fglAs($cart)->getJson('/api/cart/drawer');
    $client = fglAs($cart);
    DB::flushQueryLog();
    DB::enableQueryLog();
    $client->getJson('/api/cart/drawer');
    $drawer = count(DB::getQueryLog());
    DB::disableQueryLog();
    expect($count(3))->toBeLessThanOrEqual($drawer);

    $carts = Cart::count();
    test()->get('/api/cart/gifts?s=0')->assertOk();   // no cookie: no basket, no gifts
    expect(Cart::count())->toBe($carts);
});

it('asks only on the shopper\'s own moves: no timer, no layout measured, one route, its own throttle', function () {
    $js = (string) file_get_contents(resource_path('js/kbb/gift-live.js'));
    $cart = (string) file_get_contents(resource_path('js/kbb/cart.js'));
    $web = (string) file_get_contents(base_path('routes/web.php'));
    $code = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $js);

    expect($code)->not->toContain('setInterval')->not->toContain('setTimeout')->not->toContain('requestAnimationFrame')
        ->not->toContain('getBoundingClientRect')->not->toContain('offsetHeight')->not->toContain('IntersectionObserver')
        ->toContain('const GAP = 30000;')
        ->toContain("if (busy || document.hidden || navigator.onLine === false) return;")
        ->toContain("if (e.persisted) check(true);")
        // A box the shopper opened stays open.
        ->toContain('if (opened && th && ls) { th.hidden = true; ls.hidden = false; }')
        ->and(substr_count($cart, 'initGiftLive();'))->toBe(1)
        ->and(substr_count($web, "require __DIR__.'/cart-gifts-live.php';"))->toBe(1)
        ->and((string) file_get_contents(base_path('routes/cart-gifts-live.php')))->toContain("->middleware('throttle:30,1,cart-gifts')");
});

it('never puts a fingerprint on an order\'s own box (thank-you page, My account)', function () {
    $box = (string) file_get_contents(resource_path('views/partials/checkout/received-summary.blade.php'))
        . (string) file_get_contents(resource_path('views/store/account/order-detail.blade.php'));

    expect($box)->not->toContain('kbbFgSig');
});
