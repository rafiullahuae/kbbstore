<?php

declare(strict_types=1);

use App\Models\Product;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\OrderingScan;

/**
 * A LIST THAT CAN SHOW THE SAME PRODUCT TWICE.
 *
 * `LIMIT`, `OFFSET`, `paginate()` and `forPage()` all take a slice of an
 * ordered list. Where the order does not decide between two rows, SQL does not
 * say which of them the slice gets, and it does not promise the same answer to
 * the same question twice. Page 1 and page 2 of /best-sellers are two separate
 * HTTP requests running two separate queries; the home page's two best-seller
 * rails were two separate queries in one method. Nothing carried the first
 * query's tie decision into the second, so a product tied across the boundary
 * could be served on both sides of it — and the product it displaced on
 * neither.
 *
 * ON THIS CATALOGUE THE TIES ARE THE COMMON CASE, NOT THE EDGE. `total_sales`
 * is a counter sharing a handful of values across the tail. `review_count` and
 * `rating` are 0 for most products, so "Sort by: average rating" is one
 * enormous tied block. `position` is 0 until somebody uses the reorder screen.
 * `created_at` is identical for everything a single import wrote.
 * `2026_10_11_000000_clear_caches_storefront_speed` says as much when it
 * declines to make its index unique: "total_sales is a counter, ties are the
 * normal case."
 *
 * WHAT EACH TEST BELOW IS FOR.
 *
 *   1. That a tied block really does come back in two different orders from
 *      two correct plans. Measured here, on this machine, on this database —
 *      not asserted from the standard.
 *   2. That the shipped order therefore admitted more than one answer, and
 *      that page 1 taken from one of them and page 2 from another shows a
 *      shopper the same product twice and hides another entirely.
 *   3..5. That the home rails, and the live /best-sellers pages, now partition
 *      the catalogue exactly, over a fixture built so the tie lands on the
 *      boundary.
 *   6. The guard for the class: every query a storefront page runs that both
 *      orders and slices must end its ORDER BY on a key that cannot tie. It
 *      reads the SQL the query builder compiled, not the source that wrote it.
 *   7. A second guard over app/ that reads tokens rather than text.
 */

/**
 * ONE TIED BLOCK, TWO CORRECT PLANS, TWO DIFFERENT ANSWERS.
 *
 * This is the premise everything else rests on, so it is measured rather than
 * claimed. The table is built here, in the shape `products` has, precisely so
 * the planner can be pushed either way on demand: with an index over the sort
 * column SQLite walks it and returns the tied rows in the order the index
 * holds them; without one it scans and sorts, and the tied rows come back in
 * the opposite order. Both are correct — `ORDER BY total_sales DESC` is
 * satisfied by either.
 *
 * Production's engine is MySQL, where the same two strategies exist as an
 * index scan and a filesort, and where the optimiser picks between them on
 * costs that are not the same for `LIMIT 24` as for `LIMIT 24 OFFSET 24`.
 */
it('measures a tied block coming back in opposite orders from two correct plans', function () {
    if (DB::connection()->getDriverName() !== 'sqlite') {
        test()->markTestSkipped('Needs SQLite, the engine this measurement is calibrated on.');
    }

    $answers = [];

    foreach ([true, false] as $indexed) {
        $db = new PDO('sqlite::memory:');
        $db->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, total_sales INT)');

        // Three clear leaders, then five products tied on the same counter —
        // the shape of the tail of this catalogue.
        foreach ([[1, 100], [2, 90], [3, 80], [4, 50], [5, 50], [6, 50], [7, 50], [8, 50]] as [$id, $sales]) {
            $db->exec("INSERT INTO t VALUES ($id, $sales)");
        }

        if ($indexed) {
            $db->exec('CREATE INDEX t_total_sales ON t (total_sales)');
        }

        $answers[] = $db->query('SELECT id FROM t ORDER BY total_sales DESC LIMIT 4')
            ->fetchAll(PDO::FETCH_COLUMN);
    }

    // The three leaders are the three leaders either way: this is not the
    // database returning nonsense, it is the database exercising a freedom the
    // query gave it.
    expect(array_slice($answers[0], 0, 3))->toBe(array_slice($answers[1], 0, 3));

    // And the fourth row — the one the LIMIT cuts the tie at — is a different
    // product depending on which plan ran.
    expect($answers[0][3])->not->toBe($answers[1][3]);
});

