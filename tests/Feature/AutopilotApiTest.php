<?php

declare(strict_types=1);

/*
 * The KBB Ads Autopilot read-only API and its admin screen (Lane AP-API).
 * Each case says what it would catch on the shop and how to make it go red
 * (MUTATION).
 */

use App\Http\Middleware\AutopilotApiAuth;
use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Refund;
use App\Models\Setting;
use App\Services\Autopilot\ApiTokens;
use App\Services\SettingsService;
use App\Support\AdminCapabilities;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\AutopilotRoutes;

beforeEach(function () {
    AutopilotRoutes::wire(app());
    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
    Cache::flush();
});

function apOwner(string $role = 'owner'): AdminUser
{
    return AdminUser::create(['name' => 'AP ' . $role, 'email' => 'ap-' . $role . '-' . uniqid() . '@example.test', 'password' => 'secret-secret', 'role' => $role]);
}

/** @param list<string> $scopes */
function apKey(array $scopes = ['orders', 'products']): string
{
    return app(ApiTokens::class)->create('Test Mac', $scopes, null)['token'];
}

function apGet(string $uri, ?string $key)
{
    $headers = $key === null ? [] : ['Authorization' => 'Bearer ' . $key];

    return test()->getJson($uri, $headers);
}

function apProduct(int $fils = 10000, array $attrs = []): Product
{
    static $n = 0;
    $n++;
    $brand = Brand::firstOrCreate(['slug' => 'ap-brand'], ['name' => 'AP Brand']);

    return Product::create(array_merge([
        'slug' => 'ap-product-' . $n, 'name' => 'AP Product ' . $n, 'brand_id' => $brand->id, 'status' => 'publish',
        'is_visible' => true, 'type' => 'simple', 'price' => $fils, 'stock_status' => 'instock', 'sku' => 'AP-SKU-' . $n,
    ], $attrs));
}

/** A planted customer whose every personal detail must never leave the API. */
const AP_PII = [
    'email' => 'leak.me@example.com', 'phone' => '+971 55 999 8877', 'first' => 'Zainab', 'last' => 'Qasimi',
    'street' => '17 Secret Street', 'ip' => '198.51.100.77', 'note' => 'Leave with the guard', 'txn' => 'pi_SECRETTXN123',
];

function apOrder(array $attrs = [], int $lines = 2): Order
{
    static $n = 0;
    $n++;
    $p = apProduct(5000);
    $order = Order::create(array_merge([
        'order_number' => 'AP' . str_pad((string) $n, 6, '0', STR_PAD_LEFT), 'email' => AP_PII['email'], 'phone' => AP_PII['phone'],
        'ip_address' => AP_PII['ip'], 'customer_note' => AP_PII['note'], 'transaction_id' => AP_PII['txn'],
        'status' => 'processing', 'currency' => 'AED', 'subtotal' => 10000 * $lines, 'discount_total' => 1000, 'shipping_total' => 1500,
        'fee_total' => 0, 'tax_total' => 500, 'total' => 10000 * $lines + 1000, 'payment_method' => 'cod',
        'billing_address' => ['first_name' => AP_PII['first'], 'last_name' => AP_PII['last'], 'address_1' => AP_PII['street'], 'city' => 'Dubai', 'country' => 'AE', 'phone' => AP_PII['phone']],
        'shipping_address' => ['first_name' => AP_PII['first'], 'last_name' => AP_PII['last'], 'address_1' => AP_PII['street'], 'city' => 'Dubai', 'country' => 'AE'],
        'src_channel' => 'paid_social', 'src_campaign' => 'eid_sale',
        'src_attr' => json_encode(['first' => ['ch' => 'paid_social', 's' => 'facebook', 'm' => 'paid', 'c' => 'eid_sale', 'k' => 'f', 'p' => '/?secret=1', 'd' => 20000],
            'last' => ['ch' => 'paid_social', 's' => 'instagram', 'm' => 'paid', 'c' => 'eid_sale', 'k' => 'f', 'p' => '/', 'd' => 20001], 'days' => 1]),
    ], $attrs));

    for ($i = 0; $i < $lines; $i++) {
        OrderItem::create(['order_id' => $order->id, 'product_id' => $p->id, 'name' => $p->name, 'sku' => $p->sku, 'quantity' => 2,
            'unit_price' => 5000, 'subtotal' => 10000, 'total' => 10000]);
    }

    return $order;
}

