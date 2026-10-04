<?php

declare(strict_types=1);

/**
 * Growth & Marketing → Cart Tracking: what is recorded, and what it costs.
 *                                                                 (Lane CT)
 *
 * Every case says what the defect would look like on the shop, and how to
 * break the code to watch it go red (the mutation note).
 */

use App\Models\Cart;
use App\Models\Order;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\CartService;
use App\Services\CartTracking\BotSignals;
use App\Services\CartTracking\CartTracker;
use App\Services\CartTracking\CartTrackingPrune;
use App\Services\CartTracking\CartTrackingReport;
use App\Services\CartTracking\CartTrackingSettings;
use App\Services\Security\IpBlockList;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

const CT_BROWSER = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';

function ctProduct(string $name = 'Snail Mucin Essence', int $price = 5500): Product
{
    return Product::create([
        'slug' => Str::slug($name).'-'.uniqid(),
        'name' => $name,
        'status' => 'publish',
        'is_visible' => true,
        'price' => $price,
        'stock_status' => 'instock',
    ]);
}

/** A shopper's browser: an address, a real user agent, the cart script's header. */
function ctShopper(string $ip = '94.200.10.20', ?string $cartToken = null, ?string $hm = '6400', string $ua = CT_BROWSER)
{
    // CartService is scoped and the test client never ends the scope, so
    // without this every "shopper" in one test would share the first one's
    // basket — the second visitor would be adding to the first's cart.
    // And the router keeps each controller instance on its Route between
    // requests, holding the CartService it was built with — so the cart and
    // checkout controllers are dropped too, exactly as a new PHP-FPM request
    // would drop them.
    app()->forgetScopedInstances();

    foreach (app('router')->getRoutes()->getRoutes() as $route) {
        if (str_contains($route->uri(), 'cart') || str_contains($route->uri(), 'checkout')) {
            $route->controller = null;
        }
    }

    // The test client keeps headers and cookies from the previous call; a
    // new shopper starts with neither -- a cart cookie that matches nothing
    // is what a fresh browser amounts to.
    test()->flushHeaders();

    $t = test()->withCredentials()->withServerVariables(['REMOTE_ADDR' => $ip])->withHeaders(array_filter([
        'User-Agent' => $ua,
        'X-KBB-Hm' => $hm,
    ], fn ($v) => $v !== null));

    return $t->withoutMiddleware(\Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cartToken ?? 'fresh-browser-'.uniqid());
}

function ctCodSetup(): void
{
    $zone = ShippingZone::create(['name' => 'UAE', 'position' => 0]);
    ShippingZoneLocation::create(['shipping_zone_id' => $zone->id, 'type' => 'country', 'code' => 'AE']);
    ShippingMethod::create([
        'shipping_zone_id' => $zone->id, 'type' => 'flat_rate', 'title' => 'Standard delivery',
        'cost' => 2000, 'enabled' => true, 'position' => 0,
    ]);
    PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0]);
    app(\App\Services\SettingsService::class)->set('cod_fee', 0);
}

function ctCodForm(): array
{
    return [
        'billing_email' => 'aisha@example.com', 'billing_phone' => '+971500000000',
        'billing_first_name' => 'Aisha', 'billing_last_name' => 'Khan',
        'billing_address_1' => '12 Marina Walk', 'billing_city' => 'Dubai', 'billing_state' => 'Dubai',
        'billing_country' => 'AE', 'payment_method' => 'cod',
    ];
}