/**
 * A catalogue whose best-seller ranking ties straight across both boundaries
 * that matter: the 24/25 one /best-sellers paginates on, and the 4/5 one the
 * home page's two rails split on.
 *
 * Twenty products with distinct, descending sales take ranks 1-20. Ten more
 * share one value and take ranks 21-30, so the tied block straddles page one's
 * last place. Everything the demo seeder left behind is pushed to zero sales
 * so it cannot get between them.
 */
function soCatalogue(): void
{
    Product::query()->update(['total_sales' => 0, 'review_count' => 0]);

    for ($i = 0; $i < 20; $i++) {
        Product::create([
            'slug' => sprintf('so-ranked-%02d', $i),
            'name' => 'Ranked ' . $i,
            'type' => 'simple',
            'status' => 'publish',
            'is_visible' => true,
            'price' => 4000,
            'stock_status' => 'instock',
            'total_sales' => 9000 - $i,
            'review_count' => 0,
        ]);
    }

    for ($i = 0; $i < 10; $i++) {
        Product::create([
            'slug' => sprintf('so-tied-%02d', $i),
            'name' => 'Tied ' . $i,
            'type' => 'simple',
            'status' => 'publish',
            'is_visible' => true,
            'price' => 4000,
            'stock_status' => 'instock',
            // Every one of these ten is rank 21 as far as the sort key is
            // concerned. Only `id` tells them apart.
            'total_sales' => 5000,
            'review_count' => 0,
        ]);
    }
}

/**
 * One slice of the SHIPPED best-seller order — `total_sales DESC,
 * review_count DESC`, with nothing after it — with the tie broken one way or
 * the other.
 *
 * BOTH OF THESE ARE ANSWERS THE SHIPPED QUERY PERMITS. A database satisfies
 * `ORDER BY total_sales DESC, review_count DESC` by returning the rows in any
 * order that agrees with it, and it agrees with both of these: they differ
 * only among rows those two keys call equal. Spelling the completion out is
 * how the two legal answers are got at deliberately rather than waited for.
 *
 * @return list<string>
 */
function soShippedOrder(string $tie, int $limit, int $offset): array
{
    return Product::query()
        ->visible()
        ->orderByDesc('total_sales')
        ->orderByDesc('review_count')
        ->orderBy('id', $tie)
        ->offset($offset)
        ->limit($limit)
        ->pluck('slug')
        ->all();
}

/** The slugs the collection page was handed, in the order it will print them. */
function soPage(string $path, int $page): array
{
    $response = test()->get($page === 1 ? $path : $path . '?page=' . $page);

    $response->assertOk();

    return $response->viewData('products')->pluck('slug')->all();
}

beforeEach(function () {
    Cache::flush();
});

it('shows the same product on both pages of Best Sellers when the order leaves the tie open', function () {
    soCatalogue();

    // Page 1 answered with the tie broken one way, page 2 with it broken the
    // other. Two requests, two queries, one order that obliges neither of them
    // to agree with the other.
    $one = soShippedOrder('asc', 24, 0);
    $two = soShippedOrder('desc', 24, 24);

    // Both pages are still correct "best sellers" pages: the twenty products
    // with distinct sales are on page one either way, in the same order.
    expect(array_slice($one, 0, 20))->toBe(
        array_map(fn ($i) => sprintf('so-ranked-%02d', $i), range(0, 19))
    );

    // THE DAMAGE. A shopper paging from Best Sellers page 1 to page 2 is shown
    // these products a second time.
    $twice = array_values(array_intersect($one, $two));
    expect($twice)->not->toBeEmpty();

    // And exactly as many are shown to nobody: the two pages claim between
    // them to be the top 48 and are not.
    $seen = array_unique(array_merge($one, $two));
    expect(count($seen))->toBe(48 - count($twice));

    // Both the repeats and the losses are inside the tied block — no product
    // with a sales figure of its own is affected. That is the signature of
    // this defect and the reason it went unnoticed for so long.
    foreach ($twice as $slug) {
        expect($slug)->toStartWith('so-tied-');
    }
});

