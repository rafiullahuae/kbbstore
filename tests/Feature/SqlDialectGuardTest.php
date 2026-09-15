<?php

declare(strict_types=1);

/*
 * The whole suite runs on SQLite; the store runs on MySQL. Every endpoint here
 * answered 200 in the SQLite suite while returning a 500 on the live server,
 * or would have on the next filter the operator touched.
 *
 * So these tests do not check the response. They attach a query log, drive the
 * endpoint, and judge the SQL it issued — see Tests\Support\SqlShape for the
 * rules and why each one is a MySQL failure. That makes them dialect guards
 * that need no MySQL to run, which is the point: phpunit-mysql.xml runs this
 * same suite against a real server (docs/MYSQL-PARITY.md), and these keep
 * working on a laptop, in CI, and in the SQLite run every other lane does.
 *
 * Each endpoint below is one a shopper or the owner reaches on an ordinary day.
 * Adding a screen to this list costs two lines and is the cheapest insurance in
 * the repo.
 */

use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\MailCredential;
use App\Models\Order;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\Review;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\CustomersAdminRoutes;
use Tests\Support\SqlShape;

/**
 * Enough of a catalogue and an order history that the aggregates, the facets
 * and the joins all have rows to work on.
 *
 * A guard that runs against an empty database proves nothing: the query still
 * compiles, but several of these screens skip whole branches when a count comes
 * back zero.
 */
function guardFixtures(): void
{
    Cache::flush();

    $brand = Brand::create(['name' => 'Guard Brand', 'slug' => 'guard-brand-' . uniqid()]);
    $category = Category::create(['name' => 'Guard Cat', 'slug' => 'guard-cat-' . uniqid()]);

    foreach ([1, 2, 3] as $n) {
        $product = Product::create([
            'slug' => 'guard-p-' . $n . '-' . uniqid(),
            'name' => 'Guard Product ' . $n,
            'status' => 'publish',
            'is_visible' => true,
            'price' => 10000 * $n,
            'stock_status' => 'instock',
            'brand_id' => $brand->id,
            'category_id' => $category->id,
        ]);

        Review::create([
            'product_id' => $product->id,
            'author_name' => 'Guard Reviewer ' . $n,
            'rating' => $n + 2,
            'content' => 'Guard review ' . $n,
            'status' => 'approved',
        ]);
    }

    /*
     * More than PER_PAGE_MIN customers, deliberately.
     *
     * clampPerPage() floors per_page at 10, so `?per_page=1&page=2` against a
     * three-row fixture is silently clamped back to page one and proves
     * nothing — the pagination assertions below need a second page that really
     * exists.
     */
    foreach (range(1, 14) as $n) {
        $customer = Customer::create([
            'name' => 'Guard Customer ' . $n,
            'email' => 'guard-' . $n . '-' . uniqid() . '@example.test',
        ]);

        Order::create([
            'customer_id' => $customer->id,
            'order_number' => 'GUARD-' . $n . '-' . uniqid(),
            'email' => $customer->email,
            'status' => 'completed',
            'total' => 25000 * $n,
            'subtotal' => 25000 * $n,
        ]);
    }
}

function guardAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'Guard Owner',
        'email' => 'guard-owner-' . uniqid() . '@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
}

/* ------------------------------------------------------------------ storefront */

/**
 * Every page an unauthenticated shopper can reach that runs a grouped or
 * aggregated query: the home rails and review wall, the archive facets, the
 * brand index with its per-brand counts, the product page rating breakdown and
 * the search suggester.
 */
it('issues portable SQL on the storefront pages that aggregate', function (string $url) {
    guardFixtures();

    $product = Product::query()->where('status', 'publish')->firstOrFail();

    $url = str_replace('{slug}', (string) $product->slug, $url);

    $captured = SqlShape::capture(function () use ($url) {
        $this->get($url)->assertOk();
    });

    expect($captured)->not->toBeEmpty("no SQL was issued for {$url}");
    expect(SqlShape::violations($captured))->toBe([], "portability violations on {$url}");
})->with([
    '/',
    '/shop',
    '/shop?s=Guard',
    '/shop?orderby=price',
    '/product/{slug}',
    '/api/search?q=Guard',
]);

/* ---------------------------------------------------------------------- admin */

/**
 * The admin screens the owner opens first. /admin-api/stats and /analytics are
 * the dashboard; /customers, /orders, /products and /reviews are the four list
 * screens. All of them aggregate.
 */
