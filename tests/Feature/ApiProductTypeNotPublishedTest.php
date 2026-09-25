<?php

/*
 * THE LAST SURFACE ON WHICH A VARIABLE PRODUCT STILL COST NOTHING, AND THE ONE
 * THING THAT MUST NOT COME WITH THE FIX.
 *
 * Lane Q5 made the tile, the product-page headline, the price sort, the price
 * facet and the listing JSON-LD agree on what a variable product costs. The
 * unauthenticated feed at /api/products did not move with them, and the lane
 * said so rather than reaching into a file it did not own:
 *
 *     Api\ProductController::INDEX_COLUMNS does not select `type`, and
 *     App\Services\VariantPricing declines to derive a price for a row whose
 *     shape it cannot confirm rather than guessing from a narrowed SELECT.
 *
 * So the feed answered `price: null` for every variable product while the tile
 * beside it printed "AED 120 - AED 190" -- two public surfaces disagreeing
 * about money, which is the disagreement this area of the codebase has now been
 * fixed for three times. `type` is selected, and the feed derives the from-price
 * like everything else.
 *
 * ── AND THE HALF THAT MATTERS MORE ──────────────────────────────────────────
 *
 * /api/* IS UNAUTHENTICATED. CLAUDE.md's rule for it is an explicit allowlist of
 * what a model RETURNS, never the model -- and the whole reason this endpoint
 * could be fixed with one column is that selecting a column and returning one
 * are different acts. Product::toApi() is that allowlist and it does not carry
 * `type`.
 *
 * That distinction is exactly the kind that erodes. A later hand adding `type`
 * to toApi() "since we already select it" would publish the shop's internal
 * product taxonomy on a public endpoint, and nothing else in the suite would
 * notice -- ApiSecurityTest pins the columns that leaked in production, not the
 * ones that have not yet. So this file asserts BOTH halves of the change, in
 * the same place, because they were made in the same act.
 *
 * ── MUTATION NOTES, EACH ONE ACTUALLY RUN ───────────────────────────────────
 *
 *  A. Remove 'type' from Api\ProductController::INDEX_COLUMNS.
 *     RUN: 1 failed -- 'quotes a variable product's from-price on the public
 *     feed' reads null where 12000 is expected. The two leak cases stay green,
 *     which is the point: dropping the column is a correctness regression and
 *     not a security one, and only the correctness case reddens.
 *
 *  B. Add 'type' => $this->type to Product::toApi()'s returned array.
 *     RUN: 2 failed -- 'never publishes the product type' on both the index and
 *     the detail route, each naming `type` in the response body.
 */

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;

/**
 * One row off the feed, found by paging.
 *
 * The index answers a BARE ARRAY -- `response()->json($rows)`, no `data`
 * envelope -- and it is ordered by `position` then `id` with a server-side cap
 * of 100, so a product created by a test sits after the seeded catalogue and is
 * not on the first page. Paging is what a real consumer does and it is what
 * proves the row is actually published rather than merely not contradicted.
 */
function apiTypeFeedRow(Tests\TestCase $t, string $slug): ?array
{
    for ($page = 1; $page <= 20; $page++) {
        $rows = $t->getJson('/api/products?limit=100&page=' . $page)->assertOk()->json();

        if ($rows === []) {
            return null;
        }

        foreach ($rows as $row) {
            if (($row['slug'] ?? null) === $slug) {
                return $row;
            }
        }
    }

    return null;
}

/** A variable parent: no price of its own, the money on its variations. */
function apiTypeParent(string $slug): Product
{
    return Product::create([
        'slug' => $slug,
        'name' => 'Cushion ' . $slug,
        'status' => 'publish',
        'is_visible' => true,
        'price' => null,
        'stock_status' => 'instock',
        'type' => 'variable',
    ]);
}

it('quotes a variable product\'s from-price on the public feed', function () {
    $parent = apiTypeParent('api-type-cushion');

    ProductVariant::create([
        'product_id' => $parent->id,
        'price' => 19000,
        'stock_status' => 'instock',
    ]);

    ProductVariant::create([
        'product_id' => $parent->id,
        'price' => 12000,
        'stock_status' => 'instock',
    ]);

    $row = apiTypeFeedRow($this, 'api-type-cushion');

    expect($row)->not->toBeNull('the variable product is missing from the feed entirely');

    // 12000 fils = AED 120, the low end of 120-190: the SAME "from" figure the
    // tile prints, the price sort orders on and Seo::aggregateOffer() publishes
    // as lowPrice. Not merely "not null" -- a wrong non-null number would be a
    // worse outcome than the null this replaces.
    expect($row['price'])->toBe(12000);
});

