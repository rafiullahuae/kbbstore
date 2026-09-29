<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Review;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;

/**
 * What opening the admin console is allowed to cost, and the proof it is flat.
 *
 * `StorefrontQueryBudgetTest` does this for the shop and says in its own header
 * why: a budget alone cannot catch an N+1, because a page doing one query per
 * row passes any ceiling you like on a small enough fixture. The admin had no
 * equivalent, and it has already paid for that once —
 * `AdminController::products()` carries the note:
 *
 *     "Eager-loaded. `brand` and `category` are belongsTo relations, not
 *      columns -- reading them per row without this fires two extra queries
 *      each, which on 671 products is over 1,300 queries for one screen."
 *
 * That regression was found by reading. This file would have found it by
 * measuring, and would find it again.
 *
 * WHAT IS MEASURED. The six endpoints a cold console load actually fires,
 * established in Chromium rather than assumed: /admin-api/products (the Catalog
 * seed), /admin-api/stats (the dashboard tiles), /admin-api/brands,
 * /admin-api/categories, /admin-api/reviews/list (the queue badge) and
 * /admin-api/translations/settings (the Arabic probe). Six requests, no
 * duplicates.
 *
 * TWO THINGS ARE PINNED, and the second is the one that matters:
 *
 *   1. a ceiling per endpoint — loud when one suddenly doubles;
 *   2. FLATNESS — the same endpoint measured against a small fixture and again
 *      against one five times the size, asserting the counts are IDENTICAL. A
 *      ceiling can be raised in the same commit that breaks it. A count that
 *      does not move when the data grows cannot be fudged.
 *
 * WHY A WARM-UP PASS. `Setting::map()` memoises in a process-level static as
 * well as the cache, and the container is scoped per request in production but
 * shared across requests in one test process. Measuring cold-then-warm would
 * report a fall that has nothing to do with the data, so every endpoint is
 * requested once and discarded before the two real measurements.
 *
 * MUTATION NOTE, run: drop the eager load in AdminController::products() —
 * `Product::with(['brand:id,name', 'category:id,name'])` back to
 * `Product::query()` — and 'does not cost more queries as the catalogue grows'
 * fails with 25 queries on 10 products against 105 on 50, which is the
 * 1,300-query shape at fixture scale. The ceiling test fails with it.
 */
function adminBudgetBucket(): object
{
    static $bucket = null;
    static $listeningOn = null;

    if ($bucket === null) {
        $bucket = new class
        {
            public int $n = 0;
        };
    }

    // Object identity, not a boolean. Pest builds a fresh application per test
    // and the connection the listener was attached to goes with the old one; a
    // boolean guard here counts nothing from the second test onward and every
    // assertion passes against zero. StorefrontQueryBudgetTest shipped that bug
    // and documented it, so this is the shape that does not.
    if ($listeningOn !== app()) {
        $listeningOn = app();
        DB::listen(function () use ($bucket) {
            $bucket->n++;
        });
    }

    return $bucket;
}

function adminBudgetAdmin(): AdminUser
{
    return AdminUser::firstOrCreate(
        ['email' => 'admin-budget@example.test'],
        ['name' => 'Budget Owner', 'password' => 'password-long-enough', 'role' => 'owner']
    );
}

/** Query count for one admin-api request, started the way FPM starts one. */
function adminBudgetCount(string $path): int
{
    $bucket = adminBudgetBucket();
    SettingsService::forgetMemo();
    app()->forgetScopedInstances();

    $bucket->n = 0;
    test()->actingAs(adminBudgetAdmin(), 'admin')->get($path)->assertOk();

    return $bucket->n;
}

