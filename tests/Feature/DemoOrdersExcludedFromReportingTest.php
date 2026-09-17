<?php

declare(strict_types=1);

/*
 * DEMO ORDERS WERE COUNTED AS REAL MONEY.
 *
 * Store -> Demo Content seeds eight sample orders so the panel can be explored
 * before real data exists, and records every row it creates in `demo_seed_log`
 * so Remove can delete exactly those rows again. Nothing on `orders` marked a
 * row as demo, and no reporting query knew the log existed. So switching Demo
 * Content ON added invented revenue to the Dashboard, the Analytics screen and
 * the Customers screen; switching it OFF took it away again; and nothing on any
 * of those screens said a figure included money nobody had ever paid.
 *
 * These tests pin both halves of the fix:
 *
 *   - the money figures describe real orders only, and
 *   - the response SAYS how many demo rows it left out, so an owner who seeded
 *     demo data deliberately is not left wondering why the dashboard did not
 *     move. Excluding silently would be its own kind of lie.
 *
 * Every fixture below writes the demo rows the way DemoContentController writes
 * them — through demo_seed_log, by model and id — rather than inventing a
 * marker of its own, because the whole claim being tested is that the log is
 * already a sufficient answer.
 */

use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Support\DemoSeed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function demoAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'Demo Owner',
        'email' => 'demo-owner-' . uniqid() . '@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
}

function demoCustomer(string $label): Customer
{
    return Customer::create([
        'name' => $label,
        'first_name' => $label,
        'last_name' => 'Tester',
        'email' => strtolower($label) . '-' . uniqid() . '@example.test',
        'password' => 'secret-secret',
    ]);
}

function demoProduct(string $name, int $priceFils): Product
{
    return Product::create([
        'name' => $name,
        'slug' => \Illuminate\Support\Str::slug($name) . '-' . uniqid(),
        'sku' => 'SKU-' . uniqid(),
        'status' => 'publish',
        'is_visible' => true,
        'stock_status' => 'instock',
        'price' => $priceFils,
    ]);
}

/** One paid order with one line, at a fixed instant, for a fixed customer. */
function demoOrderFor(Customer $customer, Product $product, int $totalFils, ?string $utc = null): Order
{
    $order = Order::create([
        'order_number' => 'T-' . uniqid(),
        'customer_id' => $customer->id,
        'email' => $customer->email,
        'status' => 'completed',
        'currency' => 'AED',
        'subtotal' => $totalFils,
        'shipping_total' => 0,
        'discount_total' => 0,
        'fee_total' => 0,
        'tax_total' => 0,
        'total' => $totalFils,
        'created_at' => \Carbon\CarbonImmutable::parse($utc ?? '2026-09-16 09:00:00', 'UTC'),
    ]);

    OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'name' => $product->name,
        'brand' => 'Test Brand',
        'sku' => $product->sku,
        'quantity' => 2,
        'unit_price' => intdiv($totalFils, 2),
        'subtotal' => $totalFils,
        'total' => $totalFils,
    ]);

    return $order;
}

/** Mark a record as demo exactly the way DemoContentController::log() does. */
function markDemo(string $type, string $model, int $id): void
{
    DB::table(DemoSeed::TABLE)->insert([
        'type' => $type,
        'model' => $model,
        'record_id' => $id,
        'created_at' => now(),
    ]);
}

beforeEach(function () {
    Cache::flush();
    \App\Services\SettingsService::forgetMemo();
});

