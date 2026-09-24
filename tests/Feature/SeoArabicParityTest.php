<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\Post;
use App\Models\Product;
use App\Models\Setting;
use App\Services\SettingsService;
use App\Support\ConcernCollections;
use App\Support\LegacyCategoryUrls;
use App\Support\Locale;

/**
 * =============================================================================
 * ARABIC PARITY ACROSS THE SEO SURFACE — THE GAPS, NOT THE WHOLE SURFACE
 * =============================================================================
 *
 * `SeoBilingualTest` already pins twenty properties of this surface: canonicals
 * per language, reciprocal hreflang on every page type, x-default, the sitemap
 * carrying one entry per language with the whole cluster, robots.txt naming
 * both languages of every private path, and the cluster retracting on an
 * editorially noindexed document but NOT on a per-visitor private one.
 * `BilingualFoundationTest` pins that `/ar/sitemap.xml` 404s.
 *
 * Every one of those was re-measured against a running server for this round
 * and every one holds, so this file does not re-assert them. It covers the
 * three things nothing yet does:
 *
 *  1. `inLanguage`, which was ABSENT FROM THE WHOLE JSON-LD GRAPH in both
 *     languages. Measured on /ar/product/… before this round: zero occurrences
 *     of the string anywhere in the document.
 *
 *  2. The sitelinks SEARCHBOX TARGET, which was the English shop on every page
 *     in both languages. An Arabic reader who found the Arabic page and typed
 *     into the box Google renders under it was sent to the English catalogue.
 *
 *  3. THE SEAMS WITH WHAT SHIPPED TODAY — the concern pages (which publish and
 *     unpublish themselves), the crawl files (which are now publicly cacheable),
 *     and this lane's own round-1 redirects, none of which had an Arabic pin.
 */

const SAP_BASE = 'https://kbeautybliss.test';

function sapSettings(array $values = []): void
{
    foreach (array_merge([
        'site_url' => SAP_BASE,
        'seo_site_name' => 'K-Beauty Bliss',
        'sitemap_enabled' => '1',
        'llms_enabled' => '1',
    ], $values) as $key => $value) {
        Setting::updateOrCreate(['key' => $key], ['value' => (string) $value, 'autoload' => true]);
    }

    Setting::flushMap();
    SettingsService::forgetMemo();
}

function sapArabicOn(): void
{
    sapSettings([Locale::SETTING_ENABLED => '1']);
}

beforeEach(function () {
    sapSettings();
});

/** Every JSON-LD node in a rendered document, decoded. */
function sapNodes(string $path): array
{
    $html = test()->get($path)->getContent();
    $out = [];

    if (preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m) === false) {
        return $out;
    }

    foreach ($m[1] as $json) {
        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            continue;
        }

        foreach (array_is_list($decoded) ? $decoded : [$decoded] as $node) {
            if (is_array($node) && isset($node['@type'])) {
                $out[] = $node;
            }
        }
    }

    return $out;
}

function sapNode(string $path, string $type): ?array
{
    foreach (sapNodes($path) as $node) {
        if ($node['@type'] === $type) {
            return $node;
        }
    }

    return null;
}

function sapProduct(): Product
{
    return Product::create([
        'slug' => 'sap-toner', 'name' => 'SAP Toner', 'sku' => 'SAP-1', 'status' => 'publish',
        'is_visible' => true, 'price' => 9900, 'stock_status' => 'instock',
        'image' => 'https://cdn.test/sap.jpg',
        'short_description' => 'A toner with comfortably more than eight words in its description.',
    ]);
}

// ---------------------------------------------------------------------------
// 1. inLanguage
// ---------------------------------------------------------------------------

it('states the language of a document on the node that describes the document', function () {
    /*
     * RED WITHOUT THE FIX: `inLanguage` did not appear anywhere in the graph,
     * in either language. Measured on a running server — zero occurrences of
     * the string in the whole document.
     *
     * A CollectionPage is a WebPage is a CreativeWork, so `inLanguage` is a
     * property it really has, and its `url` is this page's own localised
     * address — so the two agree by construction.
     *
     * MUTATION NOTE, RUN: deleting the 'inLanguage' key from the CollectionPage
     * node makes both lines red -- 2 failed.
     */
    sapArabicOn();

    Category::firstOrCreate(['slug' => 'sap-cat'], ['name' => 'SAP Cat', 'path' => 'sap-cat']);

    expect(sapNode('/product-category/sap-cat/', 'CollectionPage')['inLanguage'] ?? null)->toBe('en');
    expect(sapNode('/ar/product-category/sap-cat/', 'CollectionPage')['inLanguage'] ?? null)->toBe('ar');
});