/** Products, brands, categories and reviews, in the quantity asked for. */
function adminBudgetSeed(int $n): void
{
    Product::query()->forceDelete();
    Review::query()->forceDelete();
    Brand::query()->delete();
    Category::query()->delete();

    $brands = [];
    $cats = [];
    for ($i = 0; $i < max(2, (int) ceil($n / 4)); $i++) {
        $brands[] = Brand::create(['name' => "Budget brand {$i}", 'slug' => "budget-brand-{$i}", 'position' => $i]);
        $cats[] = Category::create(['name' => "Budget category {$i}", 'slug' => "budget-category-{$i}", 'position' => $i, 'depth' => 0]);
    }

    for ($i = 0; $i < $n; $i++) {
        $p = Product::create([
            'name' => "Budget product {$i}",
            'slug' => "budget-product-{$i}",
            'sku' => "BUD-{$i}",
            'price' => 9950,
            'stock' => 7,
            'status' => 'publish',
            'position' => $i,
            'brand_id' => $brands[$i % count($brands)]->id,
            'category_id' => $cats[$i % count($cats)]->id,
        ]);

        Review::create([
            'product_id' => $p->id,
            'author_name' => "Budget reviewer {$i}",
            'author_email' => "budget{$i}@example.test",
            'rating' => 5,
            'content' => 'Measured, not asserted.',
            'status' => 'approved',
        ]);
    }
}

/**
 * The endpoints a cold console load fires, with the ceiling each is allowed.
 *
 * The numbers are what they measure today with a little headroom, not targets.
 * Raising one is a deliberate act with a reason in the commit; the flatness
 * test below is the one that cannot be argued with.
 */
const ADMIN_COLD_LOAD = [
    '/admin-api/products' => 12,
    '/admin-api/stats' => 24,
    '/admin-api/brands' => 8,
    '/admin-api/categories' => 8,
    '/admin-api/reviews/list?per_page=1' => 10,
    '/admin-api/translations/settings' => 8,
];

it('opens the console inside its query budget', function () {
    adminBudgetSeed(12);

    // Warm-up: the process-level memos are paid once, by this pass.
    foreach (array_keys(ADMIN_COLD_LOAD) as $path) {
        adminBudgetCount($path);
    }

    $over = [];
    $counts = [];

    foreach (ADMIN_COLD_LOAD as $path => $ceiling) {
        $n = adminBudgetCount($path);
        $counts[$path] = $n;
        if ($n > $ceiling) {
            $over[] = sprintf('  %-40s %d queries, budget %d', $path, $n, $ceiling);
        }
    }

    expect($over)->toBe([], sprintf(
        "opening the admin console costs more than its budget:\n%s\n\nall six: %s",
        implode("\n", $over),
        json_encode($counts)
    ));

    // A budget asserting nothing is worse than no budget. Every endpoint must
    // actually have been measured.
    foreach ($counts as $path => $n) {
        expect($n)->toBeGreaterThan(0, "{$path} was measured at zero queries, so nothing here is being counted");
    }
});

it('does not cost more queries as the catalogue grows', function () {
    /*
     * THE ASSERTION THAT MATTERS. Five times the rows, the same query count.
     *
     * Anything that reads a relation per row moves here and cannot move the
     * ceiling above to hide it. /admin-api/products is the one with history:
     * it returns EVERY product unpaginated and reads each row's brand and
     * category, which is two extra queries per row without the eager load.
     */
    adminBudgetSeed(10);
    foreach (array_keys(ADMIN_COLD_LOAD) as $path) {
        adminBudgetCount($path);          // warm-up
    }
    $small = [];
    foreach (array_keys(ADMIN_COLD_LOAD) as $path) {
        $small[$path] = adminBudgetCount($path);
    }

    adminBudgetSeed(50);
    foreach (array_keys(ADMIN_COLD_LOAD) as $path) {
        adminBudgetCount($path);          // warm-up
    }
    $large = [];
    foreach (array_keys(ADMIN_COLD_LOAD) as $path) {
        $large[$path] = adminBudgetCount($path);
    }

    $grew = [];
    foreach ($small as $path => $n) {
        if ($large[$path] !== $n) {
            $grew[] = sprintf(
                '  %-40s %d queries on 10 products, %d on 50 -- one query per row is an N+1, '
                .'and no ceiling catches it on a small fixture',
                $path, $n, $large[$path]
            );
        }
    }

    expect($grew)->toBe([], "the admin console's cold load grows with the catalogue:\n".implode("\n", $grew));
});