it('never publishes the product type on the index', function () {
    $parent = apiTypeParent('api-type-hidden-index');

    ProductVariant::create([
        'product_id' => $parent->id,
        'price' => 5000,
        'stock_status' => 'instock',
    ]);

    $row = apiTypeFeedRow($this, 'api-type-hidden-index');

    expect($row)->not->toBeNull();

    // array_key_exists, not isset: a published `type` of null would be a leak
    // of the key's existence and isset() would call that absent.
    expect(array_key_exists('type', $row))->toBeFalse(
        '`type` reached the unauthenticated feed. It is SELECTED so the price can '
        .'be derived; it is not the model, and toApi() is the allowlist.'
    );
});

it('never publishes the product type on the detail route', function () {
    $parent = apiTypeParent('api-type-hidden-detail');

    ProductVariant::create([
        'product_id' => $parent->id,
        'price' => 5000,
        'stock_status' => 'instock',
    ]);

    // The detail route loads the whole row, so `type` is on the model here
    // whatever INDEX_COLUMNS says -- which makes this the case that proves the
    // allowlist is doing the work rather than the narrow SELECT. It answers the
    // projection directly, with no envelope.
    $body = $this->getJson('/api/products/api-type-hidden-detail')->assertOk()->json();

    expect(array_key_exists('type', $body))->toBeFalse(
        '`type` reached the unauthenticated detail route, where the full row is '
        .'loaded -- so only toApi() stands between the model and the response.'
    );
});

it('costs one extra statement for any number of variable products, not one each', function () {
    /*
     * RULE 4, MEASURED RATHER THAN ASSERTED.
     *
     * Deriving a price per row is the obvious wrong way to do this, and on an
     * UNAUTHENTICATED, UNTHROTTLED endpoint that returns up to a hundred rows it
     * is not a slow page -- it is a hundred extra statements anybody may ask for
     * as often as they like. The column list on this endpoint exists because
     * exactly that argument was already made once about `description`.
     *
     * VariantPricing::load() reads EVERY variable parent's range in one grouped
     * statement and memoises it for the request. So the cost is ONE extra
     * statement when the page holds any variable product at all, and the SAME
     * one statement whether it holds one or twenty-four.
     *
     * MEASURED, warm, and these are the numbers: 1 statement with no variable
     * product, 2 with one, 2 with twenty-four.
     *
     * ▲ ONE WARM-UP REQUEST BEFORE ANY MEASUREMENT, and it is not a nicety.
     * Measured without it the count FELL from 4 to 2 as products were added,
     * which reads as the change making the endpoint cheaper. It does not:
     * Setting::map() memoises in a process-level static as well as the cache
     * (CLAUDE.md names this trap), so the first request of a process pays for
     * the settings read and no later one does. Comparing a cold request against
     * a warm one measures the warm-up and not the feature.
     *
     * MUTATION: make VariantPricing::range() call load() on every call instead
     * of memoising into $this->ranges. RUN: 1 failed here -- 25 statements
     * against 2, one per variable parent plus the row read, which is precisely
     * the N+1 this case exists to refuse.
     */
    $count = function () {
        /*
         * ▲ forgetScopedInstances() BEFORE EVERY REQUEST, and without it this
         * case measures nothing.
         *
         * VariantPricing is bound with $app->scoped(), so the framework drops
         * it between real requests and each one pays for load() once. A test
         * does NOT reboot the container between getJson() calls, so the memo
         * from the first request answers the second -- measured, the count went
         * DOWN from 2 to 1 as twenty-four products were added, which reads as
         * variable products making the endpoint cheaper. This line is what the
         * framework does between requests, and it is the difference between
         * measuring the feature and measuring the memo.
         */
        $this->app->forgetScopedInstances();

        $n = 0;
        DB::listen(function () use (&$n) { $n++; });
        $this->getJson('/api/products?limit=100&page=1')->assertOk();

        return $n;
    };

    $make = function (int $i) {
        $parent = Product::create([
            'slug' => 'api-type-flat-' . $i,
            'name' => 'Cushion flat ' . $i,
            'status' => 'publish',
            'is_visible' => true,
            'price' => null,
            'stock_status' => 'instock',
            'type' => 'variable',
        ]);

        ProductVariant::create([
            'product_id' => $parent->id,
            'price' => 1000 * $i,
            'stock_status' => 'instock',
        ]);

        ProductVariant::create([
            'product_id' => $parent->id,
            'price' => 2000 * $i,
            'stock_status' => 'instock',
        ]);
    };

    $count();                 // warm-up, discarded
    $none = $count();

    $make(1);
    $one = $count();

    foreach (range(2, 24) as $i) {
        $make($i);
    }

    $many = $count();

    expect($many)->toBe(
        $one,
        "twenty-four variable products cost {$many} statements where one costs {$one} -- "
        .'the range is being derived per row, on an endpoint anyone may call for free'
    );

    expect($many - $none)->toBe(
        1,
        "variable products add " . ($many - $none) . ' statements to this endpoint; '
        .'the grouped read is one statement and there is no second place for another'
    );
});
