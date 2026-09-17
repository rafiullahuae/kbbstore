<?php

declare(strict_types=1);

/**
 * Lane DZ — /best-sellers/ said "customers keep coming back" and counted units.
 *
 * ── THE CLAIM, AND THE MEASUREMENT UNDER IT ─────────────────────────────────
 *
 * Store\CollectionController::COLLECTIONS['best-sellers'] pairs the sentence
 *
 *     "The products our customers keep coming back for."
 *
 * with the selection `ORDER BY total_sales DESC, review_count DESC`.
 *
 * `total_sales` counts UNITS. It is incremented per item sold and imported
 * wholesale from WooCommerce, and it cannot distinguish three hundred people
 * buying one jar each from one person buying three hundred jars. Those are the
 * same number in that column and only the second is what the sentence says.
 * There is no repeat purchase in `total_sales`, in any form, at all.
 *
 * ── IT IS COMPUTABLE, WHICH IS WHY THIS IS NOT A COPY EDIT ──────────────────
 *
 * `order_items.product_id`, `orders.email`, `orders.status` and one order id per
 * purchase occasion are all present, so App\Support\RepeatPurchase measures the
 * thing the sentence claims: how many separate people bought a product in more
 * than one order. The tests below pin the measurement, the two wordings it
 * chooses between, and — through Tests\Support\SqlShape — that the statement it
 * issues survives MySQL as well as the SQLite this suite runs on.
 *
 * The wiring into the collection page is the integrator's, because that
 * controller belongs to another lane this round. cbPendingIntroWiring() carries
 * the instruction and the guard that turns itself on when it lands.
 */

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Support\RepeatPurchase;
use Illuminate\Support\Str;
use Tests\Support\SqlShape;

beforeEach(function () {
    RepeatPurchase::forget();
});

afterEach(function () {
    RepeatPurchase::forget();
});

function rpcProduct(int $totalSales = 0): Product
{
    return Product::create([
        'slug' => 'rp-' . Str::random(10),
        'name' => 'Rice Toner ' . Str::random(4),
        'status' => 'publish',
        'is_visible' => true,
        'price' => 9900,
        'stock_status' => 'instock',
        'total_sales' => $totalSales,
    ]);
}

/**
 * One order, by one email, containing the given products.
 *
 * @param  array<int, array{0: Product, 1?: int}>  $lines
 */
function rpcOrder(string $email, array $lines, string $status = 'completed'): Order
{
    $order = Order::create([
        'order_number' => 'RP-' . Str::random(10),
        'email' => $email,
        'status' => $status,
        'currency' => 'AED',
        'total' => 9900,
    ]);

    foreach ($lines as $line) {
        [$product, $quantity] = $line + [1 => 1];

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'name' => $product->name,
            'quantity' => $quantity,
            'unit_price' => 9900,
            'subtotal' => 9900 * $quantity,
            'total' => 9900 * $quantity,
        ]);
    }

    return $order;
}

/*
|------------------------------------------------------------------------------
| 1. The premise: total_sales is not repeat purchase
|------------------------------------------------------------------------------
*/

it('counts nobody as coming back for the product with the highest units sold', function () {
    /*
     * The exact shape the old page got wrong. One product has sold five hundred
     * units to a single customer on a single order; the other has sold four
     * units to two people who each came back. `total_sales` ranks the first one
     * top and the sentence underneath describes the second one.
     */
    $volume = rpcProduct(500);
    $loved = rpcProduct(4);

    rpcOrder('bulk@example.test', [[$volume, 500]]);

    rpcOrder('sara@example.test', [[$loved]]);
    rpcOrder('sara@example.test', [[$loved]]);
    rpcOrder('mona@example.test', [[$loved]]);
    rpcOrder('mona@example.test', [[$loved]]);

    $counts = RepeatPurchase::counts();

    expect($counts)->not->toHaveKey($volume->id);
    expect($counts[$loved->id] ?? null)->toBe(2);

    // And the column the page sorts by says the opposite.
    expect((int) $volume->total_sales)->toBeGreaterThan((int) $loved->total_sales);
});

it('does not call two jars in one basket a customer coming back', function () {
    $product = rpcProduct(2);

    rpcOrder('sara@example.test', [[$product, 2]]);

    expect(RepeatPurchase::counts())->toBe([]);
});

it('treats one person writing their address two ways as one person', function () {
    $product = rpcProduct();

    rpcOrder('Sara@Example.test', [[$product]]);
    rpcOrder('sara@example.test', [[$product]]);

    expect(RepeatPurchase::counts()[$product->id] ?? null)->toBe(1);
});