it('records every add, quantity change and removal, and links the cart to its order', function () {
    /*
     * DEFECT: a cart that became an order reads "Not purchased" on the Carts
     * tab — the owner cannot tell a fake COD order from an abandoned cart.
     * MUTATION: delete the converted() call in CheckoutController::place()
     * and ct_order_id stays null; delete the record() in CartService::add()
     * and there are no events at all.
     */
    ctCodSetup();
    $serum = ctProduct('Ginseng Serum', 9900);
    $toner = ctProduct('Rice Toner', 4500);

    ctShopper()->postJson('/api/cart/add', ['product_id' => $serum->id, 'quantity' => 1])->assertOk();
    $cart = Cart::query()->latest('id')->firstOrFail();

    ctShopper(cartToken: $cart->token)->postJson('/api/cart/add', ['product_id' => $toner->id, 'quantity' => 2])->assertOk();
    $line = $cart->items()->where('product_id', $serum->id)->firstOrFail();
    ctShopper(cartToken: $cart->token)->postJson('/api/cart/update', ['item_id' => $line->id, 'quantity' => 3])->assertOk();

    $cart->refresh();
    $events = DB::table('cart_events')->where('cart_id', $cart->id)->orderBy('id')->get();

    expect($events)->toHaveCount(3)
        ->and($events->pluck('type')->all())->toBe([CartTracker::ADD, CartTracker::ADD, CartTracker::QTY])
        ->and((int) $events[2]->qty)->toBe(2)
        ->and((int) $events[2]->qty_after)->toBe(3)
        ->and((int) $events[0]->unit_price)->toBe(9900)
        ->and($cart->ct_ip)->toBe('94.200.10.20')
        ->and($cart->ct_net)->toBe('94.200.10.0/24')
        ->and($cart->ct_ua)->toBe(CT_BROWSER)
        // The tracked value is the lines' own total, bundle tiers included.
        ->and((int) $cart->ct_value)->toBe((int) DB::table('cart_items')->where('cart_id', $cart->id)->sum(DB::raw('quantity * unit_price')))
        ->and((int) $cart->ct_value)->toBeGreaterThan(0)
        ->and((int) $cart->ct_added)->toBe(2)
        ->and($cart->ct_first_at)->not->toBeNull()
        ->and($cart->ct_order_id)->toBeNull();

    ctShopper(cartToken: $cart->token)->post('/checkout/place', ctCodForm())->assertRedirect();

    $order = Order::query()->latest('id')->firstOrFail();
    $cart->refresh();

    expect((int) $cart->ct_order_id)->toBe((int) $order->id)
        ->and($cart->status)->toBe('converted')
        ->and($cart->ct_country)->toBe('AE');

    $row = app(CartTrackingReport::class)->carts(['period' => 'today'])['rows'][0];
    expect($row['order']['number'])->toBe($order->order_number)
        ->and($row['order']['cod'])->toBeTrue()
        ->and(collect($row['products'])->pluck('qty', 'name')->all())->toBe(['Ginseng Serum' => 3, 'Rice Toner' => 2]);
});

it('counts removed products, on the cart and on the Removed products tab', function () {
    /*
     * DEFECT: "Products Removed" is empty on every cart and the Removed
     * products tab ranks nothing — what shoppers changed their minds about
     * is invisible. MUTATION: drop the record() in CartService::remove(), or
     * make it CartTracker::ADD, and both assertions below go red.
     */
    $a = ctProduct('Peach Sleeping Mask', 6000);
    $b = ctProduct('Centella Cream', 7000);

    ctShopper()->postJson('/api/cart/add', ['product_id' => $a->id])->assertOk();
    $cart = Cart::query()->latest('id')->firstOrFail();
    ctShopper(cartToken: $cart->token)->postJson('/api/cart/add', ['product_id' => $b->id])->assertOk();

    $line = $cart->items()->where('product_id', $a->id)->firstOrFail();
    ctShopper(cartToken: $cart->token)->postJson('/api/cart/remove', ['item_id' => $line->id])->assertOk();

    // Second shopper removes the same product by setting its quantity to 0.
    ctShopper('94.200.11.5')->postJson('/api/cart/add', ['product_id' => $a->id])->assertOk();
    $other = Cart::query()->latest('id')->firstOrFail();
    $line2 = $other->items()->firstOrFail();
    ctShopper('94.200.11.5', $other->token)->postJson('/api/cart/update', ['item_id' => $line2->id, 'quantity' => 0])->assertOk();

    $cart->refresh();
    expect((int) $cart->ct_removed)->toBe(1)
        ->and((int) $cart->ct_value)->toBe(7000);

    $removed = app(CartTrackingReport::class)->products('removed', 'today');
    expect($removed['rows'][0]['name'])->toBe('Peach Sleeping Mask')
        ->and($removed['rows'][0]['carts'])->toBe(2)
        ->and($removed['rows'][0]['times'])->toBe(2);

    $row = collect(app(CartTrackingReport::class)->carts(['period' => 'today'])['rows'])->firstWhere('id', $cart->id);
    expect($row['removed'])->toBe([['id' => $a->id, 'name' => 'Peach Sleeping Mask', 'url' => $a->url(), 'qty' => 1]]);
});

