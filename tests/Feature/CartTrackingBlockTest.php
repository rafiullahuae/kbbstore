<?php

declare(strict_types=1);

/**
 * Growth & Marketing → Cart Tracking: blocking, bots and who may do it.
 *                                                                 (Lane CT)
 *
 * The owner: "ability to block the full ip range with simple block / unblock
 * icon. this is very important bcz we received a lot of fake COD orders".
 * Each case names the defect on the shop and the mutation that reddens it.
 */

use App\Models\AdminUser;
use App\Models\Cart;
use App\Models\IpBlock;
use App\Models\Order;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\CartService;
use App\Services\CartTracking\CartTrackingSettings;
use App\Services\Security\IpBlockList;
use App\Support\AdminCapabilities;
use App\Support\IpRange;
use Illuminate\Support\Str;
use Tests\Support\CartTrackingRoutes;

const CTB_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36';

beforeEach(function () {
    CartTrackingRoutes::wire($this->app);
});

function ctbProduct(): Product
{
    return Product::create([
        'slug' => 'ctb-'.uniqid(), 'name' => 'Toner Pad', 'status' => 'publish',
        'is_visible' => true, 'price' => 4900, 'stock_status' => 'instock',
    ]);
}

function ctbAdmin(string $role = 'owner'): AdminUser
{
    return AdminUser::create([
        'name' => ucfirst($role).' Person', 'email' => $role.'-'.uniqid().'@example.test',
        'password' => 'password-long-enough', 'role' => $role,
    ]);
}

/** A visitor: their address, a real browser, a fresh client. */
function ctbVisitor(string $ip, string $ua = CTB_UA, ?string $cartToken = null)
{
    app()->forgetScopedInstances();

    foreach (app('router')->getRoutes()->getRoutes() as $route) {
        if (str_contains($route->uri(), 'cart') || str_contains($route->uri(), 'checkout')) {
            $route->controller = null;
        }
    }

    test()->flushHeaders();

    return test()->withCredentials()
        ->withServerVariables(['REMOTE_ADDR' => $ip])
        ->withHeaders(['User-Agent' => $ua, 'X-KBB-Hm' => '5000'])
        ->withoutMiddleware(\Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cartToken ?? 'fresh-'.uniqid());
}

function ctbCod(): void
{
    $zone = ShippingZone::create(['name' => 'UAE', 'position' => 0]);
    ShippingZoneLocation::create(['shipping_zone_id' => $zone->id, 'type' => 'country', 'code' => 'AE']);
    ShippingMethod::create(['shipping_zone_id' => $zone->id, 'type' => 'flat_rate', 'title' => 'Standard',
        'cost' => 2000, 'enabled' => true, 'position' => 0]);
    PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0]);
    app(\App\Services\SettingsService::class)->set('cod_fee', 0);
}

function ctbCart(Product $p): Cart
{
    $cart = Cart::create(['token' => (string) Str::uuid(), 'currency' => 'AED', 'status' => 'active',
        'shipping_country' => 'AE', 'last_activity_at' => now()]);
    $cart->items()->create(['product_id' => $p->id, 'quantity' => 1, 'unit_price' => 4900]);

    return $cart;
}

function ctbForm(): array
{
    return ['billing_email' => 'fake@example.com', 'billing_phone' => '+971500000001',
        'billing_first_name' => 'Fake', 'billing_last_name' => 'Order', 'billing_address_1' => '1 Street',
        'billing_city' => 'Dubai', 'billing_state' => 'Dubai', 'billing_country' => 'AE', 'payment_method' => 'cod'];
}

function ctbBlock(string $target): array
{
    return app(IpBlockList::class)->block($target, ['admin_ip' => '94.200.200.200', 'reason' => 'test']);
}

