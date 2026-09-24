<?php

declare(strict_types=1);

use App\Http\Middleware\CanonicalHost;
use App\Http\Middleware\CheckRedirects;
use App\Http\Middleware\SetLocaleFromPath;
use App\Models\Category;
use App\Models\Product;
use App\Models\Redirect;
use App\Models\Setting;
use App\Services\SettingsService;
use App\Support\Locale;
use Illuminate\Support\Facades\DB;

/**
 * =============================================================================
 * THE REDIRECT TABLE COULD ONLY EVER FIRE ON A 404
 * =============================================================================
 *
 * `App\Http\Middleware\CheckRedirects` has been written as middleware since the
 * day it was created, and was registered as middleware nowhere.
 * `bootstrap/app.php` did not name it; the only live reader of the `redirects`
 * table was the `NotFoundHttpException` closure in `AppServiceProvider`. One
 * consequence, absolute, and measured by Lane GB against a running server
 * rather than reasoned about:
 *
 *     A REDIRECT ROW FOR AN ADDRESS THE SHOP ALREADY ANSWERS COULD NOT FIRE.
 *
 * Three rows pointing at `/PROOF-INERT/` were written for `/shop/`, for a
 * category archive and for a product address — all enabled, all matching
 * `getPathInfo()` byte for byte. `/shop/` still answered 200, the category
 * still 301'd to its own nested path, the product still answered 200. Not one
 * of the three fired. The old shop has five years of URLs, and every one of
 * them that collides with a slug this application serves silently kept serving
 * the wrong page instead of redirecting.
 *
 * The earlier attempt at registration, whose comment reads like proof that
 * registration cannot work, put the class in the `web` middleware GROUP. A
 * group runs AFTER the router has matched a route, which is too late to change
 * which route matches. Only the global pipeline runs before routing.
 *
 * ── WHAT EACH BLOCK IS HERE TO CATCH ────────────────────────────────────────
 *
 *  1. THE DEFECT ITSELF. A path the shop serves with a 200, asserted as a 200
 *     first so the test cannot pass by accident on an address that 404s, then
 *     redirected. This block is red on the old code — it was written against
 *     it and measured at 200.
 *
 *  2. THE REGISTRATION REACHING THE SERVER. `bootstrap/` is on
 *     BuildPackage::NEVER_SHIP, so a line there can never be applied. This is
 *     the `/ar` story one file over, and it is asserted the same way
 *     ArabicUrlsWorkWithoutTheHandEditTest asserts it.
 *
 *  3. NOTHING THAT ALREADY WORKS CHANGING. The precedence is asserted in BOTH
 *     directions: a row wins over the router, and no row means the router is
 *     untouched.
 *
 *  4. THE LOOP, which is the failure mode this change introduces. While the
 *     table was read only on a 404, a row pointing an address at itself turned
 *     one 404 into another. Registered as middleware it is a page the shop was
 *     serving a moment ago, now bouncing for ever.
 *
 *  5. THE QUERY THIS WOULD HAVE ADDED TO EVERY PAGE OF THE SHOP, asserted as a
 *     count and not as a claim.
 *
 *  6. THE TWO READERS AGREEING. CanonicalHost consults the same table on the
 *     same column to fold a path correction into its own hop.
 */
beforeEach(function () {
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();
    CheckRedirects::flushIndex();
});

/** A published product, so /product/{slug}/ is an address this shop answers. */
function rmProduct(string $slug = 'rm-barrier-serum'): Product
{
    return Product::create([
        'name' => 'RM Barrier Serum',
        'slug' => $slug,
        'sku' => 'RM-'.strtoupper(substr(md5($slug), 0, 6)),
        'status' => 'publish',
        'is_visible' => true,
        'price' => 12600,
        'stock_status' => 'instock',
        'image' => 'https://cdn.test/rm.jpg',
    ]);
}

