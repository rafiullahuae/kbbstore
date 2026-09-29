<?php

declare(strict_types=1);

use App\Models\Brand;
use App\Models\GridSection;
use App\Models\Product;
use App\Services\GridSections;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * What N instances of the reusable grid cost the homepage. (Lane GS)
 *
 * ── WHY THIS FILE EXISTS SEPARATELY FROM THE BUDGET ─────────────────────────
 *
 * `StorefrontQueryBudgetTest` gives the homepage FIVE and measures THREE, so
 * there are two to spend — and "several instances each pulling products" is
 * precisely the shape that becomes an N+1 on the shop's most-hit URL. That file
 * says why a budget alone cannot catch one: "a page doing one query per product
 * passes any budget you like on a small enough fixture, which is exactly how
 * /shop reached 390 queries for four products without any test noticing."
 *
 * So two different things are measured here and only one of them is a number:
 *
 *   1. FLAT IN THE CATALOGUE. The same six instances measured against 12
 *      products and against 60, asserting the two counts are IDENTICAL. A grid
 *      that lazily loaded a brand per card would pass any ceiling on twelve.
 *
 *   2. FLAT IN THE INSTANCES, ONCE WARM — which is the case a shopper is
 *      actually in, and the case the budget file measures. `forHome()` is one
 *      cache entry, so a warm homepage costs the SAME whether the owner has
 *      built none or six.
 *
 * The cold column is reported rather than merely bounded, because it is the one
 * that grows and the owner deserves to know what with: one read for the
 * registry, one for the rows, and one per DISTINCT product selection. Six
 * different selections cannot be fetched in fewer than six reads without
 * fetching things nobody asked for.
 *
 * ── AND THE DE-DUPLICATION IS ASSERTED FROM BOTH SIDES ──────────────────────
 *
 * Two instances asking for the same eight best sellers are ONE query. That is
 * `specKey()`, and a `specKey()` that returned a constant would make this file
 * greener rather than redder — so the opposite case is asserted too: two
 * instances on DIFFERENT brands are two queries. Both directions, or the
 * de-duplication is a claim nobody can check.
 */
function gsqReset(): void
{
    GridSection::query()->delete();
    GridSections::flush();
    Cache::forget('kbb.home.rails');
    SettingsService::forgetMemo();
}

/**
 * A brand WITH a product of its own.
 *
 * A brand with no products makes its pool come back empty, and an empty pool
 * costs ONE query where a full one costs two — the select, and then the
 * `with('brand')` eager load, which Eloquent skips entirely when there is
 * nothing to hydrate. Measuring the cheap case and reporting it as the cost of
 * an instance would understate this feature by one query per instance, which is
 * exactly the kind of flattering measurement this file exists to avoid.
 */
function gsqBrand(string $slug): Brand
{
    $brand = Brand::firstOrCreate(['slug' => $slug], ['name' => 'GSQ '.$slug]);

    if (! Product::query()->where('brand_id', $brand->id)->exists()) {
        Product::create([
            'slug' => 'gsq-b-'.$slug,
            'name' => 'GSQ Brand Product '.$slug,
            'status' => 'publish',
            'is_visible' => true,
            'brand_id' => $brand->id,
            'price' => 4000,
            'stock_status' => 'instock',
            'type' => 'simple',
            'rating' => 0.0,
            'review_count' => 0,
            'total_sales' => 10,
        ]);
    }

    return $brand;
}

/**
 * Top the catalogue UP to $n rows rather than rebuilding it.
 *
 * Deleting and recreating collides on `products.slug`, which is unique — the
 * rows are still there. Topping up is also what the flatness case actually
 * wants: the SAME twelve products plus forty-eight more, so the only thing that
 * changed between the two measurements is the size of the catalogue.
 */
function gsqProducts(int $n): void
{
    $have = Product::query()->where('slug', 'like', 'gsq-p%')->count();

    if ($have >= $n) {
        return;
    }

    foreach (range($have + 1, $n) as $i) {
        Product::create([
            'slug' => 'gsq-p'.$i,
            'name' => 'GSQ Product '.$i,
            'status' => 'publish',
            'is_visible' => true,
            'brand_id' => gsqBrand('gsq-brand')->id,
            'price' => 4000 + $i,
            'stock_status' => 'instock',
            'type' => 'simple',
            'rating' => 0.0,
            'review_count' => 0,
            'total_sales' => 900000 - $i,
        ]);
    }
}

