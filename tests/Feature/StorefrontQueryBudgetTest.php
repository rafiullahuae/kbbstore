<?php

declare(strict_types=1);

use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Page;
use App\Models\Post;
use App\Models\Product;
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

/** The per-request memo really is per-request in production; reset it like one. */
function budgetReset(): void
{
    SettingsService::forgetMemo();
}

/**
 * One DB listener for the whole process, counting into a bucket the caller
 * clears.
 *
 * Deliberately not one DB::listen() per measurement: listeners ACCUMULATE, they
 * do not replace, so registering a fresh one each time and capturing the
 * counter by reference makes the Nth page report N times its real query count.
 * That mistake reported this suite's own /sitemap.xml at 416 queries when it
 * runs 16.
 */
function budgetBucket(): object
{
    static $bucket = null;

    if ($bucket === null) {
        $bucket = new class
        {
            public int $n = 0;
        };

        DB::listen(function () use ($bucket) {
            $bucket->n++;
        });
    }

    return $bucket;
}

/** Query count for one request. */
function budgetCount(string $path, ?int $customerId = null): int
{
    $bucket = budgetBucket();
    budgetReset();
    $bucket->n = 0;

    $test = test();

    if ($customerId !== null) {
        // The session key the customer guard reads — not actingAs(), which
        // changes the application's default guard. See AccountAreaTest's header.
        $test = $test->withSession(['login_customer_' . sha1(\Illuminate\Auth\SessionGuard::class) => $customerId]);
    }

    $test->get($path)->assertSuccessful();

    return $bucket->n;
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

    return [
        'customer' => $customer,
        'order' => $order,
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
 * The budgets are the measured counts with headroom, not aspirations. A page
 * that legitimately needs more should have this number raised deliberately, in
 * a commit that says why.
 */
function budgetPages(array $seed): array
{
    return [
        'home'              => ['/', null, 60],
        'shop'              => ['/shop', null, 25],
        'shop sorted'       => ['/shop?orderby=price', null, 25],
        'shop filtered'     => ['/shop?filter_brands=' . $seed['brand']->slug, null, 25],
        'category'          => ['/product-category/' . $seed['category']->slug, null, 25],
        'brand index'       => ['/korean-skincare-brands', null, 20],
        'brand page'        => ['/korean-skincare-brands/' . $seed['brand']->slug, null, 25],
        'product'           => ['/product/' . $seed['product']->slug, null, 30],
        'quick view'        => ['/quick-view/' . $seed['product']->id, null, 15],
        'collection new-in' => ['/new-in', null, 25],
        'collection best'   => ['/best-sellers', null, 25],
        'collection sale'   => ['/super-sale', null, 25],
        'collection budget' => ['/everything-under-54-aed', null, 25],
        'cart'              => ['/cart', null, 25],
        'cart drawer'       => ['/api/cart/drawer', null, 15],
        'checkout success'  => ['/checkout/success', null, 15],
        'journal'           => ['/skincare-guide', null, 15],
        'article'           => ['/budget-article-0', null, 15],
        'search suggest'    => ['/api/search?q=serum', null, 15],
        'wishlist'          => ['/my-wishlist', null, 15],
        'review wall'       => ['/reviews', null, 10],
        'skin quiz'         => ['/skin-quiz', null, 10],
        'content page'      => ['/about', null, 15],
        'my account'        => ['/my-account', $seed['customer']->id, 20],
        'account orders'    => ['/my-account/orders', $seed['customer']->id, 20],
        'account order'     => ['/my-account/orders/' . $seed['order']->id, $seed['customer']->id, 20],
        'account addresses' => ['/my-account/edit-address', $seed['customer']->id, 20],
        'track order'       => ['/track-my-order', null, 15],
        'sitemap'           => ['/sitemap.xml', null, 30],
    ];
}

it('keeps every storefront page inside its query budget', function () {
    $seed = budgetSeed();
    $pages = budgetPages($seed);

    // Warm the process-level caches first; see the file header.
    foreach ($pages as [$path, $customerId, $_]) {
        budgetCount($path, $customerId);
    }

    $over = [];

    foreach ($pages as $label => [$path, $customerId, $budget]) {
        $count = budgetCount($path, $customerId);

        if ($count > $budget) {
            $over[] = sprintf('%s (%s) ran %d queries, budget %d', $label, $path, $count, $budget);
        }
    }

    expect($over)->toBe([], "Storefront pages over budget:\n  " . implode("\n  ", $over));
});

it('does not run more queries when the catalogue triples', function () {
    $seed = budgetSeed();
    $pages = budgetPages($seed);

    // Warm, then measure at the seeded catalogue size.
    foreach ($pages as [$path, $customerId, $_]) {
        budgetCount($path, $customerId);
    }

    $before = [];
    foreach ($pages as $label => [$path, $customerId, $_]) {
        $before[$label] = budgetCount($path, $customerId);
    }

    // 24 demo products -> 84, all on the same brand and category so that the
    // brand page, the category archive and the filtered shop all grow too.
    budgetGrow(60, $seed['brand'], $seed['category']);

    $grew = [];
    foreach ($pages as $label => [$path, $customerId, $_]) {
        $after = budgetCount($path, $customerId);

        if ($after !== $before[$label]) {
            $grew[] = sprintf(
                '%s (%s): %d queries at 24 products, %d at 84 — the page is doing work per row',
                $label,
                $path,
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
