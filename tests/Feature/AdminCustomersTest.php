<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\CustomersAdminRoutes;

/**
 * Store → Customers.
 *
 * The screen it replaces was the defect shape CLAUDE.md keeps describing: it
 * looked finished. Six columns, one of which (`emirate`) is not a column on
 * this table and so was blank on every install; order totals aggregated from a
 * collection already pulled entirely into memory; and no pagination at all, so
 * the owner's 5,312 imported customers would have arrived as one response.
 *
 * Three things are pinned hardest here, because each is a way to be wrong
 * quietly:
 *
 *   THE GUARD. This endpoint returns every shopper's name, email, phone, home
 *   city and order history, and /customers/export hands the whole list over as
 *   a file. /api/* in this app is unauthenticated by design, so being on the
 *   wrong side of that line is a customer-database leak. Every route is
 *   asserted against an anonymous caller AND against a signed-in non-admin.
 *
 *   THE MONEY. Seeded to exact fils and asserted to exact fils, including the
 *   average order value, which is integer division — the whole point of storing
 *   minor units is that no float ever touches it.
 *
 *   THE QUERY COUNT. The list is a fixed number of statements whatever the
 *   customer count. Asserted against a realistic population rather than two
 *   rows, because an N+1 is invisible at two rows.
 */

/* ------------------------------------------------------------------ fixtures */

function tCustomer(array $attributes = []): Customer
{
    static $n = 0;
    $n++;

    return Customer::create(array_merge([
        'name' => 'T Customer ' . $n,
        'email' => 't-customer-' . $n . '@example.test',
    ], $attributes));
}

/** An order at an exact number of fils. Nothing here rounds. */
function tOrder(?Customer $customer, int $fils, string $status = 'completed', ?string $at = null): Order
{
    static $n = 0;
    $n++;

    $order = Order::create([
        'customer_id' => $customer?->id,
        'order_number' => 'T-' . $n . '-' . uniqid(),
        'email' => $customer?->email ?? 'guest-' . $n . '@example.test',
        'status' => $status,
        'total' => $fils,
        'subtotal' => $fils,
    ]);

    if ($at !== null) {
        // created_at is what "last order" reads, and a historical import does
        // not place its orders today.
        DB::table('orders')->where('id', $order->id)->update(['created_at' => $at]);
        $order->refresh();
    }

    return $order;
}

function tAddress(Customer $customer, array $attributes = []): void
{
    $customer->addresses()->create(array_merge([
        'type' => 'shipping',
        'is_default' => true,
        'line1' => '1 Test Street',
        'city' => 'Dubai',
        'state' => 'Dubai',
        'country' => 'AE',
    ], $attributes));
}

function tAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'T Owner',
        'email' => 't-owner-' . uniqid() . '@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
}

/** Wire the route file and sign in as an admin — the normal case. */
function asAdmin(): void
{
    CustomersAdminRoutes::wire(app());
    test()->actingAs(tAdmin(), 'admin');
}

/* ---------------------------------------------------------------- the guard */

it('refuses an anonymous caller on every route the customers screen adds', function () {
    CustomersAdminRoutes::wire(app());

    $customer = tCustomer();

    $refusals = [
        ['get', '/admin-api/customers/list'],
        ['get', '/admin-api/customers/export'],
        ['get', '/admin-api/customers/' . $customer->id],
        ['post', '/admin-api/customers/' . $customer->id . '/note'],
        ['post', '/admin-api/customers/' . $customer->id . '/restore'],
        ['post', '/admin-api/customers/bulk-delete'],
        ['delete', '/admin-api/customers/' . $customer->id],
    ];

    foreach ($refusals as [$method, $uri]) {
        $response = match ($method) {
            'get' => test()->getJson($uri),
            'post' => test()->postJson($uri, ['notes' => 'x', 'ids' => [$customer->id]]),
            'delete' => test()->deleteJson($uri),
        };

        expect($response->getStatusCode())->toBe(401, $method . ' ' . $uri . ' was not refused');

        // Nothing leaked on the way to the refusal.
        expect($response->getContent())->not->toContain($customer->email);
    }

    // And nothing was written by the anonymous caller either.
    expect(Customer::query()->find($customer->id))->not->toBeNull();
});

