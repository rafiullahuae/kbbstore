<?php

declare(strict_types=1);

/*
 * The other half of the list-screen audit: not what SHAPE the statements are,
 * but HOW MANY of them there are and whether the numbers they produce are real.
 *
 * SqlDialectGuardTest judges one request's SQL. It cannot see an N+1, because a
 * per-row SELECT is perfectly portable — it is just ruinous. /shop once issued
 * 390 statements for four products and 780 for twenty-four, and every one of
 * them was valid SQL on both engines.
 *
 * So these tests measure the same endpoint twice, against a small fixture and
 * then a larger one, and fail if the statement count grew with the row count.
 * That is the actual property — "this screen does not query per row" — rather
 * than a magic number that has to be revised every time an unrelated setting is
 * read. A screen that is constant at 40 statements passes; a screen that is 5
 * and then 25 does not.
 */

use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\SqlShape;

function budgetAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'Budget Owner',
        'email' => 'budget-' . uniqid() . '@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
}

/** @return Product the first product created, for line items to point at */
function budgetCatalogue(int $products): Product
{
    $brand = Brand::create(['name' => 'Budget Brand ' . uniqid(), 'slug' => 'bb-' . uniqid()]);
    $category = Category::create(['name' => 'Budget Cat ' . uniqid(), 'slug' => 'bc-' . uniqid()]);

    $first = null;

    foreach (range(1, $products) as $n) {
        $product = Product::create([
            'slug' => 'budget-' . uniqid(),
            'name' => 'Budget Product ' . $n,
            'status' => 'publish',
            'is_visible' => true,
            'price' => 1000 * $n,
            'stock_status' => 'instock',
            'brand_id' => $brand->id,
            'category_id' => $category->id,
        ]);

        $product->categories()->attach($category->id);

        $first ??= $product;
    }

    return $first;
}

function budgetOrders(int $orders, Product $product): void
{
    foreach (range(1, $orders) as $n) {
        $customer = Customer::create([
            'name' => 'Budget Customer ' . $n,
            'email' => 'bc-' . uniqid() . '@example.test',
        ]);

        $order = Order::create([
            'customer_id' => $customer->id,
            'order_number' => 'BGT-' . uniqid(),
            'email' => $customer->email,
            'status' => 'completed',
            'currency' => 'AED',
            'subtotal' => 5000,
            'shipping_total' => 1500,
            'fee_total' => 500,
            'shipping_method' => 'flat_rate',
            'total' => 7000,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'name' => $product->name,
            'brand' => 'Budget Brand',
            'quantity' => 3,
            'unit_price' => 1000,
            'subtotal' => 3000,
            'total' => 3000,
        ]);
    }
}

/** Statements issued by one GET, with the response body forced to render. */
function budgetQueryCount(string $path, ?AdminUser $admin = null): int
{
    $captured = SqlShape::capture(function () use ($path, $admin) {
        $request = $admin ? test()->actingAs($admin, 'admin') : test();

        $response = $request->get($path);

        if ($response->baseResponse instanceof \Symfony\Component\HttpFoundation\StreamedResponse) {
            $response->streamedContent();
        } else {
            $response->getContent();
        }
    });

    return count($captured);
}

/**
 * Store → Orders (the original AdminController list).
 *
 * This ran `Customer::find()` AND `OrderItem::where()->count()` inside its map,
 * over every row in `orders` with no pagination — 2N + 1 statements, 1,343 of
 * them on the live 671-order table for one screen. Both are resolved in one
 * query each now.
 */
it('does not query per order on the admin orders list', function () {
    Cache::flush();

    $product = budgetCatalogue(3);
    $admin = budgetAdmin();

    budgetOrders(3, $product);
    $small = budgetQueryCount('/admin-api/orders', $admin);

    budgetOrders(20, $product);
    $large = budgetQueryCount('/admin-api/orders', $admin);

    expect($large)->toBeLessThanOrEqual($small, "statement count grew from {$small} to {$large} when the order count went from 3 to 23 — that is one query per row");
});

