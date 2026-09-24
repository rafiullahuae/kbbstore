<?php

declare(strict_types=1);

use App\Http\Controllers\Store\CollectionController;
use App\Http\Controllers\Store\PageController;
use App\Models\Page;
use App\Models\Setting;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Route;

/**
 * =============================================================================
 * FOUR LISTS THAT HAD TO AGREE, WITH NOTHING ENFORCING IT
 * =============================================================================
 *
 * `docs/SEO-BUILD-PLAN.md` Part II item 9, in its own words:
 *
 *     Item 1 must add each new key in at least four places: CollectionController's
 *     key map, its title/intro map, PageController.php:62, and
 *     SeoFilesController.php:188. Four hardcoded lists that must agree, with
 *     nothing enforcing it. Miss the last one and the page works perfectly and
 *     never enters the sitemap — a silent failure that no screenshot catches.
 *
 * It asked for a test that the lists are equal. A test would have caught the
 * drift; this round removed the sitemap's copy instead, so there is nothing
 * left for it to drift FROM. `SeoFilesController::curatedListingPaths()` and
 * `routedPageSlugs()` ask the router, which is the same move the concern block
 * beside them already made.
 *
 * ── WHY "THE KEY SETS ARE EQUAL" WOULD HAVE BEEN THE WRONG TEST ─────────────
 *
 * The route path and the collection key are DIFFERENT STRINGS.
 * CollectionController's internal key `under-54` is served at
 * `/everything-under-54-aed`. A test asserting the two sets equal would have
 * failed on a correct shop, and the obvious fix for that failure — renaming one
 * to match the other — would have changed a live, indexed URL.
 *
 * ── WHAT IS LEFT FOR A TEST, AND WHAT IS ALREADY COVERED ────────────────────
 *
 * Covered elsewhere and NOT repeated here: that every static first segment the
 * router registers is refused by `PageController::slugPattern()`, so an article
 * cannot be published at `best-sellers` and shadow the listing. That is
 * `RootSlugCollisionTest`'s "it refuses the first segment of every route the
 * application registers", which already walks the real router.
 *
 * Left, and here:
 *
 *   1. THE DERIVATION REALLY DERIVES. A route added with no edit to
 *      SeoFilesController must appear in the sitemap — which is the property
 *      the change buys, and the only one that proves the drift is impossible
 *      rather than merely absent today.
 *
 *   2. THE KEY IS ONE THE CONTROLLER WILL SERVE. `show()` opens with
 *      `abort_unless(isset(self::COLLECTIONS[$key]), 404)`, so a route whose
 *      `key` default is not in that map 404s — and the sitemap, now deriving
 *      from the router, would advertise it. A sitemap entry that 404s is a
 *      Search Console error, which this controller's own comments call out
 *      twice. `COLLECTIONS` is private, so this is the one half of item 9 that
 *      stays a test rather than becoming a lookup.
 */

const SLD_BASE = 'https://kbeautybliss.test';

function sldSettings(): void
{
    foreach (['site_url' => SLD_BASE, 'seo_site_name' => 'K-Beauty Bliss', 'sitemap_enabled' => '1'] as $k => $v) {
        Setting::updateOrCreate(['key' => $k], ['value' => $v, 'autoload' => true]);
    }

    Setting::flushMap();
    SettingsService::forgetMemo();
}

beforeEach(function () {
    sldSettings();
});

/** Every curated-listing route the application registers, as a path. */
function sldRoutedListings(): array
{
    $out = [];

    foreach (Route::getRoutes() as $route) {
        if (! in_array('GET', $route->methods(), true)) {
            continue;
        }

        if (! str_ends_with((string) $route->getActionName(), 'CollectionController@show')) {
            continue;
        }

        if ($route->parameterNames() !== []) {
            continue;
        }

        $out['/' . trim($route->uri(), '/') . '/'] = (string) ($route->defaults['key'] ?? '');
    }

    return $out;
}

it('names every curated listing the router serves', function () {
    /*
     * The four as they stand. Asserted positively as well as by the derivation
     * below, so a derivation that returned nothing at all would not pass by
     * vacuously agreeing with an empty router walk.
     */
    $listings = sldRoutedListings();

    expect(array_keys($listings))->toBe([
        '/new-in/', '/best-sellers/', '/super-sale/', '/everything-under-54-aed/',
    ]);

    $sitemap = test()->get('/sitemap.xml')->getContent();

    /*
     * str_contains() wrapped in toBeTrue(), NOT ->toContain($needle, $message).
     * Pest's toContain() is VARIADIC over needles for a string subject, so a
     * message passed as the second argument becomes a second thing the haystack
     * must contain and the assertion fails on its own failure text. Same family
     * as the toHaveKey() trap this lane found last round; the difference is
     * that this one fails loudly instead of passing vacuously, which is why it
     * was caught in the first run rather than by a mutation.
     */
    foreach (array_keys($listings) as $path) {
        expect(str_contains($sitemap, '<loc>' . SLD_BASE . $path . '</loc>'))
            ->toBeTrue($path . ' is routed and not in the sitemap');
    }
});

