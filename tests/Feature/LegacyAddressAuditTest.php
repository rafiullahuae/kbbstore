<?php

/**
 * Store → SEO & Meta → SEO Audit — "Old shop address with no redirect".
 *
 * ── THE DEFECT, AS IT LOOKED ON THE SHOP ────────────────────────────────────
 *
 * kbeautybliss.com published its category archives flat at the site root, and
 * Google holds those addresses today. Two of the fifteen are confirmed in the
 * index with their live titles:
 *
 *     kbeautybliss.com/skincare/       "Glow Instantly with Korean Skincare
 *                                       Products Online | K-Beauty Bliss"
 *     kbeautybliss.com/skincare-sets/  "Best Korean Skin Care Sets for Women
 *                                       in 2024"
 *
 * This application serves those categories at /product-category/{path}/ and
 * answers the flat address with a 404 — deliberately, and
 * App\Support\LegacyCategoryUrls explains why preserving the slug rather than
 * guessing a category is the safe transformation.
 *
 * The rows that would 301 the old address to the new one are produced by the
 * WordPress import and are seeded by no migration, so on a server where the
 * import has not run there are none. Nothing anywhere in the console said so.
 * The failure mode is the one nothing looks wrong about: on cutover day the
 * shop renders perfectly, every screen is green, and fifteen indexed URLs
 * start answering 404 instead of passing their ranking and their links to the
 * page that replaced them. It is invisible precisely because these addresses
 * are NOT part of the indexable surface — no audit that scans rows this shop
 * serves can see a URL this shop refuses to serve.
 *
 * Before this check the audit reported no such finding at all: the key was
 * absent from the response, so every assertion below on
 * `legacy_url_no_redirect` reads 0 against an expected 15 and goes red.
 *
 * ── NAMED WITHOUT "Seo" ON PURPOSE ──────────────────────────────────────────
 *
 * tests/Feature/*Seo* belongs to the lane changing App\Support\Seo this round,
 * and tests/Feature/*Redirect* to the lane changing the redirect middleware.
 * This file is in neither glob, for the same reason
 * IndexableSurfaceAuditTest.php is not: a filename should not cause a merge.
 *
 * Every assertion is made against what the endpoint actually returns, never
 * against SeoAudit::run() directly, because the endpoint is what the screen
 * reads and an allowlist between the two is exactly where a finding can be
 * counted and then silently not shipped.
 */

use App\Models\AdminUser;
use App\Models\Redirect;
use App\Support\LegacyCategoryUrls;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * The route mounted exactly as routes/seo-audit-admin.php says it must be.
 *
 * ONE middleware() call — RouteRegistrar::middleware() REPLACES the attribute
 * rather than appending to it. Registering here is idempotent against the
 * integrator's own wiring, because Laravel's route collection is keyed on
 * method and URI.
 */
function laaMount(): void
{
    Route::middleware(['web', 'auth:admin', \App\Http\Middleware\NoStoreAdminApi::class])
        ->prefix('admin-api')
        ->group(base_path('routes/seo-audit-admin.php'));
}

