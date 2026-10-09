<?php

declare(strict_types=1);

/*
 * The owner app's Cart tracking screen (Lane QK10). The owner, 9 October:
 * "also give this cart tracking access to the owner app too so i can check
 * that from the mobile too."
 *
 * One read, the console's own (CartTrackingReport::carts), behind the app's
 * session and the console's capability, carttracking.view. The phone gets
 * LESS than the console: no IP address, network, bot score or reasons.
 */

use App\Models\Cart;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\OwnerAppRoutes as OA;

beforeEach(function () {
    OA::wire($this->app);
    \Illuminate\Support\Facades\RateLimiter::clear('owner-app-pin:127.0.0.1');
    \Illuminate\Support\Facades\RateLimiter::clear('owner-app-enrol:127.0.0.1');
    $this->owner = OA::admin();
    OA::member($this->owner);
});

/** $n tracked carts: every third one a known customer whose cart became an order. */
function qkCarts(int $n, int $from = 1): void
{
    $p = Product::query()->create(['slug' => 'qk-'.uniqid(), 'name' => 'Snail Mucin Essence', 'price' => 5500, 'stock_status' => 'instock']);
    for ($i = $from; $i < $from + $n; $i++) {
        $cart = Cart::create(['token' => (string) Str::uuid(), 'currency' => 'AED', 'status' => 'active', 'last_activity_at' => now()]);
        $cart->items()->create(['product_id' => $p->id, 'quantity' => 2, 'unit_price' => 5500]);
        $set = ['ct_ip' => '94.200.'.($i % 250).'.9', 'ct_net' => '94.200.'.($i % 250).'.0/24', 'ct_country' => 'AE',
            'ct_ua' => 'Mozilla/5.0', 'ct_bot_score' => 0, 'ct_bot_flags' => 0, 'ct_value' => 11000, 'ct_added' => 2,
            'ct_first_at' => now()->subMinutes(30 + $i), 'ct_last_at' => now()->subMinutes($i)];
        if ($i % 3 === 0) {
            $cid = DB::table('customers')->insertGetId(['name' => 'Customer '.$i, 'email' => 'qk'.$i.'@example.com', 'created_at' => now(), 'updated_at' => now()]);
            $o = Order::query()->create(['order_number' => (string) (50000 + $i), 'customer_id' => $cid, 'email' => 'qk'.$i.'@example.com',
                'status' => 'processing', 'total' => 11000, 'subtotal' => 11000, 'payment_method' => 'cod']);
            $set += ['customer_id' => $cid, 'ct_order_id' => $o->id];
        }
        DB::table('carts')->where('id', $cart->id)->update($set);
    }
}

it('refuses a role without carttracking.view, and shows the owner and a manager', function () {
    // DEFECT this guards: a phone screen that skips the console's capability
    // hands every enrolled member the shop's carts. MUTATION: drop the
    // refuse() line in OwnerApp\CartsController::index and support gets 200.
    qkCarts(2);

    [$c] = OA::enrol($this);
    OA::get($this, 'carts', $c)->assertOk();

    $mgr = OA::admin('manager', 'mo@example.com', 'Mo');
    OA::member($mgr);
    [$m] = OA::enrol($this, 'mo@example.com');
    OA::get($this, 'carts', $m)->assertOk();

    foreach (['support' => 'sue@example.com', 'editor' => 'ed@example.com'] as $role => $email) {
        $a = OA::admin($role, $email, ucfirst($role));
        OA::member($a);
        [$s] = OA::enrol($this, $email);
        $r = OA::get($this, 'carts', $s)->assertForbidden();
        expect($r->json('capability'))->toBe('carttracking.view')
            ->and($r->json())->not->toHaveKey('rows');
        // And the app is told, so it draws no More entry for them.
        expect(OA::get($this, 'state', $s)->json('me.can.carts'))->toBeFalse();
    }
    expect(OA::get($this, 'state', $c)->json('me.can.carts'))->toBeTrue();
});