it('keeps demo orders out of every money figure and says how many it left out', function () {
    $admin = demoAdmin();
    $product = demoProduct('Real Serum', 5000);

    // Two real orders: AED 100 and AED 50.
    $real = demoCustomer('Real');
    demoOrderFor($real, $product, 10000);
    demoOrderFor($real, $product, 5000);

    // One demo order worth AED 900, logged the way the seeder logs it.
    $fake = demoCustomer('Fake');
    $fakeOrder = demoOrderFor($fake, $product, 90000);
    markDemo('orders', Order::class, $fakeOrder->id);
    markDemo('orders', Customer::class, $fake->id);

    $stats = $this->actingAs($admin, 'admin')->getJson('/admin-api/stats')->assertOk();

    // AED 150, not AED 1,050. The demo order is six times the real revenue —
    // chosen so a failure is unmistakable rather than a rounding argument.
    expect($stats->json('revenue_30d_aed'))->toBe(150)
        ->and($stats->json('orders'))->toBe(2)
        ->and($stats->json('paid_orders'))->toBe(2)
        ->and($stats->json('customers'))->toBe(1);

    // And it SAYS so, rather than quietly subtracting.
    expect($stats->json('demo.excluded'))->toBeTrue()
        ->and($stats->json('demo.orders'))->toBe(1)
        ->and($stats->json('demo.customers'))->toBe(1);

    $analytics = $this->actingAs($admin, 'admin')->getJson('/admin-api/analytics')->assertOk();

    expect($analytics->json('revenue_total_aed'))->toBe(150)
        ->and($analytics->json('paid_orders'))->toBe(2)
        ->and($analytics->json('orders_total'))->toBe(2)
        // AED 75 average, not AED 350.
        ->and($analytics->json('aov_aed'))->toBe(75)
        // 2 real orders x 2 units. The demo order's 2 units must not appear:
        // this half of the screen is an OrderItem query joined to `orders`, a
        // different code path from the totals above, and the two halves
        // disagreeing is exactly how this screen has broken before.
        ->and($analytics->json('units_sold'))->toBe(4)
        ->and($analytics->json('demo.excluded'))->toBeTrue()
        ->and($analytics->json('demo.orders'))->toBe(1);

    // The status breakdown is a count of orders, so it is a figure too.
    expect($analytics->json('status_breakdown.completed'))->toBe(2);

    $top = $analytics->json('top_products');
    $units = array_sum(array_column($top, 'units'));
    expect($units)->toBe(4);

    /*
     * FIGURES EXCLUDE DEMO ROWS; LISTS SHOW THEM AND MARK THEM.
     *
     * The Customers screen is a LIST, and putting rows on screens is the entire
     * point of Demo Content — hiding them would break the feature. So the demo
     * customer is still listed, badged `is_demo`, while the money COLUMN beside
     * them is real-orders-only and therefore reads zero. The dashboard's
     * customer COUNT above, which is a figure, leaves them out; `demo` is what
     * lets the screen explain the difference.
     */
    $customers = $this->actingAs($admin, 'admin')->getJson('/admin-api/customers')->assertOk();

    $rows = $customers->json('customers');

    $fakeRow = collect($rows)->firstWhere('email', $fake->email);
    expect($fakeRow)->not->toBeNull('the demo customer vanished from the Customers list')
        ->and($fakeRow['is_demo'])->toBeTrue('the demo customer is not marked as demo')
        ->and($fakeRow['spent_aed'])->toBe(0, 'a demo order counted towards a lifetime-spend column');

    $realRow = collect($rows)->firstWhere('email', $real->email);
    expect($realRow)->not->toBeNull()
        ->and($realRow['is_demo'])->toBeFalse()
        ->and($realRow['spent_aed'])->toBe(150)
        ->and($realRow['orders'])->toBe(2);

    expect($customers->json('demo.customers'))->toBe(1);

    // The Orders list, same rule: present and marked.
    $orders = $this->actingAs($admin, 'admin')->getJson('/admin-api/orders')->assertOk()->json('orders');
    $flagged = array_filter($orders, fn ($o) => $o['is_demo'] === true);

    expect(count($flagged))->toBe(1, 'the demo order is not marked on the Orders list')
        ->and(count($orders))->toBe(3);
});