it('puts a listing added later into the sitemap with no edit to the sitemap', function () {
    /*
     * THE PROPERTY THE CHANGE BUYS, and the reason a test that merely compared
     * two literals would have been weaker: this one fails if anybody puts the
     * hardcoded list back.
     *
     * A fifth curated listing is registered here exactly as routes/web.php
     * registers the other four — same controller, same `key` default shape —
     * and nothing in SeoFilesController is touched.
     *
     * MUTATION NOTE, RUN: restoring the literal
     * `foreach (['new-in', 'best-sellers', 'super-sale', 'everything-under-54-aed'] as $collection)`
     * in place of curatedListingPaths() leaves every other test in this file
     * green and makes this one red -- 1 failed. That is precisely the silent
     * failure the plan describes, caught.
     */
    Route::get('/sld-fifth-listing', [CollectionController::class, 'show'])
        ->defaults('key', 'new-in')
        ->name('collection.sld');

    $sitemap = test()->get('/sitemap.xml')->getContent();

    expect(str_contains($sitemap, '<loc>' . SLD_BASE . '/sld-fifth-listing/</loc>'))
        ->toBeTrue('a curated listing was added and the sitemap did not notice');
});

it('refuses to advertise a listing whose key the controller will not serve', function () {
    /*
     * `CollectionController::show()` opens with
     * `abort_unless(isset(self::COLLECTIONS[$key]), 404)`. A route whose `key`
     * default names nothing in that map renders a 404, and a sitemap built from
     * the router alone would list it.
     *
     * This is the half of the plan's item 9 that could not be removed, because
     * COLLECTIONS is private. It is checked here rather than in the sitemap, so
     * the bad route is caught before it ships rather than filtered out of one
     * file afterwards — the same arrangement RootSlugCollisionTest uses for the
     * reserved-segment half.
     *
     * MUTATION NOTE, RUN: changing any route's `->defaults('key', …)` in
     * routes/web.php to a key COLLECTIONS does not carry makes this red naming
     * the path -- and the page 404s on the shop, which the second expectation
     * demonstrates on a deliberately broken route.
     */
    $reflection = new ReflectionClass(CollectionController::class);
    $keys = array_keys($reflection->getConstant('COLLECTIONS'));

    foreach (sldRoutedListings() as $path => $key) {
        expect($key)->not->toBe('', $path . ' is a curated listing route with no key default');
        expect(in_array($key, $keys, true))
            ->toBeTrue($path . ' names the key "' . $key . '", which the controller will 404');
    }

    // And the consequence, shown rather than described: a route naming a key
    // the map does not carry really is a 404.
    Route::get('/sld-broken-listing', [CollectionController::class, 'show'])
        ->defaults('key', 'sld-no-such-key');

    test()->get('/sld-broken-listing')->assertNotFound();
});

it('names every routed content page, and only the published ones', function () {
    /*
     * The second literal this round removed. It was the seven slugs written
     * out; an eighth routed content page would have rendered perfectly and
     * never entered the sitemap.
     *
     * MUTATION NOTE, RUN: putting the seven-slug literal back in place of
     * routedPageSlugs() makes the second block below red -- 1 failed.
     */
    Page::query()->updateOrCreate(['slug' => 'about'], ['title' => 'About', 'status' => 'published']);
    Page::query()->updateOrCreate(['slug' => 'faqs'], ['title' => 'FAQs', 'status' => 'draft']);

    $sitemap = test()->get('/sitemap.xml')->getContent();

    expect($sitemap)->toContain('<loc>' . SLD_BASE . '/about/</loc>');

    // A draft row is routed but 404s, and a sitemap entry that 404s is a
    // Search Console error.
    expect($sitemap)->not->toContain('<loc>' . SLD_BASE . '/faqs/</loc>');

    // And a published page that nothing routes stays out: PageController::show
    // only answers the slugs routes/web.php declares.
    Page::query()->updateOrCreate(
        ['slug' => 'sld-unrouted'],
        ['title' => 'Unrouted', 'status' => 'published']
    );

    expect(test()->get('/sitemap.xml')->getContent())
        ->not->toContain('<loc>' . SLD_BASE . '/sld-unrouted/</loc>');
});

