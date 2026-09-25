<?php

declare(strict_types=1);

/**
 * What `Category::buildPath()` actually costs, and where — Lane Q11, Task 3.
 *
 * ── THE QUESTION ────────────────────────────────────────────────────────────
 *
 * docs/q10-component-load-contract.md's suite-wide census found `Category::
 * $parent` read lazily 1,272 times — 96% of every lazy read in this
 * application — all of it inside `buildPath()`, and concluded:
 *
 *   > Fixing it means a recursive CTE or a materialised path column, which is a
 *   > migration and somebody's decision, not a measurement.
 *
 * The brief for this lane put the decision plainly: a tree three deep walked
 * once per page is not worth a migration; the same walk inside a loop over 24
 * tiles is. Find out which.
 *
 * ── THE ANSWER: BOTH, AND NEITHER NEEDS A MIGRATION ─────────────────────────
 *
 * 1 · THE MATERIALISED PATH COLUMN ALREADY EXISTS. `categories.path` is shipped,
 *     it is recomputed on every structural write by
 *     `Admin\CategoriesApiController` (subtreePaths/movedPaths, four call
 *     sites) and by `Import\CategoryImporter`, and both `Category::url()` and
 *     `CategoryPath::canonicalPath()` read it first. `buildPath()` is the
 *     FALLBACK for a row whose `path` is null. No migration is owed; the fix
 *     the census called for was built before the census ran.
 *
 * 2 · IT IS BOTH SHAPES AT ONCE, measured across a full instrumented suite run
 *     — `buildPath()` made to log its caller, 5,970 passed, 2,024 entries:
 *
 *       1,821  (90%)  store/home.blade.php:391, the category tile loop
 *          96   (5%)  ShopController ×3 + CategoryPath::resolve, per archive page
 *          12          admin category screens (structural writes)
 *           6          ProductController::breadcrumbTrail
 *           5          SearchController
 *
 *     So it IS a loop over tiles, and it is ALSO a per-page walk. The two cost
 *     completely different things, which is the finding.
 *
 * 3 · THE LOOP COSTS ZERO QUERIES, AND THAT IS THE BUG. `HomeController`
 *     selects `'id', 'name', 'slug'` for the tiles. `path` is not among them,
 *     so `url()` falls through to `buildPath()` on all ten tiles — and
 *     `parent_id` is not among them either, so `$this->parent` is a belongsTo
 *     on an absent key, answers null WITHOUT A QUERY, and the walk stops at the
 *     leaf. Ten walks, nought queries, and ten paths that are missing every
 *     ancestor. Measured on a real `GET /`: the tile for a category nested two
 *     deep links to `/product-category/zzh-mists/`, which
 *     `CategoryPath::resolve()` answers `redirect` → 301 →
 *     `/product-category/zzh-skincare/zzh-toners/zzh-mists/`.
 *
 *     THE SAME OMISSION CAUSES THE SPEED AND THE WRONGNESS. That is why it
 *     looked free.
 *
 * 4 · THE PER-PAGE WALK COSTS depth − 1 QUERIES, ONCE, AND ONLY ON A NULL PATH.
 *     Measured on the real category archive, at five depths, both states:
 *
 *       depth                      1    2    3    4    5
 *       `categories` queries, path set
 *                                  4    4    4    4    4     slope 0.00
 *       `categories` queries, path NULL
 *                                  4    5    6    7    8     slope 1.00
 *
 *     Once per page and not three times, although three separate call sites ask
 *     — `CategoryPath::resolve()`, `ShopController::breadcrumbTrail()` and
 *     `::absoluteListingUrl()`. The first walk loads `parent`, and its parent,
 *     onto the instance the other two are handed, so the second and third are
 *     free. Production nests four deep, so the worst real case is three extra
 *     single-row primary-key reads on one page, and only on a row whose `path`
 *     the admin screen has not yet recomputed.
 *
 * ── SO: NO MIGRATION, AND ONE LINE SOMEBODY ELSE OWNS ───────────────────────
 *
 * A recursive CTE would buy 3 queries on a page that is already 4, for rows
 * that should not have a null path in the first place. What is actually worth
 * doing is `'path'` added to HomeController's select list — zero queries, and
 * it turns ten 301s into ten 200s. `app/Http/Controllers/Store/HomeController.php`
 * is not this lane's ground, so the defect is PINNED AS IT IS below, with the
 * fix named, the way `RedirectMiddlewareTest` pinned the trailing-slash 301
 * before the lane that owned that controller closed it.
 *
 * MUTATION NOTES at the foot: six run, including the one that came back green.
 */

use App\Models\Category;
use App\Models\Product;
use App\Support\CategoryPath;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Depths driven on the archive. Production nests four; five brackets it. */
const Q11_DEPTHS = [1, 2, 3, 4, 5];

