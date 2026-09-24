<?php

declare(strict_types=1);

use App\Http\Controllers\Store\CollectionController;
use App\Http\Controllers\Store\PageController;
use App\Models\Setting;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Route;

/**
 * Lane S2 — the curated listings are defined in five places that nothing makes
 * agree, and one of the disagreements is silent.
 *
 * ── WHY THIS FILE EXISTS ────────────────────────────────────────────────────
 *
 * A collection is a key. Adding one — and docs/SEO-BUILD-PLAN.md Part II item 1
 * proposes adding `acne`, `hyperpigmentation` and `sensitivity-redness` — means
 * writing that key into all of:
 *
 *   1. routes/web.php            a Route::get(...)->defaults('key', …)
 *   2. CollectionController::COLLECTIONS   the key => [title, intro, mode] row
 *   3. CollectionController::wordingFor()  a match arm
 *   4. SeoFilesController::sitemap()       the literal list of route paths
 *   5. PageController::RESERVED_SLUGS      the first segment
 *
 * Of those, 2 and 5 already have a guard, and they are the two that fail LOUDLY
 * anyway:
 *
 *   - CollectionPhpLabelsAreKeyedTest pins array_keys(COLLECTIONS) against its
 *     own hand-written list, so a new row there is already a red suite.
 *   - RootSlugCollisionTest walks the registered routes and checks every static
 *     first segment against PageController::slugPattern(), which is built from
 *     RESERVED_SLUGS. A collection route whose segment is not reserved is
 *     already a red suite.
 *
 * Nothing at all guards 1, 3 and 4, and the three failures are of very
 * different kinds:
 *
 *   - MISSING ROUTE (1): the key exists and no address serves it. A page
 *     nobody can reach.
 *   - MISSING MATCH ARM (3): wordingFor() is a closed match with no default,
 *     so a key that reaches it with no arm throws \UnhandledMatchError — a 500
 *     on a page that 404'd correctly the day before. show() has already checked
 *     COLLECTIONS by then, so the constant being right is exactly what lets the
 *     request get far enough to crash.
 *   - MISSING SITEMAP ENTRY (4): THE ONE THIS FILE IS REALLY FOR. The page
 *     answers 200, renders perfectly, carries its canonical and its
 *     CollectionPage+ItemList, appears in the menu — and is never submitted for
 *     crawling. There is no error, no log line and nothing to screenshot. The
 *     whole point of a concern collection is that a search engine reads it, so
 *     this failure silently deletes the reason the page was built.
 *
 * ── HOW IT ASSERTS ──────────────────────────────────────────────────────────
 *
 * THE ROUTER IS THE SOURCE OF TRUTH, not a list in this file. Every assertion
 * below starts from the routes the application actually registered and asks
 * whether the other lists agree with them. A hand-written list here would be a
 * sixth thing to keep in step, which is the problem rather than the fix — the
 * same reasoning RootSlugCollisionTest's "guard that keeps the guard honest"
 * records.
 *
 * ── MUTATION NOTES ──────────────────────────────────────────────────────────
 *
 * All four were RUN, not reasoned about. Each turns exactly ONE test in this
 * file red and leaves the other five green:
 *
 *   1. Delete 'everything-under-54-aed' from the collections foreach in
 *      SeoFilesController::sitemap() (~:188)
 *      → 'it submits every curated listing to the crawler' alone goes red.
 *      That is the silent failure, caught. Nothing else in the suite notices:
 *      the page still answers 200 and still renders its heading.
 *
 *   2. Delete the 'under-54' arm from CollectionController::wordingFor()
 *      → 'it can put a heading on every listing it routes' alone goes red.
 *
 *   3. Add a row "'acne' => ['Acne', 'Mutation test row.', 'newest']" to
 *      CollectionController::COLLECTIONS without adding a route
 *      → 'it routes every collection key it defines' alone goes red.
 *
 *   4. Delete 'new-in' from PageController::RESERVED_SLUGS
 *      → 'it reserves the first segment of every listing it routes' alone goes
 *      red.
 *
 * The fifth failure mode — a route whose ->defaults('key', …) names a key the
 * constant does not carry — is what 'it serves a collection key it defines'
 * guards. It was NOT mutated here, because the mutation is an edit to
 * routes/web.php and CLAUDE.md puts that file out of this lane's reach. The
 * assertion is the mirror of test 3's and reads the same two sources.
 */

/** Every route the application registers for CollectionController@show. */
function ckdRoutedCollections(): array
{
    $found = [];

    foreach (Route::getRoutes() as $route) {
        if (! str_contains($route->getActionName(), CollectionController::class)) {
            continue;
        }

        $key = $route->defaults['key'] ?? null;

        if ($key === null) {
            continue;
        }

        // Laravel normalises the URI without slashes; the sitemap and the
        // reserved list both work in path segments.
        $found[$key] = trim($route->uri(), '/');
    }

    return $found;
}

/** The key => [title, intro, mode] constant, which is private. */
function ckdCollectionsConst(): array
{
    return (new ReflectionClass(CollectionController::class))->getConstant('COLLECTIONS');
}

it('finds the curated listings on the router at all', function () {
    // Guards every other test in this file against passing vacuously. If the
    // action name ever changes shape, the loops above quietly iterate nothing
    // and four green tests assert nothing whatsoever.
    expect(ckdRoutedCollections())->not->toBeEmpty()
        ->and(count(ckdRoutedCollections()))->toBeGreaterThanOrEqual(4);
});