/** A nested tree whose leaf the old site published flat, as gpTree() builds it. */
function rmTree(): array
{
    $parent = Category::query()->create(['name' => 'RM Skincare', 'slug' => 'rm-skincare', 'parent_id' => null]);
    $parent->forceFill(['path' => 'rm-skincare', 'depth' => 0, 'source_term_id' => 8801])->save();

    $child = Category::query()->create(['name' => 'RM Toners', 'slug' => 'rm-toners', 'parent_id' => $parent->id]);
    $child->forceFill(['path' => 'rm-skincare/rm-toners', 'depth' => 1, 'source_term_id' => 8802])->save();

    return [$parent, $child];
}

function rmRow(string $source, string $target, bool $enabled = true, int $code = 301): Redirect
{
    return Redirect::query()->create([
        'source' => $source,
        'target' => $target,
        'code' => $code,
        'enabled' => $enabled,
        'auto_created' => true,
    ]);
}

/**
 * One request through the real kernel, with the path spelled EXACTLY as given.
 *
 * ▲ NOT test()->get(), AND THAT IS NOT A STYLE CHOICE. MakesHttpRequests::
 * prepareUrlForRequest() ends in `trim(url($uri), '/')`, so the harness STRIPS
 * THE TRAILING SLASH off every URL it is handed: `test()->get('/toners/')` asks
 * this application for `/toners`. `redirects.source` is stored WITH the slash
 * (URL contract U-01, the WordPress-matching form this site really serves and
 * the form CheckRedirects compares against getPathInfo()), so every assertion
 * in this file written the convenient way reads 404 where the shop reads 301 —
 * a red test for a shop that is behaving correctly, and worse, a green one for
 * any assertion phrased the other way round. GpAddressesLandTest::gpFetch()
 * exists for the same reason; this is the same call.
 */
function rmFetch(string $path, string $method = 'GET'): \Symfony\Component\HttpFoundation\Response
{
    return app(\Illuminate\Contracts\Http\Kernel::class)->handle(
        \Illuminate\Http\Request::create('http://localhost'.$path, $method),
    );
}

/** The kernel's global middleware stack, as the Pipeline will read it. */
function rmGlobalStack(): array
{
    $kernel = app(\Illuminate\Contracts\Http\Kernel::class);
    $r = new ReflectionProperty($kernel, 'middleware');
    $r->setAccessible(true);

    return array_values($r->getValue($kernel));
}

function rmSetGlobalStack(array $stack): void
{
    $kernel = app(\Illuminate\Contracts\Http\Kernel::class);
    $r = new ReflectionProperty($kernel, 'middleware');
    $r->setAccessible(true);
    $r->setValue($kernel, array_values($stack));
}

/** Queries this closure runs against the `redirects` table, and all queries. */
function rmCount(callable $fn): array
{
    $all = 0;
    $redirects = 0;

    DB::listen(function ($query) use (&$all, &$redirects) {
        $all++;

        if (str_contains(strtolower($query->sql), 'redirects')) {
            $redirects++;
        }
    });

    $fn();

    return ['all' => $all, 'redirects' => $redirects];
}

/* ==========================================================================
 * 1. THE DEFECT: A ROW FOR AN ADDRESS THE SHOP ANSWERS
 * ========================================================================== */

it('redirects an address the shop serves with a 200, which it could never do', function () {
    /*
     * ════════════════════════════════════════════════════════════════════════
     * THE TEST THAT IS RED ON THE OLD CODE
     * ════════════════════════════════════════════════════════════════════════
     *
     * MUTATION NOTE: delete the
     * `$kernel->prependMiddleware(\App\Http\Middleware\CheckRedirects::class)`
     * line from AppServiceProvider::boot() and the
     * `$middleware->append(\App\Http\Middleware\CheckRedirects::class)` line
     * from bootstrap/app.php — which together are exactly the state this
     * repository was in — and the second assertion below reads 200. Measured
     * that way, not assumed: it is the shape Lane GB fetched off a running
     * server three times over.
     *
     * The first assertion is not scenery. Without it this test would pass on
     * the OLD code the moment the address happened to 404, which is the trap
     * the 404-only design sets for anybody testing it.
     */
    $product = rmProduct();

    expect(rmFetch('/product/'.$product->slug.'/')->getStatusCode())->toBe(200);

    rmRow('/product/'.$product->slug.'/', '/shop/');

    $response = rmFetch('/product/'.$product->slug.'/');

    expect($response->getStatusCode())->toBe(301);
    expect((string) $response->headers->get('Location'))->toEndWith('/shop/');
});