/**
 * A chain of categories $depth long, with a product on the leaf.
 *
 * `$withPath` writes the materialised column the way CategoriesApiController
 * does on every structural write; leaving it off is the imported-row state
 * CategoryPath::canonicalPath()'s own comment describes.
 *
 * @return array{0:Category, 1:Product}
 */
function q11Chain(int $depth, bool $withPath): array
{
    $parentId = null;
    $node = null;

    for ($i = 1; $i <= $depth; $i++) {
        $node = Category::create([
            'slug' => "q11c-{$i}-" . uniqid(),
            'name' => "Q11 Level {$i}",
            'parent_id' => $parentId,
        ]);

        if ($withPath) {
            $node->update(['path' => $node->buildPath(), 'depth' => $i - 1]);
            $node = $node->fresh();
        }

        $parentId = $node->id;
    }

    $product = Product::create([
        'slug' => 'q11cp-' . uniqid(),
        'name' => 'Q11 Chain Product',
        'price' => 5000,
        'status' => 'publish',
        'is_visible' => true,
        'stock_status' => 'instock',
        'type' => 'simple',
    ]);

    $product->categories()->attach($node->id);

    return [$node, $product];
}

/**
 * Statements touching `categories` during one call, and what it produced.
 *
 * enableQueryLog/flushQueryLog rather than DB::listen(), which registers a
 * listener that is never removed.
 *
 * @return array{0:int, 1:mixed}
 */
function q11Cats(callable $fn): array
{
    app()->forgetScopedInstances();
    DB::enableQueryLog();
    DB::flushQueryLog();

    $produced = $fn();

    $categories = count(array_filter(
        array_column(DB::getQueryLog(), 'query'),
        static fn (string $q) => str_contains($q, '"categories"') || str_contains($q, '`categories`')
    ));

    DB::flushQueryLog();
    DB::disableQueryLog();

    return [$categories, $produced];
}

/* ═══════════════════════════════════════════════════════════════════════════
   1 · THE PER-PAGE WALK
   ═════════════════════════════════════════════════════════════════════════ */

it('walks the ancestry once per archive page, and not at all when the path column is set', function () {
    $cost = ['set' => [], 'null' => []];

    foreach (Q11_DEPTHS as $depth) {
        foreach ([true, false] as $withPath) {
            [$leaf] = q11Chain($depth, $withPath);

            // The URL is built OUTSIDE the measured window: building it is
            // itself a walk, and counting the harness's walk as the page's is
            // how the first version of this fixture read 7 where the page costs
            // 4. Mutation 4 below.
            $path = CategoryPath::canonicalPath(Category::findOrFail($leaf->id));

            [$categories, $response] = q11Cats(
                fn () => test()->get('/product-category/' . $path . '/')
            );

            // WHAT THE PAGE ACTUALLY DID, before any count is compared. A 301 or
            // a 404 is cheap and proves nothing about a rendered archive.
            expect($response->status())->toBe(200, "the archive at depth {$depth} did not render");
            expect($response->getContent())->toContain('Q11 Chain Product');

            $cost[$withPath ? 'set' : 'null'][$depth] = $categories;
        }
    }

    // With the materialised column, flat at every depth — no walk at all.
    foreach (Q11_DEPTHS as $depth) {
        expect($cost['set'][$depth])->toBe(
            $cost['set'][1],
            "a category archive at depth {$depth} cost {$cost['set'][$depth]} category queries "
            . "against {$cost['set'][1]} at depth 1, with categories.path populated — "
            . 'the materialised column has stopped being read'
        );
    }

    /*
     * Without it, ONE walk per page: exactly depth − 1 extra single-row reads,
     * however many call sites ask for the URL. Three do. The first walk loads
     * the chain onto the instance the other two are handed.
     */
    foreach (Q11_DEPTHS as $depth) {
        expect($cost['null'][$depth] - $cost['set'][$depth])->toBe(
            $depth - 1,
            "a null path at depth {$depth} cost " . ($cost['null'][$depth] - $cost['set'][$depth])
            . ' extra queries rather than ' . ($depth - 1)
            . ' — either the walk has stopped being shared between the three call sites, '
            . 'or it has started running per tile'
        );
    }

    // The worst real case, as a number: production nests four deep.
    expect($cost['null'][4] - $cost['set'][4])->toBe(3);
});

/* ═══════════════════════════════════════════════════════════════════════════
   2 · THE LOOP — 90% of the census, and it costs nothing
   ═════════════════════════════════════════════════════════════════════════ */

