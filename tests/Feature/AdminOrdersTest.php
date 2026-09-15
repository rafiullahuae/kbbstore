<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\OrdersAdminRoutes;

/**
 * Store → Orders.
 *
 * The screen this replaces returned every order the store has ever taken in one
 * array and counted its own filter chips in the browser. Against the 2,419
 * orders the WooCommerce import brings across that is one enormous response per
 * visit, and a chip count that is only ever as right as the page happens to be.
 *
 * Four things are pinned hardest here, because each is a way to be wrong
 * quietly:
 *
 *   THE GUARD. These endpoints return every buyer's name, email, phone, city
 *   and order value, /orders-export hands the whole filtered list over as a
 *   file, and the bulk routes rewrite order status and trash orders — which
 *   moves the store's own revenue figures. /api/* in this app is
 *   unauthenticated by design, so being on the wrong side of that line is both
 *   a leak and a write primitive. Every route is asserted against an anonymous
 *   caller AND against a signed-in non-admin, and the middleware is read back
 *   off the registered routes rather than trusted from the harness.
 *
 *   THE MONEY. Seeded to exact fils and asserted to exact fils, including the
 *   average order value, which is integer division — the whole point of storing
 *   minor units is that no float ever touches them.
 *
 *   THE QUERY COUNT. The list is a fixed number of statements whatever the
 *   order count. Asserted at a realistic population, because an N+1 is
 *   invisible at two rows.
 *
 *   THE IMPORT. Guest orders with customer_id NULL, rows with no phone and no
 *   city, historical dates, and statuses this application never writes. All of
 *   those are what the table really looks like after the Woo import, and none
 *   of them may make a figure wrong or a page throw.
 */

/* ------------------------------------------------------------------ fixtures */

function oCustomer(array $attributes = []): Customer
{
    static $n = 0;
    $n++;

    return Customer::create(array_merge([
        'name' => 'O Customer ' . $n,
        'email' => 'o-customer-' . $n . '@example.test',
    ], $attributes));
}

/** An order at an exact number of fils. Nothing here rounds. */
function oOrder(array $attributes = []): Order
{
    static $n = 0;
    $n++;

    $customer = $attributes['customer'] ?? null;
    unset($attributes['customer']);

    $at = $attributes['at'] ?? null;
    unset($attributes['at']);

    $order = Order::create(array_merge([
        'customer_id' => $customer?->id,
        'order_number' => 'KBB-' . str_pad((string) $n, 5, '0', STR_PAD_LEFT),
        'email' => $customer?->email ?? 'o-guest-' . $n . '@example.test',
        'phone' => '+9715000000' . ($n % 10),
        'status' => 'completed',
        'total' => 10000,
        'subtotal' => 10000,
        'currency' => 'AED',
        'payment_method' => 'cod',
        'payment_method_title' => 'Cash on delivery',
    ], $attributes));

    if ($at !== null) {
        // created_at is what the date filter and "newest" read, and a
        // historical import does not place its orders today.
        DB::table('orders')->where('id', $order->id)->update(['created_at' => $at, 'updated_at' => $at]);
        $order->refresh();
    }

    return $order;
}

function oItem(Order $order, int $quantity = 1, int $total = 10000): OrderItem
{
    return $order->items()->create([
        'name' => 'O Product',
        'quantity' => $quantity,
        'unit_price' => $quantity > 0 ? intdiv($total, $quantity) : $total,
        'subtotal' => $total,
        'total' => $total,
    ]);
}

function oAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'O Owner',
        'email' => 'o-owner-' . uniqid() . '@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
}

/** Wire the route file and sign in as an admin — the normal case. */
function asOrderAdmin(): void
{
    OrdersAdminRoutes::wire(app());
    test()->actingAs(oAdmin(), 'admin');
}

/** @return array<int, array<string, mixed>> keyed by order id */
function oRows(string $query = ''): array
{
    $body = test()->getJson('/admin-api/orders-list' . ($query === '' ? '' : '?' . $query))
        ->assertOk()
        ->json();

    $out = [];

    foreach ($body['orders'] as $row) {
        $out[$row['id']] = $row;
    }

    return $out;
}

/* ---------------------------------------------------------------- the guard */

it('refuses an anonymous caller on every route the orders screen adds', function () {
    OrdersAdminRoutes::wire(app());

    $customer = oCustomer(['name' => 'O Private Buyer', 'email' => 'o-private@example.test']);
    $order = oOrder(['customer' => $customer, 'order_number' => 'KBB-SECRET-1']);

    $refusals = [
        ['get', '/admin-api/orders-list'],
        ['get', '/admin-api/orders-export'],
        ['post', '/admin-api/orders-bulk-status'],
        ['post', '/admin-api/orders-bulk-delete'],
        ['post', '/admin-api/orders-bulk-restore'],
    ];

    foreach ($refusals as [$method, $uri]) {
        $response = $method === 'get'
            ? test()->getJson($uri)
            : test()->postJson($uri, ['ids' => [$order->id], 'status' => 'cancelled', 'force' => true]);

        expect($response->getStatusCode())->toBe(401, $method . ' ' . $uri . ' was not refused');

        // Nothing leaked on the way to the refusal.
        expect($response->getContent())->not->toContain('KBB-SECRET-1')
            ->and($response->getContent())->not->toContain('o-private@example.test');
    }

    // And nothing was written by the anonymous caller either.
    $order->refresh();

    expect($order->status)->toBe('completed')
        ->and($order->trashed())->toBeFalse();
});

