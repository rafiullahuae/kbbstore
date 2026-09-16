<?php

declare(strict_types=1);

use App\Models\Brand;
use App\Models\Cart;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Page;
use App\Models\Post;
use App\Models\Product;
use App\Services\CartService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * What every storefront page is allowed to cost, and the proof it does not grow.
 *
 * TWO DIFFERENT THINGS ARE PINNED HERE, and the second is the one that matters.
 *
 *   1. A BUDGET — an upper bound per page. Cheap to read, and it fails loudly
 *      when a page suddenly doubles.
 *
 *   2. FLATNESS — the same page measured against a catalogue of 24 products and
 *      again against 84, asserting the two counts are IDENTICAL. A budget alone
 *      cannot catch an N+1: a page doing one query per product passes any
 *      budget you like on a small enough fixture, which is exactly how /shop
 *      reached 390 queries for four products without any test noticing. A count
 *      that does not move when the catalogue triples is the only evidence that
 *      the page batches its loads.
 *
 * Two N+1s were found and fixed by this file's measurements:
 *
 *   - SettingsService::get() fell back to `Setting::find($key)` for any key
 *     outside the autoload map, ONE SELECT PER DISTINCT KEY. The shared header,
 *     footer and product chrome read around 105 such keys, so every storefront
 *     page paid 105-126 single-row SELECTs: the homepage was 164 queries, the
 *     product page 136, /shop 112. Reading the table once took them to 40, 11
 *     and 8. This was on EVERY page of the site.
 *
 *   - BrandController::show() eager-loaded neither `brand` nor `categories`,
 *     and the <x-product-grid> component it renders through reads both, so each
 *     tile cost two more queries. The page went 12 -> 30 as the brand's
 *     catalogue grew past the twelve it previews; it is now flat.
 *
 * WHY THERE IS A WARM-UP PASS. Several caches here live for the life of the
 * PROCESS rather than the request — Setting::map() memoises in a function
 * static that nothing can reach, and the test cache store is the array driver.
 * Under PHP-FPM those are per-request, but in one test process the first
 * request through a page pays for all of them and every later one does not.
 * Measuring cold-then-warm would report a fall that has nothing to do with the
 * catalogue, so every page is requested once and discarded before the two real
 * measurements, which then differ only by the data.
 */

/**
 * Start each measured request the way PHP-FPM starts a real one.
 *
 * TWO KINDS OF PER-REQUEST STATE, and forgetting either one flatters the page.
 *
 * SettingsService::$memo is a plain static, so it survives anything.
 *
 * The other kind is subtler and cost this file a wrong answer. CartService,
 * SettingsService and (Lane BQ) the shipping lookups are `scoped` bindings:
 * production throws the whole container away between requests, so scoped means
 * per request. A TEST process keeps ONE container across many requests, and
 * nothing in Laravel's test client resets scoped instances — so the second
 * request through a page was reusing the first one's memos and reporting a
 * count no real visitor ever gets. Measured directly while adding the cart and
 * checkout pages below: /checkout read 7 with the leak and 11 without it, and
 * the homepage 2 against 4. The ceilings in this file are the honest numbers.
 *
 * What is deliberately NOT reset is the cache store. That is shared between
 * requests in production too — a file cache on this host — so a warm cache is
 * the normal case, not a measurement artifact. See the warm-up note below.
 */
function budgetReset(): void
{
    SettingsService::forgetMemo();
    app()->forgetScopedInstances();
}

/**
 * One DB listener PER APPLICATION INSTANCE, counting into a bucket that outlives
 * them all and that the caller clears.
 *
 * There are two opposite ways to get this wrong and this function has to dodge
 * both.
 *
 * Registering a listener per measurement is the first. Listeners ACCUMULATE,
 * they do not replace, so capturing the counter by reference and re-listening
 * each time makes the Nth page report N times its real query count. That
 * mistake reported this suite's own /sitemap.xml at 416 queries when it runs 20.
 *
 * Registering exactly once for the whole process is the second, and it is the
 * one this file actually shipped with. Pest builds a FRESH APPLICATION for every
 * test, and the connection the listener was attached to goes with the old one.
 * The `static $bucket` survives that refresh, so the guard saw a non-null bucket
 * and never re-listened — from the second test onward NOTHING WAS COUNTED and
 * every measurement was 0. Both tests below still passed: the budgets passed
 * because 0 is under every ceiling, and the flatness test, the one this file's
 * header calls the one that matters, passed because it compared 0 against 0.
 * Proven directly: two queries in the first test counted 2, the same two in the
 * second counted 0.
 *
 * So the listener is bound to the application instance instead. One per app,
 * re-registered when the app is replaced: no accumulation within a test, no
 * silence across them.
 */