it('issues portable SQL on the admin screens that aggregate', function (string $url) {
    guardFixtures();

    $admin = guardAdmin();

    $captured = SqlShape::capture(function () use ($admin, $url) {
        $this->actingAs($admin, 'admin')->getJson($url)->assertOk();
    });

    expect($captured)->not->toBeEmpty("no SQL was issued for {$url}");
    expect(SqlShape::violations($captured))->toBe([], "portability violations on {$url}");
})->with([
    '/admin-api/stats',
    '/admin-api/analytics',
    '/admin-api/products',
    '/admin-api/orders',
    '/admin-api/reviews',
    '/admin-api/customers',
    '/admin-api/quiz-leads',
]);

/**
 * Store → Customers, the screen the 1140 actually shipped on, across the sorts
 * and chips that change the shape of the statement.
 *
 * `sort` is the one that matters: applySort() mutates the Builder in place and
 * index() then hands that same Builder to summaryFor(), so the ORDER BY it adds
 * ends up on an aggregate query with no GROUP BY.
 */
it('issues portable SQL on the customers screen for every sort and chip', function (string $query) {
    guardFixtures();

    CustomersAdminRoutes::wire(app());

    $admin = guardAdmin();

    $captured = SqlShape::capture(function () use ($admin, $query) {
        $this->actingAs($admin, 'admin')->getJson('/admin-api/customers/list?' . $query)->assertOk();
    });

    expect($captured)->not->toBeEmpty();
    expect(SqlShape::violations($captured))->toBe([], "portability violations on ?{$query}");
})->with([
    'sort=newest',
    'sort=oldest',
    'sort=name',
    'sort=spend_desc',
    'sort=spend_asc',
    'sort=orders_desc',
    'sort=aov_desc',
    'sort=last_order_desc',
    'sort=last_active_desc',
    'segment=ordered',
    'segment=never',
    'segment=repeat',
    'segment=trashed',
    'search=Guard',
    'page=2&per_page=10',
]);

/**
 * The summary tiles describe the filtered set, not the current page of it.
 *
 * index() runs forPage() on the Builder it later hands to summaryFor(), so the
 * aggregate inherited `offset 25` on page two — and an aggregate returns one
 * row, so skipping any rows returned none and every tile read zero. It was
 * wrong on SQLite too; nothing asserted it, because the endpoint answered 200
 * and the shape guard above is what makes it visible.
 */
it('reports the same summary on every page of the customers list', function () {
    guardFixtures();

    CustomersAdminRoutes::wire(app());

    $admin = guardAdmin();

    // per_page=10 is clampPerPage()'s floor, and the fixture has 14 customers,
    // so page two is a real second page rather than a clamped first one.
    $first = $this->actingAs($admin, 'admin')
        ->getJson('/admin-api/customers/list?per_page=10&page=1')
        ->assertOk()
        ->json('summary');

    $second = $this->actingAs($admin, 'admin')
        ->getJson('/admin-api/customers/list?per_page=10&page=2')
        ->assertOk()
        ->json('summary');

    expect($second)->toBe($first)
        ->and($second['customers'])->toBeGreaterThan(0)
        ->and($second['spend_fils'])->toBeGreaterThan(0);
});

/* ------------------------------------------------------------------ the guard */

/**
 * The guard has to fail on the statements it was written for, or it is decor.
 */
it('rejects the statement shapes that took the store down', function () {
    $cases = [
        // The original Customers 500.
        'select count(*) as c, "customers"."email" from "customers"' => '1140',
        // The ORDER BY that survived the first fix.
        'select count(*) as c from "customers" order by "customers"."id" desc' => 'ORDER BY',
        // The paginated summary that silently read zero.
        'select count(*) as c from "customers" limit 1 offset 25' => 'OFFSET',
        // SQLite-only date maths.
        'select strftime(\'%Y\', "created_at") from "orders"' => 'strftime',
        // Concatenation that turns into a boolean on MySQL.
        'select "first_name" || \' \' || "last_name" from "customers"' => '||',
        // An alias MySQL cannot see in WHERE.
        'select "price" * 2 as doubled from "products" where doubled > 10' => 'alias',
    ];

    foreach ($cases as $sql => $expected) {
        $found = SqlShape::violations([['sql' => $sql, 'bindings' => []]]);

        expect($found)->not->toBe([], "no violation reported for: {$sql}");
        expect(implode("\n", $found))->toContain($expected);
    }
});

/** And it has to stay quiet on SQL that is already portable. */
it('passes statements that are portable', function () {
    $fine = [
        'select count(*) as aggregate from "customers"',
        'select "brands"."id", count("products"."id") as n from "brands" left join "products" on "products"."brand_id" = "brands"."id" group by "brands"."id" order by "brands"."name" asc',
        'select "id", "name" from "products" where "status" = ? limit 10 offset 25',
        'select count(*) as c from "orders" where "status" = ?',
    ];

    foreach ($fine as $sql) {
        $bindings = array_fill(0, substr_count($sql, '?'), 'x');

        expect(SqlShape::violations([['sql' => $sql, 'bindings' => $bindings]]))
            ->toBe([], "false positive on: {$sql}");
    }
});