it('reports nothing excluded when no demo content has been imported', function () {
    $admin = demoAdmin();
    $product = demoProduct('Only Real', 5000);
    demoOrderFor(demoCustomer('Solo'), $product, 10000);

    $stats = $this->actingAs($admin, 'admin')->getJson('/admin-api/stats')->assertOk();

    expect($stats->json('revenue_30d_aed'))->toBe(100)
        ->and($stats->json('demo.excluded'))->toBeFalse()
        ->and($stats->json('demo.orders'))->toBe(0);
});

/*
 * REMOVING DEMO CONTENT MUST NOT LEAVE AN ORPHANED FIGURE.
 *
 * This is the property that a derived exclusion buys and a stored `is_demo`
 * column would not: there is no flag to go stale. Delete the demo order and its
 * log row together — which is exactly what DemoContentController::removeType()
 * does — and the figures describe the same real rows as before, with the
 * disclosure back to zero.
 */
it('returns to plain real figures once demo content is removed', function () {
    $admin = demoAdmin();
    $product = demoProduct('Real Serum', 5000);
    $real = demoCustomer('Real');
    demoOrderFor($real, $product, 10000);

    $fake = demoCustomer('Fake');
    $fakeOrder = demoOrderFor($fake, $product, 90000);
    markDemo('orders', Order::class, $fakeOrder->id);

    $before = $this->actingAs($admin, 'admin')->getJson('/admin-api/stats')->assertOk();
    expect($before->json('revenue_30d_aed'))->toBe(100)
        ->and($before->json('demo.orders'))->toBe(1);

    // What Remove does: the row and the log entry, together.
    $fakeOrder->forceDelete();
    DB::table(DemoSeed::TABLE)->where('type', 'orders')->delete();

    $after = $this->actingAs($admin, 'admin')->getJson('/admin-api/stats')->assertOk();

    expect($after->json('revenue_30d_aed'))->toBe(100)
        ->and($after->json('demo.excluded'))->toBeFalse()
        ->and($after->json('demo.orders'))->toBe(0);
});

/*
 * A LOG ROW FOR A DIFFERENT MODEL MUST NOT HIDE AN ORDER.
 *
 * `demo_seed_log` holds a bare `record_id` alongside a `model`, and ids collide
 * across tables constantly — order 3 and product 3 both exist. An exclusion
 * that matched on record_id alone would delete real revenue from the dashboard
 * the moment the demo catalogue happened to contain a product with the same id
 * as a real order. The seeder itself creates products and customers under the
 * `orders` TYPE, so this is not a hypothetical shape.
 */
it('matches the demo log on model as well as id', function () {
    $admin = demoAdmin();
    $product = demoProduct('Real Serum', 5000);
    $real = demoCustomer('Real');
    $order = demoOrderFor($real, $product, 10000);

    // A demo PRODUCT whose id is the same number as a real ORDER's id.
    markDemo('orders', Product::class, $order->id);

    $stats = $this->actingAs($admin, 'admin')->getJson('/admin-api/stats')->assertOk();

    expect($stats->json('revenue_30d_aed'))
        ->toBe(100, 'a demo log row for another model suppressed a real order');
    expect($stats->json('demo.orders'))->toBe(0)
        ->and($stats->json('demo.products'))->toBe(1);
});

/*
 * The log table is created lazily by DemoContentController::ensureTable(), so a
 * build whose migration step was skipped has no such table until Demo Content
 * is first opened. The dashboard must answer, not 500.
 */
it('reports normally when the demo log table does not exist', function () {
    $admin = demoAdmin();
    $product = demoProduct('Real Serum', 5000);
    demoOrderFor(demoCustomer('Real'), $product, 10000);

    Schema::dropIfExists(DemoSeed::TABLE);

    $stats = $this->actingAs($admin, 'admin')->getJson('/admin-api/stats')->assertOk();

    expect($stats->json('revenue_30d_aed'))->toBe(100)
        ->and($stats->json('demo.excluded'))->toBeFalse();

    $this->actingAs($admin, 'admin')->getJson('/admin-api/analytics')->assertOk();
    $this->actingAs($admin, 'admin')->getJson('/admin-api/customers')->assertOk();
});

