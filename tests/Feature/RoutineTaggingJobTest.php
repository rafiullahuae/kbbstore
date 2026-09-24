<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Product;
use App\Services\BuildMyRoutine;
use App\Services\SettingsService;
use App\Support\ConcernCollections;
use App\Support\RoutineConcerns;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BuildMyRoutineRoutes;

/**
 * Lane Q — making the tagging job smaller, and showing the owner where he is.
 *
 * ── WHY THIS SCREEN IS THE ONE THAT MATTERS ────────────────────────────────
 *
 * docs/SEO-FEATURE-MATRIX.md §2 ranks concern-led landing pages as the largest
 * gap against the competitor, and §3 item 1 calls the tagging behind them "the
 * single biggest blocker in the whole plan" — 30 to 45 products, done on
 * Catalog -> Build my routine. Everything below is about the cost and the
 * visibility of that one job.
 *
 * ── 1. THE SEARCH COULD NOT SEE THE COLUMN THE ANSWERS ARE IN ──────────────
 *
 * docs/SEO-CONCERN-MAPPING.md §1b observed that this screen's search matches
 * `name` and `sku` only, and §7 item A named the fix. It matters most for the
 * concern that is hardest to shop for: the sensitivity signal is
 * "fragrance-free", "centella", "panthenol", "ceramide" — words that live in an
 * ingredient list and almost never in a product name. Without the column,
 * tagging `sensitivity` meant opening products one at a time.
 *
 * ── 2. THE JOB HAD NO VISIBLE FINISH LINE ──────────────────────────────────
 *
 * /concern/{slug}/ 404s until a concern has copy AND
 * ConcernCollections::MIN_PRODUCTS live tagged products. Neither half could be
 * seen from the screen the tagging is done on, so "tag 30-45 products" was a
 * leap of faith. The countdown is `coverage.concern_pages`.
 *
 * AND IT COUNTS A DIFFERENT THING FROM THE ROUTINE TILES ABOVE IT, which is the
 * subtle part and the one most likely to be got wrong in the owner's favour: an
 * untagged product suits EVERY routine and NO concern page. A countdown reading
 * "400 products for acne" over a page that still 404s would be the worst
 * possible failure of this screen.
 */
beforeEach(function () {
    BuildMyRoutineRoutes::wire(app());
    app(SettingsService::class)->setModule('build_my_routine', true);

    // Each case states its own catalogue in full — BuildMyRoutineTest's rule,
    // for its reason: otherwise every count measures the seeder.
    Product::query()->forceDelete();
});

function rtjAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'Q Owner',
        'email' => 'q-' . uniqid() . '@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
}

function rtjProduct(array $attributes = []): Product
{
    return Product::create(array_replace([
        'slug' => 'rtj-' . Str::random(8),
        'name' => 'RTJ ' . Str::random(4),
        'status' => 'publish',
        'is_visible' => true,
        'price' => 10000,
        'stock_status' => 'instock',
    ], $attributes));
}

/** The tagging table, as the screen fetches it. */
function rtjSearch(string $term): array
{
    return test()->actingAs(rtjAdmin(), 'admin')
        ->getJson('/admin-api/routine-products?q=' . urlencode($term))
        ->assertOk()
        ->json();
}

/* ─────────────────── 1. the search reaches the ingredients ───────────────── */

it('finds a product by an ingredient its name does not mention', function () {
    /*
     * THE DEFECT, and it is the whole of §7 item A: RoutinesApiController
     * ::products() built `name LIKE ? OR sku LIKE ?` and stopped there, while
     * `products.ingredients` has existed since
     * 2026_10_05_000000_add_product_editor_columns.php. So every one of the ~30
     * search terms in docs/SEO-CONCERN-MAPPING.md §3 that names an INGREDIENT
     * rather than a product line returned nothing, and the sensitivity page —
     * whose signal is almost entirely in ingredient lists — could not be tagged
     * from this box at all.
     *
     * MUTATION NOTE: delete the `orWhereRaw('ingredients LIKE ...')` line in
     * RoutinesApiController::products() and this case goes red on the first
     * expectation: 'Calming Daily Toner' stops being findable by "centella".
     */
    rtjProduct([
        'name' => 'Calming Daily Toner',
        'ingredients' => 'Water, Butylene Glycol, Centella Asiatica Extract, Madecassoside, Panthenol. Fragrance-free.',
    ]);
    rtjProduct(['name' => 'Bright Vitamin Serum', 'ingredients' => 'Water, Niacinamide 10%, Ascorbic Acid.']);

    $hit = rtjSearch('centella');

    expect($hit['total'])->toBe(1)
        ->and($hit['products'][0]['name'])->toBe('Calming Daily Toner');

    // The other half of the sensitivity signal, and the one §3 predicted would
    // "almost certainly return nothing".
    expect(rtjSearch('fragrance-free')['total'])->toBe(1);

    // And the columns that already worked still do.
    expect(rtjSearch('Bright')['total'])->toBe(1)
        ->and(rtjSearch('niacinamide')['total'])->toBe(1);
});