function laaOwner(): AdminUser
{
    return AdminUser::create([
        'name' => 'LAA owner',
        'email' => 'laa-'.uniqid().'@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
}

/** The report, as the endpoint returns it. */
function laaScan(): array
{
    laaMount();

    return test()->actingAs(laaOwner(), 'admin')
        ->getJson('/admin-api/seo-audit')
        ->assertOk()
        ->json();
}

/** The finding, whether or not the server sent it. */
function laaFinding(array $report): array
{
    return $report['findings']['legacy_url_no_redirect'] ?? ['count' => 0, 'samples' => []];
}

/** The old addresses the report is complaining about. */
function laaReported(array $report): array
{
    $names = array_column(laaFinding($report)['samples'], 'name');
    sort($names);

    return $names;
}

/** `detail` for one old address, or '' if that address is not reported. */
function laaDetail(array $report, string $path): string
{
    foreach (laaFinding($report)['samples'] as $sample) {
        if (($sample['name'] ?? null) === $path) {
            return (string) ($sample['detail'] ?? '');
        }
    }

    return '';
}

/** A redirect row of the shape the WordPress import writes. */
function laaRow(string $source, bool $enabled = true): Redirect
{
    return Redirect::create([
        'source' => $source,
        'target' => LegacyCategoryUrls::toCategoryPath($source),
        'code' => 301,
        'enabled' => $enabled,
    ]);
}

/* ------------------------------------------------------------ the finding */

it('reports every legacy category address that has no redirect row', function () {
    /*
     * The migrated database seeds ten redirect rows and all ten are journal
     * articles — not one is a category — so on a shop where the import has
     * not run, all fifteen are uncovered. That is the state of the tree this
     * test runs on and it is the state of the owner's server today.
     *
     * MUTATION NOTE, run: comment out the `self::scanLegacyAddresses($findings);`
     * call in SeoAudit::run() and this is red — 0 reported against 15.
     */
    $report = laaScan();

    $expected = LegacyCategoryUrls::PATHS;
    sort($expected);

    expect(laaReported($report))->toBe($expected);
    expect(laaFinding($report)['count'])->toBe(count(LegacyCategoryUrls::PATHS));
    expect(laaDetail($report, '/skincare-sets/'))->toBe('no redirect set');
});

it('stops reporting an address once an enabled redirect covers it', function () {
    /*
     * The row is written in the spelling the import writes and the matcher
     * reads: the trailing-slash form, which is what $request->getPathInfo()
     * hands CheckRedirects::row().
     *
     * MUTATION NOTE, run: drop the `->where('enabled', true)` half of the
     * state map in scanLegacyAddresses() — i.e. treat any row as coverage —
     * and the "switched off" test below goes red instead of this one.
     */
    laaRow('/skincare-sets/');

    $report = laaScan();

    expect(laaReported($report))->not->toContain('/skincare-sets/');
    expect(laaFinding($report)['count'])->toBe(count(LegacyCategoryUrls::PATHS) - 1);

    // And the row it points at is the one the URL contract says it should be.
    expect(LegacyCategoryUrls::toCategoryPath('/skincare-sets/'))
        ->toBe('/product-category/skincare-sets/');
});

it('still reports an address whose redirect row is switched off', function () {
    /*
     * CheckRedirects::row() filters on `enabled`, so a switched-off row sends
     * nobody anywhere — the visitor gets the same 404 as if the row did not
     * exist. An audit that counted the row as coverage would report the shop
     * as safe while it was not, which is worse than not having the check.
     *
     * The detail line separates it from "no redirect set" because the two are
     * a different amount of work: this one is a toggle at
     * Store → SEO & Meta → Redirects & 404s.
     *
     * MUTATION NOTE, run: change the `($state[$path] ?? false) === true` guard
     * to `array_key_exists($path, $state)` and this is red.
     */
    laaRow('/toners/', enabled: false);

    $report = laaScan();

    expect(laaReported($report))->toContain('/toners/');
    expect(laaDetail($report, '/toners/'))->toBe('redirect is switched off');
});

it('still reports an address covered only in the spelling with no trailing slash', function () {
    /*
     * findMatch() uses getPathInfo(), which KEEPS the trailing slash, and
     * row() matches `source` exactly. A row stored as "/toners" therefore
     * never fires for the indexed address "/toners/" — and the indexed
     * address is the one with the slash, because that is what WooCommerce
     * served. Half-covered looks covered on the Redirects screen, which lists
     * a row for toners either way.
     *
     * MUTATION NOTE, run: add `rtrim($path, '/')` to the coverage test — i.e.
     * accept either spelling — and this is red.
     */
    laaRow('/exfoliators');

    $report = laaScan();

    expect(laaReported($report))->toContain('/exfoliators/');
    expect(laaDetail($report, '/exfoliators/'))->toBe('only "/exfoliators" is set');
});

/* ------------------------------------------------- rule 1: nothing else moved */

it('leaves the scanned total and the verdict line exactly as they were', function () {
    /*
     * RULE 1, HALF ONE: the number.
     *
     * These fifteen are addresses this shop does NOT serve, so they are not
     * part of the indexable surface. Adding them to `scanned` would inflate
     * "N indexable URLs scanned" -- the one number on the screen an owner
     * reads first -- by fifteen, on every shop, the day this check ships.
     *
     * MUTATION NOTE, run: add `$scanned['Legacy'] = count(LegacyCategoryUrls::PATHS);`
     * inside scanLegacyAddresses() and this is red: total 60 against a
     * scanned sum of 45.
     */
    $report = laaScan();

    expect($report['scanned'])->not->toHaveKey('legacy_url_no_redirect');
    expect($report['scanned'])->not->toHaveKey('Legacy');
    expect($report['total'])->toBe(array_sum($report['scanned']));

    // The card still exists and still carries its count -- advisory is about
    // the headline, not about hiding the finding.
    expect(laaFinding($report)['count'])->toBe(count(LegacyCategoryUrls::PATHS));
});

it('never takes the verdict headline, even when it is the biggest number on the screen', function () {
    /*
     * RULE 1, HALF TWO: the sentence.
     *
     * verdict() names the largest finding and calls it "the biggest issue".
     * That is a fair heuristic for a finding describing something broken on a
     * page the shop serves. This one is not: no page is broken, and on a
     * freshly migrated server it is fifteen every time, because the redirect
     * rows arrive with the WordPress import and no migration seeds them. Left
     * out of the ADVISORY list it would take the headline away from
     * "Canonical points somewhere unsafe (1)" -- a page handing this shop's
     * ranking to another domain -- on the day the check ships.
     *
     * WHY THE SETUP LOOKS LIKE THIS. On the seeded demo catalogue the biggest
     * real finding is "No image" at 38, so fifteen never wins and the check
     * would pass whether or not it were advisory -- a test asserting nothing.
     * Marking the seeded rows noindex takes them out of the scan (the audit
     * skips a row the owner has told Google to skip), leaving only the routed
     * content pages plus one clean product: every non-advisory finding is then
     * under ten and the legacy fifteen is the largest number on the screen.
     * This is the only state in which the ADVISORY entry is observable, which
     * is why it is built rather than assumed.
     *
     * MUTATION NOTE, run: take 'legacy_url_no_redirect' out of
     * SeoAudit::ADVISORY and this is red -- the verdict reads
     * "Biggest issue: Old shop address with no redirect (15)."
     */
    $noindex = json_encode(['noindex' => true]);

    foreach (['products', 'categories', 'brands', 'posts'] as $table) {
        DB::table($table)->update(['seo' => $noindex]);
    }

    // One clean, indexable row, so total is not zero -- verdict() short-
    // circuits on an empty shop and never reaches the comparison under test.
    \App\Models\Product::create([
        'slug' => 'laa-clean-product',
        'name' => 'A properly named and photographed product',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 10000,
        'short_description' => 'A description with comfortably more than eight words in it, so it counts.',
        'image' => '/media/laa-clean.jpg',
        'sku' => 'LAA-1',
    ]);

    $report = laaScan();

    // The premise: it really is the biggest non-advisory number here.
    foreach ($report['findings'] as $key => $finding) {
        if ($key !== 'legacy_url_no_redirect' && $key !== 'product_no_image_alt') {
            expect($finding['count'])->toBeLessThan(
                count(\App\Support\LegacyCategoryUrls::PATHS),
                $key.' is not smaller than the legacy count, so this test asserts nothing'
            );
        }
    }

    expect(laaFinding($report)['count'])->toBe(count(LegacyCategoryUrls::PATHS));

    // And it still does not get to be the sentence at the top.
    expect($report['verdict'])->not->toContain('Old shop address');
    expect($report['total'])->toBeGreaterThan(0);
});

it('draws the new card last, so every card already on the screen keeps its place', function () {
    /*
     * The screen renders Object.keys(findings) in the order the server sends
     * them (resources/views/admin/app.blade.php, renderSeoAudit). A key
     * inserted anywhere but the end would push every existing card down the
     * page — a visible change to a screen that already works.
     *
     * MUTATION NOTE, run: move the 'legacy_url_no_redirect' entry above
     * 'product_no_category' in emptyFindings() and this is red.
     */
    $keys = array_keys(laaScan()['findings']);

    expect(array_pop($keys))->toBe('legacy_url_no_redirect');
    expect($keys)->toBe([
        'duplicate_title',
        'duplicate_description',
        'bad_canonical',
        'title_too_long',
        'title_too_short',
        'no_description',
        'thin_description',
        'no_image',
        'product_no_image_alt',
        'product_no_identifier',
        'product_no_category',
    ]);
});

/* --------------------------------------------------------------- rule 4: cost */

it('asks the redirects table exactly one question, whatever the list grows to', function () {
    /*
     * Fifteen paths, two spellings each: the obvious spelling of this check is
     * a query per path, on a screen that already does a full catalogue pass.
     *
     * MUTATION NOTE, run: replace the single whereIn() with a per-path
     * ->where('source', …)->first() loop and this is red at 15 queries.
     */
    laaMount();
    $user = laaOwner();

    /*
     * The Schema::hasTable() guard also names the table — through sqlite_master
     * here and information_schema on MySQL — and it is not the thing being
     * counted. Both probes are excluded by name so this counts the same single
     * read on either driver.
     */
    $queries = 0;
    DB::listen(function ($query) use (&$queries) {
        $sql = $query->sql;

        if (str_contains($sql, 'sqlite_master') || str_contains($sql, 'information_schema')) {
            return;
        }

        if (str_contains($sql, 'redirects')) {
            $queries++;
        }
    });

    test()->actingAs($user, 'admin')->getJson('/admin-api/seo-audit')->assertOk();

    expect($queries)->toBe(1);
});