/*
 * The seeder's own output, end to end.
 *
 * The tests above build their own fixtures so they can name exact figures. This
 * one drives the real import endpoint and asserts the property that matters:
 * importing demo orders does not change a single money figure on any of the
 * three screens, and the disclosure tells the owner where their demo orders
 * went. If DemoContentController ever logs under a different model string, this
 * fails and the ones above do not.
 */
it('leaves every reported figure unchanged when demo orders are imported for real', function () {
    $admin = demoAdmin();
    $product = demoProduct('Real Serum', 5000);
    demoOrderFor(demoCustomer('Real'), $product, 10000);

    $before = $this->actingAs($admin, 'admin')->getJson('/admin-api/stats')->assertOk()->json();
    $beforeAnalytics = $this->actingAs($admin, 'admin')->getJson('/admin-api/analytics')->assertOk()->json();

    $this->actingAs($admin, 'admin')->postJson('/admin-api/demo-content/orders/import')->assertOk();

    expect(Order::count())->toBeGreaterThan(1, 'the demo import created no orders, so this test proves nothing');

    $after = $this->actingAs($admin, 'admin')->getJson('/admin-api/stats')->assertOk();
    $afterAnalytics = $this->actingAs($admin, 'admin')->getJson('/admin-api/analytics')->assertOk();

    foreach (['revenue_30d_aed', 'orders', 'paid_orders', 'customers'] as $key) {
        expect($after->json($key))->toBe($before[$key], "dashboard '{$key}' moved when demo orders were imported");
    }

    foreach (['revenue_total_aed', 'orders_total', 'paid_orders', 'aov_aed', 'units_sold'] as $key) {
        expect($afterAnalytics->json($key))->toBe($beforeAnalytics[$key], "analytics '{$key}' moved when demo orders were imported");
    }

    expect($after->json('demo.excluded'))->toBeTrue()
        ->and($after->json('demo.orders'))->toBe(8);
});

/*
 * The Customers screen the owner actually opens is /admin-api/customers/list,
 * served by CustomersApiController -- a different controller from the legacy
 * /admin-api/customers above. Its lifetime-spend column, its store-wide average
 * order value and every date on it were all computed without knowing demo
 * content or the shop's clock existed.
 */
it('keeps demo orders out of the Customers screen spend column', function () {
    $admin = demoAdmin();
    $product = demoProduct('Real Serum', 5000);

    $real = demoCustomer('Real');
    demoOrderFor($real, $product, 10000);

    $fake = demoCustomer('Fake');
    $fakeOrder = demoOrderFor($fake, $product, 90000);
    markDemo('orders', Order::class, $fakeOrder->id);

    $body = $this->actingAs($admin, 'admin')
        ->getJson('/admin-api/customers/list')
        ->assertOk();

    $rows = collect($body->json('customers'));

    $realRow = $rows->firstWhere('email', $real->email);
    expect($realRow)->not->toBeNull()
        ->and($realRow['spend_fils'])->toBe(10000);

    $fakeRow = $rows->firstWhere('email', $fake->email);
    expect($fakeRow)->not->toBeNull()
        ->and($fakeRow['spend_fils'])->toBe(0, 'a demo order counted towards a lifetime-spend column');

    // The store-wide summary is built from the same aggregate, so it must agree.
    expect($body->json('summary.spend_fils'))
        ->toBe(10000, 'the store-wide lifetime revenue still includes demo orders');
});

