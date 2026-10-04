<?php

declare(strict_types=1);

/**
 * Cart Tracking's cost, measured rather than asserted.            (Lane CT)
 *
 * The owner: "very light". The numbers these cases print go into the lane's
 * report; the assertions pin the SHAPE that keeps them small — one INSERT per
 * cart event, one extra read on a cart's first event, nothing on a page view —
 * so a later change that adds a query per add-to-cart goes red here.
 */

use App\Models\Cart;
use App\Models\Product;
use App\Services\CartService;
use App\Services\CartTracking\CartTrackingSettings;
use App\Services\Security\IpBlockList;
use App\Support\IpRange;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function ctcProduct(): Product
{
    return Product::create(['slug' => 'ctc-'.uniqid(), 'name' => 'Cost Serum', 'status' => 'publish',
        'is_visible' => true, 'price' => 5000, 'stock_status' => 'instock']);
}

/** Queries (and the SQL) one add-to-cart request runs. */
function ctcAdd(Product $p, ?string $token): array
{
    // A real shopper's next click is seconds later. Without this, both adds
    // land in one second, CartService's last_activity_at write is not dirty
    // and its UPDATE is skipped — a baseline the live shop never has.
    test()->travel(1)->minutes();
    app()->forgetScopedInstances();
    foreach (app('router')->getRoutes()->getRoutes() as $route) {
        if (str_contains($route->uri(), 'cart')) {
            $route->controller = null;
        }
    }
    test()->flushHeaders();

    $sql = [];
    DB::listen(function ($q) use (&$sql) { $sql[] = $q->sql; });
    $t = hrtime(true);
    test()->withCredentials()
        ->withServerVariables(['REMOTE_ADDR' => '94.200.10.20'])
        ->withHeaders(['User-Agent' => 'Mozilla/5.0 (iPhone) Safari/604.1', 'X-KBB-Hm' => '6000'])
        ->withoutMiddleware(\Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $token ?? 'fresh-'.uniqid())
        ->postJson('/api/cart/add', ['product_id' => $p->id])->assertOk();
    $ms = (hrtime(true) - $t) / 1e6;
    $n = count($sql);
    app('events')->forget(\Illuminate\Database\Events\QueryExecuted::class);

    return [$n, $ms, $sql];
}

it('costs one INSERT per add-to-cart, plus one read on a cart\'s first add', function () {
    /*
     * DEFECT: tracking adds an UPDATE (or an upsert) to every add-to-cart, or
     * a lookup per event; at the shop's volume that is the slowest request
     * it has, made slower. MUTATION: write the summary with
     * $cart->save() inside CartTracker::record() and the warm delta is 2.
     */
    $p = ctcProduct();

    $measure = function (bool $track) use ($p): array {
        app(CartTrackingSettings::class)->save(['track' => $track]);
        IpBlockList::rebuild();
        ctcAdd($p, null); // warm the framework
        [$first, $firstMs] = ctcAdd($p, null);
        $token = Cart::query()->latest('id')->value('token');
        [$again, $againMs, $sql] = ctcAdd($p, $token);

        return compact('first', 'firstMs', 'again', 'againMs', 'sql');
    };

    $off = $measure(false);
    $on = $measure(true);

    fwrite(STDERR, sprintf("\n[ct-cost] add-to-cart queries: tracking off first=%d again=%d | on first=%d again=%d | ms off %.1f/%.1f on %.1f/%.1f\n",
        $off['first'], $off['again'], $on['first'], $on['again'], $off['firstMs'], $off['againMs'], $on['firstMs'], $on['againMs']));

    expect($on['again'] - $off['again'])->toBe(1)
        // The first add: the event INSERT, the one burst COUNT, and the cart
        // row's UPDATE — which an untracked first add skips only because the
        // cart was created a moment earlier in the same request and its
        // last_activity_at is not dirty yet.
        ->and($on['first'] - $off['first'])->toBe(3)
        ->and(collect($on['sql'])->filter(fn ($s) => str_contains($s, 'cart_events'))->count())->toBe(1);
});

it('decides a request against 1,000 blocks in microseconds, with no query', function () {
    /*
     * DEFECT: a block list that scans its rows (or the database) per request
     * makes every page pay for every block. MUTATION: replace the per-prefix
     * isset() in IpBlockList::match() with a loop over all 1,000 entries and
     * the per-request cost rises ~100x.
     */
    $now = now();
    $rows = [];
    for ($i = 0; $i < 1000; $i++) {
        // 250 whole /24 ranges and 750 single addresses: the mix the owner's
        // two buttons produce.
        $cidr = $i < 250 ? '198.18.'.$i.'.0/24' : '198.19.'.intdiv($i, 250).'.'.($i % 250).'/32';
        $r = IpRange::parse($cidr);
        $rows[$r['cidr']] = ['cidr' => $r['cidr'], 'family' => 4, 'prefix' => $r['prefix'], 'network' => $r['network'],
            'source' => 'manual', 'hits' => 0, 'created_at' => $now, 'updated_at' => $now];
    }
    $r6 = IpRange::parse('2001:db8:1:2::/64');
    $rows[] = ['cidr' => $r6['cidr'], 'family' => 6, 'prefix' => 64, 'network' => $r6['network'], 'source' => 'manual', 'hits' => 0, 'created_at' => $now, 'updated_at' => $now];
    foreach (array_chunk(array_values($rows), 200) as $c) {
        DB::table('ip_blocks')->insert($c);
    }
    IpBlockList::rebuild();
    $n = IpBlockList::compiled()['n'];

    $gate = new \App\Http\Middleware\BlockGate;
    $request = \Illuminate\Http\Request::create('/product/x/', 'GET', server: ['REMOTE_ADDR' => '94.200.10.20']);
    $next = fn () => new \Illuminate\Http\Response('ok');

    $queries = 0;
    DB::listen(function () use (&$queries) { $queries++; });

    $loops = 20000;
    $t = hrtime(true);
    for ($i = 0; $i < $loops; $i++) {
        $gate->handle($request, $next);
    }
    $memoUs = (hrtime(true) - $t) / 1e3 / $loops;

    // With the file read every time, as each PHP-FPM request does (here
    // WITHOUT opcache, so this is the pessimistic figure).
    $loops2 = 2000;
    $t = hrtime(true);
    for ($i = 0; $i < $loops2; $i++) {
        IpBlockList::forget();
        $gate->handle($request, $next);
    }
    $fileUs = (hrtime(true) - $t) / 1e3 / $loops2;

    fwrite(STDERR, sprintf("\n[ct-cost] BlockGate with %d blocks: %.2f us/request (list in memory), %.1f us/request (file re-read, no opcache); file %d bytes\n",
        $n, $memoUs, $fileUs, filesize(IpBlockList::path())));

    expect($n)->toBe(1001)
        ->and($queries)->toBe(0)
        ->and($memoUs)->toBeLessThan(100.0)
        ->and(IpBlockList::match('198.18.7.200'))->not->toBeNull()
        ->and(IpBlockList::match('198.19.1.3'))->not->toBeNull()
        ->and(IpBlockList::match('2001:db8:1:2:aaaa::1'))->not->toBeNull()
        ->and(IpBlockList::match('94.200.10.20'))->toBeNull();
});