it('stops a blocked address adding to cart or placing a COD order, and unblocking restores both', function () {
    /*
     * DEFECT: the owner blocks the address that sent a fake COD order and the
     * same address places another one. MUTATION: make BlockGate::handle()
     * always call $next (or drop the isCommerce() arm of refusesBlocked())
     * and the 403s below become 200 / a redirect, and an order is written.
     */
    ctbCod();
    $p = ctbProduct();

    $r = ctbBlock('203.0.113.7');
    expect($r['ok'])->toBeTrue();

    ctbVisitor('203.0.113.7')->postJson('/api/cart/add', ['product_id' => $p->id])
        ->assertStatus(403)
        ->assertJson(['ok' => false, 'blocked' => true])
        ->assertHeader('Cache-Control', 'no-store, private');

    $cart = ctbCart($p);
    ctbVisitor('203.0.113.7', cartToken: $cart->token)->post('/checkout/place', ctbForm())->assertStatus(403);
    ctbVisitor('203.0.113.7')->postJson('/api/checkout/session', [])->assertStatus(403);
    expect(Order::count())->toBe(0);

    // The neighbour on the same /24 is untouched by a single-address block.
    ctbVisitor('203.0.113.8')->postJson('/api/cart/add', ['product_id' => $p->id])->assertOk();

    app(IpBlockList::class)->unblock((int) $r['block']['id']);

    ctbVisitor('203.0.113.7')->postJson('/api/cart/add', ['product_id' => $p->id])->assertOk();
    ctbVisitor('203.0.113.7', cartToken: $cart->token)->post('/checkout/place', ctbForm())->assertRedirect();
    expect(Order::count())->toBe(1);
});

it('blocks a whole /24 from one address, and counts the refusals', function () {
    /*
     * DEFECT: "block the full ip range" blocks one address and the next fake
     * order arrives from .8. MUTATION: in CartTrackingApiController::block()
     * drop the rangeOf() for mode=range and .99 gets through.
     */
    $owner = ctbAdmin();
    $p = ctbProduct();

    test()->actingAs($owner, 'admin')->withServerVariables(['REMOTE_ADDR' => '94.200.200.200'])
        ->postJson('/admin-api/cart-tracking/blocks', ['target' => '203.0.113.7', 'mode' => 'range', 'reason' => 'Fake COD'])
        ->assertOk()->assertJsonPath('block.cidr', '203.0.113.0/24');

    app('auth')->forgetGuards();

    ctbVisitor('203.0.113.99')->postJson('/api/cart/add', ['product_id' => $p->id])->assertStatus(403);
    ctbVisitor('203.0.114.1')->postJson('/api/cart/add', ['product_id' => $p->id])->assertOk();

    $block = IpBlock::query()->firstOrFail();
    IpBlockList::flushHits((int) $block->id);
    expect((int) $block->fresh()->hits)->toBe(1)
        ->and($block->reason)->toBe('Fake COD');
});

it('blocks an IPv6 /64, and an IPv4 address arriving as IPv4-mapped IPv6', function () {
    /*
     * DEFECT: a mobile carrier hands each phone a new address inside one /64
     * every few minutes, so blocking the single IPv6 address blocks nothing.
     * MUTATION: set RANGE_PREFIX[6] to 128 in IpRange and the second address
     * of the /64 gets through.
     */
    $p = ctbProduct();

    expect(IpRange::rangeOf('2001:db8:aa:bb:1:2:3:4'))->toBe('2001:db8:aa:bb::/64');
    ctbBlock(IpRange::rangeOf('2001:db8:aa:bb:1:2:3:4'));

    ctbVisitor('2001:db8:aa:bb:ffff:ffff:ffff:fffe')->postJson('/api/cart/add', ['product_id' => $p->id])->assertStatus(403);
    ctbVisitor('2001:db8:aa:bc::1')->postJson('/api/cart/add', ['product_id' => $p->id])->assertOk();

    ctbBlock('198.51.100.0/24');
    expect(IpBlockList::match('::ffff:198.51.100.20'))->not->toBeNull()
        ->and(IpBlockList::match('198.51.101.20'))->toBeNull();
});

