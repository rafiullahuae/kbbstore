<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Refund;
use App\Models\Review;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\CatalogProductsAdminRoutes;
use Tests\Support\OrdersAdminRoutes;

/**
 * Lane ES. What the grid and the product page are allowed to cost, and the
 * proof that the admin lists still say what they said before they got faster.
 *
 * TWO HALVES, AND THEY ARE ABOUT DIFFERENT RISKS.
 *
 * 1. A HARD QUERY CEILING on the three pages that render a grid of cards and on
 *    the product page. This is the regression that comes back: a later lane
 *    adds something to <x-product-card> that reads a relation, and 24 cards
 *    become 24 more statements. It is invisible in review and invisible in
 *    production until the catalogue grows.
 *
 *    The ceilings below are the number measured on this fixture PLUS THREE, and
 *    the measured number is written beside each one so that whoever trips this
 *    can see what changed rather than being told only that a number moved.
 *
 *    THE FIXTURE IS THE ASSERTION'S TEETH. 30 products on the grid and 12
 *    reviews on the product page, which is why a ceiling works here at all: one
 *    statement per card puts /shop thirty over, and any ceiling set anywhere
 *    near the real cost fails at once. This was checked the only way it can be
 *    -- by putting the N+1 back. Removing `->with('brand:id,name,slug')` from
 *    Store\ShopController::index() takes /shop from 8 statements to 38 and this
 *    file fails with "shop grid ran 38 queries, ceiling 11". See the report.
 *
 * 2. THE ADMIN LISTS' FIGURES, computed a second time, independently, in PHP.
 *
 *    Store → Orders and Store → Catalog → Products used to join two grouped
 *    derived tables onto EVERY statement they issue, including the counts and
 *    the summary strips, which reference no column of either. Measured on MySQL
 *    at 6,000 orders and 27,000 order lines, that was 181 ms and 228 ms of SQL
 *    per request; scoping each join to the statements that actually read it
 *    took them to 71 ms and 66 ms (docs/page-cost.md).
 *
 *    That change is only safe because a LEFT JOIN onto a subquery GROUPED BY
 *    the join key matches at most one row, so it can neither multiply nor drop
 *    rows and a COUNT over it is the same COUNT. "Can neither" is an argument,
 *    not evidence, so the figures are asserted here against the same numbers
 *    worked out from the rows by hand -- per row, per chip and per summary
 *    field -- with a fixture built so that every one of them is distinct and a
 *    transposition would show.
 */

/* ------------------------------------------------------------------ fixtures */

/** Count the statements one request issues, with the caches cold. */
function pcQueries(string $path, ?callable $before = null): int
{
    // Cold, because the sidebar, the rails and the settings map are cached and
    // a warm cache measures a page that is not the page a visitor first meets.
    Cache::flush();
    \App\Models\Setting::flushMap();
    \App\Support\Facets::reset();

    if ($before !== null) {
        $before();
    }

    $n = 0;
    DB::listen(function () use (&$n): void {
        $n++;
    });

    test()->get($path)->assertOk();

    // Laravel has no removeListener, so the counter is per call and the
    // listener from an earlier call keeps counting into a variable nobody
    // reads. Harmless, and the reason $n is a closure variable rather than a
    // shared bucket.
    return $n;
}

/**
 * A catalogue big enough that one statement per card is unmissable.
 *
 * THE DEMO CATALOGUE IS CLEARED FIRST, and that is not tidiness — it is what
 * makes the two measurements below mean anything. The migration set seeds 24
 * products, and `products_per_page` is 24, so a fixture that ADDS to them fills
 * the first page whatever it does: /shop rendered 24 cards with 10 products of
 * its own and 24 cards with 40. Measured with a real N+1 deliberately in place,
 * the growth check passed — both pages drew the same 24 cards and therefore ran
 * the same 24 extra statements. A guard that cannot fail is the thing this repo
 * has four of already.
 */