it('beats a 301 the application issues for itself', function () {
    /*
     * The exact case Lane GB measured and left on the board. A category
     * published flat at the site root is redirected to its nested path by
     * CategoryArchiveController → CategoryPath::resolve(), with no row
     * involved — so a row for that source used to be inert twice over: the
     * address does not 404, and something else already answers it.
     *
     * MUTATION NOTE: unregister the middleware and the Location below goes
     * back to ending in the nested category path.
     */
    rmTree();

    $unredirected = rmFetch('/product-category/rm-toners/');
    expect($unredirected->getStatusCode())->toBe(301);

    /*
     * ═══════════════════════════════════════════════════════════════════════
     * PIN ADVANCED, DELIBERATELY — THE DEFECT THIS RECORDED IS FIXED
     * ═══════════════════════════════════════════════════════════════════════
     *
     * This asserted the SLASH-LESS form, and said so in as many words, because
     * that is what the shop sent. The note read:
     *
     *     Asserted without the trailing slash BECAUSE THAT IS WHAT IT SENDS,
     *     and that is a finding rather than a preference:
     *     CategoryArchiveController builds this hop through Laravel's own
     *     redirect() helper, which strips the slash, so it lands on an address
     *     whose <link rel="canonical"> points at the slashed one. It is the
     *     same defect docs/GP-ADDRESSES-LAND.md measured for the redirects
     *     TABLE and fixed there with Url::redirect(). Pinned here as it is:
     *     this lane owns the middleware, not the category controller, and
     *     changing that hop is a separate, visible decision.
     *
     *     expect((string) $unredirected->headers->get('Location'))
     *         ->toEndWith('/product-category/rm-skincare/rm-toners');
     *
     * That decision has now been made and made visibly: `CategoryArchive-
     * Controller::show()` issues its 301 through `CategoryPath::redirectUrl()`
     * → `Url::redirect()`, so the slash survives and the hop lands on the
     * address the destination page itself declares canonical. The pin is
     * advanced rather than deleted because what it recorded was true, and only
     * the controller moved.
     *
     * The assertion below is still the same claim about the SAME hop — where
     * the shop sends this address with no row involved — so what this test
     * exists to prove, that a row beats the application's own 301, is
     * untouched. `tests/Feature/CategoryPathContractTest.php` owns the slash
     * itself.
     */
    expect((string) $unredirected->headers->get('Location'))->toEndWith('/product-category/rm-skincare/rm-toners/');

    rmRow('/product-category/rm-toners/', '/product-category/rm-skincare/');

    $response = rmFetch('/product-category/rm-toners/');

    expect($response->getStatusCode())->toBe(301);
    expect((string) $response->headers->get('Location'))->toEndWith('/product-category/rm-skincare/');
});

it('counts the hit on a row that fires, which used to stay at zero for ever', function () {
    /*
     * The tell an owner would have seen if anything had been looking: a row
     * whose `hits` never moves is a row that never fired. The Redirects screen
     * shows this column.
     */
    rmProduct('rm-hit-counter');
    rmRow('/product/rm-hit-counter/', '/shop/');

    expect(rmFetch('/product/rm-hit-counter/')->getStatusCode())->toBe(301);

    $row = Redirect::query()->where('source', '/product/rm-hit-counter/')->first();

    expect($row->hits)->toBe(1);
    expect($row->last_hit_at)->not->toBeNull();
});

/* ==========================================================================
 * 2. THE REGISTRATION HAS TO REACH THE SERVER
 * ========================================================================== */

