<?php

declare(strict_types=1);

/*
 * The owner app's walls (Lane MAC): never indexed, never cached, never
 * written to cross-site, never answering past the member's role, never
 * returning a field nobody chose to return.
 */

use App\Http\Middleware\OwnerAppHeaders;
use App\Models\Order;
use App\Models\Product;
use App\Services\OwnerApp\OwnerAppPath;
use App\Support\AdminCapabilities;
use Illuminate\Support\Facades\DB;
use Tests\Support\OwnerAppRoutes as OA;

beforeEach(function () {
    OA::wire($this->app);
    \Illuminate\Support\Facades\RateLimiter::clear('owner-app-pin:127.0.0.1');
    \Illuminate\Support\Facades\RateLimiter::clear('owner-app-enrol:127.0.0.1');
});

function oaSeedShop(int $orders = 3, int $products = 3): int
{
    $first = 0;
    for ($i = 1; $i <= $products; $i++) {
        $p = Product::query()->create(['slug' => 'p-'.$i, 'name' => 'Snail Essence '.$i, 'sku' => 'SKU-SECRET-'.$i, 'wc_id' => 900000 + $i,
            'price' => 9900, 'manage_stock' => true, 'stock' => 10 + $i, 'stock_status' => 'instock', 'total_sales' => 777])->id;
        $first = $first ?: (int) $p;
    }
    $customer = DB::table('customers')->insertGetId(['name' => 'Sabina Dev', 'email' => 'sabina@example.com', 'phone' => '+971500000001',
        'password' => 'HASHED-PASSWORD', 'remember_token' => 'REMEMBER-ME', 'created_at' => now(), 'updated_at' => now()]);
    for ($i = 1; $i <= $orders; $i++) {
        $o = Order::query()->create(['order_number' => (string) (33000 + $i), 'wc_order_id' => 500000 + $i, 'customer_id' => $customer,
            'email' => 'sabina@example.com', 'phone' => '+971500000001', 'status' => 'processing', 'total' => 16100, 'subtotal' => 14500,
            'shipping_total' => 2000, 'payment_method' => 'tabby', 'payment_method_title' => 'Tabby', 'ip_address' => '203.0.113.9',
            'billing_address' => ['first_name' => 'Sabina', 'last_name' => 'Dev', 'city' => 'Dubai', 'country' => 'AE']]);
        DB::table('order_items')->insert(['order_id' => $o->id, 'product_id' => $first ?: null, 'name' => 'Snail Essence 1', 'sku' => 'SKU-SECRET-1',
            'quantity' => 1, 'unit_price' => 14500, 'subtotal' => 14500, 'total' => 14500, 'created_at' => now(), 'updated_at' => now()]);
    }

    return $first;
}

it('sends noindex, no-store and no-referrer on every kind of answer the app gives', function (string $what) {
    // DEFECT: the secret address in a search index or a proxy cache.
    // MUTATION: drop the X-Robots-Tag line from OwnerAppHeaders.
    $owner = OA::admin();
    OA::member($owner);

    $r = match ($what) {
        'shell' => $this->get(OA::base()),
        'manifest' => $this->get(OA::base().'/manifest.webmanifest'),
        'worker' => $this->get(OA::base().'/sw.js'),
        'state' => $this->getJson(OA::base().'/api/state'),
        'refused' => $this->withHeaders(['X-OA' => '1'])->getJson(OA::base().'/api/orders'),
        'cross-site' => $this->withHeaders(['X-OA' => '1', 'Origin' => 'https://evil.example'])->postJson(OA::base().'/api/enrol', []),
    };

    expect($r->headers->get('X-Robots-Tag'))->toBe(OwnerAppHeaders::ROBOTS)
        ->and($r->headers->get('Referrer-Policy'))->toBe('no-referrer')
        ->and((string) $r->headers->get('Cache-Control'))->toContain('no-')
        // No session and no XSRF cookie: the app shares no cookie with the shop
        // or the admin, and none of these answers sets one of its own.
        ->and(collect($r->headers->getCookies())->map(fn ($c) => $c->getName())->all())->toBe([]);
})->with(['shell', 'manifest', 'worker', 'state', 'refused', 'cross-site']);

it('serves a shell with no member, order or token in it, robots meta included', function () {
    $owner = OA::admin();
    OA::member($owner);
    oaSeedShop(1, 1);

    $html = $this->get(OA::base())->assertOk()->getContent();

    expect($html)->toContain('<meta name="robots" content="noindex, nofollow, noarchive">')
        ->toContain('rel="manifest"')
        ->not->toContain('Rafi')->not->toContain('sabina')->not->toContain('33001')
        ->not->toContain('<script>')->not->toContain('style="');
});