it('pins the homepage category tile linking at the leaf instead of the full path', function () {
    /*
     * ── A DEFECT PINNED AS IT IS, NOT A GUARANTEE ──────────────────────────
     *
     * `HomeController` selects id, name and slug for the tiles. `path` is not
     * among them, so Category::url() falls through to buildPath(); `parent_id`
     * is not among them either, so the walk finds no parent, answers no query
     * and stops at the leaf. The tile for a nested category therefore links to
     * an address that 301s to the real one.
     *
     * Nothing on the shop as shipped is affected — every seeded category is a
     * root. It bites a shop whose tree is nested, which is what the WooCommerce
     * import produces ("nested to four levels", PagesApiController).
     *
     * THE FIX IS ONE LINE and it is in another lane's file:
     *     app/Http/Controllers/Store/HomeController.php:133
     *     ->select('id', 'name', 'slug')   →   ->select('id', 'name', 'slug', 'path')
     * Zero extra queries; ten 301s become ten 200s. When that lands this case
     * goes red on the `redirect` expectation, and it is advanced to `ok` with
     * the old assertion quoted — never deleted.
     */
    Cache::flush();

    $root = Category::create(['slug' => 'q11h-skincare-' . uniqid(), 'name' => 'Q11H Skincare']);
    $root->update(['path' => $root->buildPath(), 'depth' => 0]);

    $leaf = Category::create(['slug' => 'q11h-mists-' . uniqid(), 'name' => 'Q11H Mists', 'parent_id' => $root->id]);
    $leaf->update(['path' => $leaf->buildPath(), 'depth' => 1]);
    $leaf = $leaf->fresh();

    // Enough products to earn a tile: the rail is ordered by count and capped
    // at ten, and the seeded demo catalogue is already on it.
    foreach (range(1, 40) as $i) {
        $p = Product::create([
            'slug' => 'q11hp-' . $i . '-' . uniqid(),
            'name' => 'Q11H Product ' . $i,
            'price' => 5000,
            'status' => 'publish',
            'is_visible' => true,
            'stock_status' => 'instock',
            'type' => 'simple',
        ]);
        $p->categories()->attach($leaf->id);
    }

    // The column really is populated — the defect is the SELECT, not the data.
    expect(Category::findOrFail($leaf->id)->path)->toBe($root->slug . '/' . $leaf->slug);

    /*
     * THE BASELINE, AND IT IS A SLOPE RATHER THAN A CAP. The homepage asks
     * `categories` a handful of times for reasons that have nothing to do with
     * this walk (the rail itself, the menu, the filters), and pinning that
     * number would pin somebody else's code. What is asserted is that adding
     * DEPTH to the tree changes it by nothing — which is the whole claim.
     *
     * Taken with the nested category temporarily re-parented to the root, so the
     * same ten tiles are drawn either way and only the depth differs.
     */
    Category::query()->whereKey($leaf->id)->update(['parent_id' => null]);
    Cache::flush();
    [$flat] = q11Cats(fn () => test()->get('/'));
    Category::query()->whereKey($leaf->id)->update(['parent_id' => $root->id]);

    Cache::flush();

    [$categories, $response] = q11Cats(fn () => test()->get('/'));

    expect($response->status())->toBe(200);

    preg_match_all('#<a class="ct" href="([^"]+)"#', (string) $response->getContent(), $m);

    // The rail really rendered, and this category really is on it.
    expect(count($m[1]))->toBeGreaterThanOrEqual(2, 'the homepage category rail rendered no tiles');

    $ours = array_values(array_filter($m[1], static fn (string $h) => str_contains($h, $leaf->slug)));

    expect($ours)->toHaveCount(1, 'the nested category did not earn a tile, so this case measured nothing');

    $href = $ours[0];

    /*
     * ▲ THE PIN. The leaf slug alone, with no ancestor — and resolve() answers
     * `redirect`, which is a 301 on a link the homepage printed itself.
     */
    $verdict = CategoryPath::resolve(trim(str_replace('/product-category/', '', $href), '/'));

    // FIRST, so that the run which closes this defect prints the sentence that
    // says what to do rather than a string comparison nobody can read.
    expect($verdict['status'])->toBe(
        'redirect',
        "the homepage category tile now links at {$href}, which resolves straight through — "
        . "HomeController's select has gained `path` and this defect is closed. "
        . "Advance this pin: expect 'ok', and quote the old assertion in the comment above."
    );

    expect($verdict['to_path'])->toBe($root->slug . '/' . $leaf->slug);
    expect($href)->toContain('/product-category/' . $leaf->slug . '/');
    expect($href)->not->toContain($root->slug);

    /*
     * And the reason nobody noticed: the ten walks are FREE. `parent_id` is not
     * selected either, so belongsTo answers null without a query. The homepage
     * asks `categories` a fixed small number of times whatever the tree looks
     * like — 90% of the census's 1,272 lazy reads, at nought queries.
     */
    expect($categories)->toBe(
        $flat,
        "the homepage cost {$categories} category queries with a tile nested two deep against {$flat} "
        . 'with every tile a root — the loop has started walking for real, which is one query per '
        . 'ancestor per tile and is what a migration would be needed to remove'
    );
});