function pcCatalogue(int $products = 30): array
{
    pcResetCatalogue();

    $brand = Brand::create(['name' => 'Cost Brand', 'slug' => 'cost-brand']);
    $category = Category::create(['name' => 'Cost Category', 'slug' => 'cost-category', 'path' => 'cost-category']);

    $first = null;

    foreach (range(1, $products) as $n) {
        $product = Product::create([
            'slug' => 'cost-product-'.$n,
            'name' => 'Cost Product '.$n,
            'sku' => 'COST-'.$n,
            'status' => 'publish',
            'is_visible' => true,
            'price' => 1000 * $n,
            'stock_status' => 'instock',
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'image' => '/wp-content/uploads/cost-'.$n.'.jpg',
        ]);

        $product->categories()->attach($category->id);

        $first ??= $product;
    }

    // Reviews on the page the product measurement uses, so the summary, the
    // list and the aggregateRating all have real work to do.
    foreach (range(1, 12) as $n) {
        Review::create([
            'product_id' => $first->id,
            'source' => 'wp_comment',
            'author_name' => 'Cost Reviewer '.$n,
            'author_email' => 'cost-'.$n.'@example.test',
            'rating' => 1 + ($n % 5),
            'title' => 'Cost review '.$n,
            'content' => 'A real review, number '.$n.'.',
            'status' => 'approved',
        ]);
    }

    return ['brand' => $brand, 'category' => $category, 'product' => $first];
}

/* -------------------------------------------------------------- the ceilings */

it('keeps the grid and the product page inside a hard query ceiling', function () {
    $seed = pcCatalogue();

    /*
     * path => [ceiling, measured]
     *
     * Measured on SQLite, cold caches, 30 products and 12 reviews, at the
     * commit that added this file. The ceiling is the measurement plus three:
     * enough that an unrelated setting read does not trip it, far too little to
     * hide a per-card statement on a thirty-card grid.
     *
     * MySQL measures one or two FEWER on the listing pages, not more --
     * ProductVisibility::raw() reads its column list from information_schema
     * there while SQLite answers the same question from a pragma that
     * DB::listen can see. So a ceiling that holds on SQLite holds on MySQL, and
     * the suite runs on both.
     */
    $pages = [
        '/shop' => [19, 16],
        '/product-category/'.$seed['category']->slug => [19, 16],
        '/product/'.$seed['product']->slug => [22, 19],
    ];

    $over = [];

    foreach ($pages as $path => [$ceiling, $measured]) {
        $count = pcQueries($path);

        if ($count > $ceiling) {
            $over[] = sprintf(
                '%s ran %d queries, ceiling %d (it was %d when this ceiling was set)',
                $path,
                $count,
                $ceiling,
                $measured,
            );
        }
    }

    expect($over)->toBe([], "Over the page-cost ceiling:\n  ".implode("\n  ", $over));
});

it('does not run more queries when the grid holds ten times as many cards', function () {
    // Four, so the small measurement really is a four-card page. Then forty,
    // which is more than one page holds, so the large one is a full 24-card
    // grid. Anything per-card shows as twenty statements between them.
    $seed = pcCatalogue(4);

    $small = pcQueries('/shop');

    foreach (range(5, 40) as $n) {
        $product = Product::create([
            'slug' => 'cost-extra-'.$n,
            'name' => 'Cost Extra '.$n,
            'status' => 'publish',
            'is_visible' => true,
            'price' => 1000 + $n,
            'stock_status' => 'instock',
            'brand_id' => $seed['brand']->id,
            'category_id' => $seed['category']->id,
        ]);

        $product->categories()->attach($seed['category']->id);
    }

    $large = pcQueries('/shop');

    /*
     * The ceiling above is what a reviewer reads; this is what actually names
     * the defect. A grid that batches its loads does not cost MORE for 40 cards
     * than for 10, and a grid that does not cannot pass this whatever its
     * ceiling is set to.
     *
     * Not-more rather than exactly-equal, and the difference is real: the
     * larger catalogue measures one statement FEWER, because at 40 products the
     * grid fills its page and the pager stops asking a question the short grid
     * asks. A test that demanded equality would fail on a page that got
     * cheaper, which is not the defect anybody wants to hear about.
     */
    expect($large)->toBeLessThanOrEqual($small, "/shop cost {$small} queries for 4 products and {$large} for 40. That is a query per card.");
});

/* ------------------------------------------- the admin figures, computed twice */

/**
 * Orders whose units, lines and refunds are all DIFFERENT numbers, so that a
 * row picking up its neighbour's aggregate is visible rather than plausible.
 *
 * @return array<int, array{units:int, lines:int, refunded:int, total:int, status:string}>
 */