function budgetBucket(): object
{
    static $bucket = null;
    static $listeningOn = null;

    if ($bucket === null) {
        $bucket = new class
        {
            public int $n = 0;
        };
    }

    // Object identity, not a boolean: a new application means a new connection
    // with no listener on it, and that is the only time we may register again.
    if ($listeningOn !== app()) {
        $listeningOn = app();

        DB::listen(function () use ($bucket) {
            $bucket->n++;
        });
    }

    return $bucket;
}

/**
 * Query count for one request.
 *
 * `$cartToken` puts a real basket in the visitor's hands. /cart and /checkout
 * are the two pages whose cost is ENTIRELY about the lines in the bag, and
 * until it was added this file measured /cart empty and did not measure
 * /checkout at all — so the six queries the cart page spent re-fetching its own
 * lines, and the eight the checkout spent re-asking for the same shipping zone,
 * were invisible here.
 */
function budgetCount(string $path, ?int $customerId = null, ?string $cartToken = null): int
{
    $bucket = budgetBucket();
    budgetReset();
    $bucket->n = 0;

    /*
     * EVERY request states its cart cookie, including the ones that have no
     * cart.
     *
     * Laravel's test client accumulates cookies for the life of the TEST, not
     * the request: one withUnencryptedCookie() and every later get() in the
     * same test carries it. So the moment the cart pages below were added, the
     * homepage silently started being measured with a six-line basket in hand
     * and read 7 queries instead of 2 -- and it would have read something
     * different again if the pages were listed in another order. A budget that
     * depends on where its page sits in a list is not a budget.
     *
     * The no-cart case sends a token that matches nothing rather than no cookie
     * at all, deliberately. That is the state every shopper is in right after
     * checking out -- the cart row is marked `converted` and the browser keeps
     * the cookie -- so it is the common case, and it is the one that used to
     * cost two `select * from carts` per page instead of one.
     */
    $test = test()
        ->withCredentials()
        ->withoutMiddleware(\Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cartToken ?? 'budget-no-such-cart');

    if ($customerId !== null) {
        // The session key the customer guard reads — not actingAs(), which
        // changes the application's default guard. See AccountAreaTest's header.
        $test = $test->withSession(['login_customer_' . sha1(\Illuminate\Auth\SessionGuard::class) => $customerId]);
    }

    $test->get($path)->assertSuccessful();

    return $bucket->n;
}

/** One entry of budgetPages(), measured. */
function budgetRun(array $page): int
{
    return budgetCount($page[0], $page[1], $page[3] ?? null);
}

