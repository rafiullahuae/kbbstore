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
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\MailCredential;
use App\Models\Order;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\Review;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Support\CatalogProductsAdminRoutes;
use Tests\Support\CustomersAdminRoutes;
use Tests\Support\MediaLibraryRoutes;
use Tests\Support\HtmlBlocksAdminRoutes;
use Tests\Support\OrdersAdminRoutes;
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

/* ------------------------------------------------------------- Store → Orders */

/**
 * The Orders screen shipped with the SAME defect, copied.
 *
 * Lane V branched before the Customers fix landed and took the half-finished
 * aggregate() helper with it — the version that discards the select columns but
 * leaves the ORDER BY, LIMIT and OFFSET attached. The live screen returned the
 * identical MySQL 1140. These guards did not catch it because the endpoint list
 * above names the OLD /admin-api/orders, and the new /admin-api/orders-list did
 * not exist when they were written.
 *
 * Both controllers now share App\Support\AggregatesQueries, and this covers the
 * new endpoint the way the Customers one is covered, so a third screen cannot
 * repeat it unnoticed.
 */
function ordersGuardFixture(): void
{
    Cache::flush();

    // Enough rows that page two is real, spread across statuses so the chips
    // and the revenue/non-revenue split both have something to count.
    $statuses = ['completed', 'processing', 'onhold', 'shipped', 'pending', 'cancelled', 'refunded'];

    foreach (range(1, 14) as $i) {
        Order::create([
            'order_number' => 'GRD-' . str_pad((string) $i, 5, '0', STR_PAD_LEFT),
            'email' => "guard{$i}@kbb.test",
            'status' => $statuses[$i % count($statuses)],
            'currency' => 'AED',
            'subtotal' => $i * 1000,
            'total' => $i * 1000,
            'created_at' => now()->subDays($i),
            'updated_at' => now()->subDays($i),
        ]);
    }
}

it('issues portable SQL on the orders screen for every sort and chip', function (string $query) {
    ordersGuardFixture();

    OrdersAdminRoutes::wire(app());

    $admin = guardAdmin();

    $captured = SqlShape::capture(function () use ($admin, $query) {
        $this->actingAs($admin, 'admin')->getJson('/admin-api/orders-list?' . $query)->assertOk();
    });

    expect($captured)->not->toBeEmpty();
    expect(SqlShape::violations($captured))->toBe([], "portability violations on ?{$query}");
})->with([
    'sort=newest',
    'sort=oldest',
    'sort=number',
    'sort=total_desc',
    'sort=total_asc',
    'sort=units_desc',
    'sort=status',
    'sort=customer',
    'status=completed',
    'status=revenue',
    'status=trashed',
    'search=GRD',
    'page=2&per_page=10',
]);

it('reports the same summary on every page of the orders list', function () {
    ordersGuardFixture();

    OrdersAdminRoutes::wire(app());

    $admin = guardAdmin();

    $first = $this->actingAs($admin, 'admin')
        ->getJson('/admin-api/orders-list?per_page=10&page=1')
        ->assertOk()
        ->json('summary');

    $second = $this->actingAs($admin, 'admin')
        ->getJson('/admin-api/orders-list?per_page=10&page=2')
        ->assertOk()
        ->json('summary');

    expect($second)->toBe($first)
        ->and($second['orders'])->toBeGreaterThan(0);
});

/* ------------------------------------------------- Lane X: the whole surface */

/**
 * THE GAP THAT LET THE ORDERS BUG THROUGH WAS A LIST.
 *
 * Every guard above names its endpoint by hand. /admin-api/orders-list did not
 * exist when the earlier ones were written, so the screen shipped the identical
 * MySQL 1140 with a green suite — not because a rule was missing, but because
 * nobody added two lines to an array. A guard whose coverage depends on somebody
 * remembering to extend it has a known failure mode, and that failure mode has
 * now fired twice.
 *
 * So coverage is not a list here. It is the router.
 *
 * This walks every admin-api GET route that takes no parameters, drives it, and
 * judges the SQL it issued. A screen added next month is covered the day its
 * route is registered, by nobody doing anything. The hand-written blocks above
 * stay, because they exercise the sorts, chips and second pages that a bare GET
 * of the same path never reaches — the router can enumerate paths, not
 * meaningful query strings.
 *
 * Endpoints that stream (the CSV exports) have their body consumed on purpose:
 * a StreamedResponse runs no query until something reads it, so asserting
 * against an unread stream asserts against nothing at all.
 */