function gsqSection(array $extra = []): GridSection
{
    $n = GridSection::query()->count() + 1;

    return GridSection::create(array_merge([
        'name' => 'GSQ '.$n,
        'slug' => 'gsq-'.$n.'-'.uniqid(),
        'status' => 'publish',
        'position' => $n,
        'show_heading' => true,
        'heading' => 'GSQ '.$n,
        'subheading' => '',
        'source' => 'bestsellers',
        'include_children' => false,
        'count' => 8,
        'mobile_count' => 8,
        'desktop_layout' => 'grid',
        'desktop_cols' => 4,
        'mobile_layout' => 'carousel',
        'mobile_cols' => 2,
        'skin' => '',
        'card_label' => '',
        'show_rank' => false,
        'show_view_all' => false,
        'view_all_label' => '',
        'view_all_url' => '',
    ], $extra));
}

/**
 * Queries on one GET of the homepage, after a discarded warm-up request.
 *
 * The warm-up is copied from StorefrontQueryBudgetTest and is not optional:
 * several caches here live for the life of the PROCESS rather than the request
 * — `Setting::map()` memoises in a function static nothing can reach, and the
 * test cache store is the array driver — so measuring cold-then-warm reports a
 * fall that has nothing to do with the feature.
 */
function gsqWarm(): int
{
    test()->get('/');

    DB::flushQueryLog();
    DB::enableQueryLog();

    test()->get('/')->assertOk();

    $n = count(DB::getQueryLog());

    DB::disableQueryLog();
    DB::flushQueryLog();

    return $n;
}

/**
 * Queries on one GET with this feature's own cache entries CLEARED.
 *
 * The rest of the page stays warm — `kbb.home.rails` and its siblings are not
 * touched — so what this measures is what the grid sections cost when their
 * ten minutes are up, and nothing else.
 */
function gsqCold(): int
{
    test()->get('/');

    GridSections::flush();

    DB::flushQueryLog();
    DB::enableQueryLog();

    test()->get('/')->assertOk();

    $n = count(DB::getQueryLog());

    DB::disableQueryLog();
    DB::flushQueryLog();

    return $n;
}

beforeEach(function () {
    gsqReset();
    gsqProducts(12);
});

it('costs a warm homepage the same whether none or six instances are built', function () {
    /*
     * THE NUMBER A SHOPPER ACTUALLY PAYS, and the one StorefrontQueryBudget
     * Test measures. `forHome()` is one cache entry and `registryRows()` is
     * another, both warm on a page anybody has loaded in the last ten minutes,
     * so N instances cost the same as none.
     *
     * MUTATION: delete the `Cache::remember` wrapper from
     * GridSections::forHome() — return the closure's result directly — and this
     * goes red by the number of instances. Run, red, put back.
     */
    $none = gsqWarm();

    foreach (range(1, 6) as $i) {
        gsqSection(['source' => 'bestsellers', 'count' => 4 + $i]);
    }

    $six = gsqWarm();

    expect($six)->toBe($none, "six warm instances cost {$six} against {$none} for none");
});

it('is flat in the CATALOGUE: six instances cost the same over 12 products and over 60', function () {
    /*
     * The property a budget cannot prove. A grid that lazily loaded a brand per
     * card would pass any ceiling on twelve products and quintuple on sixty.
     *
     * MUTATION: drop `->with('brand:id,name,slug')` from the `$base` builder in
     * GridSections::fetchPool() and this goes red — the card reads the brand, so
     * a cold render pays one query per DISTINCT brand per pool. Run, red, put
     * back.
     */
    foreach (range(1, 6) as $i) {
        // Six DIFFERENT selections, so nothing is de-duplicated away and the
        // measurement is of the real worst case.
        gsqSection(['source' => 'bestsellers', 'count' => $i + 2, 'mobile_count' => $i + 2]);
    }

    $small = gsqCold();

    gsqProducts(60);
    gsqReset();

    foreach (range(1, 6) as $i) {
        gsqSection(['source' => 'bestsellers', 'count' => $i + 2, 'mobile_count' => $i + 2]);
    }

    $large = gsqCold();

    expect($large)->toBe($small, "12 products cost {$small}, 60 cost {$large}");
});