it('refuses a signed-in non-admin as firmly as an anonymous one', function () {
    OrdersAdminRoutes::wire(app());

    $order = oOrder();

    // A shopper signed into the storefront. The `customer` guard is deliberately
    // separate from `admin` (config/auth.php says so in as many words); this is
    // the assertion that the separation is real and not just documented.
    $shopper = oCustomer(['email' => 'o-shopper@example.test', 'password' => 'secret-secret']);

    test()->actingAs($shopper, 'customer');

    expect(test()->getJson('/admin-api/orders-list')->getStatusCode())->toBe(401)
        ->and(test()->getJson('/admin-api/orders-export')->getStatusCode())->toBe(401)
        ->and(test()->postJson('/admin-api/orders-bulk-delete', ['ids' => [$order->id]])->getStatusCode())->toBe(401)
        ->and(test()->postJson('/admin-api/orders-bulk-restore', ['ids' => [$order->id]])->getStatusCode())->toBe(401)
        ->and(test()->postJson('/admin-api/orders-bulk-status', ['ids' => [$order->id], 'status' => 'shipped'])->getStatusCode())->toBe(401);

    // And a site user on the default `web` guard, which is neither of those.
    $user = User::create([
        'name' => 'O Web User',
        'email' => 'o-webuser@example.test',
        'password' => 'secret-secret',
    ]);

    test()->actingAs($user, 'web');

    expect(test()->getJson('/admin-api/orders-list')->getStatusCode())->toBe(401)
        ->and(test()->getJson('/admin-api/orders-export')->getStatusCode())->toBe(401)
        ->and(test()->postJson('/admin-api/orders-bulk-status', ['ids' => [$order->id], 'status' => 'shipped'])->getStatusCode())->toBe(401);

    // Not one of those calls changed the order.
    expect($order->fresh()->status)->toBe('completed');
});

it('really does mount every route behind the admin guard, not just appear to', function () {
    OrdersAdminRoutes::wire(app());

    $routes = OrdersAdminRoutes::registered();

    // Five routes, and the count is asserted so a new one cannot be added
    // without this file noticing it needs a guard assertion too.
    expect($routes)->toHaveCount(5);

    foreach ($routes as $route) {
        // The trap this exists for: RouteRegistrar::middleware() REPLACES the
        // pending middleware, so a harness that chains it twice registers
        // routes with no `auth:admin` at all while reading as though it did —
        // and every 401 assertion above would then be passing against nothing.
        // Read back off the registered route, not off the harness's intent.
        expect($route->middleware())->toContain('auth:admin')
            ->and($route->middleware())->toContain('web');
    }
});

it('leaves the existing order detail, refund and capture routes alone', function () {
    OrdersAdminRoutes::wire(app());

    // This lane adds a LIST. The detail screen, the refund and the capture
    // panel already exist and are already good; a second definition of any of
    // them would be a second way to move money.
    foreach (OrdersAdminRoutes::registered() as $route) {
        expect($route->uri())->not->toContain('{id}');
    }

    $file = (string) file_get_contents(base_path('routes/orders-admin.php'));

    expect($file)->not->toContain('PaymentSettlementController::class')
        ->and($file)->not->toContain('AdminOrderController::class')
        ->and($file)->toContain('/orders/{id}/capture')     // named, as documentation
        ->and($file)->toContain('/orders/{id}/detail');
});

it('documents for the integrator exactly where the file must be mounted', function () {
    $header = (string) file_get_contents(base_path('routes/orders-admin.php'));

    expect($header)->toContain('admin-api')
        ->and($header)->toContain('auth:admin')
        ->and($header)->toContain("require __DIR__.'/orders-admin.php';")
        // And why the list is not at GET /admin-api/orders, which web.php
        // already registers against the old controller.
        ->and($header)->toContain('/admin-api/orders-list')
        ->and($header)->toContain('clear_caches_admin_orders');
});

it('ships the clear-caches migration every route-adding package needs', function () {
    $path = database_path('migrations/2026_09_22_000000_clear_caches_admin_orders.php');

    expect(file_exists($path))->toBeTrue();

    $source = (string) file_get_contents($path);

    expect($source)->toContain('bootstrap/cache/routes-*.php')
        ->toContain('framework/views/*.php')
        // Nine migrations in this repo were silent no-ops on MySQL because of
        // ->after() on a column that did not exist yet.
        ->and($source)->not->toContain('->after(');
});

/* ------------------------------------------------------------- the list body */