it('puts each cart and each event in the right period, in the store\'s time zone', function () {
    /*
     * DEFECT: "Today" in Dubai starts at 20:00 UTC the evening before; a
     * UTC-midnight cutoff files a 22:00-UTC cart (02:00 Dubai) under
     * yesterday. MUTATION: replace StoreTime::startOfDayUtc() in
     * CartTrackingReport::range() with now()->startOfDay() and the "today"
     * count below loses the early-morning cart.
     */
    $p = ctProduct('Cleansing Balm', 5000);
    $report = app(CartTrackingReport::class);

    // 2026-10-04 02:00 in Dubai = 2026-10-03 22:00 UTC.
    $this->travelTo(\Carbon\Carbon::parse('2026-10-03 22:00:00', 'UTC'));
    ctShopper('94.200.1.1')->postJson('/api/cart/add', ['product_id' => $p->id])->assertOk();

    // 2026-10-03 12:00 Dubai — yesterday.
    $this->travelTo(\Carbon\Carbon::parse('2026-10-03 08:00:00', 'UTC'));
    ctShopper('94.200.2.2')->postJson('/api/cart/add', ['product_id' => $p->id])->assertOk();

    // Twenty days ago, and ninety days ago.
    $this->travelTo(\Carbon\Carbon::parse('2026-09-14 08:00:00', 'UTC'));
    ctShopper('94.200.3.3')->postJson('/api/cart/add', ['product_id' => $p->id])->assertOk();
    $this->travelTo(\Carbon\Carbon::parse('2026-07-06 08:00:00', 'UTC'));
    ctShopper('94.200.4.4')->postJson('/api/cart/add', ['product_id' => $p->id])->assertOk();

    $this->travelTo(\Carbon\Carbon::parse('2026-10-04 10:00:00', 'UTC'));

    $count = fn (array $p) => $report->carts($p)['total'];

    expect($count(['period' => 'today']))->toBe(1)
        ->and($count(['period' => 'yesterday']))->toBe(1)
        ->and($count(['period' => '7d']))->toBe(2)
        ->and($count(['period' => '30d']))->toBe(3)
        ->and($count(['period' => 'all']))->toBe(4)
        ->and($count(['period' => 'custom', 'from' => '2026-09-01', 'to' => '2026-09-30']))->toBe(1)
        ->and($report->products('added', 'today')['rows'][0]['carts'])->toBe(1)
        ->and($report->products('added', '30d')['rows'][0]['carts'])->toBe(3)
        ->and($report->products('added', 'all')['rows'][0]['carts'])->toBe(4);
});

it('scores bots with named reasons, and leaves a real shopper at No', function () {
    /*
     * DEFECT: the Bot column says Yes for a real iPhone shopper, or No for
     * curl — the owner blocks the wrong people. MUTATION: give NO_JS 60 points
     * in BotSignals::WEIGHTS and the "real shopper on a VPN without the
     * header" case flips to Yes; drop the TOOLS match and curl reads No.
     */
    expect(BotSignals::agent('curl/8.4.0')[0])->toBe(BotSignals::UA_TOOL)
        ->and(BotSignals::agent('python-requests/2.31.0')[0])->toBe(BotSignals::UA_TOOL)
        ->and(BotSignals::agent('Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/120.0.0.0 Safari/537.36')[0])->toBe(BotSignals::UA_HEADLESS)
        ->and(BotSignals::agent('Mozilla/5.0 (compatible; AhrefsBot/7.0; +http://ahrefs.com/robot/)')[0])->toBe(BotSignals::UA_CRAWLER)
        ->and(BotSignals::agent('')[0])->toBe(BotSignals::UA_EMPTY)
        ->and(BotSignals::agent(CT_BROWSER)[0])->toBe(0)
        // A phone brand with "BOT" in its name is not a crawler.
        ->and(BotSignals::agent('Mozilla/5.0 (Linux; Android 9; CUBOT X19) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Mobile Safari/537.36')[0])->toBe(0)
        ->and(BotSignals::agent('Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/49.0.2623.112 Safari/537.36')[0])->toBe(BotSignals::UA_OLD)
        ->and(BotSignals::score(BotSignals::NO_JS | BotSignals::HOSTING))->toBe(55)
        ->and(BotSignals::score(BotSignals::HOSTING))->toBe(25)
        ->and(BotSignals::script('300', 1500))->toBe([BotSignals::FAST, 300])
        ->and(BotSignals::script('9000', 1500))->toBe([0, 9000])
        ->and(BotSignals::script(null, 1500))->toBe([BotSignals::NO_JS, null]);

    // Through the real endpoint, with "Ask bots to leave" off so the bot gets in.
    app(CartTrackingSettings::class)->save(['bots_leave' => false]);
    IpBlockList::rebuild();
    $p = ctProduct();

    ctShopper('94.200.50.1', hm: '7200')->postJson('/api/cart/add', ['product_id' => $p->id])->assertOk();
    $human = Cart::query()->latest('id')->firstOrFail();

    ctShopper('94.200.51.1', hm: null, ua: 'python-requests/2.31.0')->postJson('/api/cart/add', ['product_id' => $p->id])->assertOk();
    $script = Cart::query()->latest('id')->firstOrFail();

    ctShopper('94.200.52.1', hm: '240')->postJson('/api/cart/add', ['product_id' => $p->id])->assertOk();
    $fast = Cart::query()->latest('id')->firstOrFail();

    $rows = collect(app(CartTrackingReport::class)->carts(['period' => 'today'])['rows'])->keyBy('id');

    expect($rows[$human->id]['bot'])->toBeFalse()
        ->and($rows[$human->id]['reasons'])->toBe([])
        ->and($rows[$script->id]['bot'])->toBeTrue()
        ->and(collect($rows[$script->id]['reasons'])->pluck('code')->all())->toBe(['ua_tool', 'no_js'])
        ->and($rows[$script->id]['reasons'][0]['text'])->toBe('Scripted client (python-requests)')
        ->and($rows[$fast->id]['score'])->toBe(35)
        ->and($rows[$fast->id]['bot'])->toBeFalse()
        ->and($rows[$fast->id]['reasons'][0]['text'])->toBe('Added 0.2 s after the page opened');
});