/**
 * The "never active" sentinel must never reach the screen as a date.
 *
 * CustomersApiController marks "no activity ever" with 1970-01-01 00:00:00,
 * because MySQL's GREATEST() and SQLite's max() both return NULL if any
 * argument is NULL and every never-active customer would otherwise sort with
 * the most recent one. iso() is what turns that sentinel back into null.
 *
 * It used to do so with an exact string compare, and that is driver-dependent.
 * lastActiveExpression() is a CASE, a CASE takes the widest type of its
 * branches, and on MySQL 8 those branches are DATETIME(6) — so the sentinel
 * came back as '1970-01-01 00:00:00.000000', the compare missed, and every
 * customer who had never ordered showed "1 Jan 1970" as their last activity.
 * SQLite and MariaDB 10.11 both return the plain 19-character string, so only
 * the production engine shows it.
 *
 * Asserted against the response rather than against the helper, so it holds on
 * whichever driver the suite is pointed at.
 */
it('never reports the epoch sentinel as a real date', function () {
    guardFixtures();

    CustomersAdminRoutes::wire(app());

    // A customer with no order and no cart: every activity column is NULL, so
    // every branch of the CASE falls through to the sentinel.
    $idle = Customer::create([
        'name' => 'Idle Customer',
        'email' => 'idle-' . uniqid() . '@example.test',
    ]);

    $body = $this->actingAs(guardAdmin(), 'admin')
        ->getJson('/admin-api/customers/list?per_page=500')
        ->assertOk();

    $row = collect($body->json('customers'))->firstWhere('id', $idle->id);

    expect($row)->not->toBeNull()
        ->and($row['last_active_at'])->toBeNull()
        ->and($row['last_order_at'])->toBeNull();

    // And not anywhere else in the payload either, whatever the column.
    expect($body->getContent())->not->toContain('1970-01-01');
});

/* ------------------------------------------------------------------ sql_mode */

/**
 * When the suite IS on MySQL, it must be on a strict one.
 *
 * phpunit-mysql.xml exists to catch what SQLite cannot, and a permissive
 * sql_mode gives most of that back: without ONLY_FULL_GROUP_BY the 1140 that
 * took the Customers screen down never fires, and without STRICT_TRANS_TABLES
 * an over-long or badly typed value is truncated with a warning instead of an
 * error — the same false green in a different costume.
 *
 * The live host's mode is unknown, so the suite is pinned to the strictest
 * realistic setting rather than to a guess. Laravel's `strict => true` on the
 * mysql connection is what issues it; this asserts the connection actually
 * arrived that way rather than trusting the config file.
 */
it('runs MySQL with ONLY_FULL_GROUP_BY and STRICT_TRANS_TABLES', function () {
    $driver = DB::connection()->getDriverName();

    if (! in_array($driver, ['mysql', 'mariadb'], true)) {
        expect($driver)->toBe('sqlite');

        return;
    }

    $mode = (string) DB::selectOne('SELECT @@SESSION.sql_mode AS mode')->mode;

    expect($mode)->toContain('ONLY_FULL_GROUP_BY')
        ->and($mode)->toContain('STRICT_TRANS_TABLES');
});

/**
 * A column holding ciphertext must not be a JSON column.
 *
 * `encrypted:array` stores base64(json_encode(['iv' => ..., 'value' => ...])) —
 * a base64 blob, not JSON. A JSON column validates every write, so this was
 * SQLSTATE 23000 / 4025 on MariaDB and is 22032 / 3140 on MySQL 8, and the
 * owner could not save a gateway key or an SMTP password at all. SQLite treats
 * `json` as `text`, so the suite passed while the feature was dead in
 * production. 2026_09_22_000000_widen_encrypted_config_columns is the repair.
 *
 * Asserted by writing through the real cast rather than by reading the column
 * type, so it holds on either driver and would also catch a new encrypted
 * column added to a JSON field later.
 */
it('stores an encrypted config on whatever driver is running', function () {
    $secret = ['secret_key' => str_repeat('sk_live_x', 40)];

    $provider = PaymentProvider::query()->firstOrNew(['id' => 'stripe']);
    $provider->forceFill(['id' => 'stripe', 'config' => $secret])->save();

    expect(PaymentProvider::query()->find('stripe')?->config)->toBe($secret);

    $password = ['password' => str_repeat('p@ss', 60)];

    $mail = MailCredential::query()->firstOrNew(['id' => 'smtp']);
    $mail->forceFill(['id' => 'smtp', 'config' => $password])->save();

    expect(MailCredential::query()->find('smtp')?->config)->toBe($password);
});