it('shows the walk answering nothing when the row was fetched without parent_id', function () {
    /*
     * The mechanism behind the case above, stated on its own so it cannot be
     * mistaken for a property of the homepage. THIS IS THE WHOLE REASON
     * buildPath() looks cheap in the census: a belongsTo whose foreign key was
     * not SELECTed is null, silently, and the walk it drives ends at once.
     */
    [$leaf] = q11Chain(4, true);

    $full = Category::findOrFail($leaf->id);
    expect($full->buildPath())->toBe($full->path);
    expect(substr_count($full->buildPath(), '/'))->toBe(3);

    [$categories, $truncated] = q11Cats(
        fn () => Category::query()->select('id', 'name', 'slug')->findOrFail($leaf->id)->buildPath()
    );

    expect($truncated)->toBe($leaf->slug, 'the walk found ancestors it was not given the key for');
    expect($categories)->toBe(1, 'the truncated walk cost a query, so it is not the free-and-wrong shape');
});

/* ═══════════════════════════════════════════════════════════════════════════
   MUTATIONS — seven, every one applied, run and reverted, with what each
   printed. One of them is the PROPOSED FIX rather than a defect, because a pin
   that does not say how it comes down is a pin nobody takes down.
   ═══════════════════════════════════════════════════════════════════════════

    1  `Category::url()` stops reading the materialised column:
       `($this->path ?: $this->buildPath())` → `$this->buildPath()`.
       RUN: 1 failed —
           "a category archive at depth 2 cost 5 category queries against 4 at
            depth 1, with categories.path populated — the materialised column
            has stopped being read"
       This is the whole Task 3 finding as an assertion: the column IS the fix,
       and removing it is what a migration would be asked to replace.

    2  `CategoryPath::canonicalPath()` stops reading it, the same way.
       RUN: 1 failed, the identical line — because resolve() is the FIRST of the
       three call sites on an archive page and the other two ride on the chain
       it loads. Either of 1 or 2 alone puts the walk back.

    3  ▲ THE PROPOSED FIX, APPLIED:
           app/Http/Controllers/Store/HomeController.php:133
           ->select('id', 'name', 'slug')  →  ->select('id', 'name', 'slug', 'path')
       RUN: 1 failed, and it prints the instruction:
           "the homepage category tile now links at /product-category/
            q11h-skincare-…/q11h-mists-…/, which resolves straight through —
            HomeController's select has gained `path` and this defect is closed.
            Advance this pin: expect 'ok', and quote the old assertion in the
            comment above."
       The tile href became the full nested path and the 301 became a 200, at NO
       extra query — the depth-slope assertion in the same case stayed green.
       That is the measurement that says the one-line fix is the whole fix.

    4  The archive URL built INSIDE the measured window
       (`q11Cats(fn () => get('…' . canonicalPath(find(...)) . '…'))`).
       RUN: 1 failed —
           "a null path at depth 2 cost 2 extra queries rather than 1"
       ▲ This is the shape the first version of the fixture had, and it is why
       the table in the header reads 4/4/4/4/4 rather than 4/5/6/7/8 for a
       populated path. Building the URL is itself a walk; counting the harness's
       walk as the page's inflates every row by depth − 1 and would have had
       this file reporting a per-page cost the page does not pay.

    5  The nested category given NO products, so it earns no tile.
       RUN: 1 failed —
           "the nested category did not earn a tile, so this case measured
            nothing / Failed asserting that actual size 0 matches expected
            size 1."

    5b The same, with that floor replaced by a fallback href.
       RUN: 1 failed, but on a string comparison with no explanation — which is
       the argument for the floor: without it the case fails for the wrong
       reason and reads like a broken assertion rather than a vanished fixture.

    6  `Q11_DEPTHS` collapsed to `[1]`.
       RUN: 1 failed. At one depth there is no slope to measure and
       `$cost['null'][4]` does not exist, so the closing "worst real case is 3"
       assertion fires. The table cannot silently shrink to one row.

    7  `foreach ([] as $i)` on the product loop AND the rail floor
       (`toBeGreaterThanOrEqual(2)`) removed.
       RUN: 1 failed on the tile floor at line 306 regardless — two independent
       floors, and the second catches what the first would have missed. Recorded
       because it was the attempt to slip an empty fixture past this case and it
       could not be done.
   ═════════════════════════════════════════════════════════════════════════ */