it('refuses to block the admin\'s own address, private networks, Cloudflare, or a whole provider', function () {
    /*
     * DEFECT: one click on the wrong row locks the owner out of his own shop,
     * or blocks the proxy every shopper arrives through. MUTATION: delete the
     * admin-IP arm of IpBlockList::refusal() and the first request returns 200
     * — and the owner's next page is a 403.
     */
    $owner = ctbAdmin();

    test()->actingAs($owner, 'admin')->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])
        ->postJson('/admin-api/cart-tracking/blocks', ['target' => '203.0.113.50', 'mode' => 'range'])
        ->assertStatus(422)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('message', 'That would block you: your own address (203.0.113.50) is inside 203.0.113.0/24. Refused, so you cannot lock yourself out of the shop.');

    foreach (['127.0.0.1', '10.20.30.40', '192.168.1.0/24', '104.16.5.5', '2606:4700::1111', '::1'] as $unblockable) {
        expect(ctbBlock($unblockable)['ok'])->toBeFalse("{$unblockable} was blockable");
    }

    expect(ctbBlock('203.0.0.0/8')['message'])->toContain('too wide')
        ->and(ctbBlock('not-an-ip')['ok'])->toBeFalse()
        ->and(IpBlock::count())->toBe(0);
});

it('recompiles the list on every change — no stale block, no stale unblock', function () {
    /*
     * DEFECT: an unblocked customer stays blocked until somebody clears a
     * cache, or a fresh block takes an hour to bite. MUTATION: remove the
     * rebuild() from IpBlockList::unblock() and the last match is still a hit.
     */
    $path = IpBlockList::path();

    $r = ctbBlock('198.51.100.9');
    IpBlockList::forget();
    expect(IpBlockList::match('198.51.100.9'))->not->toBeNull()
        ->and((string) file_get_contents($path))->toContain(IpRange::parse('198.51.100.9')['network']);

    app(IpBlockList::class)->unblock((int) $r['block']['id']);
    IpBlockList::forget();
    expect(IpBlockList::match('198.51.100.9'))->toBeNull();

    // A settings write recompiles too, whoever makes it.
    app(CartTrackingSettings::class)->save(['block_scope' => 'site']);
    IpBlockList::forget();
    expect(IpBlockList::settings()['block_scope'])->toBe('site');

    // And a block that has expired stops matching without anyone touching it.
    $b = ctbBlock('198.51.100.77');
    IpBlock::query()->whereKey($b['block']['id'])->update(['expires_at' => now()->addSecond()]);
    IpBlockList::rebuild();
    expect(IpBlockList::match('198.51.100.77'))->not->toBeNull();
    $this->travel(5)->seconds();
    expect(IpBlockList::match('198.51.100.77'))->toBeNull();
});

it('lets a blocked address read the shop unless the scope is the whole storefront, and never blocks the admin', function () {
    /*
     * DEFECT: a /24 on a mobile carrier blocks every page for real customers
     * who share it — or the owner, browsing from that carrier, cannot reach
     * the admin to undo it. MUTATION: make refusesBlocked() return true for
     * every route and the product page 403s under 'commerce'; drop
     * isAdminArea() and the admin login 403s.
     */
    $p = ctbProduct();
    ctbBlock('203.0.113.0/24');

    ctbVisitor('203.0.113.10')->get('/product/'.$p->slug.'/')->assertOk();
    ctbVisitor('203.0.113.10')->get('/cart')->assertStatus(403)->assertSee('Reference: B', false);
    ctbVisitor('203.0.113.10')->post('/newsletter/confirm', ['email' => 'x@example.com'])->assertStatus(403);
    ctbVisitor('203.0.113.10')->get(route('admin.login'))->assertOk();

    $owner = ctbAdmin();
    test()->actingAs($owner, 'admin')->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
        ->getJson('/admin-api/cart-tracking/blocks')->assertOk();
    app('auth')->forgetGuards();

    app(CartTrackingSettings::class)->save(['block_scope' => 'site']);

    ctbVisitor('203.0.113.10')->get('/product/'.$p->slug.'/')
        ->assertStatus(403)
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertDontSee('Back to the shop');
    ctbVisitor('203.0.113.10')->get(route('admin.login'))->assertOk();
    ctbVisitor('198.18.0.1')->get('/product/'.$p->slug.'/')->assertOk();
});

