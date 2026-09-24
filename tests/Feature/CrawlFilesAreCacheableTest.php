<?php

declare(strict_types=1);

use App\Http\Controllers\Store\SeoFilesController;
use App\Models\Product;
use App\Models\Setting;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Route;

/**
 * /sitemap.xml, /robots.txt and /llms.txt: publicly cacheable, and only once
 * they are actually public.
 *
 * ── THE DEFECT ──────────────────────────────────────────────────────────────
 *
 * Those three documents are the only ones this application serves that are the
 * same bytes for every visitor — no cart badge, no signed-in name, no CSRF
 * token — and they are the most-refetched URLs on the site. Every crawler asks
 * for robots.txt first, and the sitemap is built by walking the whole catalogue.
 * They left the application with no Cache-Control at all, so Symfony computed
 * `no-cache, private` for them exactly as it does for a product page, and every
 * crawl paid for the catalogue walk again.
 *
 * ── AND WHY IT COULD NOT BE FIXED BY SETTING A HEADER ───────────────────────
 *
 * They are declared in routes/web.php, so they run in the `web` group, which
 * starts a session and leaves a `Set-Cookie` behind — StartSession's session
 * cookie and, on a GET, ValidateCsrfToken's XSRF-TOKEN. `Cache-Control: public`
 * beside a `Set-Cookie` is the shared-cache hazard CacheHeaders' docblock is
 * about: one visitor's cookie handed to the next reader out of the cache. The
 * first case below MEASURES that Set-Cookie rather than asserting it exists, so
 * this file records the reason as a fact and not as a claim.
 *
 * So the fix is two halves, and this file is the proof of both:
 *
 *   1. routes/web.php drops the stateful middleware from the three routes
 *      (SeoFilesController::STATELESS — the one line this lane does not own);
 *   2. Store\SeoFilesController marks the response cacheable ONLY when no
 *      session is bound to the request.
 *
 * Half 2 makes half 1 safe to apply whenever. Until the route line lands,
 * nothing about these three files moves; the moment it lands, the header
 * appears — and it can never appear beside a Set-Cookie, because the only thing
 * that writes one is the middleware whose absence is the condition being tested.
 *
 * ── HOW THE ROUTE LINE IS EXERCISED ─────────────────────────────────────────
 *
 * Not by mocking, and not by calling the controller directly. Each case below
 * registers the SAME controller action twice — once under the plain `web` group
 * and once with exactly the `withoutMiddleware(SeoFilesController::STATELESS)`
 * the integrator is being asked to add — and fetches both. The second
 * registration is a byte-for-byte rehearsal of the production line, so if that
 * line is wrong, these go red here rather than on the shop.
 *
 * MUTATION NOTE: in Store\SeoFilesController::publiclyCacheable(), change
 * `if ($request->hasSession() || ...)` to `if (false || ...)` and the FIRST
 * case goes red — the ordinary web-group response starts advertising itself as
 * publicly cacheable while still handing out a session cookie, which is the
 * exact hazard. Delete the `$this->publiclyCacheable(...)` wrapper from any one
 * of the three actions and that document's case goes red with
 * `no-cache, private`, which is what all three published before this lane.
 */

/**
 * Register the two rehearsals of one action and fetch both.
 *
 * ▲ THE PROBE URIs HAVE TWO SEGMENTS AND NO FILE EXTENSION, and neither is
 * cosmetic. Two single-segment routes in routes/web.php swallow the obvious
 * names, because a route registered earlier wins:
 *
 *   - `/{key}.txt` (the IndexNow key file) matches any hyphenated name ending
 *     in .txt, so /kbbcache-free-robots.txt gets that route's 404;
 *   - `/{slug}` (an article at the site root) matches any lowercase hyphenated
 *     word, so /kbbcache-free-robots gets PageController's firstOrFail() 404.
 *
 * Both found by running it, and both produce a 404 that looks exactly like a
 * broken controller. A two-segment path can match neither.
 */
function cacheProbe(string $action, string $slug): array
{
    Route::middleware('web')
        ->get('/kbbcache/web-' . $slug, [SeoFilesController::class, $action]);

    // ── THE LINE FOR routes/web.php, VERBATIM ──
    Route::middleware('web')
        ->withoutMiddleware(SeoFilesController::STATELESS)
        ->get('/kbbcache/free-' . $slug, [SeoFilesController::class, $action]);

    return [
        'web' => test()->get('/kbbcache/web-' . $slug),
        'stateless' => test()->get('/kbbcache/free-' . $slug),
    ];
}

/**
 * Cache-Control as a SET OF DIRECTIVES, which is what it is.
 *
 * Symfony's ResponseHeaderBag re-spells and re-orders what it is handed —
 * `public, max-age=3600, s-maxage=3600` arrives as
 * `max-age=3600, public, s-maxage=3600`. CacheHeaderPolicyTest compares the
 * sorted set for exactly this reason, and a test that compared the literal
 * would be pinning Symfony's sort order rather than this policy.
 *
 * @return list<string>
 */
function cacheDirectives(mixed $value): array
{
    $parts = array_filter(array_map('trim', explode(',', (string) $value)));
    sort($parts);

    return array_values($parts);
}

function cacheSettings(array $pairs): void
{
    foreach ($pairs as $key => $value) {
        Setting::query()->updateOrCreate(['key' => $key], ['value' => $value, 'autoload' => true]);
    }

    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
}

/* ─────────────────────────── the hazard is real ──────────────────────────── */