it('renders the list with totals, refunds and units to the exact fil', function () {
    asOrderAdmin();

    $buyer = oCustomer(['name' => 'O Buyer']);

    $order = oOrder(['customer' => $buyer, 'total' => 12345]);
    oItem($order, 2, 10000);
    oItem($order, 1, 2345);

    Refund::create(['order_id' => $order->id, 'amount' => 2345, 'status' => 'succeeded']);

    $row = oRows()[$order->id];

    expect($row['total_fils'])->toBe(12345)
        ->and($row['refunded_fils'])->toBe(2345)
        ->and($row['net_fils'])->toBe(10000)
        ->and($row['units'])->toBe(3)
        ->and($row['lines'])->toBe(2)
        ->and($row['customer_name'])->toBe('O Buyer')
        ->and($row['guest'])->toBeFalse()
        ->and($row['counts_as_revenue'])->toBeTrue();
});

it('counts as revenue only the statuses the rest of the app counts', function () {
    asOrderAdmin();

    foreach (['processing', 'onhold', 'shipped', 'completed'] as $status) {
        oOrder(['status' => $status, 'total' => 10000]);
    }

    foreach (['draft', 'pending', 'cancelled', 'refunded', 'failed'] as $status) {
        oOrder(['status' => $status, 'total' => 99000]);
    }

    $body = test()->getJson('/admin-api/orders-list')->assertOk()->json();

    // Order::REAL_STATUSES, read from the model rather than restated here, so
    // this test cannot drift away from the definition the dashboard uses.
    expect($body['revenue_statuses'])->toBe(Order::REAL_STATUSES);

    $summary = $body['summary'];

    expect($summary['orders'])->toBe(9)
        ->and($summary['paid_orders'])->toBe(4)
        ->and($summary['revenue_fils'])->toBe(40000)
        // Gross is every row in the view, revenue is only the four that count.
        ->and($summary['gross_fils'])->toBe(40000 + 5 * 99000)
        ->and($summary['aov_fils'])->toBe(10000);

    foreach ($body['orders'] as $row) {
        expect($row['counts_as_revenue'])
            ->toBe(in_array($row['status'], Order::REAL_STATUSES, true));
    }
});

it('computes the average order value by integer division, never a float', function () {
    asOrderAdmin();

    oOrder(['total' => 10000, 'status' => 'completed']);
    oOrder(['total' => 5000, 'status' => 'completed']);
    oOrder(['total' => 33, 'status' => 'completed']);

    $summary = test()->getJson('/admin-api/orders-list')->assertOk()->json('summary');

    // 15033 / 3 = 5011 exactly. Integer division, no float anywhere on the
    // path: the SUM is integer and PHP's intdiv() does the average.
    expect($summary['revenue_fils'])->toBe(15033)
        ->and($summary['aov_fils'])->toBe(5011)
        ->and($summary['aov_fils'])->toBeInt();
});

it('subtracts refunds from revenue but never below zero on a bad row', function () {
    asOrderAdmin();

    $order = oOrder(['total' => 20000, 'status' => 'completed']);

    // More refunded than the order is worth. A data problem, not a negative
    // amount the store owes itself.
    Refund::create(['order_id' => $order->id, 'amount' => 30000, 'status' => 'succeeded']);

    $row = oRows()[$order->id];

    expect($row['refunded_fils'])->toBe(30000)
        ->and($row['net_fils'])->toBe(0);
});

it('counts a refund the same way the refund ceiling on the detail page does', function () {
    asOrderAdmin();

    $order = oOrder(['total' => 50000, 'status' => 'completed']);

    // PaymentRefunder::COUNTED is ['pending', 'succeeded'] — a refund still in
    // flight is money already committed. A failed one is not.
    Refund::create(['order_id' => $order->id, 'amount' => 1000, 'status' => 'succeeded']);
    Refund::create(['order_id' => $order->id, 'amount' => 2000, 'status' => 'pending']);
    Refund::create(['order_id' => $order->id, 'amount' => 40000, 'status' => 'failed']);

    expect(oRows()[$order->id]['refunded_fils'])->toBe(3000);
});

/* ---------------------------------------------------------- imported orders */

it('shows a guest order with no customer row at all', function () {
    asOrderAdmin();

    $order = oOrder([
        'customer' => null,
        'total' => 7700,
        'billing_address' => ['first_name' => 'Imported', 'last_name' => 'Guest', 'city' => 'Sharjah', 'country' => 'AE'],
    ]);

    $row = oRows()[$order->id];

    expect($row['customer_id'])->toBeNull()
        ->and($row['guest'])->toBeTrue()
        // The name lives only in the JSON blob on a guest order.
        ->and($row['customer_name'])->toBe('Imported Guest')
        ->and($row['city'])->toBe('Sharjah')
        ->and($row['country'])->toBe('AE')
        ->and($row['total_fils'])->toBe(7700);
});