it('gives Best Sellers two pages that partition the catalogue exactly', function () {
    soCatalogue();

    $one = soPage('/best-sellers', 1);
    $two = soPage('/best-sellers', 2);

    expect($one)->toHaveCount(24);
    expect($two)->toHaveCount(24);

    // No product on both pages, and none missing from both.
    expect(array_intersect($one, $two))->toBe([]);
    expect(count(array_unique(array_merge($one, $two))))->toBe(48);

    // The tie is still straddling the boundary, so this is not passing by
    // having accidentally arranged for there to be no tie to break.
    $tiedOnPageOne = count(array_filter($one, fn ($s) => str_starts_with($s, 'so-tied-')));
    expect($tiedOnPageOne)->toBe(4);

    // And the page the shopper is served is the one the controller's own order
    // names — the tie broken by descending id, not by the plan.
    expect(array_merge($one, $two))->toBe(soShippedOrder('desc', 48, 0));
});

it('splits the home page best-seller rails without repeating or dropping a product', function () {
    soCatalogue();

    $rails = test()->get('/')->assertOk()->viewData('rails');

    $one = $rails['best1']->pluck('slug')->all();
    $two = $rails['best2']->pluck('slug')->all();

    expect($one)->toHaveCount(4);
    expect($two)->toHaveCount(4);

    // The two rails are halves of one list, so they cannot overlap — and now
    // they are halves of one query, so they structurally cannot.
    expect(array_intersect($one, $two))->toBe([]);

    // Together they are the top eight, in order: not eight out of the top nine
    // with one of them printed twice.
    expect(array_merge($one, $two))->toBe(
        Product::query()->visible()
            ->orderByDesc('total_sales')->orderByDesc('id')
            ->limit(8)->pluck('slug')->all()
    );
});

it('builds the two home rails with one query rather than two', function () {
    soCatalogue();
    Cache::flush();

    $railQueries = 0;
    $offsets = [];

    DB::listen(function ($q) use (&$railQueries, &$offsets) {
        // The best-seller rails' shape: the card columns over the whole
        // catalogue, ordered by sales, eight at a time. The bundles rail asks
        // the same question of one category, so it is told apart by the
        // `whereHas` subquery it carries and this one does not.
        /*
         * ENGINE-AGNOSTIC QUOTING. The suite runs on SQLite and the shop runs
         * MySQL, and the two quote identifiers differently -- "id" against
         * `id`. Written for one engine, this test passed on SQLite and, on
         * MySQL, matched nothing at all: the rail counter stayed 0 and the
         * offset sweep was blind. A test that cannot see the query it is about
         * is worse than no test, because it is reported as coverage.
         */
        if (preg_match('/order by [`"]total_sales[`"] desc, [`"]id[`"] desc limit 8$/i', $q->sql)
            && ! str_contains($q->sql, 'exists (')) {
            $railQueries++;
        }

        if (preg_match('/from [`"]products[`"]/i', $q->sql) && preg_match('/\boffset\b/i', $q->sql)) {
            $offsets[] = $q->sql;
        }
    });

    test()->get('/')->assertOk();

    // One query, fetched once, sliced in PHP.
    expect($railQueries)->toBe(1);

    // And no OFFSET anywhere on the home page. That is the structural half of
    // the fix: two halves of one result set cannot overlap however the
    // database broke the ties inside it, whereas two queries and an offset
    // can only be argued to be safe.
    expect($offsets)->toBe([]);
});