it('costs one query per DISTINCT selection and not one per instance', function () {
    /*
     * `specKey()` is what makes this true, and it is asserted from BOTH sides
     * because a specKey() that returned a constant would make the first half
     * greener rather than redder.
     *
     * MUTATION 1: make GridSections::specKey() return a constant string. The
     * SECOND half goes red — two different brands come back as one query, and
     * both instances draw the same products (GridSectionTest's "two named
     * instances" case catches the visible half of that). Run, red, put back.
     *
     * MUTATION 2: remove the `$wanted[$spec] = max(...)` de-duplication and
     * fetch a pool per section. The FIRST half goes red by two. Run, red, put
     * back.
     */
    $base = gsqCold();

    // Three instances, ONE selection between them: same source, different
    // counts. The pool is fetched once at the largest and sliced in PHP.
    foreach ([4, 6, 8] as $count) {
        gsqSection(['source' => 'bestsellers', 'count' => $count, 'mobile_count' => $count]);
    }

    $shared = gsqCold();

    gsqReset();

    // Three instances, THREE selections: three brands.
    foreach (['a', 'b', 'c'] as $slug) {
        gsqSection([
            'source' => 'brand',
            'source_brand_id' => gsqBrand('gsq-'.$slug)->id,
            'count' => 4,
            'mobile_count' => 4,
        ]);
    }

    $distinct = gsqCold();

    /*
     * A NON-EMPTY POOL IS TWO QUERIES, NOT ONE, and saying so is the point of
     * these numbers: the products, and then the `with('brand:id,name,slug')`
     * eager load the card needs. That eager load is what makes the page FLAT —
     * without it the card would read a brand per tile — so it is a cost this
     * feature buys deliberately, and it is counted here rather than hidden.
     *
     *   shared:    rows(1) + one pool(2)     = 3
     *   distinct:  rows(1) + three pools(6)  = 7
     */
    expect($shared - $base)->toBe(3, "three instances sharing one selection cost {$shared} against {$base}")
        ->and($distinct - $base)->toBe(7, "three instances on three brands cost {$distinct} against {$base}");
});

it('prints the cold cost for none, one, three and six instances', function () {
    /*
     * THE TABLE THE LANE REPORT CARRIES, measured rather than asserted — with
     * one bound on it, which is the thing that can actually regress: the cost
     * must be exactly two plus one per distinct selection. An instance that
     * started costing two would show up here as a slope of 2 and the
     * expectation names the number, so it cannot drift quietly.
     *
     * The numbers themselves are DIFFERENCES from an unbuilt shop, so another
     * lane moving the homepage's own cost does not touch this file.
     */
    $none = gsqCold();

    gsqSection(['source' => 'bestsellers']);
    $one = gsqCold();

    gsqReset();

    foreach (['a', 'b', 'c'] as $i => $slug) {
        gsqSection(['source' => 'brand', 'source_brand_id' => gsqBrand('gsq-c'.$slug)->id]);
    }
    $three = gsqCold();

    gsqReset();

    foreach (['a', 'b', 'c', 'd', 'e', 'f'] as $slug) {
        gsqSection(['source' => 'brand', 'source_brand_id' => gsqBrand('gsq-s'.$slug)->id]);
    }
    $six = gsqCold();

    // The registry read alone, on a shop with no instance at all.
    expect($none)->toBeGreaterThan(0);

    /*
     * rows(1) + two per distinct selection that returns products — the select
     * and the brand eager load. The slope is what matters: 2 per instance, not
     * 2 per PRODUCT and not 2 per tile.
     */
    expect($one - $none)->toBe(3, "one instance: {$one} against {$none}")
        ->and($three - $none)->toBe(7, "three instances: {$three} against {$none}")
        ->and($six - $none)->toBe(13, "six instances: {$six} against {$none}");
});