it('states the language of an article on the Article node', function () {
    sapArabicOn();

    Post::create([
        'title' => 'Layering', 'slug' => 'sap-layering', 'status' => 'published',
        'body' => 'Body copy long enough to render.', 'published_at' => now(),
    ]);

    expect(sapNode('/sap-layering/', 'Article')['inLanguage'] ?? null)->toBe('en');
    expect(sapNode('/ar/sap-layering/', 'Article')['inLanguage'] ?? null)->toBe('ar');
});

it('says which languages the SITE is published in, not which one the page is', function () {
    /*
     * ── THE DISTINCTION THIS BLOCK EXISTS FOR ───────────────────────────────
     *
     * `WebSite` describes the SITE. It carries the same `url` on every page, so
     * a node claiming "ar" on an Arabic page and "en" on an English one would
     * be two contradictory statements about ONE thing, both published, with no
     * way for a consumer to tell which is current. The document's own language
     * belongs on the document's node — which is what the two blocks above
     * assert — and in <html lang>.
     *
     * MUTATION NOTE, RUN: changing the WebSite node's inLanguage to
     * Locale::current() makes the Arabic assertion here red while the two
     * blocks above stay green, which is exactly the shape of the mistake --
     * 1 failed.
     */
    sapArabicOn();

    foreach (['/shop/', '/ar/shop/'] as $path) {
        expect(sapNode($path, 'WebSite')['inLanguage'] ?? null)
            ->toBe(['en', 'ar'], $path . ' disagrees about what languages this site is published in');
    }
});

it('gives the single-language shop a bare string and not an array', function () {
    /*
     * RULE 1. Arabic is off until the owner switches it on, so this is the shop
     * as it ships: applying this package adds one accurate field and changes
     * nothing else about it.
     *
     * MUTATION NOTE, RUN: dropping the count()===1 branch so the value is
     * always an array makes this red -- 1 failed.
     */
    expect(Locale::enabledCodes())->toBe(['en']);
    expect(sapNode('/shop/', 'WebSite')['inLanguage'] ?? null)->toBe('en');
});

it('does not put inLanguage on a node that cannot have one', function () {
    /*
     * `inLanguage` is a property of CreativeWork. `Product`, `Organization`,
     * `Offer` and `BreadcrumbList` are not CreativeWorks, and a property
     * asserted where schema.org does not define it is not a richer document —
     * it is an invalid one, and the reason to refuse it is the same reason this
     * lane refuses to invent a redirect destination.
     *
     * A Product's language is the language of the PAGE, which the page states
     * in <html lang> and which the CollectionPage/Article node states in the
     * graph. Nothing is lost by leaving it off.
     *
     * MUTATION NOTE, RUN: adding 'inLanguage' to the Product node makes this
     * red, naming the type -- 1 failed.
     */
    sapArabicOn();
    sapProduct();

    foreach (['/product/sap-toner/', '/ar/product/sap-toner/'] as $path) {
        foreach (sapNodes($path) as $node) {
            if (in_array($node['@type'], ['Product', 'Organization', 'Offer', 'BreadcrumbList', 'Brand'], true)) {
                /*
                 * array_key_exists() AND NOT ->not->toHaveKey(), AND THE
                 * DIFFERENCE IS WHY THIS BLOCK EXISTS AT ALL.
                 *
                 * This was written as
                 *
                 *     expect($node)->not->toHaveKey('inLanguage', $message);
                 *
                 * and it SURVIVED its own mutation: adding `inLanguage` to the
                 * Product node left it green. Pest's second argument to
                 * toHaveKey() is an expected VALUE, not a failure message — so
                 * that line asserted "does not have inLanguage set to this long
                 * sentence", which is true whatever the node carries. A test
                 * that cannot fail is worse than no test, because it is counted.
                 *
                 * Every other assertion in this file takes its message as the
                 * SECOND argument of expect()->toBe(...), where it really is a
                 * message; toHaveKey is the one matcher in it that does not
                 * follow that shape.
                 */
                expect(array_key_exists('inLanguage', $node))->toBeFalse(
                    $node['@type'] . ' on ' . $path . ' carries inLanguage, which schema.org does not define for it'
                );
            }
        }
    }
});