/** Catalogue, content, and a customer with an order — the realistic fixture. */
function budgetSeed(): array
{
    test()->seed(\Database\Seeders\DatabaseSeeder::class);
    test()->seed(\Database\Seeders\DemoReviewsSeeder::class);

    for ($i = 0; $i < 6; $i++) {
        Post::create([
            'slug' => 'budget-article-' . $i,
            'title' => 'Budget Article ' . $i,
            'body' => '<p>Body.</p>',
            'excerpt' => 'Excerpt.',
            'status' => 'published',
            'published_at' => now()->subDays($i),
        ]);
    }

    foreach (['delivery', 'refund_returns', 'faqs', 'about', 'contact-us'] as $slug) {
        Page::firstOrCreate(
            ['slug' => $slug],
            ['title' => ucfirst($slug), 'content' => '<p>Placeholder.</p>', 'status' => 'published']
        );
    }

    $customer = Customer::create([
        'name' => 'Ada Shopper',
        'email' => 'budget@example.com',
        'password' => 'password123',
    ]);

    $order = Order::create([
        'order_number' => 'BUD00001',
        'customer_id' => $customer->id,
        'email' => $customer->email,
        'status' => 'processing',
        'currency' => 'AED',
        'subtotal' => 20000, 'discount_total' => 0, 'shipping_total' => 2000,
        'fee_total' => 0, 'gift_fee' => 0, 'tax_total' => 0, 'total' => 22000,
        'shipping_method' => 'Standard delivery',
        'payment_method' => 'cod',
        'payment_method_title' => 'Cash on delivery',
        'shipping_address' => [
            'first_name' => 'Ada', 'last_name' => 'Shopper', 'line1' => '12 Marina Walk',
            'city' => 'Dubai', 'state' => 'Dubai', 'country' => 'AE', 'phone' => '+971500000000',
        ],
    ]);

    // Several lines, so an order-detail N+1 on the line's product would show.
    for ($i = 0; $i < 4; $i++) {
        $order->items()->create([
            'name' => 'Line ' . $i, 'quantity' => 1,
            'unit_price' => 5000, 'subtotal' => 5000, 'total' => 5000,
        ]);
    }

    $customer->addresses()->create([
        'first_name' => 'Ada', 'last_name' => 'Shopper', 'line1' => '12 Marina Walk',
        'city' => 'Dubai', 'country' => 'AE',
    ]);

    /*
     * A basket with SIX DIFFERENT PRODUCTS in it.
     *
     * Six rather than one because the cost being measured is per line: a cart
     * of one hides a per-line product lookup completely, and /cart and
     * /checkout are the two pages where that is the whole cost. Six distinct
     * products, so the lines cannot be batched by accident.
     *
     * Not attached to the customer above -- a guest basket, carried by the
     * cookie, which is how the overwhelming majority of them arrive.
     */
    $cart = Cart::create([
        'token' => (string) Str::uuid(),
        'currency' => 'AED',
        'status' => 'active',
        'shipping_country' => 'AE',
        'last_activity_at' => now(),
    ]);

    foreach (Product::query()->visible()->limit(6)->get() as $line) {
        $cart->items()->create([
            'product_id' => $line->id,
            'quantity' => 2,
            'unit_price' => $line->effectivePrice(),
        ]);
    }

    return [
        'customer' => $customer,
        'order' => $order,
        'cart' => $cart,
        'brand' => Brand::query()->first(),
        'category' => Category::query()->first(),
        'product' => Product::query()->visible()->first(),
    ];
}

/** Add products to the same brand AND category, so every listing page grows. */
function budgetGrow(int $count, Brand $brand, Category $category): void
{
    for ($i = 0; $i < $count; $i++) {
        $product = Product::create([
            'slug' => 'budget-grow-' . $i . '-' . Str::random(6),
            'name' => 'Budget Grow Product ' . $i,
            'sku' => 'BUDGROW-' . $i,
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'type' => 'simple',
            'status' => 'publish',
            'is_visible' => true,
            // Cheap and on sale, so the sale and under-54 collections fill too.
            'price' => 4000 + $i,
            'sale_price' => 3000 + $i,
            'stock_status' => 'instock',
            'rating' => 4.5,
            'review_count' => 3,
        ]);

        $product->categories()->syncWithoutDetaching([$category->id]);
    }
}