it('survives imported rows with no phone, no city, no items and an odd address blob', function () {
    asOrderAdmin();

    $bare = oOrder(['customer' => null, 'wc_order_id' => 18422, 'total' => 4500]);

    DB::table('orders')->where('id', $bare->id)->update([
        'phone' => null,
        'billing_address' => null,
        'shipping_address' => '',
        'payment_method' => null,
        'payment_method_title' => null,
    ]);

    // And one whose address survived the import as something that is not an
    // object at all. A malformed row must not take out the page.
    $broken = oOrder(['customer' => null, 'total' => 100]);
    DB::table('orders')->where('id', $broken->id)->update(['billing_address' => 'not json at all']);

    $rows = oRows();

    expect($rows[$bare->id]['phone'])->toBeNull()
        ->and($rows[$bare->id]['city'])->toBeNull()
        ->and($rows[$bare->id]['units'])->toBe(0)
        ->and($rows[$bare->id]['lines'])->toBe(0)
        // Surfaced, not hidden: the WooCommerce order id is how an imported
        // order is matched back to the source system.
        ->and($rows[$bare->id]['wc_order_id'])->toBe(18422)
        ->and($rows[$bare->id]['payment'])->toBe('Not recorded')
        ->and($rows[$broken->id]['customer_name'])->toBeNull();
});

it('is correct for historical orders imported with old dates', function () {
    asOrderAdmin();

    $old = oOrder(['total' => 12000, 'at' => '2019-04-02 10:00:00']);
    $newer = oOrder(['total' => 8000, 'at' => '2021-11-20 09:30:00']);

    $body = test()->getJson('/admin-api/orders-list')->assertOk()->json();

    expect($body['orders'][0]['id'])->toBe($newer->id)
        ->and($body['orders'][1]['id'])->toBe($old->id)
        ->and($body['orders'][1]['placed_at'])->toStartWith('2019-04-02');

    // And the date filter reaches back that far rather than assuming "recent".
    $only2019 = oRows('from=2019-01-01&to=2019-12-31');

    expect($only2019)->toHaveKey($old->id)
        ->and($only2019)->not->toHaveKey($newer->id);
});

it('gives an imported status its own chip instead of hiding those orders', function () {
    asOrderAdmin();

    // The schema's own comment names these: production carries wc-shipped and
    // wc-tamara-p-failed alongside the core set. A hard-coded status list would
    // make every one of these orders invisible.
    oOrder(['status' => 'wc-tamara-p-failed', 'total' => 6000]);
    oOrder(['status' => 'wc-tamara-p-failed', 'total' => 6000]);
    oOrder(['status' => 'completed', 'total' => 1000]);

    $body = test()->getJson('/admin-api/orders-list')->assertOk()->json();

    expect($body['statuses'])->toContain('wc-tamara-p-failed')
        ->and($body['counts']['wc-tamara-p-failed'])->toBe(2)
        // The statuses this application writes are always offered, even at zero.
        ->and($body['statuses'])->toContain('draft')
        ->and($body['counts']['draft'])->toBe(0)
        ->and($body['counts']['all'])->toBe(3)
        ->and($body['counts']['paid'])->toBe(1);

    // And the chip actually filters to them.
    $filtered = oRows('filter=wc-tamara-p-failed');

    expect($filtered)->toHaveCount(2);
});

/* --------------------------------------------------------- filters and sort */

it('filters by status, by the revenue group and by trash', function () {
    asOrderAdmin();

    $completed = oOrder(['status' => 'completed']);
    $processing = oOrder(['status' => 'processing']);
    $cancelled = oOrder(['status' => 'cancelled']);

    $trashed = oOrder(['status' => 'completed']);
    $trashed->delete();

    expect(oRows('filter=completed'))->toHaveKey($completed->id)
        ->and(oRows('filter=completed'))->not->toHaveKey($processing->id);

    $paid = oRows('filter=paid');

    expect($paid)->toHaveKey($completed->id)
        ->and($paid)->toHaveKey($processing->id)
        ->and($paid)->not->toHaveKey($cancelled->id)
        // A trashed order is not in the paid view even though its status says
        // completed — soft deletes are outside the default scope.
        ->and($paid)->not->toHaveKey($trashed->id);

    $trash = oRows('filter=trashed');

    expect($trash)->toHaveCount(1)
        ->and($trash[$trashed->id]['trashed'])->toBeTrue();
});

it('returns nothing rather than everything for a status that does not exist', function () {
    asOrderAdmin();

    oOrder(['status' => 'completed']);

    // A chip that quietly showed every order when the operator asked for one
    // status would be a worse answer than an empty list.
    expect(oRows('filter=no-such-status'))->toBe([]);
});

it('filters by order value in whole dirhams, comparing in fils', function () {
    asOrderAdmin();

    $small = oOrder(['total' => 4999]);    // AED 49.99
    $mid = oOrder(['total' => 50000]);     // AED 500.00
    $big = oOrder(['total' => 250000]);    // AED 2,500.00

    $over100 = oRows('total_min=100');

    expect($over100)->not->toHaveKey($small->id)
        ->and($over100)->toHaveKey($mid->id)
        ->and($over100)->toHaveKey($big->id);

    $band = oRows('total_min=100&total_max=1000');

    expect($band)->toHaveCount(1)->and($band)->toHaveKey($mid->id);
});