it('asks scripted clients to leave the cart and checkout, and never refuses a search engine', function () {
    /*
     * DEFECT: curl fills carts and posts COD orders all day — or, the other
     * way, Googlebot is refused and the shop drops out of search. MUTATION:
     * remove the GOOD_CRAWLERS check from BotSignals::leaveReason() and the
     * Googlebot /cart request goes 403; remove the bots_leave arm of
     * BlockGate and curl's add is 200.
     */
    $p = ctbProduct();

    ctbVisitor('94.200.9.9', 'curl/8.4.0')->postJson('/api/cart/add', ['product_id' => $p->id])->assertStatus(403);
    ctbVisitor('94.200.9.9', 'python-requests/2.31.0')->get('/checkout')->assertStatus(403);
    ctbVisitor('94.200.9.9', '')->postJson('/api/cart/add', ['product_id' => $p->id])->assertStatus(403);
    ctbVisitor('94.200.9.9', 'Mozilla/5.0 (compatible; AhrefsBot/7.0; +http://ahrefs.com/robot/)')->get('/cart')->assertStatus(403);

    // Reading the shop is untouched.
    ctbVisitor('94.200.9.9', 'curl/8.4.0')->get('/product/'.$p->slug.'/')->assertOk();
    ctbVisitor('66.249.66.1', 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)')->get('/cart')->assertOk();
    ctbVisitor('94.200.9.9', CTB_UA)->postJson('/api/cart/add', ['product_id' => $p->id])->assertOk();

    app(CartTrackingSettings::class)->save(['bots_leave' => false]);
    ctbVisitor('94.200.9.9', 'curl/8.4.0')->postJson('/api/cart/add', ['product_id' => $p->id])->assertOk();
});

it('never refuses a payment webhook from a blocked range', function () {
    /*
     * DEFECT: a gateway's server shares a range with a blocked bot farm and
     * every "paid" notification is refused, so paid orders stay unpaid.
     * MUTATION: drop isServerToServer() from refusesBlocked().
     */
    ctbBlock('203.0.113.0/24');

    $r = ctbVisitor('203.0.113.200')->postJson('/payments/tabby/webhooks', []);
    expect($r->status())->not->toBe(403)
        ->and((string) $r->getContent())->not->toContain('"blocked":true');
});

it('gives support and editor a 403 on every Cart Tracking endpoint, and owner and manager their answers', function () {
    /*
     * DEFECT: a support account reads every shopper's IP address, or blocks
     * one. MUTATION: delete the three cart-tracking rows from
     * AdminCapabilities::RULES — the routes fall to "owner only" and the
     * manager is refused — or map them to admin.access and support gets in.
     */
    $routes = CartTrackingRoutes::registered();
    expect($routes)->toHaveCount(10);

    foreach ($routes as $route) {
        $method = array_values(array_diff($route->methods(), ['HEAD', 'OPTIONS']))[0];
        $cap = AdminCapabilities::forPath($method, $route->uri());
        expect($cap)->toBe($method === 'GET' ? 'carttracking.view' : 'carttracking.block', $route->uri());
    }

    expect(AdminCapabilities::CAPABILITIES['carttracking.view'])->toBe(['owner', 'manager'])
        ->and(AdminCapabilities::CAPABILITIES['carttracking.block'])->toBe(['owner', 'manager']);

    foreach (['support', 'editor'] as $role) {
        $admin = ctbAdmin($role);

        foreach ($routes as $route) {
            $method = array_values(array_diff($route->methods(), ['HEAD', 'OPTIONS']))[0];
            $uri = '/'.str_replace('{id}', '1', $route->uri());
            $r = test()->actingAs($admin, 'admin')->json($method, $uri, []);
            expect($r->status())->toBe(403, "{$role} {$method} {$uri}");
        }
    }

    foreach (['owner', 'manager'] as $role) {
        $admin = ctbAdmin($role);

        foreach (['/admin-api/cart-tracking', '/admin-api/cart-tracking/blocks', '/admin-api/cart-tracking/settings',
            '/admin-api/cart-tracking/products?kind=added&period=7d'] as $uri) {
            test()->actingAs($admin, 'admin')->getJson($uri)->assertOk();
        }
    }
});