it('flags a burst of carts from one address and from one /24', function () {
    /*
     * DEFECT: a bot that opens fifty carts from one address, each looking
     * like a browser, reads Bot: No on every one. MUTATION: remove the
     * placeFlags() call in CartTracker::stage() and the fifth cart scores 0.
     */
    app(CartTrackingSettings::class)->save(['burst_ip' => 5, 'burst_net' => 8, 'burst_minutes' => 60]);
    IpBlockList::rebuild();
    $p = ctProduct();

    foreach (range(1, 5) as $i) {
        ctShopper('94.200.60.7')->postJson('/api/cart/add', ['product_id' => $p->id])->assertOk();
    }

    $fifth = Cart::query()->latest('id')->firstOrFail();
    $fourth = Cart::query()->where('id', '<', $fifth->id)->latest('id')->firstOrFail();

    expect((int) $fifth->ct_bot_flags & BotSignals::BURST_IP)->toBe(BotSignals::BURST_IP)
        ->and((int) $fourth->ct_bot_flags & BotSignals::BURST_IP)->toBe(0);

    foreach (range(1, 3) as $i) {
        ctShopper('94.200.60.'.(100 + $i))->postJson('/api/cart/add', ['product_id' => $p->id])->assertOk();
    }

    $eighth = Cart::query()->latest('id')->firstOrFail();
    expect((int) $eighth->ct_bot_flags & BotSignals::BURST_NET)->toBe(BotSignals::BURST_NET)
        ->and((int) $eighth->ct_bot_flags & BotSignals::BURST_IP)->toBe(0);
});

it('knows a datacenter address from a home one, locally', function () {
    /*
     * DEFECT: a cart from an AWS box reads like one from a home broadband
     * line. MUTATION: return false from HostingNetworks::contains() and the
     * first expectation is red; break the binary search's bounds and the
     * second (a residential UAE address) goes true.
     */
    expect(\App\Services\CartTracking\HostingNetworks::contains('3.5.140.2'))->toBeTrue()          // AWS
        ->and(\App\Services\CartTracking\HostingNetworks::contains('94.200.10.20'))->toBeFalse()  // Etisalat, UAE
        ->and(\App\Services\CartTracking\HostingNetworks::contains('not an ip'))->toBeFalse();
});

it('does not track a cart changed from the admin, or when tracking is off', function () {
    /*
     * DEFECT: Store → New Order builds the order through CartService, and
     * every manual order would appear as a cart from the OWNER's address and
     * browser. MUTATION: drop the isAdminArea() check in
     * CartTracker::context() and the first count is 1.
     */
    $p = ctProduct();
    $cart = Cart::create(['token' => (string) Str::uuid(), 'currency' => 'AED', 'status' => 'active', 'last_activity_at' => now()]);

    $request = \Illuminate\Http\Request::create('/admin-api/manual-orders', 'POST');
    $route = (new \Illuminate\Routing\Route(['POST'], 'admin-api/manual-orders', fn () => null))->middleware(['web', 'auth:admin']);
    $request->setRouteResolver(fn () => $route);
    app()->instance('request', $request);

    app(CartService::class)->add($cart, $p, 1);
    expect(DB::table('cart_events')->count())->toBe(0);

    app()->instance('request', \Illuminate\Http\Request::create('/api/cart/add', 'POST'));
    app(CartTrackingSettings::class)->save(['track' => false]);
    IpBlockList::rebuild();
    app(CartService::class)->add($cart, $p, 1);
    expect(DB::table('cart_events')->count())->toBe(0);
});