it('searches order number, email, phone, account name and a guest s billing name', function () {
    asOrderAdmin();

    $named = oCustomer(['name' => 'Fatima Al Zahra', 'email' => 'fatima@example.test']);

    $byAccount = oOrder(['customer' => $named, 'order_number' => 'KBB-AAA-1']);
    $byNumber = oOrder(['order_number' => 'KBB-ZZZ-9']);
    $byEmail = oOrder(['email' => 'findme@example.test']);
    $byPhone = oOrder(['phone' => '+971509998877']);
    $byGuestName = oOrder([
        'customer' => null,
        'billing_address' => ['first_name' => 'Rashid', 'last_name' => 'Nouri'],
    ]);
    $imported = oOrder(['wc_order_id' => 18422]);

    expect(oRows('search=Fatima'))->toHaveKey($byAccount->id)
        ->and(oRows('search=KBB-ZZZ-9'))->toHaveKey($byNumber->id)
        ->and(oRows('search=findme'))->toHaveKey($byEmail->id)
        ->and(oRows('search=509998877'))->toHaveKey($byPhone->id)
        // The guest's name exists only inside billing_address.
        ->and(oRows('search=Rashid'))->toHaveKey($byGuestName->id)
        // And a Woo order id answers "did this order come across?".
        ->and(oRows('search=18422'))->toHaveKey($imported->id);

    // A term nobody has is not a term everybody has.
    expect(oRows('search=zzzznothing'))->toBe([]);
});

it('does not let a percent sign in the search become a wildcard', function () {
    asOrderAdmin();

    $literal = oOrder(['order_number' => 'KBB-100%-OFF']);
    $other = oOrder(['order_number' => 'KBB-PLAIN-1']);

    $hits = oRows('search=' . urlencode('100%'));

    expect($hits)->toHaveKey($literal->id)
        ->and($hits)->not->toHaveKey($other->id);
});

it('sorts by date, by value and by customer without burying the guests', function () {
    asOrderAdmin();

    $cheap = oOrder(['total' => 100, 'at' => '2020-01-01 00:00:00']);
    $dear = oOrder(['total' => 900000, 'at' => '2024-01-01 00:00:00']);

    expect(test()->getJson('/admin-api/orders-list?sort=total_desc')->json('orders.0.id'))->toBe($dear->id);
    expect(test()->getJson('/admin-api/orders-list?sort=total_asc')->json('orders.0.id'))->toBe($cheap->id);
    expect(test()->getJson('/admin-api/orders-list?sort=oldest')->json('orders.0.id'))->toBe($cheap->id);
    expect(test()->getJson('/admin-api/orders-list?sort=newest')->json('orders.0.id'))->toBe($dear->id);

    // A guest order has no customers row; sorting by customer must still
    // return every row rather than dropping them or grouping them under blank.
    expect(test()->getJson('/admin-api/orders-list?sort=customer')->assertOk()->json('total'))->toBe(2);
});

it('paginates, and clamps a page past the end rather than returning nothing', function () {
    asOrderAdmin();

    foreach (range(1, 25) as $i) {
        oOrder(['total' => 1000 * $i]);
    }

    $first = test()->getJson('/admin-api/orders-list?per_page=10&page=1')->assertOk()->json();

    expect($first['orders'])->toHaveCount(10)
        ->and($first['total'])->toBe(25)
        ->and($first['last_page'])->toBe(3)
        ->and($first['per_page'])->toBe(10);

    $past = test()->getJson('/admin-api/orders-list?per_page=10&page=99')->assertOk()->json();

    expect($past['page'])->toBe(3)->and($past['orders'])->toHaveCount(5);

    // per_page is clamped at both ends rather than believed.
    expect(test()->getJson('/admin-api/orders-list?per_page=1')->json('per_page'))->toBe(10)
        ->and(test()->getJson('/admin-api/orders-list?per_page=99999')->json('per_page'))->toBe(500);
});

it('summarises the filtered view, not the whole table', function () {
    asOrderAdmin();

    oOrder(['status' => 'completed', 'total' => 10000]);
    oOrder(['status' => 'completed', 'total' => 30000]);
    oOrder(['status' => 'cancelled', 'total' => 999999]);

    $summary = test()->getJson('/admin-api/orders-list?filter=completed')->assertOk()->json('summary');

    // A summary that silently described a different set of orders than the
    // rows underneath it would be worse than no summary.
    expect($summary['orders'])->toBe(2)
        ->and($summary['gross_fils'])->toBe(40000)
        ->and($summary['revenue_fils'])->toBe(40000)
        ->and($summary['aov_fils'])->toBe(20000);
});

/* --------------------------------------------------- the personal data rule */