/**
 * Every storefront page worth measuring, with the ceiling it must stay under.
 *
 * The budgets are the measured counts plus two, not aspirations. A page that
 * legitimately needs more should have this number raised deliberately, in a
 * commit that says why.
 *
 * WHAT THE NUMBERS WERE AND ARE. Lane BQ, measured by this file on this
 * fixture — 24 demo products, a six-line basket, a customer with an order —
 * with the per-request reset budgetReset() now does. Ceilings are set from the
 * AFTER column, and every one of them is BELOW the corresponding BEFORE, so
 * undoing any of the four changes fails the page that change was for:
 *
 *                       before  after  ceiling
 *   home                   7      3       5
 *   shop                  10      6       8
 *   shop sorted           10      6       8
 *   shop filtered         11      7       9
 *   category              12      7       9
 *   brand index           16     12      14
 *   brand page            11      7       9
 *   product               15     11      13
 *   collection (each)     10      6       8
 *   cart, empty           10      4       6
 *   cart, six lines       15      8      10
 *   checkout              25     14      16
 *   cart drawer            3      1       3
 *   checkout success       7      3       5
 *   wishlist               7      3       5
 *   content page           8      4       6
 *   my account             8      4       6
 *   account orders         9      5       7
 *   track order            7      3       5
 *   quick view, journal, article, search suggest, review wall,
 *   skin quiz, sitemap                 unchanged
 *
 * The four changes behind that, all of them removing repeated work rather than
 * skipping any:
 *
 *   - THE SHIPPING ZONES ARE READ ONCE AND CACHED. Every page on the site asks
 *     for the store country's free-delivery threshold, for the header bar, and
 *     each ask cost two queries — the zone, then its methods. /checkout asked
 *     four times. Nothing is stale: the three models evict on write.
 *
 *   - A CART THAT IS NOT THERE IS REMEMBERED. `select * from carts where token
 *     = ? and status = ?` ran TWICE on every page for a visitor whose cookie
 *     names no active cart, because only a hit was memoised. That is the state
 *     every shopper is in immediately after checking out.
 *
 *   - THE CART'S LINES ARE LOADED ONCE. CartController, CheckoutController and
 *     CartDrawerComposer each ran the same `load()` of lines, products and
 *     brands; `load()` re-queries whether or not the relation is there.
 *
 *   - THE CATEGORY ARCHIVE LOOKS ITS CATEGORY UP ONCE. CategoryArchiveController
 *     resolved it to choose between 200, 301 and 404 and then handed
 *     ShopController the slug to resolve again.
 *
 * LANE CW, later. Every ceiling above was re-measured on both engines and two
 * of them were carrying slack rather than a budget — the brand index at 14
 * against a measured 6, the sitemap at 30 against a measured 20. Both are noted
 * at their entries below, with the one change behind them: ProductVisibility::
 * raw() read the products column list once instead of asking about four columns
 * one at a time, which took four information_schema queries off every caller.
 *
 * Lane CW's other two changes are deliberately INVISIBLE to this file, which is
 * why they are pinned in StorefrontCostTest instead. Two indexes on `reviews`
 * cut the home page's review wall from 41.1ms to 0.3ms and its star
 * distribution from 28.3ms to 2.0ms without moving any count — a budget
 * measures queries, not rows examined. And the account panel's typeface is no
 * longer fetched on a guest page, which is a request the browser makes and the
 * database never hears about.
 */