function guardAdminApiGetPaths(): array
{
    $paths = [];

    foreach (RouteFacade::getRoutes()->getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'admin-api/')) {
            continue;
        }

        if (! in_array('GET', $route->methods(), true)) {
            continue;
        }

        if (str_contains($route->uri(), '{')) {
            continue;
        }

        $paths[$route->uri()] = true;
    }

    ksort($paths);

    return array_keys($paths);
}

it('issues portable SQL on every parameterless admin-api GET route', function () {
    guardFixtures();
    ordersGuardFixture();

    /*
     * Lane files that routes/web.php does not require yet are mounted here
     * BEFORE the router is walked.
     *
     * The walk is only as complete as the route table it reads, and a lane
     * whose file ships unmounted is not in that table — so "the router covers
     * new screens automatically" would be true of every screen except the ones
     * that have not been wired in yet, which is precisely the set most likely
     * to carry a new defect. Mounting them here closes that hole: Catalog →
     * Products is driven by this walk today, and stays driven by it after the
     * integrator adds the require line.
     */
    CatalogProductsAdminRoutes::wire(app());

    $admin = guardAdmin();

    $paths = guardAdminApiGetPaths();

    // The screens this lane added really are in the walk, rather than being
    // silently absent and leaving the test green for having found nothing.
    expect($paths)->toContain('admin-api/catalog-products-list')
        ->and($paths)->toContain('admin-api/catalog-products-export')
        ->and($paths)->toContain('admin-api/catalog-products-facets');

    // A router that returned nothing would make this test vacuously green, which
    // is the exact shape of failure it exists to prevent.
    expect(count($paths))->toBeGreaterThan(30);

    $failures = [];

    foreach ($paths as $uri) {
        $status = null;

        $captured = SqlShape::capture(function () use ($admin, $uri, &$status) {
            $response = $this->actingAs($admin, 'admin')->get('/' . $uri);

            $status = $response->getStatusCode();

            // Force a streamed body to actually run. Without this the export
            // endpoints report zero statements and pass by doing nothing.
            if ($response->baseResponse instanceof StreamedResponse) {
                $response->streamedContent();
            }
        });

        if ($status >= 500) {
            $failures[] = "{$uri}: responded {$status}";
        }

        foreach (SqlShape::violations($captured) as $problem) {
            $failures[] = "{$uri}: {$problem}";
        }
    }

    expect($failures)->toBe([]);
});

/**
 * And the parameterised ones cannot be quietly skipped.
 *
 * The walk above cannot invent an order id, so it passes over every route with a
 * {placeholder} in it — including /admin-api/orders/{id}, which is a real screen
 * reading real money. Listing them by hand would reintroduce exactly the stale
 * list this file is trying to get rid of, so instead: every parameterised route
 * is either DRIVEN below with resolved parameters, or named in the skip list
 * with a reason. A new one that is neither fails here, which forces the decision
 * to be made rather than defaulted.
 */