it('never returns the customer s IP or street address in the list', function () {
    asOrderAdmin();

    $order = oOrder([
        'customer' => null,
        'ip_address' => '203.0.113.44',
        'billing_address' => [
            'first_name' => 'Street', 'last_name' => 'Test',
            'line1' => '77 Secret Villa Road', 'city' => 'Dubai', 'country' => 'AE',
        ],
    ]);

    $raw = test()->getJson('/admin-api/orders-list')->assertOk()->getContent();

    // An explicit allowlist, not the model. The IP and the street are on the
    // detail screen, which is a deliberate act to open.
    expect($raw)->not->toContain('203.0.113.44')
        ->and($raw)->not->toContain('77 Secret Villa Road')
        ->and($raw)->not->toContain('billing_address');

    $row = oRows()[$order->id];

    expect($row)->not->toHaveKey('ip_address')
        ->and($row)->not->toHaveKey('billing_address')
        // The city and country it DOES need still came out of that blob.
        ->and($row['city'])->toBe('Dubai');
});

/* ------------------------------------------------------------------- export */

it('exports the current filtered view, not the whole table', function () {
    asOrderAdmin();

    $buyer = oCustomer(['name' => 'O Exported Buyer']);
    $kept = oOrder(['customer' => $buyer, 'status' => 'completed', 'total' => 12345, 'order_number' => 'KBB-EXPORTED']);
    Refund::create(['order_id' => $kept->id, 'amount' => 345, 'status' => 'succeeded']);

    oOrder(['status' => 'cancelled', 'order_number' => 'KBB-NOT-EXPORTED']);

    $response = test()->get('/admin-api/orders-export?filter=completed');

    expect($response->getStatusCode())->toBe(200)
        ->and($response->headers->get('Content-Type'))->toContain('text/csv')
        ->and($response->headers->get('Content-Disposition'))->toContain('orders-');

    $csv = $response->streamedContent();

    expect($csv)->toContain('KBB-EXPORTED')
        ->and($csv)->not->toContain('KBB-NOT-EXPORTED')
        // Both the exact integer and a spreadsheet-friendly decimal, so later
        // tooling has the fils and the owner has a number.
        ->and($csv)->toContain('12345')
        ->and($csv)->toContain('123.45')
        ->and($csv)->toContain('total_fils')
        ->and($csv)->toContain('12000')     // net, in fils
        ->and($csv)->toContain('120.00');
});

it('keeps the IP and the street out of the download too', function () {
    asOrderAdmin();

    $order = oOrder([
        'customer' => null,
        'billing_address' => ['first_name' => 'Csv', 'last_name' => 'Test', 'line1' => '77 Secret Villa Road', 'city' => 'Dubai'],
    ]);
    DB::table('orders')->where('id', $order->id)->update(['ip_address' => '203.0.113.99']);

    $csv = test()->get('/admin-api/orders-export')->streamedContent();

    expect($csv)->not->toContain('203.0.113.99')
        ->and($csv)->not->toContain('77 Secret Villa Road')
        ->and($csv)->toContain('Dubai');
});

it('does not let a buyer s billing name become a formula in the owner s spreadsheet', function () {
    asOrderAdmin();

    // Every one of these is typed by the public at checkout.
    oOrder(['customer' => null, 'billing_address' => ['name' => '=HYPERLINK("http://evil.test","click")']]);
    oOrder(['customer' => null, 'billing_address' => ['name' => '+1234']]);
    oOrder(['customer' => null, 'billing_address' => ['name' => '-1234']]);
    oOrder(['customer' => null, 'billing_address' => ['name' => '@SUM(A1:A9)']]);
    oOrder(['customer' => null, 'order_number' => "\t=cmd|'/c calc'!A1"]);

    $csv = test()->get('/admin-api/orders-export')->streamedContent();

    // Quoted into a text cell, so none of them is evaluated when opened.
    expect($csv)->toContain("'=HYPERLINK")
        ->and($csv)->toContain("'+1234")
        ->and($csv)->toContain("'-1234")
        ->and($csv)->toContain("'@SUM")
        // A leading tab sneaks past a naive check of the first character.
        ->and($csv)->toContain("'\t=cmd")
        // And the un-neutralised form is nowhere in the file.
        ->and($csv)->not->toContain(',=HYPERLINK')
        ->and($csv)->not->toContain(',@SUM');
});

/* -------------------------------------------------------------------- bulk */

it('sets a status on a selection and writes a note against each order', function () {
    asOrderAdmin();

    $a = oOrder(['status' => 'processing']);
    $b = oOrder(['status' => 'processing']);
    $untouched = oOrder(['status' => 'pending']);

    test()->postJson('/admin-api/orders-bulk-status', ['ids' => [$a->id, $b->id], 'status' => 'shipped'])
        ->assertOk()
        ->assertJson(['ok' => true, 'changed' => 2]);

    expect($a->fresh()->status)->toBe('shipped')
        ->and($b->fresh()->status)->toBe('shipped')
        ->and($untouched->fresh()->status)->toBe('pending');

    // A bulk edit with no trace is how a store ends up unable to explain its
    // own numbers.
    expect($a->notes()->count())->toBe(1)
        ->and((string) $a->notes()->first()->content)->toContain('shipped');
});