it('registers the middleware from a file that ships, not only from bootstrap', function () {
    /*
     * bootstrap/app.php is on BuildPackage::NEVER_SHIP and UpdateGuard's
     * forbidden list, so a registration written only there can never reach the
     * live host. That is not hypothetical: it is why the owner reported "/ar
     * gives everywhere 404 not found" twice, and ArabicUrlsWorkWithoutTheHand-
     * EditTest is this same assertion for SetLocaleFromPath.
     *
     * So: take it out — which is the state of a server that never got the hand
     * edit — and boot the provider again. If the provider is what registers
     * it, it comes back.
     */
    expect(rmGlobalStack())->toContain(CheckRedirects::class);

    rmSetGlobalStack(array_filter(
        rmGlobalStack(),
        static fn (string $m): bool => $m !== CheckRedirects::class
    ));

    expect(in_array(CheckRedirects::class, rmGlobalStack(), true))->toBeFalse();

    (new \App\Providers\AppServiceProvider(app()))->boot();

    /*
     * in_array() and not toContain($class, $message): Pest's toContain is
     * VARIADIC, so a message passed as a second argument is read as a second
     * needle. ExpectationsThatCannotFailTest sweeps for the ->not-> form of
     * the same mistake.
     */
    expect(in_array(CheckRedirects::class, rmGlobalStack(), true))->toBeTrue(
        'AppServiceProvider no longer registers CheckRedirects, so the redirect table depends on a '
        .'hand-edit to bootstrap/app.php that no package can make and the live shop has never had'
    );
});

it('registers it exactly once when bootstrap/app.php carries it too', function () {
    // A shop whose bootstrap/app.php does name the class must not run the
    // middleware twice. prependMiddleware() array_searches before it unshifts;
    // this pins that we rely on it rather than assume it.
    (new \App\Providers\AppServiceProvider(app()))->boot();
    (new \App\Providers\AppServiceProvider(app()))->boot();

    $count = count(array_filter(rmGlobalStack(), static fn (string $m): bool => $m === CheckRedirects::class));

    expect($count)->toBe(1, 'CheckRedirects is registered '.$count.' times; the request would pass through it twice');
});

it('runs after the locale is stripped and after the host is settled', function () {
    /*
     * The order is load-bearing in both directions and neither is obvious.
     *
     * SetLocaleFromPath first, because `redirects.source` carries no locale
     * segment: an Arabic visitor following an old link has to match the same
     * row an English one does.
     *
     * CanonicalHost first, because there is no sense redirecting a path on a
     * host the request is about to be forwarded off — it folds the path
     * correction into its own hop instead.
     */
    $stack = rmGlobalStack();

    $at = static function (string $class) use ($stack): int {
        $index = array_search($class, $stack, true);

        expect($index)->not->toBeFalse($class.' is not in the global middleware stack at all');

        return (int) $index;
    };

    expect($at(CanonicalHost::class))->toBeLessThan($at(SetLocaleFromPath::class));
    expect($at(SetLocaleFromPath::class))->toBeLessThan($at(CheckRedirects::class));
});

it('sends an Arabic reader to the Arabic page, not the English one', function () {
    rmProduct('rm-arabic-target');

    $s = app(SettingsService::class);
    $s->set(Locale::SETTING_ENABLED, '1');
    $s->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();

    rmRow('/rm-old-arabic/', '/product/rm-arabic-target/');

    $response = rmFetch('/ar/rm-old-arabic/');

    expect($response->getStatusCode())->toBe(301);

    // The row is stored without a locale segment and matched after
    // SetLocaleFromPath has stripped one; Url::redirect() puts it back.
    expect((string) $response->headers->get('Location'))->toEndWith('/ar/product/rm-arabic-target/');
});

/* ==========================================================================
 * 3. NOTHING THAT ALREADY WORKS MAY CHANGE
 * ========================================================================== */

it('leaves every address no row claims exactly as it was', function () {
    /*
     * The other half of the precedence, and the one the owner cares about
     * most: a shop with redirect rows in it must not start redirecting pages
     * those rows say nothing about.
     */
    rmProduct('rm-untouched');
    rmTree();

    // A table with rows in it, none of them for the addresses below.
    rmRow('/some-old-address/', '/shop/');
    rmRow('/another-old-address/', '/product/rm-untouched/');

    expect(rmFetch('/')->getStatusCode())->toBe(200);
    expect(rmFetch('/shop/')->getStatusCode())->toBe(200);
    expect(rmFetch('/product/rm-untouched/')->getStatusCode())->toBe(200);
    expect(rmFetch('/product-category/rm-skincare/')->getStatusCode())->toBe(200);
});