function budgetPages(array $seed): array
{
    return [
        //                   path                                          customer  ceiling  cart cookie
        'home'              => ['/', null, 5],
        'shop'              => ['/shop', null, 8],
        'shop sorted'       => ['/shop?orderby=price', null, 8],
        'shop filtered'     => ['/shop?filter_brands=' . $seed['brand']->slug, null, 9],
        'category'          => ['/product-category/' . $seed['category']->slug, null, 9],
        /*
         * 14 -> 8 (Lane CW).
         *
         * Two separate things were loose here. The page measured 6 against a
         * ceiling of 14, so eight queries could appear on it without this file
         * noticing — twice the page's actual cost as slack.
         *
         * And four of the six it did run were `select column_name ... from
         * information_schema.columns where table_name = 'products'`:
         * ProductVisibility::raw() guards four conditions on four separate
         * Schema::hasColumn() calls, so a main-navigation page spent two thirds
         * of its database work on MySQL describing its own columns. raw() now
         * reads the column list once. MySQL 8 -> 5.
         *
         * 7, not the file's usual measured-plus-two, and the one exception in
         * it. The two engines disagree by one here — SQLite answers hasColumn
         * from a pragma that DB::listen never sees, so it measures 6 where
         * MySQL measures 5 — and the ceiling has to hold on both, which puts
         * the floor at 6. At 8 the old MySQL count of 8 still fits, so a
         * ceiling set by the usual rule would pass against the very code this
         * entry was tightened for. Proven by reverting raw(): at 8 the suite is
         * green, at 7 it fails with "brand index (/korean-skincare-brands) ran
         * 8 queries, budget 7". One of slack on the engine that governs it.
         */
        'brand index'       => ['/korean-skincare-brands', null, 7],
        'brand page'        => ['/korean-skincare-brands/' . $seed['brand']->slug, null, 9],
        'product'           => ['/product/' . $seed['product']->slug, null, 13],
        'quick view'        => ['/quick-view/' . $seed['product']->id, null, 4],
        'collection new-in' => ['/new-in', null, 8],
        'collection best'   => ['/best-sellers', null, 8],
        'collection sale'   => ['/super-sale', null, 8],
        'collection budget' => ['/everything-under-54-aed', null, 8],
        'cart'              => ['/cart', null, 6],
        /*
         * The two pages a shopper reaches WITH A BASKET, which is the only
         * state in which either of them costs anything, and neither of which
         * this file measured before. /cart was only ever requested empty and
         * /checkout not at all, so the cart page re-fetching its own six lines
         * and the checkout asking four times for one shipping zone were
         * invisible here while both were live.
         *
         * Fourth element: the cookie that carries the basket. budgetCartIsReal()
         * below checks that it is actually arriving.
         */
        'cart with lines'   => ['/cart', null, 10, $seed['cart']->token],
        'checkout'          => ['/checkout', null, 16, $seed['cart']->token],
        'cart drawer'       => ['/api/cart/drawer', null, 3],
        'checkout success'  => ['/checkout/success', null, 5],
        'journal'           => ['/skincare-guide', null, 3],
        'article'           => ['/budget-article-0', null, 4],
        'search suggest'    => ['/api/search?q=serum', null, 3],
        'wishlist'          => ['/my-wishlist', null, 5],
        'review wall'       => ['/reviews', null, 2],
        'skin quiz'         => ['/skin-quiz', null, 2],
        'content page'      => ['/about', null, 6],
        'my account'        => ['/my-account', $seed['customer']->id, 6],
        'account orders'    => ['/my-account/orders', $seed['customer']->id, 7],
        'account order'     => ['/my-account/orders/' . $seed['order']->id, $seed['customer']->id, 7],
        'account addresses' => ['/my-account/edit-address', $seed['customer']->id, 6],
        'track order'       => ['/track-my-order', null, 5],
        /*
         * 30 -> 22 (Lane CW).
         *
         * Ten queries of slack on a URL a crawler fetches far more often than
         * any shopper fetches anything. Measured: 20 on SQLite, 15 on MySQL,
         * where it was 18 before ProductVisibility::raw() stopped asking about
         * its four columns one at a time. Twelve of that eighteen were schema
         * introspection; only six fetched anything a sitemap contains.
         *
         * 22 is the higher engine plus the file's standard two. SQLite is the
         * higher one because its pragma-based hasColumn IS visible to
         * DB::listen while MySQL's information_schema queries were the ones
         * removed — so on SQLite this number did not move, and the ceiling is
         * set by the engine the change did not help.
         *
         * Five Schema::hasTable/hasColumn calls in SeoFilesController itself
         * are still one query each and are left alone: that file belongs to the
         * SEO lane. See the report.
         */
        'sitemap'           => ['/sitemap.xml', null, 22],
    ];
}

/**
 * The basket pages are measured WITH A BASKET — checked, not assumed.
 *
 * A ceiling only means something if the page it names is doing the work the
 * ceiling is about. /cart and /checkout with an empty bag render an entirely
 * different, much cheaper page, and they answer 200 doing it, so a fixture
 * whose cookie failed to arrive would sail under any ceiling and report
 * nothing. That is not hypothetical: Laravel's test client accumulates cookies
 * across a test rather than per request, and while these two pages were being
 * added a third one silently measured the no-basket page under the name of the
 * basket one.
 *
 * So the fixture is asserted rather than trusted: with lines in the bag both
 * pages must cost strictly MORE than the empty cart page. If the cookie ever
 * stops arriving, this fails and says so, instead of the ceilings quietly
 * becoming decoration.
 */