it('counts only orders the shop itself treats as real', function () {
    /*
     * Order::REAL_STATUSES is the shop's own definition, already shared by the
     * revenue figures. A cancelled order is not a purchase and a soft-deleted
     * one is an order the owner decided did not count, so neither may be
     * evidence that somebody came back.
     */
    $cancelled = rpcProduct();
    $deleted = rpcProduct();
    $real = rpcProduct();

    rpcOrder('a@example.test', [[$cancelled]], 'cancelled');
    rpcOrder('a@example.test', [[$cancelled]], 'cancelled');

    rpcOrder('b@example.test', [[$deleted]])->delete();
    rpcOrder('b@example.test', [[$deleted]])->delete();

    rpcOrder('c@example.test', [[$real]], 'shipped');
    rpcOrder('c@example.test', [[$real]], 'processing');

    $counts = RepeatPurchase::counts();

    expect($counts)->not->toHaveKey($cancelled->id);
    expect($counts)->not->toHaveKey($deleted->id);
    expect($counts[$real->id] ?? null)->toBe(1);
});

/*
|------------------------------------------------------------------------------
| 2. The sentence follows the measurement
|------------------------------------------------------------------------------
*/

it('will not say customers keep coming back on a shop where nobody has', function () {
    /*
     * THE COMMON CASE, and the reason this is a condition rather than a rewrite.
     * A fresh install, a staging copy, the day before the order import lands:
     * no history at all. The page still has best sellers to show — `total_sales`
     * arrives with the catalogue — but it has no evidence of loyalty, so it says
     * what it is actually sorted by.
     */
    rpcProduct(900);

    expect(RepeatPurchase::any())->toBeFalse();
    expect(RepeatPurchase::intro())->toBe(RepeatPurchase::INTRO_BY_UNITS);
    expect(mb_strtolower(RepeatPurchase::INTRO_BY_UNITS))->toContain('units sold');
});

it('says exactly the sentence that shipped once the shop can stand behind it', function () {
    // Unchanged wording, deliberately: when it is true, nothing about the page
    // needs to change.
    $product = rpcProduct();

    rpcOrder('sara@example.test', [[$product]]);
    rpcOrder('sara@example.test', [[$product]]);

    expect(RepeatPurchase::any())->toBeTrue();
    expect(RepeatPurchase::intro())->toBe('The products our customers keep coming back for.');
});

/*
|------------------------------------------------------------------------------
| 3. The ordering, and what it costs
|------------------------------------------------------------------------------
*/

it('puts the products people came back for above the ones that merely shifted units', function () {
    $volume = rpcProduct(500);
    $loved = rpcProduct(4);

    rpcOrder('bulk@example.test', [[$volume, 500]]);
    rpcOrder('sara@example.test', [[$loved]]);
    rpcOrder('sara@example.test', [[$loved]]);

    $ordered = RepeatPurchase::applyTo(Product::query()->visible())->pluck('id')->all();

    expect(array_search($loved->id, $ordered, true))
        ->toBeLessThan(array_search($volume->id, $ordered, true));
});

it('orders exactly as the page always has when nobody has come back', function () {
    /*
     * The safety property. With no repeat history the CASE is not emitted at
     * all, so the statement is the one /best-sellers/ has always issued and a
     * shop that applies this package sees its listing in the same order.
     */
    $high = rpcProduct(900);
    $low = rpcProduct(3);

    $applied = RepeatPurchase::applyTo(Product::query()->visible())->toSql();
    $today = Product::query()->visible()->orderByDesc('total_sales')->orderByDesc('review_count')->toSql();

    expect($applied)->toBe($today);

    $ordered = RepeatPurchase::applyTo(Product::query()->visible())->pluck('id')->all();

    expect(array_search($high->id, $ordered, true))
        ->toBeLessThan(array_search($low->id, $ordered, true));
});

it('issues a statement MySQL will also accept', function () {
    /*
     * The suite runs on SQLite and the shop runs MySQL, so the measurement is
     * judged on the SQL it ISSUES rather than the answer it returns — the same
     * guard the storefront's own screens are held to. A derived table, a
     * COUNT(DISTINCT), a LOWER() in a GROUP BY and an alias in ORDER BY are each
     * a place the two engines could have parted company.
     */
    $product = rpcProduct();

    rpcOrder('sara@example.test', [[$product]]);
    rpcOrder('sara@example.test', [[$product]]);

    RepeatPurchase::forget();

    $sql = SqlShape::capture(function () use ($product) {
        expect(RepeatPurchase::counts()[$product->id] ?? null)->toBe(1);

        RepeatPurchase::applyTo(Product::query()->visible())->get();
    });

    expect(SqlShape::violations($sql))->toBe([]);
});