it('refuses to take an order out of revenue in bulk until it is confirmed', function () {
    asOrderAdmin();

    $paid = oOrder(['status' => 'completed', 'total' => 45000]);
    $unpaid = oOrder(['status' => 'pending', 'total' => 1000]);

    $out = test()->postJson('/admin-api/orders-bulk-status', [
        'ids' => [$paid->id, $unpaid->id],
        'status' => 'cancelled',
    ])->assertOk()->json();

    // The safe half of the selection is still what the operator meant.
    expect($out['changed'])->toBe(1)
        ->and($out['skipped'])->toHaveCount(1)
        ->and($out['skipped'][0]['id'])->toBe($paid->id)
        ->and($out['skipped'][0]['total_fils'])->toBe(45000);

    expect($paid->fresh()->status)->toBe('completed')
        ->and($unpaid->fresh()->status)->toBe('cancelled');

    // And only with force does the revenue-carrying one move.
    test()->postJson('/admin-api/orders-bulk-status', [
        'ids' => [$paid->id], 'status' => 'cancelled', 'force' => true,
    ])->assertOk()->assertJson(['changed' => 1]);

    expect($paid->fresh()->status)->toBe('cancelled');
});

it('will not let a bulk action invent a refund', function () {
    asOrderAdmin();

    $order = oOrder(['status' => 'completed', 'total' => 30000]);

    // 'refunded' is written by PaymentRefunder when money actually goes back.
    // A bulk button that could set it would be a way to make the books say a
    // refund happened without one having happened.
    test()->postJson('/admin-api/orders-bulk-status', [
        'ids' => [$order->id], 'status' => 'refunded', 'force' => true,
    ])->assertStatus(422);

    expect($order->fresh()->status)->toBe('completed');

    // And an arbitrary string is not a status either.
    test()->postJson('/admin-api/orders-bulk-status', [
        'ids' => [$order->id], 'status' => 'wc-anything',
    ])->assertStatus(422);
});

it('trashes a selection, refusing the paid ones until they are confirmed', function () {
    asOrderAdmin();

    $paid = oOrder(['status' => 'completed', 'total' => 60000]);
    $draft = oOrder(['status' => 'draft', 'total' => 0]);

    $out = test()->postJson('/admin-api/orders-bulk-delete', ['ids' => [$paid->id, $draft->id]])
        ->assertOk()->json();

    expect($out['deleted'])->toBe(1)
        ->and($out['skipped'])->toHaveCount(1)
        ->and($out['skipped'][0]['total_display'])->not->toBeEmpty();

    expect(Order::withTrashed()->find($draft->id)->trashed())->toBeTrue()
        ->and(Order::withTrashed()->find($paid->id)->trashed())->toBeFalse();

    test()->postJson('/admin-api/orders-bulk-delete', ['ids' => [$paid->id], 'force' => true])
        ->assertOk()->assertJson(['deleted' => 1]);

    // A soft delete: the row and its line items are still there.
    expect(Order::withTrashed()->find($paid->id)->trashed())->toBeTrue()
        ->and(DB::table('orders')->where('id', $paid->id)->exists())->toBeTrue();
});

it('restores a trashed selection', function () {
    asOrderAdmin();

    $a = oOrder(['status' => 'completed']);
    $b = oOrder(['status' => 'completed']);
    $a->delete();
    $b->delete();

    test()->postJson('/admin-api/orders-bulk-restore', ['ids' => [$a->id, $b->id]])
        ->assertOk()
        ->assertJson(['ok' => true, 'restored' => 2]);

    expect(Order::find($a->id))->not->toBeNull()
        ->and(Order::find($b->id))->not->toBeNull();
});

it('will not accept an unbounded bulk request', function () {
    asOrderAdmin();

    test()->postJson('/admin-api/orders-bulk-delete', ['ids' => range(1, 5000)])->assertStatus(422);
    test()->postJson('/admin-api/orders-bulk-delete', ['ids' => []])->assertStatus(422);
    test()->postJson('/admin-api/orders-bulk-status', ['ids' => [1]])->assertStatus(422);
});

/* ------------------------------------------------------------- query counts */