it('drives or explicitly excuses every parameterised admin-api GET route', function () {
    guardFixtures();

    // Same reason as the walk above: a route file that is not mounted is a
    // route file this test cannot make anybody account for.
    CatalogProductsAdminRoutes::wire(app());

    // Content → Media Library (Lane AX). Wired here for exactly that reason:
    // its routes ship in their own file for the integrator to mount, and until
    // somebody mounts them this guard would let GET /admin-api/media/{media}
    // through unexamined — then fail on the integrator's commit instead of the
    // one that wrote the query.
    MediaLibraryRoutes::wire(app());

    // Content → HTML Blocks (Lane BC). Wired for the same reason: its routes
    // ship in their own file, and GET /admin-api/blocks/{block} resolves where
    // each block is used by scanning pages and posts — a parameterised admin
    // GET that must not skip this walk unexamined.
    HtmlBlocksAdminRoutes::wire(app());

    $admin = guardAdmin();

    $product = Product::query()->firstOrFail();
    $category = Category::query()->firstOrFail();
    $customer = Customer::query()->firstOrFail();
    $order = Order::query()->firstOrFail();

    /*
     * The coupons screen reads redemptions grouped per coupon, so it is the
     * shape that has produced a dialect failure twice in this repo already.
     * Created here rather than in the seed above because nothing else in this
     * file needs one.
     */
    $review = Review::query()->firstOrFail();

    /*
     * One media row for the Media Library detail route below. Created here
     * rather than in the seed because nothing else in this file needs one, and
     * attached to a product so the usage resolution actually has something to
     * find — driving it against an image nobody uses would exercise the empty
     * branch and prove the least interesting half.
     */
    $media = \App\Models\Media::create([
        'filename' => 'guard-shot.png',
        'original_name' => 'Guard Shot.png',
        'path' => 'uploads/products/guard-shot.png',
        'mime' => 'image/png',
        'size' => 4096,
        'width' => 800,
        'height' => 600,
        'alt' => '',
    ]);

    $product->forceFill(['image' => '/uploads/products/guard-shot.png'])->save();

    $block = \App\Models\Block::create([
        'name' => 'Guard Block',
        'slug' => 'guard-block-' . uniqid(),
        'content' => '<p>Guard</p>',
        'status' => 'published',
    ]);

    $coupon = Coupon::create([
        'code' => 'GUARD-' . uniqid(),
        'type' => 'percent',
        'amount' => 1000,
        'usage_limit' => 5,
        'usage_count' => 0,
        // No is_active column on this table -- a coupon's state is its
        // starts_at/expires_at window plus its usage, which is what
        // CouponService::validate() actually reads.
        'starts_at' => now()->subDay(),
        'expires_at' => now()->addYear(),
    ]);

    /** Route URI => the concrete path to drive it with. */
    $driven = [
        // Driven rather than excused: it reads payment_providers, so it does
        // issue SQL, and a gateway key is a fixed string that needs no fixture.
        'admin-api/payments/preflight/{gateway}' => '/admin-api/payments/preflight/cod',
        'admin-api/products/{id}' => '/admin-api/products/' . $product->id,
        'admin-api/orders/{id}' => '/admin-api/orders/' . $order->id,
        'admin-api/orders/{id}/detail' => '/admin-api/orders/' . $order->id . '/detail',
        'admin-api/orders/{id}/settlement' => '/admin-api/orders/' . $order->id . '/settlement',
        'admin-api/customers/{id}' => '/admin-api/customers/' . $customer->id,
        'admin-api/catalog-products-detail/{id}' => '/admin-api/catalog-products-detail/' . $product->id,
        'admin-api/catalog/reorder/{type}/{id}/products' => '/admin-api/catalog/reorder/category/' . $category->id . '/products',
        // Both render a whole order with its items and addresses, so they are
        // exactly the shape that has produced a dialect failure twice.
        'admin-api/orders/{id}/invoice' => '/admin-api/orders/' . $order->id . '/invoice',
        'admin-api/orders/{id}/packing-slip' => '/admin-api/orders/' . $order->id . '/packing-slip',
        // Groups redemptions per coupon and counts them beside the row, which
        // is the aggregate-plus-row shape MySQL's ONLY_FULL_GROUP_BY rejects.
        'admin-api/coupons/{coupon}' => '/admin-api/coupons/' . $coupon->id,
        /*
         * The coupon EDITOR's read of one code (Lane BT). Resolves the product
         * and category selections out of two JSON columns and counts the
         * redemption rows beside the row itself -- the aggregate-plus-row shape
         * ONLY_FULL_GROUP_BY rejects, on a different table from the report
         * above.
         */
        'admin-api/coupons/manage/{coupon}' => '/admin-api/coupons/manage/' . $coupon->id,
        // Reads one review with its product, and recomputes nothing -- but it
        // is a parameterised admin GET and the point of this list is that no
        // such route gets to skip the walk unexamined.
        'admin-api/reviews/{review}' => '/admin-api/reviews/' . $review->id,
        // Loads one product with its brand, its categories pivot, its gallery
        // and its SEO blob for the editor — several joins and a json column,
        // which is exactly the shape that has produced a dialect failure here.
        'admin-api/product-editor-load/{id}' => '/admin-api/product-editor-load/' . $product->id,
        /*
         * Reads one image and then resolves WHERE IT IS USED, which walks every
         * product, brand and category — three cursor()ed queries whose results
         * are matched in PHP. No aggregate and no GROUP BY, so it is not the
         * 1140 shape; it is here because it is a parameterised admin GET and
         * the point of this list is that no such route skips the walk
         * unexamined.
         */
        'admin-api/media/{media}' => '/admin-api/media/' . $media->id,
        /*
         * Reads one block and then resolves WHERE IT IS PLACED, scanning
         * pages.content and posts.body with the renderer's own regex. Same
         * shape as the media row above: no aggregate, no GROUP BY, listed
         * because it is a parameterised admin GET and nothing of that shape
         * skips this walk unexamined.
         */
        'admin-api/blocks/{block}' => '/admin-api/blocks/' . $block->id,
    ];

    /** Route URI => why driving it here would prove nothing. */
    $excused = [
        // Serves a zip from disk. Touches no query and cannot carry a dialect
        // problem; driving it would assert against a 404 for a missing file.
        'admin-api/updates/{release}/download' => 'file download, issues no SQL',
    ];

    $parameterised = [];

    foreach (RouteFacade::getRoutes()->getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'admin-api/')) {
            continue;
        }

        if (! in_array('GET', $route->methods(), true)) {
            continue;
        }

        if (! str_contains($route->uri(), '{')) {
            continue;
        }

        $parameterised[$route->uri()] = true;
    }

    $unhandled = array_values(array_diff(
        array_keys($parameterised),
        array_keys($driven),
        array_keys($excused)
    ));

    expect($unhandled)->toBe([], 'parameterised admin-api GET routes with no dialect coverage and no recorded reason: '
        . implode(', ', $unhandled));

    $failures = [];

    foreach ($driven as $uri => $path) {
        // Only drive what is actually registered — a route this lane resolved
        // that another lane later renames should fail on the list above, with a
        // readable message, not here with a 404.
        if (! isset($parameterised[$uri])) {
            continue;
        }

        $status = null;

        $captured = SqlShape::capture(function () use ($admin, $path, &$status) {
            $status = $this->actingAs($admin, 'admin')->get($path)->getStatusCode();
        });

        if ($status >= 500) {
            $failures[] = "{$path}: responded {$status}";
        }

        foreach (SqlShape::violations($captured) as $problem) {
            $failures[] = "{$path}: {$problem}";
        }
    }

    expect($failures)->toBe([]);
});