it('routes every collection key it defines', function () {
    $defined = array_keys(ckdCollectionsConst());
    $routed = array_keys(ckdRoutedCollections());

    sort($defined);
    sort($routed);

    // A key in the constant with no route is a listing with no address: it is
    // fully built, fully translated, and unreachable.
    expect($routed)->toBe($defined, sprintf(
        "CollectionController::COLLECTIONS and the registered routes have stopped agreeing.\n"
        . "  defined in the constant: %s\n"
        . "  routed in web.php:       %s\n"
        . 'Every key needs a Route::get(...)->defaults(\'key\', …) in routes/web.php.',
        implode(', ', $defined),
        implode(', ', $routed)
    ));
});

it('serves a collection key it defines', function () {
    // The other direction, and the worse one: a route whose default key is not
    // in the constant reaches show(), which abort_unless()es it to 404. A live
    // address in the header menu answering 404.
    $defined = ckdCollectionsConst();

    $orphans = [];

    foreach (ckdRoutedCollections() as $key => $path) {
        if (! array_key_exists($key, $defined)) {
            $orphans[] = sprintf("  /%s/  passes key '%s'", $path, $key);
        }
    }

    expect($orphans)->toBe([], sprintf(
        "These routes pass a key CollectionController::COLLECTIONS does not carry, so "
        . "show()'s abort_unless() 404s a live address:\n\n%s\n\n"
        . 'Either add the row to the constant or remove the route.',
        implode("\n", $orphans)
    ));
});

it('can put a heading on every listing it routes', function () {
    /*
     * wordingFor() is a CLOSED match with no default arm, deliberately — its
     * own comment says a key composed from a URL segment is how a listing ends
     * up rendering its own slug at a shopper. The cost of that correct choice
     * is that a missing arm is an \UnhandledMatchError, which is a 500.
     *
     * Called through reflection rather than fetched, so this stays a statement
     * about the method and not about whichever routes happen to be wired.
     */
    $wordingFor = (new ReflectionMethod(CollectionController::class, 'wordingFor'));
    $wordingFor->setAccessible(true);

    $controller = app(CollectionController::class);

    foreach (array_keys(ckdRoutedCollections()) as $key) {
        [$title, $intro] = $wordingFor->invoke($controller, $key);

        expect($title)->toBeString()->not->toBe('', sprintf(
            "The listing '%s' has no heading. Add its arm to CollectionController::wordingFor().",
            $key
        ));

        // A missing translation line returns the key itself, which renders
        // "store.collection.title_acne" as an <h1>.
        expect($title)->not->toStartWith('store.collection.')
            ->and($intro)->not->toStartWith('store.collection.');
    }
});

it('submits every curated listing to the crawler', function () {
    /*
     * THE SILENT ONE.
     *
     * SeoFilesController::sitemap() carries its own literal list of collection
     * PATHS -- not keys; 'under-54' is served at /everything-under-54-aed --
     * and nothing has ever compared it with the router. A concern collection
     * missing from it is a page built to be crawled that is never offered for
     * crawling, and every other signal about it is green.
     *
     * Asserted against the fetched document rather than against the array
     * literal, because the literal is not reachable: it is an inline
     * foreach inside the method, not a constant, so there is nothing to
     * reflect on. The fetched sitemap is the better assertion anyway -- it is
     * what Google is handed.
     */
    Setting::updateOrCreate(
        ['key' => 'site_url'],
        ['value' => 'https://kbeautybliss.test', 'autoload' => true]
    );
    Setting::updateOrCreate(
        ['key' => 'sitemap_enabled'],
        ['value' => '1', 'autoload' => true]
    );
    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();

    $xml = $this->get('/sitemap.xml')->assertOk()->getContent();

    $unsubmitted = [];

    foreach (ckdRoutedCollections() as $key => $path) {
        if (! str_contains($xml, '<loc>https://kbeautybliss.test/' . $path . '/</loc>')) {
            $unsubmitted[] = sprintf("  '%s'  served at /%s/", $key, $path);
        }
    }

    expect($unsubmitted)->toBe([], sprintf(
        "These curated listings are served and are not in /sitemap.xml:\n\n%s\n\n"
        . "Add the PATH -- not the key -- to the collections foreach in "
        . "SeoFilesController::sitemap().\n"
        . 'The page works perfectly without this, which is why nothing else catches it.',
        implode("\n", $unsubmitted)
    ));
});

it('reserves the first segment of every listing it routes', function () {
    /*
     * Already covered from the other end by RootSlugCollisionTest, which walks
     * the whole router against slugPattern(). Repeated here, scoped to the
     * collections, because the failure it prevents is specific and the message
     * it prints is the one a lane adding a concern collection needs to read:
     * an unreserved segment does not 404, it serves the JOURNAL's root-level
     * catch-all, so /acne/ becomes a lookup for an article called "acne".
     */
    $unreserved = [];

    foreach (ckdRoutedCollections() as $key => $path) {
        $segment = explode('/', $path)[0];

        if (! in_array($segment, PageController::RESERVED_SLUGS, true)) {
            $unreserved[] = sprintf("  '%s'  served at /%s/, first segment '%s'", $key, $path, $segment);
        }
    }

    expect($unreserved)->toBe([], sprintf(
        "These listings' first segments are not in PageController::RESERVED_SLUGS:\n\n%s\n\n"
        . 'An unreserved segment is not a 404 -- the root-level article route serves it, '
        . 'so /acne/ becomes a lookup for an article called "acne".',
        implode("\n", $unreserved)
    ));
});