// ---------------------------------------------------------------------------
// 2. The sitelinks searchbox
// ---------------------------------------------------------------------------

it('sends an Arabic reader who uses the sitelinks searchbox to the Arabic shop', function () {
    /*
     * THE DEFECT, AS IT LOOKED ON THE SHOP. Google renders the sitelinks
     * searchbox under the result for the page it crawled. The target was
     * `$base . '/shop/?s='` — built from the site root and nothing else — so on
     * every Arabic page it named the ENGLISH catalogue, and a reader who found
     * the Arabic page and typed into that box left Arabic without touching a
     * language switch. Measured before this round: the urlTemplate on /ar/shop/
     * was byte-for-byte the one on /shop/.
     *
     * A search is something a PERSON does from THIS page, which is why it is
     * localised while the node's own `url` is not.
     *
     * MUTATION NOTE, RUN: putting the target back to $base . '/shop/?s=…'
     * makes the Arabic line red and leaves the English one green -- 1 failed.
     */
    sapArabicOn();

    $en = sapNode('/shop/', 'WebSite')['potentialAction']['target']['urlTemplate'] ?? '';
    $ar = sapNode('/ar/shop/', 'WebSite')['potentialAction']['target']['urlTemplate'] ?? '';

    expect($en)->toBe(SAP_BASE . '/shop/?s={search_term_string}');
    expect($ar)->toBe(SAP_BASE . '/ar/shop/?s={search_term_string}');
});

it('leaves the search target exactly as it was while Arabic is off', function () {
    // Rule 1 again: Locale::withSegment() adds nothing in English, so the shop
    // as it ships emits the string it emitted before this round.
    expect(sapNode('/shop/', 'WebSite')['potentialAction']['target']['urlTemplate'] ?? '')
        ->toBe(SAP_BASE . '/shop/?s={search_term_string}');
});

// ---------------------------------------------------------------------------
// 3. The seams with what shipped today
// ---------------------------------------------------------------------------

it('gives a concern page the same Arabic treatment as everything else', function () {
    /*
     * Lane Q wired the quiz into these and `SeoBilingualTest` does not mention
     * them — it predates the route. A concern page publishes itself once the
     * owner has tagged MIN_PRODUCTS live products, so it is the one page shape
     * on the shop that can appear and disappear without a release, and that is
     * exactly the shape whose crawl surface goes wrong quietly.
     */
    sapArabicOn();

    $slug = ConcernCollections::ENABLED[0];

    for ($i = 0; $i < ConcernCollections::MIN_PRODUCTS; $i++) {
        Product::create([
            'slug' => 'sap-concern-' . $i, 'name' => 'Concern Product ' . $i, 'sku' => 'SAPC-' . $i,
            'status' => 'publish', 'is_visible' => true, 'price' => 9900, 'stock_status' => 'instock',
            'image' => 'https://cdn.test/c.jpg', 'routine_concerns' => json_encode([$slug]),
        ]);
    }

    $en = test()->get('/concern/' . $slug . '/');
    $ar = test()->get('/ar/concern/' . $slug . '/');

    $en->assertOk();
    $ar->assertOk();

    expect($en->getContent())->toContain('<link rel="canonical" href="' . SAP_BASE . '/concern/' . $slug . '/">');
    expect($ar->getContent())->toContain('<link rel="canonical" href="' . SAP_BASE . '/ar/concern/' . $slug . '/">');

    // The whole cluster, both ways round.
    foreach ([$en, $ar] as $response) {
        expect($response->getContent())
            ->toContain('hreflang="en" href="' . SAP_BASE . '/concern/' . $slug . '/"')
            ->toContain('hreflang="ar" href="' . SAP_BASE . '/ar/concern/' . $slug . '/"')
            ->toContain('hreflang="x-default" href="' . SAP_BASE . '/concern/' . $slug . '/"');
    }
});