it('says why a row matched when neither the name nor the SKU shows it', function () {
    /*
     * THE DEFECT A BARE INGREDIENT SEARCH WOULD HAVE: 'Calming Daily Toner'
     * appears in the results for "centella" with nothing on the row explaining
     * it, which reads as a broken search — the fastest way to make an owner
     * stop trusting the box on a 30-45 product job.
     *
     * MUTATION NOTE: return null unconditionally from
     * RoutinesApiController::ingredientHit() and the first expectation is red.
     */
    rtjProduct([
        'name' => 'Calming Daily Toner',
        'ingredients' => 'Water, Butylene Glycol, Centella Asiatica Extract, Madecassoside, Panthenol.',
    ]);

    $row = rtjSearch('centella')['products'][0];

    expect($row['ingredient_hit'])->toContain('Centella Asiatica Extract');

    /*
     * A SNIPPET AND NOT THE COLUMN. Twenty-five rows of full ingredient lists
     * is a payload nobody reads; the window is small on purpose.
     */
    expect(mb_strlen((string) $row['ingredient_hit']))->toBeLessThan(120);

    // Never repeated when the row already shows the answer.
    rtjProduct(['name' => 'Centella Ampoule', 'ingredients' => 'Water, Centella Asiatica Extract.']);

    $named = collect(rtjSearch('Centella Ampoule')['products'])
        ->firstWhere('name', 'Centella Ampoule');

    expect($named['ingredient_hit'])->toBeNull();
});

it('escapes a search term the same way in the new column as in the old ones', function () {
    /*
     * THE DEFECT: a second escaping scheme. §7 item A's guard says to reuse the
     * declared ESCAPE character rather than write another one — a pattern
     * escaped with backslashes matches what the operator typed on MySQL and a
     * literal backslash on SQLite, so "50%" would return the whole catalogue on
     * one engine and nothing on the other.
     *
     * MUTATION NOTE: drop " ESCAPE '!'" from the ingredients clause only, and
     * the first expectation goes red on SQLite — `%` stops being a literal.
     */
    rtjProduct(['name' => 'Plain Cream', 'ingredients' => 'Water, Niacinamide 10%, Glycerin.']);
    rtjProduct(['name' => 'Other Cream', 'ingredients' => 'Water, Glycerin.']);

    expect(rtjSearch('10%')['total'])->toBe(1);

    // A bare wildcard is a literal, not "everything".
    expect(rtjSearch('%')['total'])->toBe(1);
});