it('ignores a row that is switched off', function () {
    rmProduct('rm-disabled-row');
    rmRow('/product/rm-disabled-row/', '/shop/', enabled: false);

    expect(rmFetch('/product/rm-disabled-row/')->getStatusCode())->toBe(200);
});

it('ignores anything that is not a GET', function () {
    // A 301 on a POST is turned into a GET by every browser and the body is
    // dropped, so forwarding a form submission would silently discard an order.
    rmRow('/rm-post-target/', '/shop/');

    expect(rmFetch('/rm-post-target/', 'POST')->getStatusCode())->not->toBe(301);
});

it('never looks at the back office, the API, the assets or the health check', function () {
    /*
     * The last one is the reason this list grew with the registration:
     * UpdateRunner fetches /_kbb-health over HTTP right after it writes files
     * and rolls the update back if it does not answer. A 301 there is an
     * update that always rolls itself back — and the row causing it would have
     * arrived in the same package.
     */
    foreach (['admin', 'admin-api', 'api', 'build', 'uploads', 'storage', '_kbb-health', 'import-chain'] as $prefix) {
        rmRow('/'.$prefix.'/rm-exempt/', '/shop/');
    }

    $request = \Illuminate\Http\Request::create('http://localhost/_kbb-health/rm-exempt/', 'GET');
    expect(CheckRedirects::findMatch($request))->toBeNull();

    $request = \Illuminate\Http\Request::create('http://localhost/admin/rm-exempt/', 'GET');
    expect(CheckRedirects::findMatch($request))->toBeNull();

    $request = \Illuminate\Http\Request::create('http://localhost/api/rm-exempt/', 'GET');
    expect(CheckRedirects::findMatch($request))->toBeNull();

    // …and the storefront spelling of the same shape still matches, so the
    // exemption is a prefix list and not an accident that swallows everything.
    rmRow('/rm-not-exempt/', '/shop/');
    $request = \Illuminate\Http\Request::create('http://localhost/rm-not-exempt/', 'GET');
    expect(CheckRedirects::findMatch($request))->not->toBeNull();
});

it('is live on the next request after a row is written, edited or removed', function () {
    /*
     * The index is a cache, and a cache on this table is only safe because the
     * eviction is a write-through rather than a TTL. The owner edits a row on
     * Store → Redirects and expects the next request to obey it.
     *
     * MUTATION NOTE: delete the two hooks in Redirect::booted() and the second
     * and fourth assertions below go red — the first request warms the index
     * and every later one answers out of a set that no longer matches the
     * table.
     */
    rmProduct('rm-cache-eviction');

    // Warm the index while there is nothing in it.
    expect(rmFetch('/product/rm-cache-eviction/')->getStatusCode())->toBe(200);

    $row = rmRow('/product/rm-cache-eviction/', '/shop/');

    expect(rmFetch('/product/rm-cache-eviction/')->getStatusCode())->toBe(301);

    $row->forceFill(['enabled' => false])->save();

    expect(rmFetch('/product/rm-cache-eviction/')->getStatusCode())->toBe(200);

    $row->forceFill(['enabled' => true])->save();

    expect(rmFetch('/product/rm-cache-eviction/')->getStatusCode())->toBe(301);

    $row->delete();

    expect(rmFetch('/product/rm-cache-eviction/')->getStatusCode())->toBe(200);
});

/* ==========================================================================
 * 4. THE LOOP, WHICH IS WHAT THIS CHANGE MAKES POSSIBLE
 * ========================================================================== */