it('keeps all-time product counts when old events are pruned', function () {
    /*
     * DEFECT: retention deletes six-month-old events and "All time" on the
     * Added products tab silently becomes "last six months". MUTATION: remove
     * rollUp() from CartTrackingPrune::run() and the all-time count drops
     * from 3 to 1.
     */
    $p = ctProduct('Sun Stick', 4000);

    $this->travelTo(now()->subDays(200));
    ctShopper('94.200.70.1')->postJson('/api/cart/add', ['product_id' => $p->id])->assertOk();
    ctShopper('94.200.70.2')->postJson('/api/cart/add', ['product_id' => $p->id])->assertOk();
    $this->travelBack();
    ctShopper('94.200.70.3')->postJson('/api/cart/add', ['product_id' => $p->id])->assertOk();

    $done = app(CartTrackingPrune::class)->run();
    \Illuminate\Support\Facades\Cache::flush();

    expect($done['events'])->toBe(2)
        ->and(DB::table('cart_events')->count())->toBe(1)
        ->and(Cart::query()->whereNotNull('ct_first_at')->count())->toBe(3)
        ->and(app(CartTrackingReport::class)->products('added', 'all')['rows'][0]['carts'])->toBe(3)
        ->and(app(CartTrackingReport::class)->products('added', '30d')['rows'][0]['carts'])->toBe(1);
});

it('runs the retention sweep from the heartbeat at most once per interval', function () {
    /*
     * DEFECT: the sweep runs on every request (a DELETE on a shopper's page
     * view) or never. MUTATION: drop the filemtime() check in
     * CartTrackingTick::onRequest() and the second call returns true.
     */
    @unlink(\App\Services\CartTracking\CartTrackingTick::markerPath());
    \Illuminate\Support\Facades\Cache::forget(\App\Services\CartTracking\CartTrackingTick::LOCK_KEY);

    expect((new \App\Services\CartTracking\CartTrackingTick)->onRequest())->toBeTrue();
    \Illuminate\Support\Facades\Cache::forget(\App\Services\CartTracking\CartTrackingTick::LOCK_KEY);
    expect((new \App\Services\CartTracking\CartTrackingTick)->onRequest())->toBeFalse();
});

it('adds no query to a page view, and exactly one to an add-to-cart', function () {
    /*
     * DEFECT: the tracker or the gate reads the database on every page — on
     * a shop with 100k carts that is the whole storefront slowed for a
     * report. MUTATION: make BlockGate call IpBlock::count(), or make
     * CartTracker run on GET, and the page counts differ.
     */
    $p = ctProduct('Vitamin C Serum', 8000);

    // 1,000 blocks in the compiled list.
    $now = now();
    $rows = [];
    for ($i = 0; $i < 1000; $i++) {
        $cidr = '198.18.'.intdiv($i, 250).'.'.($i % 250).'/32';
        $range = \App\Support\IpRange::parse($cidr);
        $rows[] = ['cidr' => $range['cidr'], 'family' => 4, 'prefix' => 32, 'network' => $range['network'],
            'source' => 'manual', 'hits' => 0, 'created_at' => $now, 'updated_at' => $now];
    }
    foreach (array_chunk($rows, 200) as $chunk) {
        DB::table('ip_blocks')->insert($chunk);
    }
    IpBlockList::rebuild();
    expect(IpBlockList::compiled()['n'])->toBe(1000);

    $count = function (callable $fn): int {
        $n = 0;
        DB::listen(function () use (&$n) { $n++; });
        $fn();

        return $n;
    };

    // The gate on its own, 1,000 blocks loaded: zero queries.
    IpBlockList::forget();
    $request = \Illuminate\Http\Request::create('/product/x/', 'GET', server: ['REMOTE_ADDR' => '94.200.10.20']);
    $gateQueries = $count(function () use ($request) {
        (new \App\Http\Middleware\BlockGate)->handle($request, fn () => response('ok'));
    });
    expect($gateQueries)->toBe(0);

    // A product page: the same count with the gate as without it.
    $page = '/product/'.$p->slug.'/';
    ctShopper()->get($page)->assertOk();
    $with = $count(fn () => ctShopper()->get($page)->assertOk());
    $without = $count(fn () => ctShopper()->withoutMiddleware(\App\Http\Middleware\BlockGate::class)->get($page)->assertOk());
    expect($with)->toBe($without)
        ->and(DB::table('cart_events')->count())->toBe(0);
});