it('returns only the allowlisted keys: no IP address, network, bot score or reasons', function () {
    // DEFECT this guards: passing the console's row straight through puts
    // the shopper's IP address on a phone that never needed it. MUTATION:
    // return $d['rows'] unmapped and the key list and the IP check are red.
    qkCarts(3);
    [$c] = OA::enrol($this);
    $d = OA::get($this, 'carts?period=all', $c)->assertOk()->json();

    expect(array_keys($d))->toBe(['rows', 'total', 'page', 'pages', 'summary'])
        ->and(array_keys($d['summary']))->toBe(['carts', 'bought', 'conversion', 'bots', 'open_value', 'bought_value'])
        ->and($d['total'])->toBe(3);

    foreach ($d['rows'] as $row) {
        expect(array_keys($row))->toBe(\App\Http\Controllers\OwnerApp\CartsController::ROW_KEYS)
            ->and(array_keys($row['items'][0]))->toBe(['id', 'name', 'qty']);
    }
    $json = json_encode($d);
    expect($json)->not->toContain('94.200.')->not->toContain('"ip"')->not->toContain('"score"')->not->toContain('"reasons"');

    // Newest first, value in AED, the order when it became one.
    $bought = collect($d['rows'])->firstWhere('order', '!=', null);
    expect($d['rows'][0]['last'] > $d['rows'][2]['last'])->toBeTrue()
        ->and($d['rows'][0]['value_label'])->toContain('110')
        ->and($bought['order']['number'])->toBe('50003')
        ->and($bought['customer']['name'])->toBe('Customer 3')
        ->and($bought['email'])->toBe('qk3@example.com');
});

it('filters to carts that did not become orders, and ignores a value that is not one of its options', function () {
    qkCarts(6);
    [$c] = OA::enrol($this);

    expect(OA::get($this, 'carts?period=all&bought=no', $c)->assertOk()->json('total'))->toBe(4)
        ->and(OA::get($this, 'carts?period=all&bought=yes', $c)->assertOk()->json('total'))->toBe(2)
        ->and(OA::get($this, 'carts?period=nonsense&bought=%27or1&page=-4', $c)->assertOk()->json('page'))->toBe(1);
});

it('costs the same number of queries for 3 carts as for 40, and pages at 50', function () {
    // DEFECT: a query per cart -- the phone screen slows as the shop grows.
    $count = function (int $n): int {
        DB::table('cart_items')->delete();
        DB::table('carts')->delete();
        Order::query()->forceDelete();
        DB::table('customers')->delete();
        qkCarts($n);
        [$c] = OA::enrol($this);
        OA::get($this, 'carts?period=all', $c)->assertOk();   // warm the per-process memos
        Cache::flush();                                       // the cold, uncached summary
        DB::flushQueryLog();
        DB::enableQueryLog();
        OA::get($this, 'carts?period=all', $c)->assertOk();
        $q = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $q;
    };

    expect($count(40))->toBe($count(3));

    // ($count(3) ran last, so three carts remain.) 57 more: 60, two pages of 50.
    qkCarts(57, 100);
    [$c] = OA::enrol($this);
    $d = OA::get($this, 'carts?period=all&page=2', $c)->assertOk()->json();
    expect($d['pages'])->toBe(2)->and($d['page'])->toBe(2)->and(count($d['rows']))->toBe(10);
});

it('is reachable from the app: More lists it first, the router draws it, nothing polls it', function () {
    // The bottom bar already carries five tabs (My store, Live, Orders,
    // Products, More), so Cart tracking is the FIRST row under More.
    $js = fn (string $f) => (string) file_get_contents(resource_path('js/owner-app/'.$f));

    $more = $js('store.js');
    $at = strpos($more, 'const go = ');
    expect($at)->not->toBeFalse()
        ->and(substr_count($more, "href=\"#/carts\""))->toBe(1)
        ->and(strpos($more, "'Cart tracking'", (int) $at))->toBeLessThan(strpos($more, "'Customers'", (int) $at))
        ->and($more)->toContain("me.can.carts ? r('cart', 'Cart tracking'");

    $app = $js('owner-app.js');
    expect(substr_count($app, "import { renderCarts, cartsClick, fetchCarts } from './carts.js';"))->toBe(1)
        ->and(substr_count($app, "else if (n === 'carts') await renderCarts(view);"))->toBe(1)
        ->and($app)->toContain("['customers', 'notifications', 'carts'].indexOf(route.name)");

    $carts = $js('carts.js');
    expect($carts)->not->toContain('setInterval')->not->toContain('setTimeout')
        ->not->toContain('getBoundingClientRect')->not->toContain('offsetHeight');

    $shell = (string) file_get_contents(resource_path('views/owner-app/shell.blade.php'));
    expect(substr_count($shell, '<symbol id="i-cart"'))->toBe(1);

    expect(substr_count((string) file_get_contents(base_path('routes/owner-app.php')), "->name('carts');"))->toBe(1)
        ->and(\App\Http\Middleware\OwnerAppUiGate::ALWAYS)->toContain('carts');
});