it('measures once and serves the rest from the cache', function () {
    // A public listing page must not scan the order history on every request.
    $product = rpcProduct();

    rpcOrder('sara@example.test', [[$product]]);
    rpcOrder('sara@example.test', [[$product]]);

    RepeatPurchase::forget();
    RepeatPurchase::counts();

    $sql = SqlShape::capture(static function () {
        RepeatPurchase::counts();
        RepeatPurchase::counts();
    });

    $touchedOrders = array_filter(
        $sql,
        static fn (array $entry) => str_contains($entry['sql'], 'order_items')
    );

    expect($touchedOrders)->toBe([]);
});

/*
|------------------------------------------------------------------------------
| 4. The collection page, and the edit it is waiting for
|------------------------------------------------------------------------------
*/

/**
 * THE INTEGRATOR'S EDIT, AND WHERE IT GOES.
 *
 * File: app/Http/Controllers/Store/CollectionController.php (another lane's this
 * round). BOTH lines, together — they are a pair, and either alone is a page
 * that describes itself wrongly in the other direction.
 *
 * 1. In the COLLECTIONS constant, the 'best-sellers' entry's intro is a literal
 *    and a constant cannot hold a call, so the intro is resolved in show().
 *    REPLACE:
 *
 *        'best-sellers' => [
 *            'Best Sellers',
 *            'The products our customers keep coming back for.',
 *            'popular',
 *        ],
 *
 *    WITH:
 *
 *        'best-sellers' => [
 *            'Best Sellers',
 *            // Resolved in show(): the sentence is only true if the order
 *            // history says so. App\Support\RepeatPurchase measures it.
 *            '',
 *            'popular',
 *        ],
 *
 *    and, immediately after `[$title, $intro, $mode] = self::COLLECTIONS[$key];`
 *    in show():
 *
 *        if ($mode === 'popular') {
 *            $intro = \App\Support\RepeatPurchase::intro();
 *        }
 *
 * 2. In the `match ($mode)` block, REPLACE:
 *
 *        'popular' => $query->orderByDesc('total_sales')->orderByDesc('review_count'),
 *
 *    WITH:
 *
 *        'popular' => \App\Support\RepeatPurchase::applyTo($query),
 *
 *    which issues that exact ordering, unchanged, on any shop with no repeat
 *    purchases in its history.
 *
 * 3. tests/Feature/SeoCrawlSurfaceTest.php (~line 245) asserts the old sentence
 *    in the page's <head>. It builds no orders, so the honest answer there is
 *    the units-sold one. REPLACE:
 *
 *        expect($best)->toContain('The products our customers keep coming back for')
 *
 *    WITH:
 *
 *        expect($best)->toContain(\App\Support\RepeatPurchase::intro())
 *
 *    which keeps that test's point — two collections must not describe
 *    themselves identically — while letting the sentence follow the data.
 *
 * THEN FLIP cbPendingIntroWiring() TO false.
 */
function cbPendingIntroWiring(): bool
{
    return true;
}

it('describes /best-sellers/ with a measurement it actually made', function () {
    $intro = static function (): string {
        $html = test()->get('/best-sellers')->assertOk()->getContent();
        $m = [];

        expect(preg_match('/<h1>Best Sellers.*?<\/h1>\s*<p>(.*?)<\/p>/s', $html, $m))->toBe(1);

        return trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
    };

    if (cbPendingIntroWiring()) {
        /*
         * Waiting on the integrator; the instruction is above, in
         * cbPendingIntroWiring()'s docblock. Two things are asserted meanwhile so
         * the note cannot rot: the page still prints the unmeasured claim (the
         * anchor the instruction names), and RepeatPurchase already answers —
         * so the edit cannot land on a call that does not work.
         */
        expect($intro())->toBe(RepeatPurchase::INTRO_MEASURED);
        expect(RepeatPurchase::any())->toBeFalse();
        expect(RepeatPurchase::intro())->toBe(RepeatPurchase::INTRO_BY_UNITS);

        return;
    }

    // With no order history the page says what it is sorted by.
    rpcProduct(100);

    expect($intro())->toBe(RepeatPurchase::INTRO_BY_UNITS);

    // And earns the other sentence the moment somebody comes back.
    $product = rpcProduct(5);
    rpcOrder('sara@example.test', [[$product]]);
    rpcOrder('sara@example.test', [[$product]]);
    RepeatPurchase::forget();

    expect($intro())->toBe(RepeatPurchase::INTRO_MEASURED);
});

it('leaves nothing pending that the collection page has already done', function () {
    expect(cbPendingIntroWiring())->toBeBool();

    if (! cbPendingIntroWiring()) {
        return;
    }

    $source = (string) file_get_contents(app_path('Http/Controllers/Store/CollectionController.php'));

    expect(str_contains($source, 'RepeatPurchase'))
        ->toBeFalse('CollectionController already calls RepeatPurchase, so cbPendingIntroWiring() must be false.');
});