it('measures the basket pages with a basket in hand', function () {
    $seed = budgetSeed();
    $pages = budgetPages($seed);

    foreach ($pages as $page) {
        budgetRun($page);
    }

    $empty = budgetRun($pages['cart']);
    $full = budgetRun($pages['cart with lines']);
    $checkout = budgetRun($pages['checkout']);

    expect($full > $empty)->toBeTrue(
        "/cart ran {$full} queries with six lines in the bag and {$empty} with none. "
        . 'Equal counts mean the cart cookie is not reaching the page and the '
        . 'ceiling below is measuring the empty-cart render.'
    );

    expect($checkout > $empty)->toBeTrue(
        "/checkout ran {$checkout} queries and the empty cart page {$empty}. "
        . '/checkout redirects to /cart when the bag is empty, so a count this '
        . 'low means the fixture is not arriving.'
    );
});

it('keeps every storefront page inside its query budget', function () {
    $seed = budgetSeed();
    $pages = budgetPages($seed);

    // Warm the process-level caches first; see the file header.
    foreach ($pages as $page) {
        budgetRun($page);
    }

    $over = [];

    foreach ($pages as $label => $page) {
        $count = budgetRun($page);
        $budget = $page[2];

        if ($count > $budget) {
            $over[] = sprintf('%s (%s) ran %d queries, budget %d', $label, $page[0], $count, $budget);
        }
    }

    expect($over)->toBe([], "Storefront pages over budget:\n  " . implode("\n  ", $over));
});

it('does not run more queries when the catalogue triples', function () {
    $seed = budgetSeed();
    $pages = budgetPages($seed);

    // Warm, then measure at the seeded catalogue size.
    foreach ($pages as $page) {
        budgetRun($page);
    }

    $before = [];
    foreach ($pages as $label => $page) {
        $before[$label] = budgetRun($page);
    }

    // 24 demo products -> 84, all on the same brand and category so that the
    // brand page, the category archive and the filtered shop all grow too.
    budgetGrow(60, $seed['brand'], $seed['category']);

    /*
     * Re-warm before measuring again.
     *
     * Writing those 60 products correctly EVICTS the homepage fragments — that
     * is the Product::saved hook in AppServiceProvider doing its job, so that a
     * price edit cannot leave / advertising the old one. But it means the first
     * request after the growth loop rebuilds eight cached fragments from cold,
     * and / measured 4 queries warm against 33 cold.
     *
     * That 33 is a one-time rebuild, not work per row: it does not move with the
     * catalogue, which is the very thing this test is asking about. Counting it
     * would report the cache eviction as an N+1 and say the page "is doing work
     * per row" when it is doing nothing of the kind.
     *
     * This does not soften the test. An N+1 is per-row work on EVERY request,
     * warm or cold, so it still shows in the measurement below — verified by
     * deleting the eager load in ShopController and watching /shop, the
     * category archive and the filtered shop all fail this assertion.
     */
    foreach ($pages as $page) {
        budgetRun($page);
    }

    $grew = [];
    foreach ($pages as $label => $page) {
        $after = budgetRun($page);

        if ($after !== $before[$label]) {
            $grew[] = sprintf(
                '%s (%s): %d queries at 24 products, %d at 84 — the page is doing work per row',
                $label,
                $page[0],
                $before[$label],
                $after
            );
        }
    }

    expect($grew)->toBe([], "Query count grows with the catalogue:\n  " . implode("\n  ", $grew));
});

/**
 * The settings N+1 specifically, pinned at the unit level.
 *
 * The page-level tests above would also catch a regression here, but only as
 * "the homepage gained 120 queries", which is a long way from the cause. This
 * says the cause.
 */
it('reads settings outside the autoload map in one query, not one per key', function () {
    for ($i = 0; $i < 40; $i++) {
        \App\Models\Setting::create(['key' => 'cold_key_' . $i, 'value' => 'v' . $i, 'autoload' => false]);
    }

    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();

    $service = app(SettingsService::class);
    $bucket = budgetBucket();
    $bucket->n = 0;

    for ($i = 0; $i < 40; $i++) {
        expect($service->get('cold_key_' . $i))->toBe('v' . $i);
    }

    // Plus 40 keys that are not there at all: a miss must not cost a query
    // either, which is how an absent key used to cost one on every call.
    for ($i = 0; $i < 40; $i++) {
        expect($service->get('absent_key_' . $i, 'fallback'))->toBe('fallback');
    }

    // One read of the autoload map, one of the rest. Never one per key.
    expect($bucket->n)->toBeLessThanOrEqual(2);
});