/** Queries a request made, without the door's own bookkeeping (key lookup, log row). */
function apQueries(callable $fn): array
{
    // Orders made by the test queue owner-app pushes that send on the next
    // terminate(); flush them first so the count is the endpoint's alone.
    app()->terminate();
    DB::enableQueryLog();
    DB::flushQueryLog();
    $res = $fn();
    $all = DB::getQueryLog();
    DB::disableQueryLog();

    $kept = array_values(array_filter($all, fn ($q) => ! str_contains($q['query'], 'autopilot_')));

    return [$res, count($kept)];
}

// ------------------------------------------------------------------ the door

it('refuses every call without a valid key with the same 401, and logs it without the key', function () {
    // MUTATION: return $next($request) when bearerToken() is empty, and the first line is 200.
    $revoked = apKey();
    app(ApiTokens::class)->revoke((int) DB::table('autopilot_tokens')->where('token_hash', ApiTokens::hash($revoked))->value('id'));

    $bodies = [];
    foreach ([null, 'not-a-key', 'kbbap_' . str_repeat('A', 40), $revoked] as $key) {
        $res = apGet('/autopilot-api/v1/orders', $key)->assertStatus(401)->assertHeader('WWW-Authenticate', 'Bearer');
        expect($res->headers->get('Cache-Control'))->toContain('no-store')
            ->and($res->headers->get('Set-Cookie'))->toBeNull();
        $bodies[] = $res->getContent();
    }

    // An unknown key and a revoked key cannot be told apart.
    expect(array_unique($bodies))->toHaveCount(1);
    $log = DB::table('autopilot_api_log')->get();
    expect($log)->toHaveCount(4)
        ->and($log->pluck('status')->unique()->all())->toBe([401])
        ->and($log->pluck('token_id')->filter()->all())->toBe([])
        ->and(json_encode($log))->not->toContain($revoked);
});

it('answers 403 for a key without the route\'s scope, and lets each scope read only its own', function () {
    // MUTATION: drop the in_array scope check in AutopilotApiAuth and /orders answers 200 to a products key.
    $products = apKey(['products']);
    $orders = apKey(['orders']);

    apGet('/autopilot-api/v1/orders', $products)->assertStatus(403)->assertJson(['error' => 'forbidden']);
    apGet('/autopilot-api/v1/orders/daily', $products)->assertStatus(403);
    apGet('/autopilot-api/v1/refunds', $products)->assertStatus(403);
    apGet('/autopilot-api/v1/products', $orders)->assertStatus(403);
    apGet('/autopilot-api/v1/products', $products)->assertOk();
    apGet('/autopilot-api/v1/orders', $orders)->assertOk();
    apGet('/autopilot-api/v1/ping', $orders)->assertOk()->assertJson(['ok' => true, 'currency' => 'AED']);
    expect(DB::table('autopilot_tokens')->whereNotNull('last_used_at')->count())->toBe(2);
});

it('rate-limits guessing per address and calls per key', function () {
    // MUTATION: remove the fail-limiter check and the 21st call is a 401, not a 429.
    $key = apKey();
    for ($i = 0; $i < AutopilotApiAuth::FAILS_PER_MINUTE; $i++) {
        apGet('/autopilot-api/v1/ping', 'kbbap_' . str_repeat('B', 40))->assertStatus(401);
    }
    // Even the right key waits once an address has been guessing.
    apGet('/autopilot-api/v1/ping', $key)->assertStatus(429);

    RateLimiter::clear('autopilot-api-fail:' . sha1('127.0.0.1'));
    $id = (int) DB::table('autopilot_tokens')->where('token_hash', ApiTokens::hash($key))->value('id');
    for ($i = 0; $i < AutopilotApiAuth::CALLS_PER_MINUTE; $i++) {
        RateLimiter::hit('autopilot-api-calls:' . $id, 60);
    }
    apGet('/autopilot-api/v1/ping', $key)->assertStatus(429)->assertJson(['error' => 'rate_limited']);
});

