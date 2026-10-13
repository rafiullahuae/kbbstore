<?php

declare(strict_types=1);

/*
 * Where a shopper with a basket went and where they left (Lane CJ, 2.60.494).
 *
 * The owner, 13 October 2026: "I want to track customer left page tracking.
 * that from where customer left? ... like he proceeded to checkout or not,
 * where he clicked (for cart and checkout page) and more if any. also this
 * functionality i want in my owner app too."
 */

use App\Models\AdminUser;
use App\Models\Cart;
use App\Models\Product;
use App\Services\CartService;
use App\Services\CartTracking\CartTrail;
use App\Services\CartTracking\CartTrackingSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\CartTrackingRoutes;
use Tests\Support\OwnerAppRoutes as OA;

const CJ_UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';

beforeEach(function () {
    Route::middleware('web')->group(base_path('routes/instant-nav.php'));
    Route::middleware('web')->group(base_path('routes/cart-trail.php'));
    CartTrackingRoutes::wire($this->app);
});

function cjCart(): Cart
{
    $p = Product::query()->create(['slug' => 'glow-serum-'.uniqid(), 'name' => 'Glow Serum', 'status' => 'publish', 'is_visible' => true, 'price' => 9700, 'stock_status' => 'instock']);
    $cart = Cart::create(['token' => (string) Str::uuid(), 'currency' => 'AED', 'status' => 'active', 'last_activity_at' => now()]);
    $cart->items()->create(['product_id' => $p->id, 'quantity' => 1, 'unit_price' => 9700]);
    DB::table('carts')->where('id', $cart->id)->update(['ct_first_at' => now()->subMinutes(5), 'ct_last_at' => now()->subMinutes(5), 'ct_value' => 9700, 'ct_ip' => '94.200.1.9', 'ct_country' => 'AE', 'ct_ua' => CJ_UA]);

    return $cart->fresh();
}

/** A shopper's browser: the basket cookie (or none), a real phone's user agent. */
function cjAs(?Cart $cart, string $ua = CJ_UA)
{
    app()->forgetScopedInstances();
    $t = test()->flushHeaders()->withHeaders(['User-Agent' => $ua])->withServerVariables(['REMOTE_ADDR' => '94.200.1.9'])
        ->withoutMiddleware(\Illuminate\Cookie\Middleware\EncryptCookies::class);

    return $cart ? $t->withUnencryptedCookie(CartService::COOKIE, $cart->token) : $t;
}

function cjTaps(?Cart $cart, array $events)
{
    return cjAs($cart)->post('/api/cart-trail', ['e' => json_encode($events)]);
}

function cjRows(Cart $cart): array
{
    return DB::table('cart_trail')->where('cart_id', $cart->id)->orderBy('id')->get(['code', 'path'])->map(fn ($r) => [(int) $r->code, $r->path])->all();
}

it('records each page a basket-holder opens, once per visit, and where they were last', function () {
    /*
     * DEFECT this guards: "from where customer left" with nothing behind it.
     * MUTATION: drop the CartTrail::page() call in ViewedController -> no rows,
     * red on the first expect; drop the SAME_PAGE_SECONDS check -> the reload
     * is a second row, red on toHaveCount(3).
     */
    $cart = cjCart();

    cjAs($cart)->post('/api/viewed', ['p' => '/product/glow-serum/?utm_source=ig', 't' => 'Glow'])->assertNoContent();
    cjAs($cart)->post('/api/viewed', ['p' => '/cart/'])->assertNoContent();
    cjAs($cart)->post('/api/viewed', ['p' => '/cart/'])->assertNoContent(); // a reload
    cjAs($cart)->post('/api/viewed', ['p' => '/checkout/'])->assertNoContent();

    expect(cjRows($cart))->toBe([[1, '/product/glow-serum/'], [1, '/cart/'], [1, '/checkout/']])
        ->toHaveCount(3);

    $c = DB::table('carts')->find($cart->id);
    expect($c->ct_seen_path)->toBe('/checkout/')
        ->and((int) $c->ct_stage)->toBe(3)
        ->and($c->ct_left_at)->toBeNull();
});

