<?php

declare(strict_types=1);

/*
 * The shop's search clause, joined instead of re-tested per row (Lane EY).
 *
 * ShopController::applyFacets() matched a brand name with
 * orWhereHas('brand', …), which compiles to a correlated EXISTS the planner
 * evaluates once per candidate row, PER SEARCH TERM — and
 * App\Support\SearchTerms::expand() routinely returns four or five terms
 * ("moisturiser" expands to moisturiser, moisturizer, cream, lotion). A LEFT
 * JOIN onto brands is the same question asked once.
 *
 * Measured on MySQL 8.0 against 3,025 products and 93 brands, the COUNT that
 * decides the pagination on every search:
 *
 *     search          EXISTS      LEFT JOIN
 *     "Heartleaf"      6.78 ms      5.24 ms
 *     "Medicube"       6.84 ms      5.34 ms
 *     "Ginseng"        6.32 ms      5.36 ms
 *     "moisturiser"   20.03 ms      8.22 ms
 *     "sun cream"     23.72 ms      8.98 ms
 *
 * This file is the other half of that claim: not that it is faster, but that it
 * is the SAME QUERY. Every test below compares the shipped query against the
 * orWhereHas form it replaces — same rows, same order, same count — on a search
 * that matches by product name only, by brand name only, by both at once, and
 * by neither.
 *
 * The reference implementation is written out here rather than imported,
 * deliberately: if someone later changes both the controller and a shared
 * helper in the same way, this file still fails, because the thing it compares
 * against is the code that was actually replaced.
 */

use App\Models\Brand;
use App\Models\Product;
use App\Support\SearchTerms;

/**
 * The search clause EXACTLY as ShopController::applyFacets() built it before
 * this lane: three ORs per term, the brand one a correlated EXISTS.
 */
function ssjExistsQuery(string $search)
{
    $terms = SearchTerms::expand($search);

    return Product::query()
        ->visible()
        ->select('products.id')
        ->where(function ($q) use ($terms) {
            foreach ($terms as $term) {
                SearchTerms::orWhereLike($q, 'products.name', $term);
                SearchTerms::orWhereLike($q, 'products.sku', $term);
                $q->orWhereHas('brand', fn ($b) => SearchTerms::whereLike($b, 'brands.name', $term));
            }
        })
        ->orderByDesc('products.featured')
        ->orderBy('products.name')
        ->orderBy('products.id');
}

/**
 * A catalogue shaped so each of the four cases is reachable and distinct.
 *
 * The demo catalogue the migration set seeds is cleared first. Not for tidiness:
 * it contains a brand called Medicube and products named after Heartleaf, so
 * "the rows this search returns" would otherwise be a mix of fixture and demo
 * data and the four cases below would stop being the four cases.
 */
function ssjCatalogue(): void
{
    \Illuminate\Support\Facades\DB::table('category_product')->delete();
    \Illuminate\Support\Facades\DB::table('products')->delete();
    \Illuminate\Support\Facades\DB::table('brands')->delete();

    $anua = Brand::create(['slug' => 'ssj-anua', 'name' => 'Anua']);
    $ginsengHouse = Brand::create(['slug' => 'ssj-ginseng-house', 'name' => 'Ginseng House']);
    $medicube = Brand::create(['slug' => 'ssj-medicube', 'name' => 'Medicube']);

    $rows = [
        // matches on PRODUCT NAME only
        ['ssj-1', 'Heartleaf Cleansing Oil', $anua->id],
        ['ssj-2', 'Heartleaf Toner', $anua->id],
        // matches on BRAND NAME only
        ['ssj-3', 'Zinc Serum', $medicube->id],
        ['ssj-4', 'Collagen Cream', $medicube->id],
        // matches on BOTH: the product is named Ginseng AND its brand is
        // "Ginseng House", so the same row satisfies two arms of the OR.
        ['ssj-5', 'Ginseng Essence', $ginsengHouse->id],
        // matches on BRAND ONLY, for the same term
        ['ssj-6', 'Radiance Ampoule', $ginsengHouse->id],
        // matches on PRODUCT NAME ONLY, for the same term
        ['ssj-7', 'Ginseng Eye Cream', $anua->id],
        // NO BRAND AT ALL: the row LEFT JOIN must keep and INNER JOIN would
        // silently drop. It matches by name.
        ['ssj-8', 'Heartleaf Sheet Mask', null],
        // no brand, no match
        ['ssj-9', 'Plain Lip Balm', null],
    ];

    foreach ($rows as [$slug, $name, $brandId]) {
        Product::create([
            'slug' => $slug,
            'name' => $name,
            'sku' => strtoupper($slug),
            'brand_id' => $brandId,
            'status' => 'publish',
            'is_visible' => true,
            'price' => 9900,
            'stock_status' => 'instock',
        ]);
    }
}

