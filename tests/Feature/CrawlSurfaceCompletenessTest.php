<?php

declare(strict_types=1);

use App\Models\Brand;
use App\Models\Category;
use App\Models\Page;
use App\Models\Post;
use App\Models\Product;
use App\Models\Review;
use App\Models\Setting;
use App\Services\SettingsService;
use App\Support\Indexability;
use Illuminate\Support\Facades\Route;

/**
 * =============================================================================
 * THE OTHER DIRECTION: EVERY PAGE THAT INVITES THE CRAWL IS SUBMITTED
 * =============================================================================
 *
 * `SeoCrawlSurfaceTest` walks the sitemap and asserts every `<loc>` in it
 * answers 200 — sitemap → shop. **Nothing walked the shop → sitemap**, and that
 * is the direction the failure this repo actually shipped runs in.
 *
 * `docs/SEO-MODULE-ROUND-4.md` §3 found `/_design-check`: a developer page that
 * answered 200 to anybody, carried the brand in its `<title>`, self-canonicalised
 * and explicitly said `index, follow` — mentioned in neither robots.txt nor the
 * sitemap, eighteen phases after its own comment said to delete it. It has since
 * been deleted (routes/web.php:893). What has not existed until now is anything
 * that would notice the next one.
 *
 * `docs/SEO-FEATURE-MATRIX.md` makes crawl hygiene something this shop WINS on
 * against the competitor — "we publish no second machine-readable address per
 * listing", with the note *"so nobody 'fixes' a problem we do not have"*. We had
 * one, for eighteen phases, and the document said we did not. This file is what
 * makes that claim checkable rather than believed.
 *
 * ── HOW IT DECIDES, AND WHY IT IS NOT NOISY ────────────────────────────────
 *
 * It asks the ROUTER for every parameterless storefront GET route, fetches each
 * one, and sorts the 200s by what they say about themselves:
 *
 *   A — it declares `index`. It must be in /sitemap.xml, or be named below with
 *       the reason it deliberately is not. There is exactly one of those today
 *       and the exception discharges itself (see the /reviews/ case).
 *   B — it declares nothing at all, so a crawler's default applies and the
 *       default is "index". The set is asserted whole, so a new member shows up
 *       as a failure rather than as a page nobody looked at.
 *
 * Parameterised routes are out of scope here and covered elsewhere: the product,
 * category, brand, article and concern shapes each have their own sitemap
 * assertions in `SeoCrawlSurfaceTest`, `SeoBilingualTest` and
 * `ConcernCollectionsTest`.
 */
const CSC_BASE = 'https://kbeautybliss.test';

function cscSettings(): void
{
    foreach (['site_url' => CSC_BASE, 'seo_site_name' => 'K-Beauty Bliss', 'sitemap_enabled' => '1'] as $k => $v) {
        Setting::updateOrCreate(['key' => $k], ['value' => (string) $v, 'autoload' => true]);
    }

    Setting::flushMap();
    SettingsService::forgetMemo();
}

/**
 * Enough of a shop that the listings are not empty and the seven content pages
 * exist — otherwise a page missing from the sitemap for want of data would read
 * as a page missing from the sitemap for want of a line of code.
 */
function cscSeed(): void
{
    $brand = Brand::firstOrCreate(['slug' => 'csc-brand'], ['name' => 'CSC Brand']);
    $category = Category::firstOrCreate(['slug' => 'csc-serums'], ['name' => 'CSC Serums', 'path' => 'csc-serums']);

    foreach (['csc-one', 'csc-two', 'csc-three'] as $i => $slug) {
        $product = Product::firstOrCreate(['slug' => $slug], [
            'name' => 'CSC Product ' . $i,
            'brand_id' => $brand->id,
            'status' => 'publish',
            'is_visible' => true,
            'price' => 9900 + $i,
            'stock_status' => 'instock',
            'type' => 'simple',
            'short_description' => 'A product with comfortably more than eight words in its own description.',
        ]);
        $product->categories()->syncWithoutDetaching([$category->id]);
    }

    Post::firstOrCreate(['slug' => 'csc-article'], [
        'title' => 'A CSC article', 'status' => 'published', 'body' => 'Body copy.', 'published_at' => now(),
    ]);

    foreach ([
        'about' => 'About Us', 'contact-us' => 'Contact Us', 'delivery' => 'Shipping & Delivery',
        'faqs' => 'Frequently Asked Questions', 'privacy-policy' => 'Privacy Policy',
        'refund_returns' => 'Returns & Refunds', 'terms-and-conditions' => 'Terms & Conditions',
    ] as $slug => $title) {
        Page::updateOrCreate(['slug' => $slug], [
            'title' => $title, 'status' => 'published', 'content' => '<p>Copy.</p>', 'seo' => null,
        ]);
    }
}