it('dates the Customers screen on the shop clock', function () {
    $admin = demoAdmin();

    $customer = Customer::create([
        'name' => 'Night Owl', 'first_name' => 'Night', 'last_name' => 'Owl',
        'email' => 'owl-' . uniqid() . '@example.test', 'password' => 'secret-secret',
        // 01:30 on the 16th in Dubai; stored as 21:30 on the 15th, UTC.
        'created_at' => \Carbon\CarbonImmutable::parse('2026-09-15 21:30:00', 'UTC'),
    ]);

    app(\App\Services\SettingsService::class)->set(\App\Support\StoreTime::SETTING_KEY, 'Asia/Dubai');

    $rows = collect($this->actingAs($admin, 'admin')
        ->getJson('/admin-api/customers/list')
        ->assertOk()
        ->json('customers'));

    $row = $rows->firstWhere('email', $customer->email);
    expect($row)->not->toBeNull();

    $joined = (string) ($row['registered_at'] ?? '');

    expect(substr($joined, 0, 10))->toBe('2026-09-16', "the Customers screen still shows a UTC day: {$joined}")
        // Same wire shape as toIso8601String() produced, offset included, so
        // the console's positional slices keep working.
        ->and(preg_match('/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}[+-]\\d{2}:\\d{2}$/', $joined))
        ->toBe(1, "the wire shape changed: {$joined}");
});

/*
|==============================================================================
| THE DISCLOSURE MENTIONS REVIEWS — Lane DT
|==============================================================================
|
| Lane DO closed demo reviews off from the storefront and flagged what it could
| not finish: DemoSeed::counts() covered orders, customers and products and said
| nothing about reviews. So the admin line that tells the owner which figures
| leave demo rows out was silent about the largest fabrication in the shop —
| the seeded wall is 12,481 reviews averaging 4.8 stars.
|
| It is additive, but it widens a shape four endpoints emit, which is why DO
| left it alone. What is pinned here:
|
|   - every one of the four endpoints carries the new key;
|   - the count is RIGHT, across BOTH of the mechanisms that write demo
|     reviews — `source = 'demo'` from the seeder, and a demo_seed_log row from
|     the admin panel — because a count that knew only one of them would be a
|     new understatement in place of the old silence;
|   - `excluded` DID NOT CHANGE MEANING. This is the trap. It used to be
|     `array_sum(counts) > 0`, so folding reviews into the sum was the one-line
|     version — and it would have made the dashboard banner announce "0 demo
|     orders are excluded from these figures" on a shop that has demo reviews
|     and no demo orders, which is the shipped state of a fresh install.
*/

/** A review the seeder wrote: stamped `source`, never logged. */
function demoSeededReview(Product $product): \App\Models\Review
{
    return \App\Models\Review::create([
        'product_id' => $product->id,
        'author_name' => 'Invented Person ' . uniqid(),
        'rating' => 5,
        'content' => 'Written by a seeder, not by a customer.',
        'status' => \App\Support\ReviewStatus::APPROVED,
        'source' => \Database\Seeders\DemoReviewsSeeder::SOURCE,
    ]);
}

/** A review the ADMIN PANEL seeded: logged, and (on older rows) unstamped. */
function demoLoggedReview(Product $product): \App\Models\Review
{
    $review = \App\Models\Review::create([
        'product_id' => $product->id,
        'author_name' => 'Logged Only Person ' . uniqid(),
        'rating' => 5,
        'content' => 'Seeded from the admin panel before it stamped a source.',
        'status' => \App\Support\ReviewStatus::APPROVED,
    ]);

    markDemo('reviews', \App\Models\Review::class, $review->id);

    return $review;
}

/** A review an actual shopper left. */
function demoRealReview(Product $product): \App\Models\Review
{
    return \App\Models\Review::create([
        'product_id' => $product->id,
        'author_name' => 'Real Shopper ' . uniqid(),
        'rating' => 4,
        'content' => 'A genuine review left by a genuine customer of this shop.',
        'status' => \App\Support\ReviewStatus::APPROVED,
    ]);
}

it('counts demo reviews from BOTH mechanisms, and counts a real one as neither', function () {
    $product = demoProduct('Reviewed Serum', 5000);

    $before = DemoSeed::counts()['reviews'];

    demoSeededReview($product);
    demoLoggedReview($product);
    demoRealReview($product);

    // Two invented, one real. A count built on `source` alone would say one;
    // a count built on the log alone would also say one.
    expect(DemoSeed::counts()['reviews'])->toBe(
        $before + 2,
        'the review count does not cover both of the two ways a demo review is written',
    );
});