it('refuses a signed-in non-admin as firmly as an anonymous one', function () {
    CustomersAdminRoutes::wire(app());

    $target = tCustomer();

    // A shopper signed into the storefront. The `customer` guard is deliberately
    // separate from `admin` (config/auth.php says so in as many words); this is
    // the assertion that the separation is real and not just documented.
    $shopper = tCustomer(['email' => 't-shopper@example.test', 'password' => 'secret-secret']);

    test()->actingAs($shopper, 'customer');

    expect(test()->getJson('/admin-api/customers/list')->getStatusCode())->toBe(401)
        ->and(test()->getJson('/admin-api/customers/export')->getStatusCode())->toBe(401)
        ->and(test()->getJson('/admin-api/customers/' . $target->id)->getStatusCode())->toBe(401)
        ->and(test()->deleteJson('/admin-api/customers/' . $target->id)->getStatusCode())->toBe(401);

    // And a site user on the default `web` guard, which is neither of those.
    $user = User::create([
        'name' => 'T Web User',
        'email' => 't-webuser@example.test',
        'password' => 'secret-secret',
    ]);

    test()->actingAs($user, 'web');

    expect(test()->getJson('/admin-api/customers/list')->getStatusCode())->toBe(401)
        ->and(test()->getJson('/admin-api/customers/export')->getStatusCode())->toBe(401);
});