it('records nothing, and reads nothing, for a visitor with no basket, an administrator or a bot', function () {
    /*
     * DEFECT this guards: page tracking that costs every visitor a query or
     * records the owner's own browsing. MUTATION: drop allowed()'s kbb_ah or
     * BOT_RE test -> rows appear; resolve the cart before allowed() -> the
     * no-basket visitor costs a carts query.
     */
    $cart = cjCart();

    $q = [];
    DB::listen(function ($e) use (&$q) { $q[] = strtolower($e->sql); });
    cjAs(null)->post('/api/viewed', ['p' => '/shop/'])->assertNoContent();
    expect(array_values(array_filter($q, fn ($s) => str_contains($s, 'carts') || str_contains($s, 'cart_trail'))))->toBe([]);

    cjAs($cart)->withUnencryptedCookie('kbb_ah', '1')->post('/api/viewed', ['p' => '/shop/'])->assertNoContent();
    cjAs($cart, 'python-requests/2.31')->post('/api/viewed', ['p' => '/shop/'])->assertNoContent();
    cjTaps(null, [[39, '/checkout/', 0]])->assertNoContent();

    expect(cjRows($cart))->toBe([]);

    app(CartTrackingSettings::class)->save(['journey' => false]);
    cjAs($cart)->post('/api/viewed', ['p' => '/shop/'])->assertNoContent();
    expect(cjRows($cart))->toBe([]);
});

it('keeps the taps it knows, never anything typed, and moves the furthest step forward only', function () {
    /*
     * DEFECT this guards: a field's contents reaching the table, or an unknown
     * code stored as noise. MUTATION: store $e[1] as sent for code 40 -> the
     * email address is in the row; drop the CODES check -> code 999 is a row;
     * write ct_stage unconditionally -> the later tap moves the step back.
     */
    $cart = cjCart();

    cjTaps($cart, [
        [10, '/', 900], [12, '/', 800], [30, '/checkout/', 700], [33, '/checkout/', 600],
        [39, '/checkout/', 500], [40, 'billing_phone,billing_address_1,secret@example.com,password', 400],
        [999, '/x', 0], ['x', '/y', 0], [1, '/smuggled-page/', 0],
    ])->assertNoContent();
    cjTaps($cart, [[41, '/checkout/', 0]])->assertNoContent();

    expect(cjRows($cart))->toBe([
        [10, '/'], [12, '/'], [30, '/checkout/'], [33, '/checkout/'], [39, '/checkout/'],
        [40, 'Phone, Address'], [41, '/checkout/'],
    ]);
    expect(DB::table('cart_trail')->where('path', 'like', '%secret%')->count())->toBe(0)
        ->and((int) DB::table('carts')->where('id', $cart->id)->value('ct_stage'))->toBe(7);
});

it('says where the shopper left, and takes it back when they come back', function () {
    // MUTATION: ignore BACK in client() -> the shopper who returned still reads "Left".
    $cart = cjCart();

    cjAs($cart)->post('/api/viewed', ['p' => '/checkout/'])->assertNoContent();
    $this->travel(5)->seconds();
    cjTaps($cart, [[2, '/checkout/', 0]])->assertNoContent();

    $row = DB::table('carts')->where('id', $cart->id)->first();
    $s = CartTrail::summary($row, false);
    expect($s['left'])->not->toBeNull()->and($s['seen_page'])->toBe('Checkout')->and($s['stage_label'])->toBe('Opened checkout');

    $this->travel(5)->seconds();
    cjTaps($cart, [[3, '/checkout/', 0]])->assertNoContent();
    $s = CartTrail::summary(DB::table('carts')->where('id', $cart->id)->first(), false);
    expect($s['left'])->toBeNull()->and($s['online'])->toBeTrue();

    expect(CartTrail::summary($row, true)['stage_label'])->toBe('Ordered');
});

it('caps a batch and refuses an oversized body', function () {
    $cart = cjCart();
    cjTaps($cart, array_fill(0, 60, [14, '/', 0]))->assertNoContent();
    expect(DB::table('cart_trail')->where('cart_id', $cart->id)->count())->toBe(CartTrail::MAX_BATCH);

    cjAs($cart)->post('/api/cart-trail', ['e' => str_repeat('[14,"/",0],', 500)])->assertNoContent();
    expect(DB::table('cart_trail')->where('cart_id', $cart->id)->count())->toBe(CartTrail::MAX_BATCH);
});