it('runs a bounded number of queries however many orders there are', function () {
    asOrderAdmin();

    // A realistic population, not two rows: an N+1 is invisible at two rows and
    // this screen is going to be pointed at 2,419 imported orders.
    foreach (range(1, 60) as $i) {
        $customer = $i % 3 === 0 ? null : oCustomer(['name' => 'O Load ' . $i]);

        $order = oOrder([
            'customer' => $customer,
            'status' => ['completed', 'processing', 'cancelled', 'shipped'][$i % 4],
            'total' => 1000 * $i,
            'billing_address' => ['first_name' => 'Load', 'last_name' => (string) $i, 'city' => 'City ' . ($i % 7)],
        ]);

        oItem($order, 2, 500 * $i);
        oItem($order, 1, 500 * $i);

        if ($i % 5 === 0) {
            Refund::create(['order_id' => $order->id, 'amount' => 100, 'status' => 'succeeded']);
        }
    }

    // One unmeasured request first. The currency settings row is read through a
    // cache on the first call in a process and memoised after it, so without
    // this the first measurement carries a warm-up query the second does not.
    test()->getJson('/admin-api/orders-list?per_page=50')->assertOk();

    DB::flushQueryLog();
    DB::enableQueryLog();

    test()->getJson('/admin-api/orders-list?per_page=50')->assertOk();

    $withFifty = count(DB::getQueryLog());

    DB::flushQueryLog();

    // A tenth of the page size. A per-row query would shrink with it; a join
    // does not.
    test()->getJson('/admin-api/orders-list?per_page=5')->assertOk();

    $withFive = count(DB::getQueryLog());

    DB::disableQueryLog();

    expect($withFifty)->toBeLessThanOrEqual(8, 'the order list is running ' . $withFifty . ' queries for one page')
        // The same count for a tenth of the rows is what "not N+1" means.
        ->and($withFive)->toBe($withFifty);

    // And the aggregates are still right at this size, so the bound was not
    // bought by not computing anything.
    $row = test()->getJson('/admin-api/orders-list?sort=oldest&per_page=1')->json('orders.0');

    expect($row['units'])->toBe(3)->and($row['lines'])->toBe(2)->and($row['total_fils'])->toBe(1000);
});

it('runs a bounded number of queries for the export too', function () {
    asOrderAdmin();

    foreach (range(1, 60) as $i) {
        $order = oOrder(['total' => 1000 + $i, 'status' => 'completed']);
        oItem($order, 1, 1000 + $i);
    }

    test()->get('/admin-api/orders-export')->streamedContent();

    DB::flushQueryLog();
    DB::enableQueryLog();

    $csv = test()->get('/admin-api/orders-export')->streamedContent();

    $count = count(DB::getQueryLog());

    DB::disableQueryLog();

    // Chunked, so it is one statement per 500 rows rather than one per order.
    expect($count)->toBeLessThanOrEqual(4, 'the export is running ' . $count . ' queries for 60 orders')
        ->and(substr_count($csv, "\n"))->toBeGreaterThan(60);
});

/* ---------------------------------------------------------------- the screen */

it('replaces the client-side Orders screen in the admin console', function () {
    $source = (string) file_get_contents(resource_path('views/admin/app.blade.php'));

    // The old screen fetched every order and counted its chips in the browser.
    expect($source)->not->toContain("api('/admin-api/orders')");

    $start = strpos($source, 'LANE V · Store · Orders — BEGIN');
    $end = strpos($source, 'LANE V · Store · Orders — END');

    expect($start)->not->toBeFalse('the Lane V region is missing from the admin view')
        ->and($end)->not->toBeFalse('the Lane V region is not closed');

    $region = substr($source, (int) $start, (int) $end - (int) $start);

    expect($region)->toContain('async function renderOrders()')
        ->toContain("api('/admin-api/orders-list?'")
        ->toContain("fixAdminApiUrl('/admin-api/orders-export')")
        // Billing names, emails and cities are typed by the public and land in
        // innerHTML. Everything written into the page goes through sesc().
        ->toContain('sesc(')
        // A destructive action never happens on a click alone.
        ->toContain('olConfirmDelete')
        ->toContain('olConfirmStatus')
        ->toContain('force')
        // The detail screen is the one that already exists, not a new one.
        ->toContain('renderOrderDetail(');

    // And the table is in its own scroll container, so a wide table scrolls
    // inside the card instead of making the whole page scroll sideways.
    expect($region)->toContain('odlscroll');
});

it('keeps the order detail screen and its capture panel untouched', function () {
    $source = (string) file_get_contents(resource_path('views/admin/app.blade.php'));

    // The settlement panel and the refund form belong to another lane and are
    // wired to endpoints this one does not own. (The settlement STATE arrives
    // inside the detail payload as o.settlement rather than from a separate
    // fetch, which is why the capture path is the one that appears here.)
    expect($source)->toContain('/capture')
        ->toContain('/refund')
        ->toContain('o.settlement')
        ->toContain('async function renderOrderDetail(id)')
        ->toContain("api('/admin-api/orders/'+id+'/detail')");
});

it('renders the whole admin document with the orders screen in it', function () {
    // One 600KB Blade file with several lanes editing it at once: a stray brace
    // here takes down the entire admin console, not just this screen.
    $html = view('admin.app')->render();

    expect($html)->toContain('async function renderOrders()')
        ->toContain('/admin-api/orders-list');
});

it('does not scroll the page sideways on a narrow phone', function () {
    $source = (string) file_get_contents(resource_path('views/admin/app.blade.php'));

    $start = (int) strpos($source, 'LANE V · Store · Orders — BEGIN');
    $end = (int) strpos($source, 'LANE V · Store · Orders — END');
    $region = substr($source, $start, $end - $start);

    // The measured proof is the Chromium run at 390px recorded in the PR; this
    // pins the two rules that make it true, so neither can be dropped by a
    // later edit without a test going red.
    expect($region)->toContain('overflow-x:auto')
        ->and($region)->toContain('max-width:100%');
});