it('stores only the hash of a key, shows the key once, and lets only the owner manage keys', function () {
    // MUTATION: map admin-api/ads-autopilot/** to marketing.view and the manager's POST is 201-ish, not 403.
    $owner = apOwner();
    $made = test()->actingAs($owner, 'admin')->postJson('/admin-api/ads-autopilot/keys', ['name' => 'Office <b>Mac</b>', 'scopes' => ['orders', 'products', 'admin']])->assertOk()->json();

    expect($made['token'])->toMatch('/^kbbap_[A-Za-z0-9]{40}$/');
    $row = DB::table('autopilot_tokens')->first();
    expect($row->token_hash)->toBe(hash('sha256', $made['token']))
        ->and($row->scopes)->toBe('orders,products')
        ->and(json_encode(DB::table('autopilot_tokens')->get()))->not->toContain($made['token']);

    // The next read of the screen carries the hint, never the key or its hash.
    $screen = test()->actingAs($owner, 'admin')->getJson('/admin-api/ads-autopilot')->assertOk()->getContent();
    expect($screen)->not->toContain($made['token'])->and($screen)->not->toContain($row->token_hash)
        ->and($screen)->toContain(substr($made['token'], -4));

    // The key works, then is revoked and does not.
    apGet('/autopilot-api/v1/ping', $made['token'])->assertOk();
    test()->actingAs($owner, 'admin')->postJson('/admin-api/ads-autopilot/keys/' . $row->id . '/revoke')->assertOk();
    apGet('/autopilot-api/v1/ping', $made['token'])->assertStatus(401);

    foreach (['manager', 'support', 'editor'] as $role) {
        test()->actingAs(apOwner($role), 'admin')->getJson('/admin-api/ads-autopilot')->assertForbidden();
        test()->actingAs(apOwner($role), 'admin')->postJson('/admin-api/ads-autopilot/keys', ['scopes' => ['orders']])->assertForbidden();
        test()->actingAs(apOwner($role), 'admin')->postJson('/admin-api/ads-autopilot/costs', ['rows' => [['id' => 1, 'cost' => '1']]])->assertForbidden();
    }

    expect(AdminCapabilities::forPath('POST', 'admin-api/ads-autopilot/keys/3/revoke'))->toBe('autopilot.manage')
        ->and(AdminCapabilities::forPath('GET', 'admin-api/ads-autopilot/costs'))->toBe('autopilot.view')
        ->and(AdminCapabilities::CAPABILITIES['autopilot.manage'])->toBe(['owner']);
});

// ------------------------------------------------------------------ the data

it('answers orders with net after refunds, catalog ids, the Meta click and no personal data', function () {
    // MUTATION: return (array) $r in ShopData::orders() and the PII sweep is red.
    $key = apKey();
    $first = apOrder();
    $second = apOrder(['payment_method' => 'tabby']);
    Refund::create(['order_id' => $second->id, 'amount' => 4000, 'status' => 'succeeded']);
    Refund::create(['order_id' => $second->id, 'amount' => 999, 'status' => 'pending']);
    apOrder(['status' => 'cancelled', 'email' => 'other@example.com']);

    $json = apGet('/autopilot-api/v1/orders', $key)->assertOk()->json();
    expect($json['count'])->toBe(3);

    $a = collect($json['orders'])->firstWhere('id', $first->id);
    $b = collect($json['orders'])->firstWhere('id', $second->id);
    $c = collect($json['orders'])->firstWhere('status', 'cancelled');
    $pid = (string) $first->items()->first()->product_id;

    expect($a['total'])->toEqual(210.0)->and($a['net'])->toEqual(210.0)->and($a['vat'])->toEqual(5.0)->and($a['shipping'])->toEqual(15.0)
        ->and($a['discount'])->toEqual(10.0)->and($a['payment_family'])->toBe('cod')->and($a['country'])->toBe('AE')
        ->and($a['new_customer'])->toBeTrue()->and($a['is_sale'])->toBeTrue()
        ->and($a['items'][0])->toEqual(['product_id' => $pid, 'catalog_id' => $pid, 'sku' => $first->items()->first()->sku, 'qty' => 2, 'unit_price' => 50.0, 'line_total' => 100.0])
        ->and($a['source'])->toMatchArray(['channel' => 'paid_social', 'utm_source' => 'instagram', 'utm_campaign' => 'eid_sale', 'click_id' => 'fbclid', 'fbclid' => true])
        ->and($a['placed_at'])->toEndWith('+04:00');
    // Only the succeeded refund is net; the pending one is still in flight.
    expect($b['refunded'])->toEqual(40.0)->and($b['net'])->toEqual(170.0)->and($b['refunded_at'])->not->toBeNull()
        ->and($b['payment_family'])->toBe('bnpl')->and($b['new_customer'])->toBeFalse();
    expect($c['net'])->toEqual(0.0)->and($c['is_sale'])->toBeFalse()->and($c['cancelled_at'])->not->toBeNull();

    $raw = json_encode($json);
    foreach (AP_PII as $field => $value) {
        expect(str_contains($raw, $value))->toBeFalse("/orders leaked the customer's {$field}");
    }
    expect(str_contains($raw, 'secret=1'))->toBeFalse('the landing path left');
    foreach (['email', 'phone', 'ip_address', 'billing_address', 'shipping_address', 'customer_id', 'transaction_id', 'customer_note'] as $col) {
        expect(str_contains($raw, '"' . $col . '"'))->toBeFalse("/orders carries a {$col} key");
    }
});