it('carries the review count on every endpoint that carries the disclosure', function () {
    $admin = demoAdmin();
    $product = demoProduct('Reviewed Serum', 5000);

    demoSeededReview($product);
    demoLoggedReview($product);

    $expected = DemoSeed::counts()['reviews'];

    expect($expected)->toBeGreaterThan(0, 'the fixture seeded no demo reviews, so this proves nothing');

    /*
     * All four, by name. The shape is additive, so a screen that names the old
     * keys still finds them — but a screen the integrator forgot would be the
     * silence this change exists to end, on that screen only.
     */
    foreach ([
        '/admin-api/stats',
        '/admin-api/orders',
        '/admin-api/analytics',
        '/admin-api/customers',
    ] as $endpoint) {
        $demo = $this->actingAs($admin, 'admin')->getJson($endpoint)->assertOk()->json('demo');

        expect($demo)->toBeArray("{$endpoint} stopped carrying a demo disclosure block");

        /*
         * array_key_exists, NOT ->toHaveKey($key, $message). Pest reads that
         * second argument as the EXPECTED VALUE, so a "message" there asserts
         * the key holds that sentence and fails on every healthy response.
         */
        foreach (['excluded', 'orders', 'customers', 'products'] as $old) {
            expect(array_key_exists($old, $demo))
                ->toBeTrue("{$endpoint} dropped the '{$old}' key some screen already reads");
        }

        expect(array_key_exists('reviews', $demo))
            ->toBeTrue("{$endpoint} does not disclose demo reviews");

        expect($demo['reviews'])->toBe($expected, "{$endpoint} reports a different number of demo reviews");
    }
});

it('does not flip "excluded" on for demo reviews alone', function () {
    $admin = demoAdmin();
    $product = demoProduct('Reviewed Serum', 5000);

    // Demo REVIEWS and no demo orders, customers or products — a fresh install.
    demoSeededReview($product);
    demoLoggedReview($product);

    $demo = $this->actingAs($admin, 'admin')->getJson('/admin-api/stats')->assertOk()->json('demo');

    expect($demo['reviews'])->toBeGreaterThan(0);

    /*
     * THE WHOLE POINT OF THIS TEST. The dashboard banner reads
     *
     *   (s.demo && s.demo.excluded) ? s.demo.orders + ' demo orders are
     *   excluded from these figures.' : ''
     *
     * so `excluded` being true here would print "0 demo orders are excluded
     * from these figures" — a disclosure stating a figure that is not true,
     * which is worse than the silence it replaced.
     */
    expect($demo['excluded'])->toBeFalse(
        'demo reviews flipped the sales-figure disclosure on; the dashboard would now announce "0 demo orders are excluded"',
    );

    expect($demo['orders'])->toBe(0);
});

it('still flips "excluded" on for a demo order, reviews or no reviews', function () {
    $admin = demoAdmin();
    $product = demoProduct('Reviewed Serum', 5000);

    demoSeededReview($product);

    $fake = demoCustomer('Fake');
    markDemo('customers', Customer::class, $fake->id);

    $demo = $this->actingAs($admin, 'admin')->getJson('/admin-api/stats')->assertOk()->json('demo');

    expect($demo['excluded'])->toBeTrue('a logged demo customer no longer flips the disclosure on')
        ->and($demo['customers'])->toBe(1)
        ->and($demo['reviews'])->toBeGreaterThan(0);
});

it('answers zero reviews on a shop that has seeded none', function () {
    $admin = demoAdmin();

    demoRealReview(demoProduct('Reviewed Serum', 5000));

    $demo = $this->actingAs($admin, 'admin')->getJson('/admin-api/stats')->assertOk()->json('demo');

    expect($demo['reviews'])->toBe(0, 'a real review was counted as invented')
        ->and($demo['excluded'])->toBeFalse();
});