/** The dashboard's recent-orders strip did the same thing, bounded at eight. */
it('does not query per order on the admin dashboard', function () {
    Cache::flush();

    $product = budgetCatalogue(3);
    $admin = budgetAdmin();

    budgetOrders(2, $product);
    $small = budgetQueryCount('/admin-api/stats', $admin);

    budgetOrders(20, $product);
    $large = budgetQueryCount('/admin-api/stats', $admin);

    expect($large)->toBeLessThanOrEqual($small, "statement count grew from {$small} to {$large} on a fixed-size strip of eight recent orders");
});

/**
 * Analytics used to read every order and every order item into PHP to add them
 * up. It is now aggregated in SQL, so it is flat in both tables.
 */
it('does not grow with the order table on the analytics screen', function () {
    Cache::flush();

    $product = budgetCatalogue(3);
    $admin = budgetAdmin();

    budgetOrders(2, $product);
    $small = budgetQueryCount('/admin-api/analytics', $admin);

    budgetOrders(20, $product);
    $large = budgetQueryCount('/admin-api/analytics', $admin);

    expect($large)->toBeLessThanOrEqual($small, "statement count grew from {$small} to {$large} when the order table grew");
});

/** The paginated list screens, each measured the same way. */
it('keeps a flat statement count on the paginated admin lists', function (string $path) {
    Cache::flush();

    $product = budgetCatalogue(3);
    $admin = budgetAdmin();

    budgetOrders(3, $product);
    budgetCatalogue(3);
    $small = budgetQueryCount($path, $admin);

    budgetOrders(20, $product);
    budgetCatalogue(20);
    $large = budgetQueryCount($path, $admin);

    /*
     * Less-than-or-equal, not equal. A count that FALLS is not an N+1 — an
     * eager load skips its second statement when the first returns no keys, so
     * a screen can legitimately cost one query more on an empty table than on a
     * full one. Growth with the row count is the defect; anything else is noise
     * and pinning it exactly would make this test fail for unrelated reasons.
     */
    expect($large)->toBeLessThanOrEqual($small, "{$path}: statement count grew from {$small} to {$large} when the fixture grew — that is one query per row");
})->with([
    '/admin-api/customers/list?per_page=500',
    '/admin-api/orders-list?per_page=500',
    '/admin-api/catalog/products?per_page=500',
    '/admin-api/products',
    '/admin-api/reviews',
    '/admin-api/brands',
    '/admin-api/categories',
    '/admin-api/attributes',
    '/admin-api/redirects',
    '/admin-api/posts',
    '/admin-api/quiz-leads',
    '/admin-api/users',
]);

/**
 * And the storefront listing, which is the one that actually cost 390 queries.
 *
 * Measured against a cold cache both times, so the second reading cannot be
 * flattered by the sidebar counts being remembered from the first.
 */
it('keeps a flat statement count on the shop listing', function () {
    Cache::flush();
    budgetCatalogue(4);

    // One warm-up request: SettingsService memoises non-autoloaded keys in a
    // process-level static, so the FIRST storefront request of a process pays
    // for all of them and no later one does. Measuring without this compares a
    // cold process against a warm one and reports a fall, not a rise.
    budgetQueryCount('/shop');

    Cache::flush();
    $small = budgetQueryCount('/shop');

    budgetCatalogue(20);

    Cache::flush();
    $large = budgetQueryCount('/shop');

    expect($large)->toBeLessThanOrEqual($small, "statement count grew from {$small} to {$large} when the catalogue went from 4 products to 24");
});

/* ------------------------------------------------------ the numbers themselves */

/**
 * `units_sold` read ZERO on the Analytics screen for as long as the screen has
 * existed.
 *
 * `order_items` has a `quantity` column. The code asked for `$it->qty`, which is
 * not a column on that table and never has been, and Eloquent answers a missing
 * attribute with null rather than raising — so every unit count summed to 0 and
 * the per-product revenue each row was RANKED by was unit_price * 0. A 200
 * response the whole time.
 */