it('pages by cursor and keeps an orders page at the same query count for 3 and 40 orders', function () {
    // MUTATION: read $order->items per row (or the refunds per row) in ShopData::orders() and $q40 > $q3.
    $key = apKey();
    apGet('/autopilot-api/v1/ping', $key)->assertOk(); // last_used_at written once, outside the count
    foreach (range(1, 3) as $i) {
        Refund::create(['order_id' => apOrder()->id, 'amount' => 100, 'status' => 'succeeded']);
    }
    [$r3, $q3] = apQueries(fn () => apGet('/autopilot-api/v1/orders?limit=200', $key)->assertOk()->json());
    foreach (range(1, 37) as $i) {
        Refund::create(['order_id' => apOrder(['email' => "buyer{$i}@example.com"], 3)->id, 'amount' => 100, 'status' => 'succeeded']);
    }
    [$r40, $q40] = apQueries(fn () => apGet('/autopilot-api/v1/orders?limit=200', $key)->assertOk()->json());

    expect($r3['count'])->toBe(3)->and($r40['count'])->toBe(40)->and($q40)->toBe($q3);

    $p1 = apGet('/autopilot-api/v1/orders?limit=25', $key)->json();
    $p2 = apGet('/autopilot-api/v1/orders?limit=25&after=' . $p1['next_after'], $key)->json();
    expect($p1['count'])->toBe(25)->and($p2['count'])->toBe(15)->and($p2['next_after'])->toBeNull()
        ->and(array_intersect(array_column($p1['orders'], 'id'), array_column($p2['orders'], 'id')))->toBe([]);

    [, $qd] = apQueries(fn () => apGet('/autopilot-api/v1/orders/daily', $key)->assertOk());
    foreach (range(1, 10) as $i) {
        apOrder(['email' => "late{$i}@example.com"]);
    }
    [, $qd2] = apQueries(fn () => apGet('/autopilot-api/v1/orders/daily', $key)->assertOk());
    expect($qd2)->toBe($qd);
});

it('sums each shop day, every day present, with refunds and cancellations apart', function () {
    $key = apKey();
    $o = apOrder(['created_at' => now()->subDays(2)]);
    Refund::create(['order_id' => $o->id, 'amount' => 5000, 'status' => 'succeeded']);
    apOrder(['status' => 'cancelled', 'created_at' => now()->subDays(2), 'email' => 'c@example.com']);

    $from = now('Asia/Dubai')->subDays(6)->format('Y-m-d');
    $json = apGet('/autopilot-api/v1/orders/daily?from=' . $from, $key)->assertOk()->json();
    expect($json['days'])->toHaveCount(7);
    $day = collect($json['days'])->firstWhere('date', now('Asia/Dubai')->subDays(2)->format('Y-m-d'));
    expect($day['orders'])->toBe(1)->and($day['gross'])->toEqual(210.0)->and($day['net'])->toEqual(160.0)
        ->and($day['cancelled'])->toBe(1)->and($day['new_customers'])->toBe(1)->and($day['cod_orders'])->toBe(1);
    expect(collect($json['days'])->sum('refunds_issued'))->toEqual(50.0);

    apGet('/autopilot-api/v1/orders/daily?from=2026-02-30', $key)->assertStatus(422);
    apGet('/autopilot-api/v1/orders/daily?from=2020-01-01&to=2026-01-01', $key)->assertStatus(422);
});

it('lists refunds that moved money and cancelled orders, without personal data', function () {
    $key = apKey();
    $o = apOrder();
    Refund::create(['order_id' => $o->id, 'amount' => 2500, 'status' => 'succeeded', 'reason' => AP_PII['note']]);
    Refund::create(['order_id' => $o->id, 'amount' => 900, 'status' => 'failed']);
    apOrder(['status' => 'cancelled', 'email' => 'x@example.com']);

    $json = apGet('/autopilot-api/v1/refunds', $key)->assertOk()->json();
    expect($json['refunds'])->toHaveCount(1)->and($json['refunds'][0]['amount'])->toEqual(25.0)
        ->and($json['cancellations'])->toHaveCount(1)->and($json['cancellations'][0]['payment_family'])->toBe('cod');
    $raw = json_encode($json);
    foreach (AP_PII as $field => $value) {
        expect(str_contains($raw, $value))->toBeFalse("/refunds leaked the {$field}");
    }
});