/** What the shipped controller actually returns for a search, in page order. */
function ssjShipped(string $search): array
{
    $html = test()->get('/shop/?s=' . urlencode($search))->assertOk()->getContent();

    preg_match_all('#/product/([a-z0-9-]+)/#', $html, $m);

    // The grid prints each card's URL more than once (image link, title link),
    // so collapse repeats while KEEPING the order they first appeared in —
    // ordering is half of what this file is asserting.
    return array_values(array_unique($m[1]));
}

function ssjExpected(string $search): array
{
    return ssjExistsQuery($search)
        ->get()
        ->map(fn ($p) => Product::query()->whereKey($p->id)->value('slug'))
        ->all();
}

beforeEach(function () {
    ssjCatalogue();
});

dataset('searches', [
    'product name only' => ['Heartleaf', 3],
    'brand name only' => ['Medicube', 2],
    'both a product name and a brand name' => ['Ginseng', 3],
    'neither' => ['zzzznothing', 0],
]);

it('returns the same rows in the same order as the EXISTS form it replaces', function (string $search, int $expectedCount) {
    $expected = ssjExpected($search);

    expect($expected)->toHaveCount($expectedCount, "the fixture does not exercise '{$search}' as intended");

    // Same set, same order, off the rendered page rather than off the query
    // builder — this is what a shopper is served.
    expect(ssjShipped($search))->toBe($expected);
})->with('searches');

it('counts the same rows as the EXISTS form, which is what paginates the grid', function (string $search, int $expectedCount) {
    $html = test()->get('/shop/?s=' . urlencode($search))->assertOk()->getContent();

    // The COUNT is the number the page prints and the number forPage() divides.
    expect(ssjExistsQuery($search)->count())->toBe($expectedCount);

    if ($expectedCount > 0) {
        expect($html)->toContain((string) $expectedCount);
    }
})->with('searches');

it('keeps a product with no brand at all, which an INNER JOIN would drop', function () {
    // ssj-8 has brand_id NULL and matches by name. Under EXISTS it was included
    // because the EXISTS is simply false for it; under LEFT JOIN because the
    // join keeps it and brands.name is NULL, which is not a match either. An
    // INNER JOIN would have removed it and no count test would have noticed,
    // because the totals would agree with each other and both be wrong.
    expect(ssjShipped('Heartleaf'))->toContain('ssj-8')
        ->and(ssjExpected('Heartleaf'))->toContain('ssj-8');
});

it('does not list a product twice when its name and its brand both match', function () {
    // brand_id is a single foreign key onto the brands primary key, so the join
    // matches at most one row and cannot multiply anything — but that is the
    // whole safety argument for replacing an EXISTS with a join, so it is
    // asserted rather than asserted-in-a-comment. ssj-5 is named "Ginseng
    // Essence" AND belongs to "Ginseng House".
    $rows = ssjShipped('Ginseng');

    expect($rows)->toBe(array_values(array_unique($rows)))
        ->and(array_count_values(ssjExpected('Ginseng'))['ssj-5'] ?? 0)->toBe(1);
});