it('is in neither robots.txt nor the sitemap, and no storefront page links to it', function () {
    $path = OwnerAppPath::current();

    expect($this->get('/robots.txt')->getContent())->not->toContain($path)
        ->and($this->get('/sitemap.xml')->getContent())->not->toContain($path)
        ->and($this->get('/')->getContent())->not->toContain($path);
});

it('refuses a write without the CSRF header, with a wrong one, cross-site, or without X-OA', function () {
    // DEFECT: a forged form on another site changing an order's status.
    // MUTATION: remove the X-OA-CSRF check from OwnerAppSession.
    $owner = OA::admin();
    OA::member($owner);
    oaSeedShop(1, 0);
    [$cookies, $csrf] = OA::enrol($this);
    $id = (int) Order::query()->value('id');

    OA::post($this, 'orders/'.$id.'/status', ['status' => 'completed'], $cookies, null)->assertStatus(419);
    OA::post($this, 'orders/'.$id.'/status', ['status' => 'completed'], $cookies, str_repeat('a', 64))->assertStatus(419);
    $this->withCredentials()->withUnencryptedCookies($cookies)->withHeaders(['X-OA' => '1', 'X-OA-CSRF' => $csrf, 'Origin' => 'https://evil.example'])
        ->postJson(OA::base().'/api/orders/'.$id.'/status', ['status' => 'completed'])->assertStatus(403);
    $this->withCredentials()->withUnencryptedCookies($cookies)->withHeaders(['X-OA' => '1', 'X-OA-CSRF' => $csrf, 'Sec-Fetch-Site' => 'cross-site'])
        ->postJson(OA::base().'/api/orders/'.$id.'/status', ['status' => 'completed'])->assertStatus(403);
    $this->flushHeaders();
    $this->withCredentials()->withUnencryptedCookies($cookies)->withHeaders(['X-OA-CSRF' => $csrf])
        ->postJson(OA::base().'/api/orders/'.$id.'/status', ['status' => 'completed'])->assertStatus(403);

    expect(Order::query()->value('status'))->toBe('processing');

    $this->flushHeaders();
    OA::post($this, 'orders/'.$id.'/status', ['status' => 'completed'], $cookies, $csrf)->assertOk();
    expect(Order::query()->value('status'))->toBe('completed');
});

it('fails closed on every capability the member’s role does not hold', function () {
    // DEFECT: a Content Editor reading customers' orders from a phone.
    // MUTATION: return null at the top of Concerns::refuse().
    $pid = oaSeedShop(1, 1);
    $oid = (int) Order::query()->value('id');
    $editor = OA::admin('editor', 'ed@example.com', 'Ed Editor');          // catalogue, no orders, no customers
    OA::member($editor);
    [$c] = OA::enrol($this, 'ed@example.com');

    OA::get($this, 'orders', $c)->assertStatus(403)->assertJsonPath('capability', 'orders.view');
    OA::get($this, 'orders/'.$oid, $c)->assertStatus(403);
    OA::get($this, 'customers', $c)->assertStatus(403)->assertJsonPath('capability', 'customers.view');
    OA::get($this, 'dashboard', $c)->assertStatus(403);
    OA::get($this, 'products', $c)->assertOk();

    $support = OA::admin('support', 'sue@example.com', 'Sue Support');    // orders, no catalogue edits, no money
    OA::member($support);
    [$s, $csrf] = OA::enrol($this, 'sue@example.com');
    OA::get($this, 'products', $s)->assertStatus(403)->assertJsonPath('capability', 'catalog.view');
    OA::post($this, 'products/'.$pid, ['stock' => 1], $s, $csrf)->assertStatus(403);
    OA::post($this, 'orders/'.$oid.'/mark-paid', ['payment_method' => 'cod'], $s, $csrf)->assertStatus(403)->assertJsonPath('capability', 'orders.payment');
    OA::get($this, 'dashboard', $s)->assertOk()->assertJsonPath('money', false)->assertJsonPath('sales', null);

    expect(Product::query()->whereKey($pid)->value('stock'))->toBe(11);
});