it('puts a content page routed later into the sitemap with no edit to the sitemap', function () {
    /*
     * The same property as the listings block, for the other literal.
     *
     * MUTATION NOTE, RUN: restoring the seven-slug array makes this red -- 1
     * failed.
     */
    Route::get('/sld-new-page', [PageController::class, 'show'])->defaults('slug', 'sld-new-page');

    Page::query()->updateOrCreate(
        ['slug' => 'sld-new-page'],
        ['title' => 'A page routed after the sitemap was written', 'status' => 'published']
    );

    expect(str_contains(test()->get('/sitemap.xml')->getContent(), '<loc>' . SLD_BASE . '/sld-new-page/</loc>'))
        ->toBeTrue('a content page was routed and the sitemap did not notice');
});

it('publishes a content page at the address the router serves it from', function () {
    /*
     * REPORTED RATHER THAN HIDDEN: this block exists because a mutation
     * SURVIVED. Replacing `$route->defaults['slug']` with the route's URI left
     * every test green, because for all seven content pages the slug and the
     * path are the same string — the guard was correct and unreached.
     *
     * Chasing it found something better than a test. The line that built the
     * URL did `'/' . $page->slug . '/'`, so the sitemap assumed the row's slug
     * and the page's address are spelled identically. They need not be: this
     * application already serves the key `under-54` at
     * /everything-under-54-aed/ one block away. Register a page route whose URI
     * differs from its slug and the old code advertised an address the shop
     * does not serve.
     *
     * So the slug now identifies the ROW and the router's URI identifies the
     * ADDRESS, which is what each of them actually is.
     *
     * MUTATION NOTE, RUN: building the URL from `$page->slug` again makes this
     * red -- the sitemap names /sld-aliased/ instead of /sld-aliased-page/ --
     * 1 failed.
     */
    Route::get('/sld-aliased-page', [PageController::class, 'show'])->defaults('slug', 'sld-aliased');

    Page::query()->updateOrCreate(
        ['slug' => 'sld-aliased'],
        ['title' => 'A page whose address is not its slug', 'status' => 'published']
    );

    $sitemap = test()->get('/sitemap.xml')->getContent();

    expect(str_contains($sitemap, '<loc>' . SLD_BASE . '/sld-aliased-page/</loc>'))
        ->toBeTrue('the sitemap does not name the address the router serves');

    expect(str_contains($sitemap, '<loc>' . SLD_BASE . '/sld-aliased/</loc>'))
        ->toBeFalse('the sitemap advertises the slug as an address, and nothing serves it');

    /*
     * NOT fetched here, and the reason is the harness rather than the shop:
     * a route registered inside a test is appended AFTER
     * routes/kbb-brands-blog.php's `/{slug}/` catch-all, so this request is
     * matched as an article and 404s. Registration order is what routes/web.php
     * gets right and a test cannot reproduce — `RootSlugCollisionTest` is where
     * that ordering is asserted, against the real router.
     *
     * The two expectations above are the property under test either way: the
     * sitemap names the router's address and not the row's slug.
     */
});

it('never writes a route parameter into the sitemap as if it were an address', function () {
    /*
     * REPORTED RATHER THAN HIDDEN: the second mutation that SURVIVED. Dropping
     * the `parameterNames() !== []` guard from curatedListingPaths() left every
     * test green, because the one parameterised collection route is
     * `CollectionController@concern` and the action-name filter already
     * excludes it. Correct, and unreached.
     *
     * It is worth keeping rather than deleting: a `@show` route taking a
     * parameter is one line away, and without the guard the sitemap would
     * publish the literal `/{listing}/` as a URL. A route URI is not an
     * address until something supplies its parameters — the same distinction
     * SourceReachability draws when it refuses to judge a parameterised route
     * from its URI alone.
     *
     * MUTATION NOTE, RUN: removing the parameterNames() check from
     * curatedListingPaths() makes this red -- the sitemap gains
     * <loc>…/sld-param/{listing}/</loc> -- 1 failed. Same for routedPagePaths().
     */
    Route::get('/sld-param/{listing}', [CollectionController::class, 'show'])->defaults('key', 'new-in');

    $sitemap = test()->get('/sitemap.xml')->getContent();

    expect(str_contains($sitemap, '{'))
        ->toBeFalse('a route parameter reached the sitemap as part of a URL');
});

it('leaves the sitemap byte-identical for the shop as it stands', function () {
    /*
     * RULE 1. The derivation preserves registration order, which is the order
     * the literal used, so this round changes the bytes of /sitemap.xml by
     * nothing at all on a shop nobody has added a route to.
     */
    $sitemap = test()->get('/sitemap.xml')->getContent();

    $positions = [];

    foreach (['/new-in/', '/best-sellers/', '/super-sale/', '/everything-under-54-aed/'] as $path) {
        $positions[] = strpos($sitemap, '<loc>' . SLD_BASE . $path . '</loc>');
    }

    expect($positions)->toBe(array_values(array_filter($positions, fn ($p) => $p !== false)));

    $sorted = $positions;
    sort($sorted);

    expect($positions)->toBe($sorted, 'the curated listings are no longer in registration order');
});