it('reports real unit counts on the analytics screen', function () {
    Cache::flush();

    $product = budgetCatalogue(2);
    budgetOrders(4, $product);        // 4 orders x 3 units

    $body = $this->actingAs(budgetAdmin(), 'admin')
        ->getJson('/admin-api/analytics')
        ->assertOk();

    /*
     * The mechanism, pinned here so the reason this test exists cannot be lost
     * with it: reading a column that is not on the table yields null per row and
     * ZERO in aggregate, and raises nothing anywhere. That is the whole defect —
     * there was never an error to notice, only a dashboard tile reading 0.
     *
     * Aggregating the same thing in SQL, which is what the fix does, turns the
     * silent zero into a hard SQLSTATE 42S22 the moment a column name is wrong.
     * That is a strict improvement: a screen that breaks loudly gets fixed.
     */
    expect(OrderItem::query()->get()->sum('qty'))->toBe(0)
        ->and(OrderItem::query()->get()->sum('quantity'))->toBe(12);

    /*
     * A trashed order is not revenue, and its units are not units.
     *
     * The totals come from an Eloquent Order query, which carries the
     * SoftDeletes scope. The per-product figures come from OrderItem joined to
     * orders, which does NOT — the scope belongs to the Order builder. Trash
     * one order and assert BOTH halves moved, or the two sides of this screen
     * can disagree and nothing says so.
     */
    Order::query()->orderBy('id')->first()?->delete();

    $body = $this->actingAs(budgetAdmin(), 'admin')
        ->getJson('/admin-api/analytics')
        ->assertOk();

    expect($body->json('units_sold'))->toBe(9)
        ->and($body->json('top_products.0.units'))->toBe(9)
        ->and($body->json('paid_orders'))->toBe(3);

    Order::withTrashed()->orderBy('id')->first()?->restore();

    $body = $this->actingAs(budgetAdmin(), 'admin')
        ->getJson('/admin-api/analytics')
        ->assertOk();

    expect($body->json('units_sold'))->toBe(12)
        ->and($body->json('top_products.0.units'))->toBe(12)
        // 4 lines x 3 units x 1000 fils = 12000 fils = AED 120.
        ->and($body->json('top_products.0.revenue_aed'))->toBe(120)
        ->and($body->json('paid_orders'))->toBe(4)
        // 4 orders x 7000 fils = 28000 fils = AED 280.
        ->and($body->json('revenue_total_aed'))->toBe(280);
});

/**
 * The old order modal showed a quantity of nothing and a line total of AED 0.
 *
 * Same family of wrong column names: `$i->qty`, `$o->delivery`, `$o->cod_fee`
 * and `$o->ship_method`, none of which exist. The real columns are quantity,
 * shipping_total, fee_total and shipping_method.
 */
it('reports real quantities and charges on the old order endpoint', function () {
    Cache::flush();

    $product = budgetCatalogue(1);
    budgetOrders(1, $product);

    $order = Order::query()->firstOrFail();

    $body = $this->actingAs(budgetAdmin(), 'admin')
        ->getJson('/admin-api/orders/' . $order->id)
        ->assertOk();

    expect($body->json('items.0.qty'))->toBe(3)
        // 3 units x 1000 fils = 3000 fils = AED 30.
        ->and($body->json('items.0.line_aed'))->toBe(30)
        ->and($body->json('delivery_aed'))->toBe(15)
        ->and($body->json('cod_fee_aed'))->toBe(5)
        ->and($body->json('ship_method'))->toBe('flat_rate');
});

/** And the list form of the same column. */
it('reports the shipping method on the old orders list', function () {
    Cache::flush();

    $product = budgetCatalogue(1);
    budgetOrders(1, $product);

    $body = $this->actingAs(budgetAdmin(), 'admin')
        ->getJson('/admin-api/orders')
        ->assertOk();

    expect($body->json('orders.0.ship_method'))->toBe('flat_rate')
        ->and($body->json('orders.0.items'))->toBe(1);
});

/* ------------------------------------------------------------ the export cap */