it('leaves the crawl files exactly as they are while they still run a session', function () {
    Product::firstOrCreate(
        ['slug' => 'kbbcache-toner'],
        [
            'name' => 'Dokdo Toner',
            'status' => 'publish',
            'is_visible' => true,
            'price' => 8500,
            'stock_status' => 'instock',
            'type' => 'simple',
        ]
    );

    $r = cacheProbe('sitemap', 'sitemap')['web'];

    $r->assertOk();

    // THE HAZARD, MEASURED. This is why a `public` header could not simply be
    // set: the response that would have carried it also carries a cookie.
    expect($r->headers->getCookies())->not->toBeEmpty();

    // And therefore it does NOT carry one. `no-cache, private` is what Symfony
    // computes for a response with no Cache-Control of its own, which is the
    // header every one of these three files has published to date.
    expect((string) $r->headers->get('Cache-Control'))
        ->not->toContain('public')
        ->toContain('private');
});

/* ────────────────────── and the header once it is gone ───────────────────── */

it('serves a public, hour-long sitemap.xml once the session middleware is off', function () {
    Product::firstOrCreate(
        ['slug' => 'kbbcache-toner-2'],
        [
            'name' => 'Dokdo Toner',
            'status' => 'publish',
            'is_visible' => true,
            'price' => 8500,
            'stock_status' => 'instock',
            'type' => 'simple',
        ]
    );

    $r = cacheProbe('sitemap', 'sitemap')['stateless'];

    $r->assertOk();
    $r->assertHeader('Content-Type', 'application/xml; charset=UTF-8');

    expect(cacheDirectives($r->headers->get('Cache-Control')))
        ->toBe(cacheDirectives(SeoFilesController::PUBLIC_CACHE));

    // The other half of the pair, and the one that makes `public` honest: with
    // the session middleware gone there is nothing left to set a cookie.
    expect($r->headers->getCookies())->toBeEmpty();
});

it('serves a public, hour-long robots.txt once the session middleware is off', function () {
    $r = cacheProbe('robots', 'robots')['stateless'];

    $r->assertOk();
    expect($r->getContent())->toContain('User-agent: *');
    expect(cacheDirectives($r->headers->get('Cache-Control')))
        ->toBe(cacheDirectives(SeoFilesController::PUBLIC_CACHE));
    expect($r->headers->getCookies())->toBeEmpty();
});

it('serves a public, hour-long llms.txt once the session middleware is off', function () {
    cacheSettings(['llms_enabled' => '1']);

    $r = cacheProbe('llms', 'llms')['stateless'];

    $r->assertOk();
    expect($r->getContent())->toContain('## Key pages');
    expect(cacheDirectives($r->headers->get('Cache-Control')))
        ->toBe(cacheDirectives(SeoFilesController::PUBLIC_CACHE));
});

it('serves a public robots.txt for a custom body too', function () {
    // robots() has three exits, and the custom-override one is a different
    // `return` from the default builder. A fix applied to one and not the
    // others is the shape of defect this case exists for.
    cacheSettings(['robots_txt' => "User-agent: *\nDisallow: /nothing\n"]);

    $r = cacheProbe('robots', 'robots-custom')['stateless'];

    $r->assertOk();
    expect($r->getContent())->toContain('Disallow: /nothing');
    expect(cacheDirectives($r->headers->get('Cache-Control')))
        ->toBe(cacheDirectives(SeoFilesController::PUBLIC_CACHE));
});

/* ───────────────────────────── never the 404s ────────────────────────────── */

it('never caches a switched-off sitemap or llms.txt', function () {
    // A 404 pinned into a shared cache for an hour outlives the setting that
    // caused it: the owner switches the sitemap back on and Google keeps being
    // told there is not one.
    cacheSettings(['sitemap_enabled' => '0']);

    $sitemap = cacheProbe('sitemap', 'sitemap-off')['stateless'];
    $sitemap->assertNotFound();
    expect((string) $sitemap->headers->get('Cache-Control'))->not->toContain('public');

    // And the same for llms.txt, which ships ENABLED (SeoSettings::DEFAULTS
    // has llms_enabled => '1') and is switched off from SEO → Crawl files.
    cacheSettings(['llms_enabled' => '0']);

    $llms = cacheProbe('llms', 'llms-off')['stateless'];
    $llms->assertNotFound();
    expect((string) $llms->headers->get('Cache-Control'))->not->toContain('public');
});

/* ───────────────────── and the exclusion list itself ─────────────────────── */

it('names middleware the web group actually carries', function () {
    // A constant listing a class Laravel no longer puts in `web` would silently
    // stop excluding anything, and the symptom would be a cookie on a public
    // response — the one outcome this whole design exists to make impossible.
    $web = app('router')->getMiddlewareGroups()['web'] ?? [];

    foreach (SeoFilesController::STATELESS as $class) {
        expect($web)->toContain($class);
    }
});

it('keeps the storefront headers a crawl file still needs', function () {
    /*
     * NOT `withoutMiddleware(['web'])`, which was the obvious version of this
     * change and the wrong one. bootstrap/app.php appends SecurityHeaders and
     * NoIndexStaging to that group, and NoIndexStaging is what stamps
     * X-Robots-Tag: noindex on a staging copy — so dropping the group wholesale
     * would take the noindex off a staging sitemap, which is precisely the
     * accident SeoFilesController::robots() spends thirty lines explaining it
     * must not make.
     */
    expect(SeoFilesController::STATELESS)
        ->not->toContain(\App\Http\Middleware\SecurityHeaders::class)
        ->not->toContain(\App\Http\Middleware\NoIndexStaging::class)
        ->not->toContain(\App\Http\Middleware\CacheHeaders::class);

    $r = cacheProbe('robots', 'robots-headers')['stateless'];

    // Measured through the real stack rather than read off the constant.
    expect($r->headers->has('X-Content-Type-Options'))->toBeTrue();
});