it('refuses a row that points a page at itself', function () {
    /*
     * ════════════════════════════════════════════════════════════════════════
     * REFUSED AT READ TIME, BECAUSE THE ROWS ARE ALREADY IN THE DATABASE
     * ════════════════════════════════════════════════════════════════════════
     *
     * `RedirectMap` line 425 refuses to WRITE one — a top-level category whose
     * flat address and nested address are the same string — and says in as many
     * words that CheckRedirects would serve it as an infinite loop. It has only
     * refused since it was written; rows from before that are in the owner's
     * database now, and while the table was read on a 404 only they turned one
     * 404 into another rather than trapping a live page.
     *
     * MUTATION NOTE: make lookup() return the row without calling loops() and
     * this test fails with a 301 whose Location is the address requested — a
     * browser follows that until it gives up.
     */
    rmProduct('rm-self-loop');
    rmRow('/product/rm-self-loop/', '/product/rm-self-loop/');

    expect(rmFetch('/product/rm-self-loop/')->getStatusCode())->toBe(200);
});

it('refuses a pair of rows that point at each other', function () {
    /*
     * Neither row points at itself, so the row-by-row guard above cannot see
     * it; following the chain can. This shape is reachable by hand on
     * Store → Redirects, and RedirectManager's chain collapsing does not
     * prevent it.
     *
     * MUTATION NOTE: return false from loops() as soon as the first hop does
     * not match the source, and this bounces /rm-cycle-a/ ⇄ /rm-cycle-b/ for
     * ever.
     */
    rmRow('/rm-cycle-a/', '/rm-cycle-b/');
    rmRow('/rm-cycle-b/', '/rm-cycle-a/');

    expect(rmFetch('/rm-cycle-a/')->getStatusCode())->not->toBe(301);
    expect(rmFetch('/rm-cycle-b/')->getStatusCode())->not->toBe(301);
});

it('still follows an honest chain, so the guard is not a blanket refusal', function () {
    /*
     * The guard must refuse a cycle and nothing else. A → B where B is itself
     * a redirect source is a real shape — RedirectManager collapses those at
     * write time, but a hand-written pair is not collapsed — and the visitor
     * has to reach the far end.
     */
    rmProduct('rm-chain-end');

    rmRow('/rm-chain-a/', '/rm-chain-b/');
    rmRow('/rm-chain-b/', '/product/rm-chain-end/');

    $first = rmFetch('/rm-chain-a/');
    expect($first->getStatusCode())->toBe(301);
    expect((string) $first->headers->get('Location'))->toEndWith('/rm-chain-b/');

    $second = rmFetch('/rm-chain-b/');
    expect($second->getStatusCode())->toBe(301);
    expect((string) $second->headers->get('Location'))->toEndWith('/product/rm-chain-end/');
});

it('does not mistake another host for a loop', function () {
    // An absolute target cannot collide with a path this application is asked
    // for, so the walk has to stop rather than guess.
    rmRow('/rm-offsite/', 'https://example.test/rm-offsite/');

    $response = rmFetch('/rm-offsite/');

    expect($response->getStatusCode())->toBe(301);
    expect((string) $response->headers->get('Location'))->toBe('https://example.test/rm-offsite/');
});

/* ==========================================================================
 * 5. WHAT IT COSTS EVERY PAGE OF THE SHOP
 * ========================================================================== */

it('adds no query at all to a page no row claims', function () {
    /*
     * ════════════════════════════════════════════════════════════════════════
     * THE BUDGET, MEASURED RATHER THAN ASSERTED
     * ════════════════════════════════════════════════════════════════════════
     *
     * The obvious implementation asks the database on every request. That is
     * one query added to every page of the shop for an answer that is "no row"
     * on every address the shop actually serves, and StorefrontQueryBudgetTest
     * is a budget rather than a suggestion.
     *
     * So the set of enabled `source` values is cached whole and consulted in
     * memory. The index costs ONE query to build, once, and zero thereafter.
     *
     * MUTATION NOTE: replace claimed() with `return true` — the honest
     * unindexed implementation — and the warm measurement below reads 1
     * instead of 0, on every storefront page.
     */
    rmProduct('rm-budget');
    rmRow('/some-unrelated-old-address/', '/shop/');

    CheckRedirects::flushIndex();

    // Cold: the index is built, and that is the only thing it asks for.
    $cold = rmCount(fn () => expect(rmFetch('/product/rm-budget/')->getStatusCode())->toBe(200));
    expect($cold['redirects'])->toBe(1, 'building the source index cost '.$cold['redirects'].' queries, not 1');

    // Warm, which is every request on a running server: nothing at all.
    $warm = rmCount(fn () => expect(rmFetch('/product/rm-budget/')->getStatusCode())->toBe(200));
    expect($warm['redirects'])->toBe(0, 'a storefront page cost '.$warm['redirects'].' queries against `redirects`');

    $home = rmCount(fn () => expect(rmFetch('/')->getStatusCode())->toBe(200));
    expect($home['redirects'])->toBe(0);

    $shop = rmCount(fn () => expect(rmFetch('/shop/')->getStatusCode())->toBe(200));
    expect($shop['redirects'])->toBe(0);
});