/**
 * Catalog → Products, across its sorts, chips, search and second page.
 *
 * Same treatment the Customers and Orders screens get above. This screen builds
 * its page from a Builder it has already sorted, and it reports a `total` and a
 * `counts` block alongside the rows — the exact arrangement that produced the
 * 1140 twice. It happens to be correct today; nothing said so until now.
 */
it('issues portable SQL on the catalog products screen for every sort and chip', function (string $query) {
    guardFixtures();

    $admin = guardAdmin();

    $captured = SqlShape::capture(function () use ($admin, $query) {
        $this->actingAs($admin, 'admin')->getJson('/admin-api/catalog/products?' . $query)->assertOk();
    });

    expect($captured)->not->toBeEmpty();
    expect(SqlShape::violations($captured))->toBe([], "portability violations on ?{$query}");
})->with([
    'sort=newest',
    'sort=name',
    'sort=price_desc',
    'sort=stock_asc',
    'filter=all',
    'filter=published',
    'filter=draft',
    'filter=low',
    'filter=out',
    'search=Guard',
    'page=2&per_page=10',
]);

/**
 * The tab counts and the row total describe the filtered set, not the page.
 *
 * This is the assertion nobody had written for the Customers screen, which is
 * why `offset 25` on an aggregate went unnoticed: every tile read zero from page
 * two on and the endpoint still answered 200.
 */
it('reports the same counts on every page of the catalog products list', function () {
    guardFixtures();

    // Enough products that page two is real at the per_page floor of 10.
    foreach (range(1, 14) as $n) {
        Product::create([
            'slug' => 'paged-' . $n . '-' . uniqid(),
            'name' => 'Paged Product ' . $n,
            'status' => 'publish',
            'is_visible' => true,
            'price' => 1000 * $n,
            'stock_status' => 'instock',
        ]);
    }

    $admin = guardAdmin();

    $first = $this->actingAs($admin, 'admin')
        ->getJson('/admin-api/catalog/products?per_page=10&page=1')->assertOk();

    $second = $this->actingAs($admin, 'admin')
        ->getJson('/admin-api/catalog/products?per_page=10&page=2')->assertOk();

    expect($second->json('counts'))->toBe($first->json('counts'))
        ->and($second->json('total'))->toBe($first->json('total'))
        ->and($second->json('total'))->toBeGreaterThan(10);
});