it('leaves no storefront page slicing a list it has not finished ordering', function () {
    /*
     * THE GUARD FOR THE CLASS, and the one that can actually catch a new
     * listing.
     *
     * It reads the SQL the query builder COMPILED, through DB::listen, rather
     * than the PHP that wrote it: a controller that applies its ordering in
     * one method and its paging in another — which is how both
     * CollectionController and ShopController are written — is invisible to
     * any source-level check but perfectly visible here. Every query one of
     * these pages runs that both orders rows and takes a slice of them must
     * end its ORDER BY on `id`.
     *
     * Verified to fail: removing the `->orderByDesc('id')` from
     * CollectionController's 'popular' arm reports
     * `"review_count" desc` against the /best-sellers select.
     *
     * A query that orders without slicing is not this defect and is not
     * checked; neither is a slice with no ORDER BY at all, which is a
     * different bug and not one this catalogue has.
     */
    soCatalogue();

    $sql = [];

    DB::listen(function ($q) use (&$sql) {
        $sql[] = $q->sql;
    });

    $pages = [
        '/',
        '/shop/',
        '/shop/?orderby=popularity',
        '/shop/?orderby=rating',
        '/shop/?orderby=date',
        '/shop/?orderby=plow',
        '/shop/?orderby=phigh',
        '/shop/?orderby=name',
        '/best-sellers',
        '/best-sellers?page=2',
        '/new-in',
        '/super-sale',
        '/everything-under-54-aed',
        '/api/products',
        '/api/posts',
    ];

    foreach ($pages as $page) {
        Cache::flush();
        test()->get($page)->assertOk();
    }

    expect($sql)->not->toBeEmpty();

    $unsettled = [];

    foreach (array_unique($sql) as $statement) {
        // Both halves must be present: an order, and a slice taken out of it.
        if (! preg_match('/\border by\s+(.+?)\s+limit\b/is', $statement, $match)) {
            continue;
        }

        // Split on the commas between keys, not on commas inside a function
        // call such as COALESCE(a, b).
        $keys = preg_split('/,(?![^(]*\))/', $match[1]);
        $last = trim((string) end($keys));

        // Both quoting styles, for the reason recorded on the rail counter
        // above: on MySQL this pattern matched nothing, so every correctly
        // ordered query in the shop was reported as unsettled and the test
        // failed on the engine production actually runs.
        if (preg_match('/[`"]id[`"]\s*(asc|desc)?$/i', $last) === 1) {
            continue;
        }

        /*
         * A key that IS the statement's own GROUP BY key is already total:
         * the group by guarantees one row per value, so there is nothing left
         * to tie. The source scan beside this test keeps a named allowlist for
         * exactly this case because it cannot see the SQL; here the SQL is in
         * hand, so the condition can be checked rather than listed.
         *
         * Deliberately narrow: the last ORDER BY key must appear in a GROUP BY
         * in the same statement, as the ONLY key of that GROUP BY. A group on
         * (a, b) ordered by b alone still ties, and must still be reported.
         */
        // The LAST group by in the statement, not the first: a derived table
        // carries its own, and the outer query's is the one that governs the
        // rows this ORDER BY is sorting. Matching the first one made a
        // two-key inner group hide a single-key outer group, which is the
        // wrong answer in the safe direction but still the wrong answer.
        if (preg_match_all('/\bgroup by\s+(.+?)(?:\s+having\b|\s+order by\b|\s*\)|$)/is', $statement, $groupMatches, PREG_SET_ORDER) > 0) {
            $groupMatch = end($groupMatches);
            $groupKeys = preg_split('/,(?![^(]*\))/', $groupMatch[1]);
            $onlyKey = count($groupKeys) === 1 ? trim((string) $groupKeys[0]) : null;
            $bareLast = trim(preg_replace('/\s+(asc|desc)$/i', '', $last));

            if ($onlyKey !== null && $onlyKey === $bareLast) {
                continue;
            }
        }

        $unsettled[] = $last . '  —  in: ' . $statement;
    }

    expect($unsettled)->toBe([]);
});