/** The parameterless storefront GET routes, as the router holds them. */
function cscStorefrontPaths(): array
{
    $out = [];

    foreach (Route::getRoutes() as $route) {
        if (! in_array('GET', $route->methods(), true)) {
            continue;
        }

        $uri = '/' . ltrim($route->uri(), '/');

        // Parameterised shapes are covered by their own sitemap assertions.
        if (str_contains($uri, '{')) {
            continue;
        }

        // The admin panel and the two API surfaces are not crawl surface at all:
        // robots.txt disallows the three prefixes and every one of them either
        // redirects to a login or answers JSON.
        if (preg_match('#^/(admin-api|api|_ignition|storage|build|sanctum|livewire)(/|$)#', $uri) === 1) {
            continue;
        }

        if (str_contains((string) $route->getActionName(), '\\Admin\\')) {
            continue;
        }

        // The admin path itself is configurable, so it is recognised by its
        // controller rather than by its spelling.
        if (str_contains((string) $route->getActionName(), 'AdminPage')
            || str_contains((string) $route->getActionName(), 'AdminAuthController')) {
            continue;
        }

        $out[$uri] = true;
    }

    return array_keys($out);
}

/** The set of paths /sitemap.xml submits, trailing slashes normalised away. */
function cscSitemapPaths(): array
{
    $xml = test()->get('/sitemap.xml')->assertOk()->getContent();

    preg_match_all('#<loc>(.*?)</loc>#', $xml, $m);

    expect(count($m[1]))->toBeGreaterThan(12, 'the sitemap is too short to be the real one; the sweep would pass vacuously');

    $out = [];

    foreach ($m[1] as $loc) {
        $path = parse_url(html_entity_decode($loc), PHP_URL_PATH) ?: '/';
        $out[rtrim($path, '/') ?: '/'] = true;
    }

    return $out;
}

beforeEach(function () {
    cscSettings();
    cscSeed();
});

it('submits every static page that tells a crawler to index it', function () {
    /*
     * ── WHAT GOES RED HERE ──────────────────────────────────────────────────
     *
     * A new route serving an HTML page that says "index, follow" and is not in
     * the sitemap. That is exactly the shape of `/_design-check`, and of the
     * "page works perfectly and never enters the sitemap" failure
     * docs/SEO-BUILD-PLAN.md Part II item 9 calls "a silent failure that no
     * screenshot catches".
     *
     * ── THE ONE EXCEPTION, AND WHY IT IS NOT A HOLE ─────────────────────────
     *
     * /reviews/ is deliberately absent while the shop has no approved review.
     * The reasoning is written out in SeoFilesController::sitemap() and it is
     * right: 404 would break a real link from the home page's review wall, and
     * noindex would keep the page out of the index even after the shop earns
     * reviews because nothing would prompt a re-crawl. Absent from the sitemap is
     * the lightest of the three.
     *
     * The exception cannot become permanent, because the case below asserts it
     * DISCHARGES — one approved review and /reviews/ is submitted. An exception
     * that only ever excuses is an exception nobody re-reads.
     *
     * MUTATION NOTE, RUN: registering
     * `Route::get('/_design-check', fn () => view('store.page', …))` in the test
     * and re-running makes this red naming /_design-check -- 1 failed.
     */
    $deliberatelyUnsubmitted = [
        '/reviews' => 'thin until the shop has an approved review; see the next case, which asserts it is submitted once it has one',
    ];

    $sitemap = cscSitemapPaths();
    $missing = [];
    $checked = 0;

    foreach (cscStorefrontPaths() as $uri) {
        $response = test()->call('GET', $uri);

        if ($response->getStatusCode() !== 200) {
            continue;
        }

        $html = $response->getContent();

        if (! is_string($html) || preg_match('#<meta name="robots" content="([^"]*)"#', $html, $m) !== 1) {
            continue;
        }

        if (str_contains($m[1], 'noindex')) {
            continue;
        }

        $checked++;
        $key = rtrim($uri, '/') ?: '/';

        if (isset($sitemap[$key]) || array_key_exists($key, $deliberatelyUnsubmitted)) {
            continue;
        }

        $missing[] = $uri . ' says "' . $m[1] . '" and /sitemap.xml does not carry it';
    }

    // The sweep must have swept. A filter that matched nothing would make the
    // assertion below pass on a shop with no pages at all.
    expect($checked)->toBeGreaterThan(10, 'too few indexable static pages were reached; the walk is broken, not the shop');

    expect($missing)->toBe([], "A page invites the crawl and the sitemap does not submit it:\n  " . implode("\n  ", $missing));

});