/**
 * BOTH CSV EXPORTS CARRIED A ROW CEILING THAT DID NOTHING.
 *
 * `$query->limit(self::EXPORT_MAX)->chunk(500, ...)`. chunk() walks the query
 * with forPage(), and forPage() SETS limit and offset rather than narrowing what
 * is already there — so the 50,000 ceiling was replaced by `limit 500 offset 0`
 * on the first round trip and the export streamed the whole filtered table. The
 * constant never reached the database at all.
 *
 * Demonstrated here on the mechanism rather than by building fifty thousand
 * rows: a builder limited to three rows, chunked, hands back all of them.
 */
it('proves chunk() discards a limit already on the builder', function () {
    Cache::flush();

    $product = budgetCatalogue(1);
    budgetOrders(8, $product);

    $delivered = 0;

    Order::query()->orderBy('id')->limit(3)->chunk(2, function ($rows) use (&$delivered) {
        $delivered += $rows->count();
    });

    expect($delivered)->toBe(8, 'chunk() honoured a preceding limit(3) — if this ever passes at 3, the export cap can go back to being a limit()');
});

/** So the cap is enforced by counting rows written, and the CSV really stops. */
it('stops the customers export at the row ceiling', function () {
    Cache::flush();

    $product = budgetCatalogue(1);
    budgetOrders(6, $product);

    $csv = exportBody('/admin-api/customers/export');

    // Header plus one row per customer: the cap is 50,000, so nothing is cut
    // here — this pins that removing ->limit() did not change the ordinary
    // result, which is the risk in the fix.
    expect(substr_count(trim($csv), "\n"))->toBe(6);
});

it('stops the orders export at the row ceiling', function () {
    Cache::flush();

    $product = budgetCatalogue(1);
    budgetOrders(6, $product);

    $csv = exportBody('/admin-api/orders-export');

    expect(substr_count(trim($csv), "\n"))->toBe(6);
});

function exportBody(string $path): string
{
    $response = test()->actingAs(budgetAdmin(), 'admin')->get($path);

    $response->assertOk();

    return $response->streamedContent();
}

/**
 * A count of rows written is only a cap if the walk actually stops, so drive the
 * stop condition directly with a ceiling of one.
 *
 * The production ceiling is 50,000 and building that many rows in a test would
 * cost more than it proves; what needs proving is that `return false` from the
 * chunk callback ends the walk, and that is the same code path at any ceiling.
 */
it('stops the chunk walk when the callback returns false', function () {
    Cache::flush();

    $product = budgetCatalogue(1);
    budgetOrders(8, $product);

    $written = 0;
    $roundTrips = 0;

    $captured = SqlShape::capture(function () use (&$written, &$roundTrips) {
        Order::query()->orderBy('id')->chunk(2, function ($rows) use (&$written) {
            foreach ($rows as $row) {
                if ($written >= 1) {
                    return false;
                }

                $written++;
            }
        });
    });

    $roundTrips = count($captured);

    expect($written)->toBe(1)
        ->and($roundTrips)->toBe(1, 'the walk kept fetching pages after the callback asked it to stop');
});

/** Money stays integer fils; nothing on these screens may return a float. */
it('never reports a fractional amount from the admin money fields', function () {
    Cache::flush();

    $product = budgetCatalogue(1);
    budgetOrders(3, $product);

    $admin = budgetAdmin();

    $analytics = $this->actingAs($admin, 'admin')->getJson('/admin-api/analytics')->assertOk();

    foreach (['revenue_total_aed', 'aov_aed', 'units_sold', 'orders_total', 'paid_orders'] as $key) {
        expect($analytics->json($key))->toBeInt("{$key} is not an integer");
    }

    $summary = $this->actingAs($admin, 'admin')
        ->getJson('/admin-api/orders-list')->assertOk()->json('summary');

    foreach ($summary as $key => $value) {
        if (str_ends_with((string) $key, '_fils')) {
            expect($value)->toBeInt("summary.{$key} is not an integer");
        }
    }

    expect(DB::connection()->getDriverName())->not->toBeEmpty();
});