function pcOrders(): array
{
    pcResetCatalogue();

    $customer = Customer::create(['name' => 'Cost Buyer', 'email' => 'cost-buyer@example.test']);
    $product = Product::create([
        'slug' => 'cost-ordered', 'name' => 'Cost Ordered', 'status' => 'publish',
        'is_visible' => true, 'price' => 5000, 'stock_status' => 'instock',
    ]);

    $plan = [
        ['status' => 'completed', 'total' => 10000, 'lines' => [1, 2, 3], 'refund' => 1500],
        ['status' => 'processing', 'total' => 20000, 'lines' => [4], 'refund' => 0],
        ['status' => 'cancelled', 'total' => 30000, 'lines' => [5, 6], 'refund' => 700],
        ['status' => 'completed', 'total' => 40000, 'lines' => [], 'refund' => 0],
    ];

    $expected = [];

    foreach ($plan as $i => $spec) {
        $order = Order::create([
            'order_number' => 'COST-'.($i + 1),
            'customer_id' => $customer->id,
            'email' => 'cost-buyer@example.test',
            'status' => $spec['status'],
            'subtotal' => $spec['total'],
            'total' => $spec['total'],
            'created_at' => now()->subDays(10 - $i),
        ]);

        foreach ($spec['lines'] as $quantity) {
            OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'name' => 'Cost line',
                'quantity' => $quantity,
                'unit_price' => 1000,
                'subtotal' => 1000 * $quantity,
                'total' => 1000 * $quantity,
            ]);
        }

        if ($spec['refund'] > 0) {
            Refund::create([
                'order_id' => $order->id,
                'amount' => $spec['refund'],
                'status' => 'succeeded',
            ]);
        }

        $expected[$order->id] = [
            'units' => array_sum($spec['lines']),
            'lines' => count($spec['lines']),
            'refunded' => $spec['refund'],
            'total' => $spec['total'],
            'status' => $spec['status'],
        ];
    }

    return $expected;
}

/**
 * Empty the demo catalogue the migration set seeds.
 *
 * Every figure below is an exact number, and 24 demo products and their
 * reviews would make each of them "24 plus whatever this test made" — which is
 * a test that still passes when the aggregate is wrong by less than 24. The
 * deletes run inside RefreshDatabase's transaction, so the seeded catalogue is
 * back for the next test. Same reasoning, and the same helper shape, as
 * AdminCatalogProductsTest::cpResetCatalogue().
 */
function pcResetCatalogue(): void
{
    DB::table('reviews')->delete();
    DB::table('refunds')->delete();
    DB::table('category_product')->delete();
    DB::table('order_items')->delete();
    DB::table('orders')->delete();
    DB::table('products')->delete();
}

function pcAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'Cost Owner',
        'email' => 'cost-owner-'.uniqid().'@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
}

it('still reports every order aggregate after the derived joins were scoped', function () {
    $expected = pcOrders();

    OrdersAdminRoutes::wire(app());
    test()->actingAs(pcAdmin(), 'admin');

    $body = test()->getJson('/admin-api/orders-list')->assertOk()->json();

    $rows = [];

    foreach ($body['orders'] as $row) {
        $rows[$row['id']] = $row;
    }

    expect(array_keys($rows))->toHaveCount(count($expected));

    foreach ($expected as $id => $want) {
        expect($rows[$id]['units'])->toBe($want['units'], "units on order {$id}")
            ->and($rows[$id]['lines'])->toBe($want['lines'], "lines on order {$id}")
            ->and($rows[$id]['refunded_fils'])->toBe($want['refunded'], "refunded on order {$id}");
    }

    // The three statements the joins were REMOVED from: the pagination count,
    // the chip counts and the summary strip.
    expect($body['total'])->toBe(count($expected));

    $counts = $body['counts'];
    expect($counts['all'])->toBe(count($expected))
        ->and($counts['completed'])->toBe(2)
        ->and($counts['processing'])->toBe(1)
        ->and($counts['cancelled'])->toBe(1)
        ->and($counts['paid'])->toBe(3)
        ->and($counts['trashed'])->toBe(0);

    $revenue = 0;
    $paidOrders = 0;
    $gross = 0;
    $refunded = 0;

    foreach ($expected as $want) {
        $gross += $want['total'];
        $refunded += $want['refunded'];

        if (in_array($want['status'], Order::REAL_STATUSES, true)) {
            $paidOrders++;
            $revenue += $want['total'] - $want['refunded'];
        }
    }

    expect($body['summary']['gross_fils'])->toBe($gross)
        ->and($body['summary']['refunded_fils'])->toBe($refunded)
        ->and($body['summary']['paid_orders'])->toBe($paidOrders)
        ->and($body['summary']['revenue_fils'])->toBe($revenue)
        ->and($body['summary']['aov_fils'])->toBe(intdiv($revenue, $paidOrders));
});