it('submits /reviews/ the moment the shop has a review to show', function () {
    /*
     * The other half of the exception above. Without this, "reviews is allowed
     * to be absent" would be a line nobody ever revisits, and the day the
     * conditional broke the sitemap would quietly stop advertising a page full
     * of real customer copy.
     *
     * MUTATION NOTE, RUN: deleting the `if ($query->exists())` block in
     * SeoFilesController::sitemap() so /reviews/ is never added makes this red
     * while the sweep above stays green -- 1 failed.
     */
    expect(cscSitemapPaths())->not->toHaveKey('/reviews');

    Review::create([
        'product_id' => Product::query()->where('slug', 'csc-one')->value('id'),
        'author_name' => 'A shopper',
        'rating' => 5,
        'content' => 'A real review with enough words in it to be a real review.',
        'status' => 'approved',
    ]);

    expect(cscSitemapPaths())->toHaveKey('/reviews');
});

it('accounts for every HTML page that declares nothing about indexing', function () {
    /*
     * A page with no robots meta is not a page that is safe from indexing — the
     * default a crawler applies is "index". So the set of them is asserted
     * WHOLE, and a new member is a failure rather than something nobody looked
     * at.
     *
     * There is one today: /up, Laravel's own health endpoint. It renders its own
     * document rather than the storefront layout, carries no brand, no
     * <title> with the shop's name and no canonical, and nothing on the shop
     * links to it — so it is a curiosity rather than the /_design-check shape,
     * which carried the brand and asked to be indexed. Named here rather than
     * changed: it is framework furniture, and adding it to
     * Indexability::PRIVATE_PREFIXES would put "Disallow: /up" into robots.txt
     * on a shop that has no problem, which is the thing the matrix warns against.
     *
     * The private prefixes are excluded from this walk on purpose. /cart/address
     * and /wishlist/ids answer JSON for a logged-in visitor and are covered by
     * Indexability::PRIVATE_PREFIXES, which robots.txt and the page-level
     * noindex are both built from.
     *
     * MUTATION NOTE, RUN: adding any route that renders HTML without the
     * storefront layout makes this red naming it -- verified by registering
     * `Route::get('/_csc-probe', fn () => '<html><head></head><body>x</body></html>')`
     * -- 1 failed.
     */
    $expected = ['/up'];
    $found = [];

    foreach (cscStorefrontPaths() as $uri) {
        if (Indexability::isPrivate($uri)) {
            continue;
        }

        $response = test()->call('GET', $uri);

        if ($response->getStatusCode() !== 200) {
            continue;
        }

        if (! str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
            continue;
        }

        $html = (string) $response->getContent();

        if (preg_match('#<meta name="robots"#', $html) === 1) {
            continue;
        }

        $found[] = $uri;
    }

    sort($found);
    sort($expected);

    expect($found)->toBe($expected, 'an HTML page says nothing about whether it may be indexed: ' . implode(', ', $found));
});

it('keeps robots.txt and the page-level noindex saying the same thing', function () {
    /*
     * `SeoBilingualTest` already pins that Indexability::PRIVATE_PREFIXES and
     * SeoFilesController::ROBOTS_PRIVATE are equal as SETS. This asserts the
     * consequence on the wire, which is the thing that actually matters: every
     * prefix robots.txt disallows serves a page that says noindex about itself,
     * because a URL Google may not crawl is a URL whose noindex it can never
     * read.
     *
     * MUTATION NOTE, RUN: removing '/track-my-order' from
     * Indexability::PRIVATE_PREFIXES makes this red (the page starts saying
     * "index, follow" while robots.txt still disallows it) AND makes
     * SeoBilingualTest's set equality red -- 3 failed across the two files.
     */
    $robots = test()->get('/robots.txt')->assertOk()->getContent();

    foreach (Indexability::PRIVATE_PREFIXES as $prefix) {
        expect($robots)->toContain('Disallow: ' . $prefix);

        $response = test()->call('GET', $prefix);

        // A 302 to a login is the strongest possible answer: there is no
        // document to index at all. Only a 200 has to say noindex itself.
        if ($response->getStatusCode() !== 200) {
            continue;
        }

        if (! str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
            continue;
        }

        /*
         * str_contains() and toBeTrue(), NOT toContain($needle, $message).
         * Pest's toContain() is VARIADIC OVER NEEDLES for a string subject, so
         * the message becomes a second thing the document has to contain and the
         * assertion fails for the wrong reason -- exactly the matcher trap
         * docs/SEO-MODULE-ROUND-4.md §4 records. It failed loudly here rather
         * than passing vacuously, which is the good direction, and it is written
         * this way so the next reader does not reintroduce it.
         */
        expect(str_contains((string) $response->getContent(), '<meta name="robots" content="noindex, nofollow">'))
            ->toBeTrue($prefix . ' is disallowed in robots.txt and does not say noindex about itself');
    }
});