/**
 * Catalog → Products, rebuilt (Lane AF), across every sort, chip, search and
 * second page.
 *
 * This screen now reports a summary and eleven-plus chip counts beside a page
 * of rows, all built from Builders it also sorts and pages — the exact
 * arrangement that produced the MySQL 1140 on Customers and then again on
 * Orders. The counts go through App\Support\AggregatesQueries; these drive the
 * combinations the bare GET in the route walk above cannot reach, because the
 * router can enumerate paths and not meaningful query strings.
 *
 * The chip list is not hard-coded here either: it is read back off the
 * endpoint, so a chip added to the controller is covered the day it exists.
 */
function catalogProductsGuardFixture(): void
{
    Cache::flush();

    $brand = Brand::create(['name' => 'CPG Brand', 'slug' => 'cpg-brand-'.uniqid()]);
    $category = Category::create(['name' => 'CPG Category', 'slug' => 'cpg-cat-'.uniqid()]);

    $statuses = ['publish', 'draft', 'private', 'publish'];
    $stock = ['instock', 'outofstock', 'onbackorder', 'instock'];

    foreach (range(1, 14) as $i) {
        $product = Product::create([
            'slug' => 'cpg-'.$i.'-'.uniqid(),
            'name' => 'CPG Product '.$i,
            'sku' => 'CPG-'.$i,
            'status' => $statuses[$i % 4],
            'is_visible' => $i % 5 !== 0,
            'featured' => $i % 6 === 0,
            'brand_id' => $i % 3 === 0 ? null : $brand->id,
            'price' => $i % 7 === 0 ? null : 1000 * $i,
            'sale_price' => $i % 4 === 0 ? 500 * $i : null,
            'manage_stock' => $i % 2 === 0,
            'stock' => $i,
            'stock_status' => $stock[$i % 4],
            'image' => $i % 3 === 0 ? null : 'https://cdn.test/cpg-'.$i.'.jpg',
            'wc_id' => 900000 + $i,
        ]);

        if ($i % 2 === 0) {
            $product->categories()->attach($category->id);
        }
    }

    // A status the schema has no concept of, written by the importer this repo
    // replaced. The chips are built from the column, so it gets one.
    DB::table('products')->insert([
        'name' => 'CPG Legacy', 'slug' => 'cpg-legacy-'.uniqid(), 'status' => 'active',
        'is_visible' => 1, 'type' => 'simple', 'stock_status' => 'instock',
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

it('issues portable SQL on the rebuilt products screen for every sort and chip', function (string $query) {
    catalogProductsGuardFixture();

    CatalogProductsAdminRoutes::wire(app());

    $admin = guardAdmin();

    $captured = SqlShape::capture(function () use ($admin, $query) {
        $this->actingAs($admin, 'admin')->getJson('/admin-api/catalog-products-list?'.$query)->assertOk();
    });

    expect($captured)->not->toBeEmpty();
    expect(SqlShape::violations($captured))->toBe([], "portability violations on ?{$query}");
})->with([
    'sort=newest',
    'sort=oldest',
    'sort=updated',
    'sort=name',
    'sort=name_desc',
    'sort=sku',
    'sort=price_desc',
    'sort=price_asc',
    'sort=stock_asc',
    'sort=stock_desc',
    'sort=status',
    'sort=brand',
    'sort=orders_desc',
    'sort=sales_desc',
    'sort=position',
    'filter=all',
    'filter=publish',
    'filter=draft',
    'filter=private',
    'filter=active',
    'filter=instock',
    'filter=outofstock',
    'filter=onbackorder',
    'filter=low',
    'filter=hidden',
    'filter=featured',
    'filter=no_image',
    'filter=no_price',
    'filter=no_category',
    'filter=on_sale',
    'filter=trashed',
    'search=CPG',
    'search=900001',
    'brand_id=1',
    'category_id=1',
    'price_min=10&price_max=100',
    'page=2&per_page=10',
]);

it('covers every chip the rebuilt products screen offers, not a list written by hand', function () {
    catalogProductsGuardFixture();

    CatalogProductsAdminRoutes::wire(app());

    $admin = guardAdmin();

    $body = $this->actingAs($admin, 'admin')
        ->getJson('/admin-api/catalog-products-list')->assertOk()->json();

    /*
     * THE GAP THAT LET THE ORDERS BUG THROUGH WAS A LIST. The dataset above is
     * one, so this reads the chips back off the endpoint and drives each of
     * them. A chip added to the controller next month is covered the day it
     * exists, by nobody doing anything.
     */
    $chips = array_values(array_unique(array_merge(
        ['all'],
        $body['statuses'],
        $body['stock_statuses'],
        array_keys($body['counts'])
    )));

    expect(count($chips))->toBeGreaterThan(12);

    $failures = [];

    foreach ($chips as $chip) {
        $captured = SqlShape::capture(function () use ($admin, $chip, &$status) {
            $status = $this->actingAs($admin, 'admin')
                ->getJson('/admin-api/catalog-products-list?filter='.urlencode((string) $chip))
                ->getStatusCode();
        });

        if ($status >= 400) {
            $failures[] = "filter={$chip}: responded {$status}";
        }

        foreach (SqlShape::violations($captured) as $problem) {
            $failures[] = "filter={$chip}: {$problem}";
        }
    }

    expect($failures)->toBe([]);
});

it('reports the same counts and summary on every page of the rebuilt products list', function () {
    catalogProductsGuardFixture();

    CatalogProductsAdminRoutes::wire(app());

    $admin = guardAdmin();

    $first = $this->actingAs($admin, 'admin')
        ->getJson('/admin-api/catalog-products-list?per_page=10&page=1')->assertOk();

    $second = $this->actingAs($admin, 'admin')
        ->getJson('/admin-api/catalog-products-list?per_page=10&page=2')->assertOk();

    // An aggregate returns one row. Skip 10 and there is none, so every tile
    // reads zero from page two on — on every driver, while the endpoint still
    // answers 200. That is the bug App\Support\AggregatesQueries exists for.
    expect($second->json('counts'))->toBe($first->json('counts'))
        ->and($second->json('summary'))->toBe($first->json('summary'))
        ->and($second->json('total'))->toBe($first->json('total'))
        ->and($second->json('total'))->toBeGreaterThan(10)
        ->and($second->json('summary.inventory_fils'))->toBeGreaterThan(0);
});

it('fails if the summary is rebuilt from the page s own sorted, paged builder', function () {
    catalogProductsGuardFixture();

    /*
     * The guard, aimed at itself.
     *
     * This is the statement the Customers screen shipped and the Orders screen
     * then copied: the row query's columns and ORDER BY still attached to an
     * aggregate, with the page's OFFSET along for the ride. If SqlShape stopped
     * reporting it, every dialect test above would keep passing while the
     * defect walked back in — so the rule is asserted to FIRE, not merely to be
     * absent elsewhere.
     */
    $defective = DB::table('products')
        ->selectRaw('products.id, products.name, COUNT(*) as c')
        ->orderBy('products.created_at', 'desc')
        ->offset(10)
        ->limit(1);

    /*
     * Judged from the SQL it WOULD issue, not by issuing it. Running it is not
     * an option here: on MySQL it raises the 1140 itself, which aborts the test
     * before anything is asserted — and DB::listen never fires for a statement
     * that throws, so there would be nothing captured to judge either. The
     * shape is the whole subject, and toSql() has it.
     */
    $violations = SqlShape::violations([[
        'sql' => $defective->toSql(),
        'bindings' => $defective->getBindings(),
        'schema' => false,
    ]]);

    expect($violations)->not->toBe([]);

    $text = implode("\n", $violations);

    expect($text)->toContain('1140')
        ->and($text)->toContain('ORDER BY')
        ->and($text)->toContain('OFFSET');
});


/**
 * Catalog → Reorder reports a total beside a page of products. Same shape, same
 * assertion.
 */
it('reports the same total on every page of the catalog reorder list', function () {
    guardFixtures();

    $category = Category::query()->firstOrFail();

    foreach (range(1, 14) as $n) {
        $p = Product::create([
            'slug' => 'reorder-' . $n . '-' . uniqid(),
            'name' => 'Reorder Product ' . $n,
            'status' => 'publish',
            'is_visible' => true,
            'price' => 1000 * $n,
            'stock_status' => 'instock',
        ]);

        $p->categories()->attach($category->id);
    }

    $admin = guardAdmin();

    $base = '/admin-api/catalog/reorder/category/' . $category->id . '/products?per_page=10&page=';

    $first = $this->actingAs($admin, 'admin')->getJson($base . '1')->assertOk();
    $second = $this->actingAs($admin, 'admin')->getJson($base . '2')->assertOk();

    expect($second->json('total'))->toBe($first->json('total'))
        ->and($second->json('total'))->toBeGreaterThan(10);
});

/**
 * Schema introspection is the framework's, and the framework knows both
 * dialects.
 *
 * Schema::hasTable() reads sqlite_master here and information_schema on MySQL,
 * because the grammar is chosen per driver. Five live admin endpoints call it —
 * Newsletter, Extended Delivery, Demo Content, Mega Menu and Updates — and while
 * SqlShape reported those statements the route walk above could not be written
 * at all: every one of them failed on a portability problem that does not exist.
 *
 * The distinction is made on the call stack, not on the text, so a query the
 * APPLICATION writes against sqlite_master is still a violation. Both halves are
 * asserted, because a rule that stops firing is worth no more than a rule that
 * fires wrongly.
 */
it('excuses the framework schema introspection and nothing else', function () {
    $captured = SqlShape::capture(function () {
        Schema::hasTable('orders');
    });

    expect($captured)->not->toBeEmpty()
        ->and(SqlShape::violations($captured))->toBe([]);

    if (DB::connection()->getDriverName() !== 'sqlite') {
        return;
    }

    // The same table, read by application code rather than by the schema
    // builder, is still SQLite-only and still reported.
    $byHand = SqlShape::capture(function () {
        DB::select("select name from sqlite_master where type = 'table' limit 1");
    });

    expect(SqlShape::violations($byHand))->not->toBe([]);
});

/* ----------------------------------------- Lane X: the rest of the storefront */

/**
 * The storefront list above stopped at four pages. Every other page a shopper
 * reaches that counts, groups or paginates is here.
 *
 * The brand directory and the shop sidebar both carry per-brand and
 * per-category counts behind a GROUP BY. The four curated collections paginate.
 * The review wall aggregates ratings. The category archive is the shop query
 * with a pivot join on it — the join is what makes its count different from the
 * shop's, and a count over a join is where a GROUP BY goes missing.
 */
it('issues portable SQL on the rest of the storefront', function (string $url) {
    guardFixtures();

    $product = Product::query()->where('status', 'publish')->firstOrFail();
    $category = Category::query()->firstOrFail();
    $brand = Brand::query()->firstOrFail();

    /*
     * Real slugs, not literals. guardFixtures() suffixes every slug with a
     * uniqid() so repeated runs cannot collide, so a hard-coded 'guard-brand'
     * in the dataset below would filter to nothing — the page would still be
     * 200 and the guard would still pass, having exercised the unfiltered
     * statement rather than the filtered one it was written for.
     *
     * The archive is reached through the category's own path, not its slug: a
     * nested category's URL is the full chain and the bare slug 404s.
     */
    $url = str_replace(
        ['{slug}', '{category}', '{brand}'],
        [(string) $product->slug, (string) ($category->path ?: $category->slug), (string) $brand->slug],
        $url
    );

    $captured = SqlShape::capture(function () use ($url) {
        $this->get($url)->assertOk();
    });

    expect($captured)->not->toBeEmpty("no SQL was issued for {$url}");
    expect(SqlShape::violations($captured))->toBe([], "portability violations on {$url}");
})->with([
    '/korean-skincare-brands',
    '/product-category/{category}',
    '/new-in',
    '/best-sellers',
    '/super-sale',
    '/everything-under-54-aed',
    '/reviews',
    '/shop?page=2',
    '/shop?brand={brand}',
    '/cart',
    '/api/products',
    '/api/reviews',
]);

/**
 * The shop's result count describes the filtered catalogue, not the page of it.
 *
 * Same property as an admin summary tile, and the same failure if it is built
 * from the Builder the page was taken from: the heading would read "24 products"
 * on page one and "0 products" on page two while both pages rendered fine.
 */
it('reports the same product total on every page of the shop', function () {
    guardFixtures();

    // Enough to fill more than one page at the default of 24 per page.
    foreach (range(1, 30) as $n) {
        Product::create([
            'slug' => 'shop-paged-' . $n . '-' . uniqid(),
            'name' => 'Shop Paged ' . $n,
            'status' => 'publish',
            'is_visible' => true,
            'price' => 1000 + $n,
            'stock_status' => 'instock',
        ]);
    }

    $first = $this->get('/shop')->assertOk()->viewData('total');
    $second = $this->get('/shop?page=2')->assertOk()->viewData('total');

    expect($second)->toBe($first)
        ->and($second)->toBeGreaterThan(24);
});