it('answers products with catalog ids, stock and the optional cost and margin, at flat cost', function () {
    // MUTATION: query product_variants per product in ShopData::products() and $q40 > $q3.
    DB::table('products')->update(['deleted_at' => now()]);
    $key = apKey(['products']);
    apGet('/autopilot-api/v1/ping', $key)->assertOk();
    $make = function (int $n) {
        foreach (range(1, $n) as $i) {
            $p = apProduct(10000, ['type' => 'variable', 'manage_stock' => true, 'stock' => 7, 'wc_id' => random_int(100000, 999999), 'total_sales' => 4242]);
            ProductVariant::create(['product_id' => $p->id, 'sku' => $p->sku . '-V', 'price' => 12000, 'manage_stock' => true, 'stock' => 3, 'stock_status' => 'instock']);
        }
    };
    $make(3);
    [$r3, $q3] = apQueries(fn () => apGet('/autopilot-api/v1/products?limit=200', $key)->assertOk()->json());
    $make(37);
    [$r40, $q40] = apQueries(fn () => apGet('/autopilot-api/v1/products?limit=200', $key)->assertOk()->json());
    expect($r3['count'])->toBe(3)->and($r40['count'])->toBe(40)->and($q40)->toBe($q3);

    $p = $r3['products'][0];
    $v = DB::table('product_variants')->where('product_id', (int) $p['id'])->first();
    expect($p['stock'])->toBe(7)->and($p['cost'])->toBeNull()->and($p['margin_pct'])->toBeNull()
        ->and($p['variants'][0]['catalog_id'])->toBe($p['id'] . '-' . $v->id)
        ->and(str_contains(json_encode($r40), 'wc_id') || str_contains(json_encode($r40), '4242'))->toBeFalse();

    // The owner sets a cost price in the admin; the API reports cost and margin.
    $owner = apOwner();
    test()->actingAs($owner, 'admin')->postJson('/admin-api/ads-autopilot/costs', ['rows' => [['id' => (int) $p['id'], 'cost' => '42.50']]])->assertOk()->assertJson(['saved' => 1]);
    test()->actingAs($owner, 'admin')->postJson('/admin-api/ads-autopilot/costs', ['rows' => [['id' => (int) $p['id'], 'cost' => '-3']]])->assertStatus(422);
    test()->actingAs($owner, 'admin')->postJson('/admin-api/ads-autopilot/costs', ['rows' => [['id' => (int) $p['id'], 'cost' => '1e9']]])->assertStatus(422);
    $after = collect(apGet('/autopilot-api/v1/products?limit=200', $key)->json('products'))->firstWhere('id', $p['id']);
    expect($after['cost'])->toEqual(42.5)->and($after['margin_pct'])->toEqual(57.5);

    $costs = test()->actingAs($owner, 'admin')->getJson('/admin-api/ads-autopilot/costs?missing=1')->assertOk()->json();
    expect($costs['with_cost'])->toBe(1)->and(collect($costs['rows'])->pluck('id')->all())->not->toContain((int) $p['id']);
});

it('never lets a cost price out through a product\'s own toArray()', function () {
    // MUTATION: delete Product::$hidden and any JSON of a product (a public /api answer
    // built from toArray(), a cached payload) carries the shop's margin.
    $p = apProduct(10000);
    DB::table('products')->where('id', $p->id)->update(['cost_price' => 4250]);
    $fresh = Product::find($p->id);
    expect($fresh->cost_price)->toBe(4250)
        ->and(array_key_exists('cost_price', $fresh->toArray()))->toBeFalse()
        ->and($fresh->toJson())->not->toContain('4250');
});

it('ships the admin screen escaped, once, with no timer and no layout measurement', function () {
    $src = (string) file_get_contents(resource_path('views/admin/partials/ads-autopilot-screen.blade.php'));
    expect($src)->not->toContain('setInterval')->and($src)->not->toContain('setTimeout')
        ->and($src)->not->toContain('getBoundingClientRect')->and($src)->not->toContain('offsetWidth')
        ->and(substr_count($src, "window.go = function"))->toBe(1);

    // The sidebar row and its screen agree (AdminSidebarIsCompleteAtBuildTest compares them).
    $row = \App\Support\AdminNav::rows()['adsautopilot'];
    expect($row['label'])->toBe('Ads Autopilot')->and($row['read'])->toBe('admin-api/ads-autopilot')
        ->and(AdminCapabilities::forPath('GET', $row['read']))->toBe('autopilot.view');

    // Wired at most once. Zero until the integrator includes it; two would wrap window.go twice.
    $app = (string) file_get_contents(resource_path('views/admin/app.blade.php'));
    expect(substr_count($app, "@include('admin.partials.ads-autopilot-screen')"))->toBeLessThanOrEqual(1);
});