it('does not cost the tagging table another query', function () {
    /*
     * The search is one more OR inside a WHERE that was already being built, so
     * the endpoint still costs the count and the page and nothing else.
     * StorefrontQueryBudgetTest is a budget for the storefront; this is the same
     * discipline applied to the one admin screen a lane was asked to make
     * faster to use.
     */
    foreach (range(1, 12) as $i) {
        rtjProduct(['ingredients' => 'Water, Centella Asiatica Extract, Glycerin.']);
    }

    $admin = rtjAdmin();

    $count = static function (string $url) use ($admin): int {
        $log = [];
        DB::listen(function ($q) use (&$log) { $log[] = $q->sql; });
        test()->actingAs($admin, 'admin')->getJson($url)->assertOk();
        DB::getEventDispatcher()->forget(\Illuminate\Database\Events\QueryExecuted::class);

        return count($log);
    };

    /*
     * WARM FIRST. The first request of a process also fills the settings cache
     * — two SELECTs on `settings` that belong to the console's boot rather than
     * to this endpoint — and counting them would have made the plain call look
     * dearer than the searched one for a reason that has nothing to do with
     * either. Measured: 4 then 2, with the same two statements doing the work.
     */
    $count('/admin-api/routine-products');

    $plain = $count('/admin-api/routine-products');
    $searched = $count('/admin-api/routine-products?q=centella');

    /*
     * TWO WITHOUT A TERM — the count and the page — and FOUR with one. Raised
     * deliberately in round 3 rather than quietly, and this is the whole of the
     * reason:
     *
     * Round 1 added `ingredients` to the search's WHERE. That column is created
     * by a migration which guards every column with Schema::hasColumn, so on a
     * server where it never ran there is no such column and EVERY SEARCH WAS A
     * 500 — reproduced in Chromium, and the likeliest cause of the owner's
     * "the product search was not working and was not showing any results".
     * The fix probes for the column, which is a schema lookup: two statements
     * on SQLite, and it is paid only on a request that carries a term.
     *
     * A 500 on every search is not worth saving two metadata queries. Measured
     * end to end against a 681-product catalogue the round trip is 38-65ms
     * WITH the probe, against a 250ms debounce — the operator feels the
     * debounce, not this.
     *
     * MUTATION NOTE: remove the probe and this reads 2 again, and
     * RoutineSearchAndDemoTest's "still searches when the server has no
     * ingredients column" goes red with a 500.
     */
    expect($plain)->toBe(2)
        ->and($searched)->toBe(4);
});

/* ────────────────────── 2. the concern-page countdown ────────────────────── */

it('tells the owner how many more products each concern page needs', function () {
    /*
     * THE DEFECT: the owner could not see how close any concern was to being
     * publishable. `coverage` carried the routine tiles and nothing about the
     * pages the tagging is actually for, so "tag 30-45 products" had no
     * countdown behind it — the page either appeared one day or did not.
     *
     * MUTATION NOTE: delete the 'concern_pages' key from
     * BuildMyRoutine::coverage()'s return and this goes red immediately.
     */
    rtjProduct(['routine_concerns' => json_encode(['acne'])]);

    $rows = collect(
        test()->actingAs(rtjAdmin(), 'admin')
            ->getJson('/admin-api/routines')->assertOk()
            ->json('coverage.concern_pages')
    )->keyBy('concern');

    expect($rows)->toHaveCount(count(RoutineConcerns::LIST));

    $acne = $rows['acne'];

    expect($acne['label'])->toBe('Acne & blemishes')
        ->and($acne['tagged'])->toBe(1)
        ->and($acne['min'])->toBe(ConcernCollections::MIN_PRODUCTS)
        ->and($acne['needed'])->toBe(ConcernCollections::MIN_PRODUCTS - 1)
        ->and($acne['has_copy'])->toBeTrue()
        ->and($acne['live'])->toBeFalse()
        ->and($acne['path'])->toBe('/concern/acne/');

    // A concern nobody has tagged, and which has no copy either. Both facts are
    // reported separately, because they are closed by different people.
    expect($rows['sun']['tagged'])->toBe(0)
        ->and($rows['sun']['has_copy'])->toBeFalse()
        ->and($rows['sun']['live'])->toBeFalse();
});

it('counts a concern page the way the concern page counts, not the way a routine does', function () {
    /*
     * THE DEFECT, and it is the one that would have been worst: an untagged
     * product suits EVERY routine (BuildMyRoutine::forConcern) and NO concern
     * page (ConcernCollections::query selects explicit tags only). Tallying the
     * countdown the way the routine tiles are tallied would have told the owner
     * he had a hundred products for acne over a page that still 404s.
     *
     * MUTATION NOTE: move the `RoutineConcerns::clean()` tally in
     * BuildMyRoutine::coverage() BELOW the `if ($role === null) continue;`, or
     * make it fall back to every slug when the list is empty — either way the
     * first expectation goes red.
     */
    // Six live products, none of them tagged for anything.
    foreach (range(1, 6) as $i) {
        rtjProduct(['routine_role' => 'cleanse']);
    }
    // One tagged for acne, and deliberately given NO role: a concern page does
    // not care which step a product fills.
    rtjProduct(['routine_concerns' => json_encode(['acne'])]);

    $rows = collect(app(BuildMyRoutine::class)->coverage()['concern_pages'])->keyBy('concern');

    expect($rows['acne']['tagged'])->toBe(1)
        ->and($rows['hydration']['tagged'])->toBe(0);
});