it('serves the Carts tab, one cart, bulk blocks, deletes and a CSV a spreadsheet cannot run', function () {
    /*
     * DEFECT: a user agent of "=HYPERLINK(...)" in the export runs as a
     * formula in Excel. MUTATION: drop CartTrackingApiController::cell() from
     * the export and the cell starts with "=".
     */
    $owner = ctbAdmin();
    $p = ctbProduct();

    ctbVisitor('94.200.30.1', '=HYPERLINK("http://evil.example","x") Mozilla/5.0')->postJson('/api/cart/add', ['product_id' => $p->id])->assertOk();
    ctbVisitor('94.200.30.2')->postJson('/api/cart/add', ['product_id' => $p->id])->assertOk();
    $ids = Cart::query()->orderBy('id')->pluck('id')->all();

    $admin = fn () => test()->actingAs($owner, 'admin')->withServerVariables(['REMOTE_ADDR' => '94.200.200.200']);

    $list = $admin()->getJson('/admin-api/cart-tracking?period=today&sort=value&dir=desc')->assertOk();
    expect($list->json('total'))->toBe(2)
        ->and($list->json('rows.0'))->not->toHaveKey('token')
        ->and($list->json('summary.carts'))->toBe(2);

    $one = $admin()->getJson('/admin-api/cart-tracking/carts/'.$ids[0])->assertOk();
    expect($one->json('timeline.0.type'))->toBe('add')
        ->and($one->json('timeline.0.product'))->toBe('Toner Pad')
        ->and($one->json('ip'))->toBe('94.200.30.1');

    $csv = $admin()->get('/admin-api/cart-tracking/export?period=today')->assertOk()->streamedContent();
    expect($csv)->toContain("'=HYPERLINK")->not->toContain(',=HYPERLINK');

    $admin()->postJson('/admin-api/cart-tracking/bulk', ['action' => 'block_range', 'ids' => $ids])
        ->assertOk()->assertJsonPath('blocked', 1);
    expect(IpBlock::query()->pluck('cidr')->all())->toBe(['94.200.30.0/24']);

    $admin()->postJson('/admin-api/cart-tracking/bulk', ['action' => 'delete', 'ids' => [$ids[1]]])
        ->assertOk()->assertJsonPath('deleted', 1);
    expect(Cart::query()->pluck('id')->all())->toBe([$ids[0]])
        ->and(\Illuminate\Support\Facades\DB::table('cart_events')->where('cart_id', $ids[1])->count())->toBe(0);

    $admin()->postJson('/admin-api/cart-tracking/settings', ['values' => ['keep_days' => 0, 'block_scope' => 'everything', 'bots_leave' => '0']])
        ->assertOk()
        ->assertJsonPath('values.keep_days', 30)
        ->assertJsonPath('values.block_scope', 'commerce')
        ->assertJsonPath('values.bots_leave', false);
});

it('is wired exactly once: the routes in web.php and the screen in the console', function () {
    /*
     * DEFECT: zero is the "built, never wired up" shape — the sidebar has no
     * Cart Tracking, or the screen opens on "not in the route table"; two
     * registers the sidebar row twice. Pins the FINISHED state, so it is red
     * in the lane's worktree until the integrator applies
     * docs/CT-ADMIN-APP-BLOCKS.md, and green from then on.
     */
    $web = (string) file_get_contents(base_path('routes/web.php'));
    $app = (string) file_get_contents(resource_path('views/admin/app.blade.php'));

    expect(substr_count($web, "require __DIR__.'/cart-tracking-admin.php';"))->toBe(1)
        ->and(substr_count($app, "@include('admin.partials.cart-tracking-screen')"))->toBe(1)
        ->and(substr_count($app, "{screen:'carttracking',label:'Cart Tracking',group:'Growth & Marketing'"))->toBe(1);
});