it('leaves no query in app/ that slices a list it has not finished ordering', function () {
    /*
     * The source half of the guard, for everything the storefront walk above
     * does not reach: the admin's own tables, the importers, the services.
     *
     * Tests\Support\OrderingScan reads app/ through token_get_all(), so
     * comments and string literals — including every paragraph in this file,
     * which names these method calls repeatedly — are dropped before anything
     * is matched. Six lanes have been bitten by a guard that read its own
     * explanatory prose as code; this one cannot.
     *
     * THE ALLOWLIST IS THREE QUERIES AND EVERY ONE OF THEM IS ALREADY TOTAL.
     * The scan recognises `id` and a few known-unique columns by name, so it
     * cannot see that a GROUP BY key or a DISTINCT column is unique BY
     * CONSTRUCTION within its own query. These three are:
     *
     *   - the analytics "Top products" table, grouped by (name, brand) and
     *     ordered by both, which is the entire group key;
     *   - the customer filter's country list, `distinct()` on the one column
     *     it orders by;
     *   - the review screen's per-product counts, grouped by product_id and
     *     ordered by it;
     *   - StockAlerts' demand list, grouped by stock_alerts.product_id and
     *     ending on it. Identical in shape to the review-screen entry above and
     *     admitted on identical terms: the real tie was `COUNT(*)` and then
     *     `products.name`, both of which several products can share, so the
     *     limit could fall inside a tied block and the reorder report would
     *     drop a different product each time it was opened. That was fixed with
     *     a genuine tie-break — the group key, which is one row per product and
     *     therefore total — and the exemption below is only for the scan's
     *     inability to see that a GROUP BY key cannot tie within its own query.
     *   - RepeatPurchase's map, grouped by rp.product_id and ending on it. The
     *     scan caught this query the moment it landed, which is the guard
     *     working: its real tie was `repeat_buyers`, a small integer that most
     *     products with any repeat buyers at all share, so the MAX_PRODUCTS cut
     *     fell inside a tied block. That was fixed with a genuine tie-break
     *     rather than an exemption, and the exemption below is only for the
     *     scan's inability to see that the remaining key is the GROUP BY key.
     *
     * Anything else reported is the defect this file is about. If one of these
     * three is later rewritten so it no longer needs the exemption, delete its
     * line: the assertion is that nothing OUTSIDE the list is reported, so a
     * shorter list is always safe.
     */
    $allowed = [
        ['Admin/AdminController.php', "orderBy('order_items.brand')"],
        ['Admin/CustomersApiController.php', "orderBy('country')"],
        ['Admin/ReviewsApiController.php', "orderByDesc('reviews.product_id')"],
        ['Support/RepeatPurchase.php', "orderBy('rp.product_id')"],
        ['Services/StockAlerts.php', "orderBy('stock_alerts.product_id')"],
    ];

    $root = app_path();
    $findings = OrderingScan::findings($root);

    // The scan must actually be looking at something.
    expect($findings)->toBeArray();

    $reported = [];

    foreach ($findings as $finding) {
        /*
         * Normalised the same way the report below is, and for a reason worth
         * recording: this used to strip only `app/Http/Controllers/`, so the
         * three entries above (all controllers) matched and a file anywhere
         * else in app/ kept its absolute path and could never be exempted. The
         * list was therefore controller-only by accident rather than by
         * design, which nobody would have discovered until the first non-
         * controller entry was added -- as it just was.
         */
        $relative = str_replace(
            [$root . '/Http/Controllers/', $root . '/'],
            '',
            $finding['file'],
        );
        $exempt = false;

        foreach ($allowed as [$path, $call]) {
            if ($relative === $path && str_contains($finding['why'], $call)) {
                $exempt = true;
                break;
            }
        }

        if (! $exempt) {
            $reported[] = str_replace($root . '/', '', $finding['file'])
                . ':' . $finding['line'] . ' — ' . $finding['why'];
        }
    }

    expect($reported)->toBe([]);
});