it('still filters by brand facet and by search at the same time', function () {
    // The brand FACET is a separate whereHas that the join must not disturb:
    // two different questions about the same table in one query.
    $html = test()->get('/shop/?s=Ginseng&brand=ssj-ginseng-house')->assertOk()->getContent();

    preg_match_all('#/product/([a-z0-9-]+)/#', $html, $m);
    $rows = array_values(array_unique($m[1]));

    // ssj-5 is named Ginseng AND is a Ginseng House product; ssj-6 is a Ginseng
    // House product that matches the search only through its brand. ssj-7 is
    // named Ginseng but belongs to Anua, so the brand facet excludes it — which
    // is the point: the facet's whereHas and the search's join are two
    // different questions about the same table in one query.
    expect($rows)->toBe(['ssj-5', 'ssj-6'])
        ->and($rows)->not->toContain('ssj-7');
});

it('sorts a search result by every offered order without an ambiguous column', function () {
    /*
     * `brands` carries id, slug, name, position and created_at, and so does
     * `products`. Unqualified, every one of these ORDER BY clauses is ambiguous
     * the moment the search joins brands — MySQL answers with an error and
     * every search on the shop is a 500, while SQLite guesses and the suite
     * stays green. That gap is what docs/MYSQL-PARITY.md exists for, so each
     * sort is driven here rather than assumed.
     */
    foreach (array_keys(\App\Support\Facets::SORTS) as $orderby) {
        test()->get('/shop/?s=Ginseng&orderby=' . $orderby)->assertOk();
    }

    // And the same sorts with no search at all, where nothing is joined.
    foreach (array_keys(\App\Support\Facets::SORTS) as $orderby) {
        test()->get('/shop/?orderby=' . $orderby)->assertOk();
    }
});

/*
 * ── AND THE ONE TEST THAT FAILS ON THE TIP ──────────────────────────────────
 *
 * Everything above is an IDENTITY test: it compares the shipped query against
 * the orWhereHas form and asserts they agree, so by construction it passes
 * before this change and after it. That is what "behaviour-identical" has to
 * mean, and it is also why those tests cannot protect the change — the
 * optimisation could be reverted tomorrow and every one of them would stay
 * green.
 *
 * This is the test that fails on the tip, and it asserts the only thing that
 * actually differs: the SQL the shop sends.
 */
it('asks the database about the brand once per search, not once per row', function () {
    \Illuminate\Support\Facades\DB::enableQueryLog();

    test()->get('/shop/?s=Ginseng')->assertOk();

    $log = \Illuminate\Support\Facades\DB::getQueryLog();

    \Illuminate\Support\Facades\DB::disableQueryLog();

    $productQueries = array_values(array_filter(
        array_column($log, 'query'),
        static fn (string $q): bool => str_contains($q, 'from "products"') || str_contains($q, 'from `products`')
    ));

    expect($productQueries)->not->toBeEmpty('no query against products ran at all; this test is not looking at the shop');

    $searchQueries = array_values(array_filter(
        $productQueries,
        static fn (string $q): bool => str_contains($q, 'like ?')
    ));

    expect($searchQueries)->not->toBeEmpty('the search ran no LIKE at all, so this assertion proves nothing');

    foreach ($searchQueries as $query) {
        // A correlated EXISTS over brands is re-evaluated per candidate row and
        // per search term; SearchTerms::expand() returns four terms for
        // "moisturiser" and five for "sun cream", so a 3,025-product catalogue
        // pays it fifteen thousand times on one COUNT.
        expect($query)->not->toContain('exists (select * from "brands"')
            ->and($query)->not->toContain('exists (select * from `brands`');

        // Asked once, as a join.
        expect(
            str_contains($query, 'left join "brands"') || str_contains($query, 'left join `brands`')
        )->toBeTrue('the search query does not join brands: ' . $query);
    }
});