it('pays for a lookup only on the request it actually redirects', function () {
    rmProduct('rm-cost-target');
    rmRow('/rm-cost-source/', '/product/rm-cost-target/');

    // Warm the index.
    expect(rmFetch('/shop/')->getStatusCode())->toBe(200);

    $counted = rmCount(fn () => expect(rmFetch('/rm-cost-source/')->getStatusCode())->toBe(301));

    // One SELECT for the row, one UPDATE for the hit counter, and nothing for
    // the chain walk: the target is not itself a redirect source, which
    // claimed() answers out of the index.
    expect($counted['redirects'])->toBe(2, 'a redirected request cost '.$counted['redirects'].' queries against `redirects`, not 2');
});

/* ==========================================================================
 * 6. THE TWO READERS OF THE TABLE CANNOT DRIFT
 * ========================================================================== */

it('gives CanonicalHost the same answer the middleware gives', function () {
    /*
     * CanonicalHost consults the same table on the same column, to fold the
     * path correction into the host correction so an old address on the
     * retired domain costs the visitor one hop instead of two. It used to hold
     * its own copy of the query, which was survivable only while CheckRedirects
     * was dead code. Now both run on every request, and a difference between
     * them is a redirect that fires on one host and not the other.
     *
     * Asserted against EACH OTHER rather than against a literal: that is what
     * makes a change applied to one of them the failure this catches.
     *
     * MUTATION NOTE: put CanonicalHost's own
     * `Redirect::query()->where('source', …)` back and the loop case below
     * goes red — it would map a self-pointing row that the middleware refuses.
     */
    rmProduct('rm-both-readers');

    rmRow('/rm-both/', '/product/rm-both-readers/');
    rmRow('/rm-both-loop/', '/rm-both-loop/');

    foreach (['/rm-both/', '/rm-both-loop/', '/rm-nothing-here/'] as $path) {
        $viaMiddleware = CheckRedirects::findMatch(
            \Illuminate\Http\Request::create('http://localhost'.$path, 'GET')
        );

        $viaLookup = CheckRedirects::lookup($path);

        expect($viaMiddleware?->id)->toBe($viaLookup?->id, $path.' is read differently by the two callers');
    }

    // And the one CanonicalHost must refuse: a self-pointing row would
    // otherwise send an alias host's visitor to the address they asked for.
    expect(CheckRedirects::lookup('/rm-both-loop/'))->toBeNull();
    expect(CheckRedirects::lookup('/rm-both/'))->not->toBeNull();
});

it('ships the clear_caches migration the registration needs', function () {
    /*
     * route:cache compiles the router's middleware stack, and config:cache
     * freezes the rest of the boot. A package that adds a global middleware to
     * a host carrying either one adds it to a file nothing reads: the code
     * lands, the class is never called, and nothing in any log says so.
     */
    $found = glob(database_path('migrations/*clear_caches_redirect_middleware.php'));

    expect($found)->not->toBeEmpty('the middleware registration ships without a clear_caches migration');

    $body = file_get_contents($found[0]);

    expect(str_contains($body, 'routes-*.php'))->toBeTrue('the migration does not clear the compiled route cache');
    expect(str_contains($body, 'CheckRedirects::flushIndex'))->toBeTrue('the migration does not drop the cached source index');
});