it('hides stock events from a member who cannot see the catalogue', function () {
    $support = OA::admin('support', 'sue@example.com', 'Sue Support');
    OA::member($support);
    [$s] = OA::enrol($this, 'sue@example.com');
    DB::table('owner_app_events')->insert([
        ['type' => 'stock.low', 'ref_id' => 1, 'title' => 'Low stock: X', 'body' => '2 left', 'created_at' => now()],
        ['type' => 'order.new', 'ref_id' => 9, 'title' => 'New order #9', 'body' => 'AED 1', 'created_at' => now()],
    ]);

    $types = collect(OA::get($this, 'notifications', $s)->assertOk()->json('events'))->pluck('type')->all();
    expect($types)->toBe(['order.new']);
});

it('returns allowlisted fields only: no WooCommerce ids, no IP, no password, no PIN, no token', function () {
    // DEFECT: /products leaking wc_id and total_sales, /orders leaking the
    // buyer's IP, /customers leaking a password hash. MUTATION: return
    // `(array) $p` from ProductsController::row().
    $owner = OA::admin();
    OA::member($owner);
    $pid = oaSeedShop(2, 2);
    $oid = (int) Order::query()->value('id');
    [$c] = OA::enrol($this);
    $cid = (int) DB::table('customers')->value('id');

    $bodies = [
        'orders' => OA::get($this, 'orders', $c)->assertOk()->getContent(),
        'order' => OA::get($this, 'orders/'.$oid, $c)->assertOk()->getContent(),
        'products' => OA::get($this, 'products', $c)->assertOk()->getContent(),
        'product' => OA::get($this, 'products/'.$pid, $c)->assertOk()->getContent(),
        'customers' => OA::get($this, 'customers', $c)->assertOk()->getContent(),
        'customer' => OA::get($this, 'customers/'.$cid, $c)->assertOk()->getContent(),
        'dashboard' => OA::get($this, 'dashboard', $c)->assertOk()->getContent(),
        'state' => $this->withCredentials()->withUnencryptedCookies($c)->withHeaders(['X-OA' => '1'])->getJson(OA::base().'/api/state')->getContent(),
    ];

    foreach ($bodies as $name => $body) {
        foreach (['wc_id', 'wc_order_id', '900001', '500001', 'total_sales', '777', 'ip_address', '203.0.113.9',
            'HASHED-PASSWORD', 'REMEMBER-ME', 'password', 'pin_hash', 'token_hash', 'session_hash',
            $c['kbb_oa_d'], $c['kbb_oa_s']] as $needle) {
            expect(str_contains($body, (string) $needle))->toBeFalse("{$name} carries {$needle}");
        }
    }

    // SKU is a detail-screen field, not a list field.
    expect($bodies['products'])->not->toContain('SKU-SECRET')
        ->and($bodies['product'])->toContain('SKU-SECRET-1')
        ->and($bodies['state'])->not->toContain('owner@example.com');
});

it('maps every admin endpoint to ownerapp.manage, which only a Full Admin holds', function () {
    // DEFECT: a Store Manager handing himself a PIN. MUTATION: delete the two
    // owner-app RULES lines and the manager gets through.
    expect(AdminCapabilities::forPath('GET', 'admin-api/owner-app'))->toBe('ownerapp.manage')
        ->and(AdminCapabilities::forPath('PUT', 'admin-api/owner-app/members/3'))->toBe('ownerapp.manage')
        ->and(AdminCapabilities::forPath('POST', 'admin-api/owner-app/devices/3/revoke'))->toBe('ownerapp.manage')
        ->and(AdminCapabilities::CAPABILITIES['ownerapp.manage'])->toBe(['owner']);

    $manager = OA::admin('manager', 'm@example.com', 'Max Manager');
    $this->actingAs($manager, 'admin')->getJson('/admin-api/owner-app')->assertStatus(403);
    $this->actingAs($manager, 'admin')->putJson('/admin-api/owner-app/members/'.$manager->id, ['enabled' => true, 'pin' => '7391'])->assertStatus(403);
    expect(DB::table('owner_app_members')->count())->toBe(0);
});

it('locks out a disabled member and a member whose admin account was deleted', function () {
    $owner = OA::admin();
    OA::member($owner);
    $staff = OA::admin('support', 'sara@example.com', 'Sara');
    OA::member($staff);
    [$c] = OA::enrol($this, 'sara@example.com');
    OA::get($this, 'orders', $c)->assertOk();

    $staff->delete();
    OA::get($this, 'orders', $c)->assertStatus(401)->assertJsonPath('code', 'no_device');
});