it('keeps the sitemap and the router agreeing about a concern in both languages', function () {
    /*
     * THE FAILURE MODE: a sitemap that advertises an address the shop will not
     * serve. A concern page goes live and dark on a product count, so the two
     * can part company with no code change at all — which is why the sitemap
     * asks the same class the route asks rather than keeping its own list.
     *
     * Asserted in BOTH directions and in BOTH languages, because a rule that
     * only ever adds is a rule that never gets tested on the way down.
     */
    sapArabicOn();

    $slug = ConcernCollections::ENABLED[0];

    // Below the threshold: 404, and absent from the sitemap in both languages.
    test()->get('/concern/' . $slug . '/')->assertNotFound();
    test()->get('/ar/concern/' . $slug . '/')->assertNotFound();
    expect(test()->get('/sitemap.xml')->getContent())->not->toContain('/concern/' . $slug . '/');

    for ($i = 0; $i < ConcernCollections::MIN_PRODUCTS; $i++) {
        Product::create([
            'slug' => 'sap-live-' . $i, 'name' => 'Live ' . $i, 'sku' => 'SAPL-' . $i,
            'status' => 'publish', 'is_visible' => true, 'price' => 9900, 'stock_status' => 'instock',
            'image' => 'https://cdn.test/c.jpg', 'routine_concerns' => json_encode([$slug]),
        ]);
    }

    $sitemap = test()->get('/sitemap.xml')->getContent();

    expect($sitemap)
        ->toContain('<loc>' . SAP_BASE . '/concern/' . $slug . '/</loc>')
        ->toContain('<loc>' . SAP_BASE . '/ar/concern/' . $slug . '/</loc>');
});

it('lands this lane\'s own legacy addresses on an Arabic page that says it is Arabic', function () {
    /*
     * THE ROUND 1 / ROUND 3 SEAM, and neither round pinned it on its own.
     * Round 1 proved /ar/toners/ 301s to the Arabic archive. What it did not
     * check is what that archive then SAYS about itself — a redirect that
     * preserves the language and lands on a page canonicalising to the English
     * address would have moved the defect one hop along rather than fixing it.
     */
    sapArabicOn();

    $parent = Category::firstOrCreate(['slug' => 'skincare'], ['name' => 'Skincare', 'path' => 'skincare']);
    Category::updateOrCreate(
        ['slug' => 'toners'],
        ['name' => 'Toners', 'parent_id' => $parent->id, 'path' => 'skincare/toners']
    );

    expect(LegacyCategoryUrls::landingPath('/toners/'))->toBe('/product-category/skincare/toners/');

    $kernel = app(\Illuminate\Contracts\Http\Kernel::class);
    $hop = $kernel->handle(\Illuminate\Http\Request::create(SAP_BASE . '/ar/toners/', 'GET'));

    expect($hop->getStatusCode())->toBe(301);

    $landing = (string) $hop->headers->get('Location');

    expect($landing)->toContain('/ar/product-category/skincare/toners/');

    $page = $kernel->handle(\Illuminate\Http\Request::create($landing, 'GET'));
    $html = $page->getContent();

    expect($page->getStatusCode())->toBe(200);
    expect($html)->toContain('<html lang="ar"');
    expect($html)->toContain('<link rel="canonical" href="' . SAP_BASE . '/ar/product-category/skincare/toners/">');
});

it('serves no crawl file under a locale prefix, so a cached one cannot be the wrong language', function () {
    /*
     * ── WHY THIS IS WORTH ITS OWN BLOCK NOW ─────────────────────────────────
     *
     * 2.60.259 made /sitemap.xml, /robots.txt and /llms.txt leave with
     * `Cache-Control: public, max-age=3600, s-maxage=3600`. A shared cache may
     * now hold them for an hour. If any of the three could be served under a
     * locale prefix, a crawler fetching the Arabic spelling would prime a cache
     * with a document built in the wrong language — and unlike a page, these
     * three carry no <html lang> for anybody to notice it by.
     *
     * They cannot, and the reason is structural rather than a rule somebody
     * remembered: Locale::localisable() refuses any first segment containing a
     * dot, so SetLocaleFromPath never strips /ar off these paths and the router
     * has no route for the prefixed form. There is no request that reaches the
     * controller with a non-default locale bound.
     *
     * BilingualFoundationTest already pins the 404 for /ar/sitemap.xml. What is
     * asserted here is the whole set plus the property that makes it safe —
     * that the cacheable response is byte-identical whatever locale is live —
     * because the 404 alone would still pass if the document varied.
     */
    sapArabicOn();

    foreach (['/ar/sitemap.xml', '/ar/robots.txt', '/ar/llms.txt'] as $path) {
        test()->get($path)->assertNotFound();
    }

    // And the unprefixed documents do not vary with the language being live,
    // which is what makes an hour in a shared cache safe.
    $withArabic = test()->get('/robots.txt')->getContent();

    sapSettings([Locale::SETTING_ENABLED => '1']);
    expect(test()->get('/robots.txt')->getContent())->toBe($withArabic);
});