it('really does mount every route behind the admin guard, not just appear to', function () {
    CustomersAdminRoutes::wire(app());

    $routes = CustomersAdminRoutes::registered();

    // Seven routes, and the count is asserted so a new one cannot be added
    // without this file noticing it needs a guard assertion too.
    expect($routes)->toHaveCount(7);

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

it('documents for the integrator exactly where the file must be mounted', function () {
    $header = (string) file_get_contents(base_path('routes/customers-admin.php'));

    expect($header)->toContain('admin-api')
        ->and($header)->toContain('auth:admin')
        ->and($header)->toContain("require __DIR__.'/customers-admin.php';")
        // And why the list is not at GET /admin-api/customers, which web.php
        // already registers against the old controller.
        ->and($header)->toContain('/admin-api/customers/list');
});

/* ------------------------------------------------------------- the list body */

it('renders the list with order counts, lifetime spend and AOV to the exact fil', function () {
    asAdmin();

    $buyer = tCustomer(['name' => 'T Buyer']);
    tOrder($buyer, 10000);   // AED 100.00
    tOrder($buyer, 5000);    // AED  50.00
    tOrder($buyer, 33);      // AED   0.33  — makes the average non-terminating

    $never = tCustomer(['name' => 'T Never']);

    $body = test()->getJson('/admin-api/customers/list')->assertOk()->json();

    $rows = collect($body['customers'])->keyBy('id');

    expect($rows[$buyer->id]['orders'])->toBe(3)
        ->and($rows[$buyer->id]['spend_fils'])->toBe(15033)
        // 15033 / 3 = 5011 exactly. Integer division, no float anywhere on the
        // path: the SUM is integer, and PHP's intdiv() does the average.
        ->and($rows[$buyer->id]['aov_fils'])->toBe(5011)
        ->and($rows[$never->id]['orders'])->toBe(0)
        ->and($rows[$never->id]['spend_fils'])->toBe(0)
        // Not a division by zero, and not null either — a customer who has
        // never ordered has an average order value of nothing.
        ->and($rows[$never->id]['aov_fils'])->toBe(0);
});

it('counts only orders that are really orders', function () {
    asAdmin();

    $customer = tCustomer();

    tOrder($customer, 10000, 'completed');
    tOrder($customer, 7000, 'shipped');
    tOrder($customer, 2500, 'processing');
    tOrder($customer, 4000, 'onhold');

    // None of these are money the store has taken.
    tOrder($customer, 99000, 'cancelled');
    tOrder($customer, 88000, 'failed');
    tOrder($customer, 77000, 'pending');
    tOrder($customer, 66000, 'refunded');

    $trashed = tOrder($customer, 55000, 'completed');
    $trashed->delete();

    $row = collect(test()->getJson('/admin-api/customers/list')->json('customers'))
        ->firstWhere('id', $customer->id);

    expect($row['orders'])->toBe(4)
        ->and($row['spend_fils'])->toBe(23500)
        // The all-statuses count is still known, so the detail view can show
        // the cancelled ones without the money on this list being wrong. The
        // soft-deleted order is not in either figure.
        ->and($row['orders_all'])->toBe(8);
});

it('is correct for historical orders imported with old dates', function () {
    asAdmin();

    $customer = tCustomer();

    tOrder($customer, 12000, 'completed', '2019-04-02 10:00:00');
    tOrder($customer, 8000, 'completed', '2021-11-20 09:30:00');

    $row = collect(test()->getJson('/admin-api/customers/list')->json('customers'))
        ->firstWhere('id', $customer->id);

    expect($row['orders'])->toBe(2)
        ->and($row['spend_fils'])->toBe(20000)
        ->and($row['aov_fils'])->toBe(10000)
        // Last order is the later of the two, years before this test runs.
        ->and($row['last_order_at'])->toStartWith('2021-11-20');
});

it('survives imported rows with no name, no phone, no address and no registration date', function () {
    asAdmin();

    $bare = Customer::create(['email' => 't-bare@example.test', 'wp_user_id' => 88421]);

    // The owner's own reference screenshot shows blank usernames and em-dash
    // registration dates, so this is the shape the import really produces.
    DB::table('customers')->where('id', $bare->id)->update([
        'name' => null,
        'first_name' => null,
        'last_name' => null,
        'phone' => null,
        'created_at' => null,
        'updated_at' => null,
    ]);

    $body = test()->getJson('/admin-api/customers/list')->assertOk()->json();

    $row = collect($body['customers'])->firstWhere('id', $bare->id);

    expect($row)->not->toBeNull()
        ->and($row['name'])->toBeNull()
        ->and($row['registered_at'])->toBeNull()
        ->and($row['city'])->toBeNull()
        ->and($row['country'])->toBeNull()
        ->and($row['last_order_at'])->toBeNull()
        ->and($row['last_active_at'])->toBeNull()
        // The external-id mapping is surfaced, not swallowed.
        ->and($row['wp_user_id'])->toBe(88421);

    // Sorting by name must not fall over on the null either.
    test()->getJson('/admin-api/customers/list?sort=name')->assertOk();
});

it('never returns a credential or a token, whatever the caller asks for', function () {
    asAdmin();

    $customer = tCustomer(['password' => 'secret-secret', 'phone' => '+971500000000']);

    DB::table('customers')->where('id', $customer->id)->update([
        'legacy_password' => '$P$Bsomethingphpasshash',
        'remember_token' => 'a-remember-token-value',
    ]);

    foreach (['/admin-api/customers/list', '/admin-api/customers/' . $customer->id] as $uri) {
        $raw = test()->getJson($uri)->assertOk()->getContent();

        expect($raw)->not->toContain('legacy_password')
            ->not->toContain('remember_token')
            ->not->toContain('a-remember-token-value')
            ->not->toContain('phpasshash')
            // The bcrypt hash of the password, and the key it would arrive under.
            ->not->toContain('"password"')
            ->not->toContain('$2y$');
    }
});

/* ------------------------------------------------------------------ filters */

it('filters on every segment a shop owner actually asks for', function () {
    asAdmin();

    $oneOrder = tCustomer(['name' => 'T One Order']);
    tOrder($oneOrder, 20000);

    $repeat = tCustomer(['name' => 'T Repeat']);
    tOrder($repeat, 30000);
    tOrder($repeat, 10000);

    $never = tCustomer(['name' => 'T Never Ordered']);

    $withLogin = tCustomer(['name' => 'T Has Login', 'password' => 'secret-secret']);
    $verified = tCustomer(['name' => 'T Verified', 'email_verified_at' => now()]);

    $ids = fn (string $filter) => collect(
        test()->getJson('/admin-api/customers/list?filter=' . $filter)->assertOk()->json('customers')
    )->pluck('id')->all();

    expect($ids('ordered'))->toContain($oneOrder->id, $repeat->id)
        ->and($ids('ordered'))->not->toContain($never->id)
        ->and($ids('never'))->toContain($never->id, $withLogin->id)
        ->and($ids('never'))->not->toContain($repeat->id)
        ->and($ids('repeat'))->toContain($repeat->id)
        ->and($ids('repeat'))->not->toContain($oneOrder->id)
        // A guest here is a customer row with no usable credential, which is
        // what checkout's firstOrCreate() leaves behind.
        ->and($ids('account'))->toContain($withLogin->id)
        ->and($ids('account'))->not->toContain($never->id)
        ->and($ids('guest'))->toContain($never->id)
        ->and($ids('guest'))->not->toContain($withLogin->id)
        ->and($ids('verified'))->toBe([$verified->id])
        ->and($ids('unverified'))->not->toContain($verified->id);
});

it('filters by spend band, registration date, country and city', function () {
    asAdmin();

    $big = tCustomer(['name' => 'T Big Spender']);
    tOrder($big, 500000);                      // AED 5,000
    tAddress($big, ['city' => 'Abu Dhabi', 'country' => 'AE']);

    $small = tCustomer(['name' => 'T Small Spender']);
    tOrder($small, 4900);                      // AED 49
    tAddress($small, ['city' => 'Doha', 'country' => 'QA']);

    DB::table('customers')->where('id', $small->id)->update(['created_at' => '2020-01-05 00:00:00']);

    $ids = fn (string $query) => collect(
        test()->getJson('/admin-api/customers/list?' . $query)->assertOk()->json('customers')
    )->pluck('id')->all();

    // Bands are given in dirhams and converted to fils with Money::fromMajor,
    // so the operator types 100 and the comparison happens at 10000.
    expect($ids('spend_min=100'))->toContain($big->id)
        ->and($ids('spend_min=100'))->not->toContain($small->id)
        ->and($ids('spend_max=99'))->toContain($small->id)
        ->and($ids('spend_max=99'))->not->toContain($big->id)
        ->and($ids('from=2019-01-01&to=2020-12-31'))->toBe([$small->id])
        ->and($ids('country=QA'))->toBe([$small->id])
        ->and($ids('country=qa'))->toBe([$small->id])
        ->and($ids('city=abu'))->toBe([$big->id]);
});

it('searches name, email, phone and the WooCommerce user id', function () {
    asAdmin();

    $target = tCustomer([
        'name' => 'Aisha Rahman',
        'email' => 'aisha.rahman@example.test',
        'phone' => '+971509998877',
        'wp_user_id' => 40321,
    ]);

    tCustomer(['name' => 'Someone Else', 'email' => 'nobody@example.test']);

    $ids = fn (string $term) => collect(
        test()->getJson('/admin-api/customers/list?search=' . urlencode($term))->assertOk()->json('customers')
    )->pluck('id')->all();

    expect($ids('Aisha'))->toBe([$target->id])
        ->and($ids('aisha.rahman@example.test'))->toBe([$target->id])
        ->and($ids('509998877'))->toBe([$target->id])
        ->and($ids('40321'))->toBe([$target->id])
        // A LIKE metacharacter is a literal, not a wildcard: a search for "%"
        // must not return the whole customer database.
        ->and($ids('%'))->toBe([]);
});

it('keeps trashed customers out of the list until they are asked for', function () {
    asAdmin();

    $kept = tCustomer(['name' => 'T Kept']);
    $gone = tCustomer(['name' => 'T Gone']);
    $gone->delete();

    $all = collect(test()->getJson('/admin-api/customers/list')->json('customers'))->pluck('id')->all();
    $trashed = collect(test()->getJson('/admin-api/customers/list?filter=trashed')->json('customers'))->pluck('id')->all();

    expect($all)->toContain($kept->id)
        ->and($all)->not->toContain($gone->id)
        ->and($trashed)->toBe([$gone->id]);
});

/* ------------------------------------------------------- sorting and paging */

it('sorts by spend, orders, average order value and name', function () {
    asAdmin();

    $a = tCustomer(['name' => 'Aaa Customer']);
    tOrder($a, 100000);                        // 1 order, AOV 100000

    $b = tCustomer(['name' => 'Bbb Customer']);
    tOrder($b, 60000);
    tOrder($b, 60000);
    tOrder($b, 60000);                         // 3 orders, spend 180000, AOV 60000

    $c = tCustomer(['name' => 'Ccc Customer']); // nothing

    $order = fn (string $sort) => collect(
        test()->getJson('/admin-api/customers/list?sort=' . $sort)->assertOk()->json('customers')
    )->pluck('id')->all();

    expect($order('spend_desc'))->toBe([$b->id, $a->id, $c->id])
        ->and($order('spend_asc'))->toBe([$c->id, $a->id, $b->id])
        ->and($order('orders_desc'))->toBe([$b->id, $a->id, $c->id])
        // Highest average, not highest total — the two disagree here on
        // purpose, which is the only way this assertion means anything.
        ->and($order('aov_desc'))->toBe([$a->id, $b->id, $c->id])
        ->and($order('name'))->toBe([$a->id, $b->id, $c->id]);
});

it('sorts by last order and last activity, with the never-active last', function () {
    asAdmin();

    $old = tCustomer(['name' => 'T Old Order']);
    tOrder($old, 1000, 'completed', '2020-01-01 00:00:00');

    $recent = tCustomer(['name' => 'T Recent Order']);
    tOrder($recent, 1000, 'completed', '2026-09-01 00:00:00');

    $quiet = tCustomer(['name' => 'T Never Active']);

    expect(collect(test()->getJson('/admin-api/customers/list?sort=last_order_desc')->json('customers'))->pluck('id')->all())
        ->toBe([$recent->id, $old->id, $quiet->id]);

    // A browsing customer who has never ordered is still active. The cart is
    // where that shows up, and it is joined in the same statement.
    DB::table('carts')->insert([
        'token' => (string) \Illuminate\Support\Str::uuid(),
        'customer_id' => $quiet->id,
        'currency' => 'AED',
        'status' => 'active',
        'last_activity_at' => '2026-09-10 12:00:00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $byActivity = collect(test()->getJson('/admin-api/customers/list?sort=last_active_desc')->json('customers'))
        ->keyBy('id');

    expect($byActivity->keys()->first())->toBe($quiet->id)
        ->and($byActivity[$quiet->id]['last_active_at'])->toStartWith('2026-09-10')
        ->and($byActivity[$old->id]['last_active_at'])->toStartWith('2020-01-01');
});

it('paginates, and reports the totals the pager draws itself from', function () {
    asAdmin();

    foreach (range(1, 25) as $i) {
        tCustomer(['name' => 'T Page ' . str_pad((string) $i, 2, '0', STR_PAD_LEFT)]);
    }

    $first = test()->getJson('/admin-api/customers/list?per_page=10&page=1&sort=oldest')->assertOk()->json();
    $third = test()->getJson('/admin-api/customers/list?per_page=10&page=3&sort=oldest')->assertOk()->json();

    expect($first['total'])->toBe(25)
        ->and($first['last_page'])->toBe(3)
        ->and($first['per_page'])->toBe(10)
        ->and($first['customers'])->toHaveCount(10)
        ->and($third['customers'])->toHaveCount(5)
        // Page one and page three are different customers, which is the part a
        // broken forPage() gets wrong while still returning ten rows.
        ->and(collect($third['customers'])->pluck('id')->intersect(collect($first['customers'])->pluck('id')))
        ->toHaveCount(0);

    // Out of range does not 500 and does not return an empty screen with a
    // page number nobody can get back from.
    $beyond = test()->getJson('/admin-api/customers/list?per_page=10&page=99')->assertOk()->json();
    expect($beyond['page'])->toBe(3);

    // per_page is clamped rather than trusted.
    expect(test()->getJson('/admin-api/customers/list?per_page=99999')->json('per_page'))->toBe(500)
        ->and(test()->getJson('/admin-api/customers/list?per_page=1')->json('per_page'))->toBe(10);
});

it('counts each chip against the other filters, not against the whole table', function () {
    asAdmin();

    $match = tCustomer(['name' => 'Zeta Buyer']);
    tOrder($match, 5000);

    tCustomer(['name' => 'Zeta Browser']);
    $other = tCustomer(['name' => 'Omega Buyer']);
    tOrder($other, 5000);

    $counts = test()->getJson('/admin-api/customers/list?search=Zeta')->assertOk()->json('counts');

    expect($counts['all'])->toBe(2)
        ->and($counts['ordered'])->toBe(1)
        ->and($counts['never'])->toBe(1);
});

/* ------------------------------------------------------------------- detail */

it('shows one customer with their orders, addresses and lifetime value', function () {
    asAdmin();

    $customer = tCustomer(['name' => 'T Detail', 'phone' => '+97150111222', 'wp_user_id' => 777]);

    tOrder($customer, 25000, 'completed', '2025-02-01 10:00:00');
    tOrder($customer, 15000, 'shipped', '2025-06-01 10:00:00');
    tOrder($customer, 90000, 'cancelled', '2025-07-01 10:00:00');

    tAddress($customer, ['city' => 'Sharjah', 'country' => 'AE', 'type' => 'shipping']);
    tAddress($customer, ['city' => 'Sharjah', 'country' => 'AE', 'type' => 'billing', 'is_default' => false]);

    $body = test()->getJson('/admin-api/customers/' . $customer->id)->assertOk()->json();

    expect($body['customer']['id'])->toBe($customer->id)
        ->and($body['customer']['wp_user_id'])->toBe(777)
        ->and($body['customer']['orders'])->toBe(2)
        ->and($body['customer']['spend_fils'])->toBe(40000)
        ->and($body['customer']['aov_fils'])->toBe(20000)
        ->and($body['customer']['last_order_at'])->toStartWith('2025-06-01')
        ->and($body['customer']['registered_at'])->not->toBeNull()
        ->and($body['orders'])->toHaveCount(3)
        ->and($body['addresses'])->toHaveCount(2)
        ->and($body['addresses'][0]['city'])->toBe('Sharjah');

    // The cancelled order is listed — the owner needs to see it — and is
    // marked as not counting toward the money above.
    $cancelled = collect($body['orders'])->firstWhere('status', 'cancelled');
    expect($cancelled['counts_as_spend'])->toBeFalse()
        ->and(collect($body['orders'])->firstWhere('status', 'completed')['counts_as_spend'])->toBeTrue();
});

it('404s on a customer that does not exist rather than leaking whether it might', function () {
    asAdmin();

    test()->getJson('/admin-api/customers/99999999')->assertStatus(404);
});

/* ------------------------------------------------------------------- export */

it('exports the current filtered view, not the whole table', function () {
    asAdmin();

    $buyer = tCustomer(['name' => 'T Exported Buyer']);
    tOrder($buyer, 12345);
    tAddress($buyer, ['city' => 'Dubai', 'country' => 'AE']);

    $skipped = tCustomer(['name' => 'T Not Exported']);

    $response = test()->get('/admin-api/customers/export?filter=ordered');

    expect($response->getStatusCode())->toBe(200)
        ->and($response->headers->get('Content-Type'))->toContain('text/csv')
        ->and($response->headers->get('Content-Disposition'))->toContain('customers-');

    $csv = $response->streamedContent();

    expect($csv)->toContain('T Exported Buyer')
        ->and($csv)->not->toContain('T Not Exported')
        // Both the exact integer and a spreadsheet-friendly decimal, so the
        // later import tooling has the fils and the owner has a number.
        ->and($csv)->toContain('12345')
        ->and($csv)->toContain('123.45')
        ->and($csv)->toContain('total_spent_fils');
});

it('does not let a customer name become a formula in the owner s spreadsheet', function () {
    asAdmin();

    // Every one of these is typed by the public at checkout.
    tCustomer(['name' => '=HYPERLINK("http://evil.test","click")', 'email' => 't-formula@example.test']);
    tCustomer(['name' => '+1234', 'email' => 't-plus@example.test']);
    tCustomer(['name' => '-1234', 'email' => 't-minus@example.test']);
    tCustomer(['name' => '@SUM(A1:A9)', 'email' => 't-at@example.test']);

    $csv = test()->get('/admin-api/customers/export')->streamedContent();

    // Quoted into a text cell, so none of the four is evaluated when opened.
    expect($csv)->toContain("'=HYPERLINK")
        ->and($csv)->toContain("'+1234")
        ->and($csv)->toContain("'-1234")
        ->and($csv)->toContain("'@SUM")
        // And the un-neutralised form is nowhere in the file.
        ->and($csv)->not->toContain(',=HYPERLINK')
        ->and($csv)->not->toContain(',@SUM');
});

/* ------------------------------------------------------------------ actions */

it('saves the owner s private note against a customer', function () {
    asAdmin();

    $customer = tCustomer();

    test()->postJson('/admin-api/customers/' . $customer->id . '/note', ['notes' => 'Prefers WhatsApp.'])
        ->assertOk()
        ->assertJson(['ok' => true]);

    expect((string) $customer->fresh()->notes)->toBe('Prefers WhatsApp.');

    expect(test()->getJson('/admin-api/customers/' . $customer->id)->json('customer.notes'))
        ->toBe('Prefers WhatsApp.');
});

it('refuses to trash a customer with order history until it is confirmed', function () {
    asAdmin();

    $customer = tCustomer();
    tOrder($customer, 45000);
    tOrder($customer, 15000);

    $refused = test()->deleteJson('/admin-api/customers/' . $customer->id);

    expect($refused->getStatusCode())->toBe(409)
        ->and($refused->json('needs_confirmation'))->toBeTrue()
        // The confirm dialog is only useful if it can say what is at stake.
        ->and($refused->json('orders'))->toBe(2)
        ->and($refused->json('spend_fils'))->toBe(60000);

    // Nothing happened.
    expect(Customer::query()->find($customer->id))->not->toBeNull();

    test()->deleteJson('/admin-api/customers/' . $customer->id . '?force=1')->assertOk();

    // Trashed, not destroyed — and the orders, which are financial records,
    // are untouched.
    expect(Customer::query()->find($customer->id))->toBeNull()
        ->and(Customer::withTrashed()->find($customer->id))->not->toBeNull()
        ->and(Order::query()->where('customer_id', $customer->id)->count())->toBe(2);

    test()->postJson('/admin-api/customers/' . $customer->id . '/restore')->assertOk();

    expect(Customer::query()->find($customer->id))->not->toBeNull();
});

it('trashes a customer with no history without arguing about it', function () {
    asAdmin();

    $customer = tCustomer();

    test()->deleteJson('/admin-api/customers/' . $customer->id)->assertOk();

    expect(Customer::query()->find($customer->id))->toBeNull();
});

it('applies the same rule to a bulk selection, and reports what it skipped', function () {
    asAdmin();

    $withOrders = tCustomer(['name' => 'T Bulk Buyer']);
    tOrder($withOrders, 9000);

    $a = tCustomer(['name' => 'T Bulk A']);
    $b = tCustomer(['name' => 'T Bulk B']);

    $ids = [$withOrders->id, $a->id, $b->id];

    $body = test()->postJson('/admin-api/customers/bulk-delete', ['ids' => $ids])->assertOk()->json();

    expect($body['deleted'])->toBe(2)
        ->and($body['skipped'])->toHaveCount(1)
        ->and($body['skipped'][0]['id'])->toBe($withOrders->id)
        ->and($body['skipped'][0]['orders'])->toBe(1)
        ->and(Customer::query()->find($withOrders->id))->not->toBeNull()
        ->and(Customer::query()->find($a->id))->toBeNull();

    // With force, the one that was held back goes too.
    test()->postJson('/admin-api/customers/bulk-delete', ['ids' => [$withOrders->id], 'force' => true])
        ->assertOk()
        ->assertJson(['deleted' => 1]);

    expect(Customer::query()->find($withOrders->id))->toBeNull();
});

it('will not let one bulk call empty the table', function () {
    asAdmin();

    test()->postJson('/admin-api/customers/bulk-delete', ['ids' => range(1, 501)])
        ->assertStatus(422);

    test()->postJson('/admin-api/customers/bulk-delete', ['ids' => []])
        ->assertStatus(422);
});

/* ------------------------------------------------------------ the N+1 proof */

it('runs a bounded number of queries however many customers there are', function () {
    asAdmin();

    // A realistic population, not two rows: an N+1 is invisible at two rows and
    // this screen is going to be pointed at 5,312 imported customers.
    $customers = [];

    foreach (range(1, 60) as $i) {
        $customer = tCustomer(['name' => 'T Load ' . $i]);
        $customers[] = $customer;

        tAddress($customer, ['city' => 'City ' . ($i % 7), 'country' => $i % 3 === 0 ? 'QA' : 'AE']);

        foreach (range(1, 3) as $j) {
            tOrder($customer, 1000 * $j, 'completed');
        }
    }

    // One unmeasured request first. The currency settings row is read through
    // a cache on the first call in a process and memoised after it, so without
    // this the first measurement carries a warm-up query the second does not
    // and the comparison below would be off by one for a reason that has
    // nothing to do with the customer list.
    test()->getJson('/admin-api/customers/list?per_page=50')->assertOk();

    DB::flushQueryLog();
    DB::enableQueryLog();

    test()->getJson('/admin-api/customers/list?per_page=50')->assertOk();

    $withFifty = count(DB::getQueryLog());

    DB::flushQueryLog();

    // Ten times the page size. A per-row query would multiply; a join does not.
    test()->getJson('/admin-api/customers/list?per_page=5')->assertOk();

    $withFive = count(DB::getQueryLog());

    DB::disableQueryLog();

    expect($withFifty)->toBeLessThanOrEqual(10, 'the customer list is running ' . $withFifty . ' queries for one page')
        // The same count for a tenth of the rows is what "not N+1" means. If
        // this ever differs, something is querying per row.
        ->and($withFive)->toBe($withFifty);

    // And the aggregates are still right at this size, so the bound was not
    // bought by not computing anything.
    $row = collect(test()->getJson('/admin-api/customers/list?sort=oldest&per_page=1')->json('customers'))->first();

    expect($row['orders'])->toBe(3)->and($row['spend_fils'])->toBe(6000);
});

it('runs a bounded number of queries on the detail page too', function () {
    asAdmin();

    $customer = tCustomer();

    foreach (range(1, 40) as $i) {
        tOrder($customer, 1000 + $i, 'completed');
    }

    foreach (range(1, 5) as $i) {
        tAddress($customer, ['city' => 'City ' . $i, 'is_default' => false]);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();

    test()->getJson('/admin-api/customers/' . $customer->id)->assertOk();

    $count = count(DB::getQueryLog());

    DB::disableQueryLog();

    /*
     * The customer, their orders, their addresses. Not one query per order.
     *
     * 6 -> 7: the spend figures now exclude demo orders, and DemoSeed asks once
     * per request whether `demo_seed_log` exists before it joins to it -- the
     * table is created lazily by DemoContentController::ensureTable(), so a
     * build whose migration step was skipped must degrade rather than 500. That
     * is one CONSTANT statement, which is the kind of change this bound is meant
     * to permit; the property it exists to defend is unchanged and still pinned
     * by the list test above, where the same count for a tenth of the rows is
     * what "not N+1" means.
     */
    expect($count)->toBeLessThanOrEqual(7, 'the customer detail page is running ' . $count . ' queries');
});

/* ---------------------------------------------------------------- the screen */

it('replaces the six-column preview screen in the admin console', function () {
    $source = (string) file_get_contents(resource_path('views/admin/app.blade.php'));

    // `emirate` is not a column on `customers` and never was, so the old
    // screen's third column was blank on every install.
    expect($source)->not->toContain("'<td>'+(c.emirate||'\\u2014')+'</td>'")
        ->and($source)->not->toContain("api('/admin-api/customers')");

    $start = strpos($source, 'LANE T · Store · Customers — BEGIN');
    $end = strpos($source, 'LANE T · Store · Customers — END');

    expect($start)->not->toBeFalse('the Lane T region is missing from the admin view')
        ->and($end)->not->toBeFalse('the Lane T region is not closed');

    $region = substr($source, (int) $start, (int) $end - (int) $start);

    expect($region)->toContain('window.renderCustomers = async function()')
        ->toContain("api('/admin-api/customers/list?'")
        ->toContain("fixAdminApiUrl('/admin-api/customers/export')")
        // Customer names, emails and cities are typed by the public and land in
        // innerHTML. Everything written into the page goes through sesc().
        ->toContain('sesc(')
        // A destructive action never happens on a click alone.
        ->toContain('cuConfirmDelete')
        ->toContain('force=1');
});

it('renders the whole admin document with the customers screen in it', function () {
    // One 600KB Blade file with several lanes editing it at once: a stray brace
    // here takes down the entire admin console, not just this screen.
    $html = view('admin.app')->render();

    expect($html)->toContain('window.renderCustomers = async function()')
        ->toContain('/admin-api/customers/list');
});