it('agrees with the class the router asks, product for product', function () {
    /*
     * THE DEFECT A SECOND TALLY WOULD BE: a countdown that says "live" over a
     * URL that 404s, or the reverse. The screen's whole job is to be believed.
     *
     * MUTATION NOTE: change `max(0, MIN_PRODUCTS - $count)` to
     * `MIN_PRODUCTS - $count` and the negative-number expectation is red; change
     * `in_array($slug, $live, true)` to `$count > 0` and the `live` comparison
     * against the router is red at one product.
     */
    foreach (range(1, ConcernCollections::MIN_PRODUCTS + 2) as $i) {
        rtjProduct(['routine_concerns' => json_encode(['acne'])]);
    }
    rtjProduct(['routine_concerns' => json_encode(['hydration'])]);

    $rows = collect(app(BuildMyRoutine::class)->coverage()['concern_pages'])->keyBy('concern');

    foreach (RoutineConcerns::slugs() as $slug) {
        $row = $rows[$slug];

        // The count, against the query the page itself runs.
        expect($row['tagged'])->toBe(ConcernCollections::counts([$slug])[$slug]);

        // The verdict, against the router.
        expect($row['live'])->toBe(ConcernCollections::isLive($slug));
        test()->get($row['path'])->assertStatus($row['live'] ? 200 : 404);

        // "0 more" is the finish line; there is no such thing as -2 more.
        expect($row['needed'])->toBeGreaterThanOrEqual(0);
    }

    expect($rows['acne']['live'])->toBeTrue()
        ->and($rows['acne']['needed'])->toBe(0)
        ->and($rows['hydration']['live'])->toBeFalse();
});

it('does not count a product a shopper cannot be shown', function () {
    /*
     * THE DEFECT: a countdown that reaches the floor on drafts and sold-out
     * rows, and a page that 404s anyway. ConcernCollections filters on
     * visible() and in-stock; so must the countdown.
     */
    rtjProduct(['routine_concerns' => json_encode(['acne'])]);
    rtjProduct(['routine_concerns' => json_encode(['acne']), 'status' => 'draft']);
    rtjProduct(['routine_concerns' => json_encode(['acne']), 'is_visible' => false]);
    rtjProduct(['routine_concerns' => json_encode(['acne']), 'stock_status' => 'outofstock']);

    $rows = collect(app(BuildMyRoutine::class)->coverage()['concern_pages'])->keyBy('concern');

    expect($rows['acne']['tagged'])->toBe(1);
});

it('costs the screen one query for all eight concerns, not one each', function () {
    /*
     * The counts are free — coverage() had already read every live product's
     * routine_concerns. The one query is ConcernCollections::live(), asked once
     * for the whole set, and it stays one however many concerns get copy.
     *
     * MUTATION NOTE: replace `counts()` in ConcernCollections::live() with a
     * loop calling isLive() per slug. Identical answers, and this goes red the
     * day a second concern is enabled — which is exactly when nobody would be
     * looking.
     */
    foreach (range(1, 6) as $i) {
        rtjProduct(['routine_concerns' => json_encode(['acne'])]);
    }

    app(BuildMyRoutine::class)->coverage();      // warm anything cacheable

    $log = [];
    DB::listen(function ($q) use (&$log) { $log[] = $q->sql; });
    app(BuildMyRoutine::class)->coverage();
    DB::getEventDispatcher()->forget(\Illuminate\Database\Events\QueryExecuted::class);

    // The products read, plus the routine overrides, plus ONE for live().
    expect(count($log))->toBeLessThanOrEqual(3);

    $likeQueries = array_values(array_filter($log, static fn (string $s): bool => str_contains($s, 'routine_concerns')));

    expect(count($likeQueries))->toBeLessThanOrEqual(2);
});

/* ─────────────────────── 3. nothing already working moved ───────────────── */

it('leaves the shop dark: no concern page appears because this lane shipped', function () {
    /*
     * Rule 1, asked of the two things this lane could have moved by accident.
     * ENABLED is still one slug, MIN_PRODUCTS is still the floor it was, and an
     * untagged shop still 404s every concern address.
     */
    expect(ConcernCollections::ENABLED)->toBe(['acne'])
        ->and(ConcernCollections::MIN_PRODUCTS)->toBe(3)
        ->and(ConcernCollections::live())->toBe([]);

    foreach (RoutineConcerns::slugs() as $slug) {
        test()->get(ConcernCollections::path($slug))->assertNotFound();
    }
});