it('still sorts orders by units, which reads the join that moved', function () {
    $expected = pcOrders();

    OrdersAdminRoutes::wire(app());
    test()->actingAs(pcAdmin(), 'admin');

    $ids = array_column(
        test()->getJson('/admin-api/orders-list?sort=units_desc')->assertOk()->json('orders'),
        'id'
    );

    $byUnits = $expected;
    // Highest units first, then highest id — the controller's own tie-break.
    uksort($byUnits, fn ($a, $b) => [$expected[$b]['units'], $b] <=> [$expected[$a]['units'], $a]);

    expect($ids)->toBe(array_keys($byUnits));
});

it('still reports every product aggregate after the sales join was scoped', function () {
    pcResetCatalogue();

    $brand = Brand::create(['name' => 'Cost Brand', 'slug' => 'cost-brand']);
    $category = Category::create(['name' => 'Cost Cat', 'slug' => 'cost-cat', 'path' => 'cost-cat']);
    $customer = Customer::create(['name' => 'Cost Buyer', 'email' => 'cost-buyer@example.test']);

    $sold = Product::create([
        'slug' => 'cost-sold', 'name' => 'Cost Sold', 'status' => 'publish', 'is_visible' => true,
        'price' => 5000, 'stock_status' => 'instock', 'brand_id' => $brand->id,
    ]);
    $sold->categories()->attach($category->id);

    $unsold = Product::create([
        'slug' => 'cost-unsold', 'name' => 'Cost Unsold', 'status' => 'publish', 'is_visible' => true,
        'price' => 7000, 'stock_status' => 'instock', 'brand_id' => $brand->id,
    ]);
    // Deliberately in NO category: the "No category" chip is a predicate on the
    // OTHER derived table, the one that stayed in the base query.

    // Two revenue orders and one cancelled one. The cancelled order's lines
    // must not be counted, which is the whole reason the subquery filters on
    // Order::REAL_STATUSES.
    foreach ([['completed', 2, 4000], ['processing', 3, 6000], ['cancelled', 9, 18000]] as $i => [$status, $quantity, $total]) {
        $order = Order::create([
            'order_number' => 'COSTP-'.($i + 1),
            'customer_id' => $customer->id,
            'email' => 'cost-buyer@example.test',
            'status' => $status,
            'subtotal' => $total,
            'total' => $total,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $sold->id,
            'name' => 'Cost line',
            'quantity' => $quantity,
            'unit_price' => 2000,
            'subtotal' => $total,
            'total' => $total,
        ]);
    }

    CatalogProductsAdminRoutes::wire(app());
    test()->actingAs(pcAdmin(), 'admin');

    $body = test()->getJson('/admin-api/catalog-products-list')->assertOk()->json();

    $rows = [];

    foreach ($body['products'] as $row) {
        $rows[$row['id']] = $row;
    }

    expect($rows[$sold->id]['orders_count'])->toBe(2, 'two revenue orders, not three')
        ->and($rows[$sold->id]['units_sold'])->toBe(5, '2 + 3 units, the cancelled 9 excluded')
        ->and($rows[$sold->id]['revenue_fils'])->toBe(10000)
        ->and($rows[$unsold->id]['orders_count'])->toBe(0)
        ->and($rows[$unsold->id]['units_sold'])->toBe(0)
        ->and($rows[$unsold->id]['revenue_fils'])->toBe(0);

    // The chip counts and the summary, which the sales join was removed from.
    expect($body['total'])->toBe(2)
        ->and($body['counts']['all'])->toBe(2)
        ->and($body['counts']['publish'])->toBe(2)
        ->and($body['counts']['no_category'])->toBe(1)
        ->and($body['summary']['products'])->toBe(2)
        ->and($body['summary']['priced'])->toBe(2)
        ->and($body['summary']['average_price_fils'])->toBe(6000);

    // And the chip still FILTERS as well as counting, which is the one place
    // the surviving derived table is read from a WHERE.
    $filtered = test()->getJson('/admin-api/catalog-products-list?filter=no_category')->assertOk()->json();

    expect(array_column($filtered['products'], 'id'))->toBe([$unsold->id]);

    // And the sort that reads the join that moved.
    $sorted = test()->getJson('/admin-api/catalog-products-list?sort=sales_desc')->assertOk()->json();

    expect(array_column($sorted['products'], 'id'))->toBe([$sold->id, $unsold->id]);
});