it('shows the console the Left at line, the journey, the funnel and the exit pages', function () {
    /*
     * MUTATION: drop 'journey' from cartRow(), 'trail' from cart(), or the
     * funnel/exits from summary() -> red on the matching expect.
     */
    $cart = cjCart();
    $slug = Product::query()->latest('id')->value('slug');
    cjAs($cart)->post('/api/viewed', ['p' => '/product/'.$slug.'/'])->assertNoContent();
    cjAs($cart)->post('/api/viewed', ['p' => '/checkout/'])->assertNoContent();
    cjTaps($cart, [[35, '/checkout/', 0], [2, '/checkout/', 0]])->assertNoContent();

    $owner = AdminUser::create(['name' => 'Owner', 'email' => 'cj-'.uniqid().'@example.test', 'password' => 'password-long-enough', 'role' => 'owner']);
    \Illuminate\Support\Facades\Cache::flush();
    $list = test()->actingAs($owner, 'admin')->getJson('/admin-api/cart-tracking?period=7d')->assertOk();
    $row = collect($list->json('rows'))->firstWhere('id', $cart->id);

    expect($row['journey']['seen_page'])->toBe('Checkout')
        ->and($row['journey']['stage_label'])->toBe('Chose payment')
        ->and($row['journey']['left'])->not->toBeNull()
        ->and(collect($list->json('summary.funnel'))->firstWhere('stage', 6)['carts'])->toBe(1)
        // A step is counted only when it was DONE: this shopper chose Tamara
        // without ever completing the address (MUTATION: go back to "reached
        // at least this far" and Completed address reads 1).
        ->and(collect($list->json('summary.funnel'))->firstWhere('stage', 5)['carts'])->toBe(0)
        ->and(collect($list->json('summary.funnel'))->firstWhere('stage', 3)['carts'])->toBe(1)
        ->and(collect($list->json('summary.funnel'))->firstWhere('stage', 0)['carts'])->toBeGreaterThanOrEqual(1)
        ->and($list->json('summary.exits.0'))->toBe(['page' => 'Checkout', 'n' => 1]);

    $detail = test()->actingAs($owner, 'admin')->getJson('/admin-api/cart-tracking/carts/'.$cart->id)->assertOk();
    expect(array_column($detail->json('trail'), 'title'))->toBe(['Product: Glow Serum', 'Checkout', 'Chose Tamara', 'Left Checkout']);
});

it('gives the owner app the same journey, behind carttracking.view, allowlisted', function () {
    /*
     * MUTATION: drop refuse() in CartsController::journey -> support reads it;
     * pass the trail rows through whole -> the extra keys fail toBe().
     */
    OA::wire($this->app);
    $owner = OA::admin();
    OA::member($owner);
    [$c] = OA::enrol($this);

    $cart = cjCart();
    cjAs($cart)->post('/api/viewed', ['p' => '/cart/'])->assertNoContent();
    cjTaps($cart, [[20, '/cart/', 0], [40, 'billing_email', 0]])->assertNoContent();

    $r = OA::get($this, 'carts/'.$cart->id.'/journey', $c)->assertOk();
    expect(array_keys($r->json('steps.0')))->toBe(['at', 'kind', 'title', 'detail'])
        ->and(array_column($r->json('steps'), 'title'))->toBe(['Cart page', 'Tapped Proceed to checkout', 'Place order stopped: fields to fix'])
        ->and($r->json('steps.2.detail'))->toBe('Email');

    $list = OA::get($this, 'carts?period=7d', $c)->assertOk();
    $row = collect($list->json('rows'))->firstWhere('id', $cart->id);
    expect($row['journey']['stage_label'])->toBe('Pressed Place order')
        ->and(array_keys($row['journey']))->toBe(['stage', 'stage_label', 'seen', 'seen_page', 'left', 'online']);

    $a = OA::admin('support', 'sue-cj@example.com', 'Sue');
    OA::member($a);
    [$s] = OA::enrol($this, 'sue-cj@example.com');
    OA::get($this, 'carts/'.$cart->id.'/journey', $s)->assertForbidden();
});

it('prunes journey rows with Cart Tracking\'s own "Keep cart events for"', function () {
    $cart = cjCart();
    DB::table('cart_trail')->insert([
        ['cart_id' => $cart->id, 'code' => 1, 'path' => '/old/', 'created_at' => now()->subDays(400)],
        ['cart_id' => $cart->id, 'code' => 1, 'path' => '/new/', 'created_at' => now()],
    ]);
    app(\App\Services\CartTracking\CartTrackingPrune::class)->run();
    expect(DB::table('cart_trail')->where('cart_id', $cart->id)->pluck('path')->all())->toBe(['/new/']);
});

it('ships the shop listener inside the existing bundle, with no layout measuring and nothing typed', function () {
    $js = (string) file_get_contents(resource_path('js/kbb/trail.js'));
    $app = (string) file_get_contents(resource_path('js/kbb/app.js'));

    expect(substr_count($app, "import { initTrail } from './trail.js';"))->toBe(1)
        ->and(substr_count($app, "    initTrail,\n"))->toBe(1)
        ->and($js)->not->toMatch('/getBoundingClientRect|offsetWidth|offsetHeight|getComputedStyle|setInterval/')
        // The only field contents read are tested, never sent: the queue holds codes, paths and field NAMES.
        ->and($js)->toContain("push(40, names.slice(0, 12).join(','))")
        ->and($js)->toContain('hasAdminHint(document.cookie)');
});
