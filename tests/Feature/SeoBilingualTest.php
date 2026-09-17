<?php

declare(strict_types=1);

/*
 * T7 — what this shop tells a search engine about two languages (Lane EY).
 *
 * The bilingual foundation (T1) already shipped <html lang>, <html dir> and an
 * hreflang pair on the pages that use layouts/store.blade.php. This file is the
 * rest of it, and every assertion below was written against a fetched page, a
 * fetched sitemap or a fetched robots.txt, never against a helper's return
 * value. Each one FAILED on the tip this lane branched from; the comment on
 * each says what was being served instead.
 *
 * What was being served, established by fetching, before any of this changed:
 *
 *   /ar/shop/            canonical -> https://…/shop/        (the ENGLISH page)
 *   /ar/new-in/          canonical -> https://…/new-in/
 *   /ar/skincare-guide/  canonical -> https://…/skincare-guide/ , no hreflang
 *   /ar/reviews/         canonical -> https://…/reviews/        , no hreflang
 *   /ar/skin-quiz/       canonical -> https://…/skin-quiz/      , no hreflang
 *   /sitemap.xml         54 English URLs, no /ar, no xhtml:link
 *   /robots.txt          no /ar rule at all, and no deployment prefix
 *   /llms.txt            English addresses only
 *   Indexability::isPrivate('/ar/checkout')  false
 */

use App\Models\Brand;
use App\Models\Category;
use App\Models\Page;
use App\Models\Post;
use App\Models\Product;
use App\Models\Setting;
use App\Services\SettingsService;
use App\Support\Indexability;
use App\Support\Locale;
use App\Support\Seo;
use App\Support\Url;

const SBL_BASE = 'https://kbeautybliss.test';

function sblSettings(array $values = []): void
{
    foreach (array_merge([
        'site_url' => SBL_BASE,
        'seo_site_name' => 'K-Beauty Bliss',
        'llms_enabled' => '1',
        'sitemap_enabled' => '1',
    ], $values) as $key => $value) {
        Setting::updateOrCreate(['key' => $key], ['value' => (string) $value, 'autoload' => true]);
    }

    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
}

/** Turn Arabic on the way Translation → Settings does: a setting, not a release. */
function sblArabicOn(): void
{
    sblSettings([Locale::SETTING_ENABLED => '1']);
}

beforeEach(function () {
    sblSettings();
});

/** One of everything the sitemap and the crawl surface can name. */
function sblCatalogue(): array
{
    $brand = Brand::firstOrCreate(['slug' => 'sbl-anua'], ['name' => 'Anua']);
    $category = Category::firstOrCreate(
        ['slug' => 'sbl-cleansers'],
        ['name' => 'Cleansers', 'path' => 'sbl-cleansers']
    );

    $product = Product::create([
        'slug' => 'sbl-heartleaf-toner',
        'name' => 'Heartleaf Toner',
        'brand_id' => $brand->id,
        'status' => 'publish',
        'is_visible' => true,
        'price' => 9900,
        'stock_status' => 'instock',
    ]);
    $product->categories()->syncWithoutDetaching([$category->id]);

    Page::updateOrCreate(
        ['slug' => 'about'],
        ['title' => 'About', 'content' => '<p>Hi.</p>', 'status' => 'published']
    );

    Post::create([
        'slug' => 'sbl-heartleaf-explained',
        'title' => 'Heartleaf, explained',
        'excerpt' => 'What it does.',
        'body' => '<p>Words.</p>',
        'status' => 'published',
        'published_at' => now()->subDay(),
    ]);

    return compact('brand', 'category', 'product');
}

/**
 * The <head>, so nothing below can match body copy or a script.
 *
 * Fails loudly on a document with no <head> rather than returning '' — a parse
 * that quietly hands back nothing is how a guard ends up asserting nothing,
 * which is the shape this lane was told to avoid.
 */
function sblHead(string $html): string
{
    expect((bool) preg_match('#<head>(.*?)</head>#si', $html, $m))
        ->toBeTrue('the page rendered no <head> at all; the parse is wrong, not the assertion');

    return $m[1];
}

function sblCanonical(string $html): ?string
{
    return preg_match('#<link rel="canonical" href="([^"]+)"#', sblHead($html), $m)
        ? html_entity_decode($m[1])
        : null;
}

/** @return array<string, string> hreflang => href, x-default included */
function sblAlternates(string $html): array
{
    preg_match_all(
        '#<link rel="alternate" hreflang="([^"]+)" href="([^"]+)"#',
        sblHead($html),
        $m,
        PREG_SET_ORDER
    );

    $out = [];

    foreach ($m as $set) {
        $out[$set[1]] = html_entity_decode($set[2]);
    }

    return $out;
}

/** Every page type that builds an SEO url of its own, as a path. */
function sblPageTypes(): array
{
    return [
        '/',
        '/shop/',
        '/new-in/',
        '/skincare-guide/',
        '/reviews/',
        '/skin-quiz/',
        '/about/',
        '/korean-skincare-brands/',
        '/korean-skincare-brands/sbl-anua/',
        '/product-category/sbl-cleansers/',
        '/product/sbl-heartleaf-toner/',
        '/sbl-heartleaf-explained/',
        '/cart/',
    ];
}

/** The Arabic address of a storefront path. */
function sblAr(string $path): string
{
    return $path === '/' ? '/ar/' : '/ar' . $path;
}

/* ═══════════════ 1. per-language canonical ═══════════════ */

it('canonicalises an Arabic page to its Arabic address on every page type', function () {
    sblCatalogue();
    sblArabicOn();

    foreach (sblPageTypes() as $path) {
        // English is unchanged: this is the half that must not move.
        expect(sblCanonical(test()->get($path)->assertOk()->getContent()))
            ->toBe(SBL_BASE . $path, "English canonical for {$path}");

        /*
         * And the Arabic page canonicalises to ITSELF.
         *
         * Five of these named the English address before this lane: /ar/shop/,
         * the four curated collections, /ar/skincare-guide/, /ar/reviews/ and
         * /ar/skin-quiz/ each built their SEO url as
         * `$siteBase . '/a/literal/path/'`, a string rather than a link, so
         * Url::to() never saw it and no locale segment was ever added. A
         * canonical naming another language is not a weak signal, it is an
         * instruction to drop the page from the index.
         */
        expect(sblCanonical(test()->get(sblAr($path))->assertOk()->getContent()))
            ->toBe(SBL_BASE . sblAr($path), "Arabic canonical for {$path}");
    }
});

it('keeps the Arabic page inside Arabic in og:url and in its breadcrumb trail', function () {
    sblCatalogue();
    sblArabicOn();

    $html = test()->get('/ar/product/sbl-heartleaf-toner/')->assertOk()->getContent();

    /*
     * og:url IS THE CANONICAL, and every share scraper reads it rather than the
     * <link>. It came out of Seo::canonical() too, so it moved with it.
     */
    expect($html)->toContain('<meta property="og:url" content="' . SBL_BASE . '/ar/product/sbl-heartleaf-toner/">');

    /*
     * And the BreadcrumbList. Google renders the breadcrumb it is given in the
     * result, so an Arabic page whose trail names English URLs advertises the
     * English pages under an Arabic title — and the trail is built from the
     * same canonical() call, so this is one behaviour rather than two.
     */
    preg_match('#"@type":"BreadcrumbList".*?\]#', $html, $m);

    expect($m[0] ?? '')->not->toBe('', 'no BreadcrumbList was emitted, so this assertion proves nothing');

    $trail = str_replace('\\/', '/', $m[0]);

    expect($trail)->toContain(SBL_BASE . '/ar/')
        ->and($trail)->toContain(SBL_BASE . '/ar/shop/')
        ->and($trail)->toContain(SBL_BASE . '/ar/product/sbl-heartleaf-toner/');
});

it('does not drag the Arabic page onto the English canonical through a seo column override', function () {
    ['product' => $product] = sblCatalogue();
    sblArabicOn();

    // The owner types a cross-page canonical once, in English, for both
    // languages — there is one `seo` column and one editor.
    $product->seo = ['canonical' => SBL_BASE . '/product/some-other-product/'];
    $product->save();

    expect(sblCanonical(test()->get('/product/sbl-heartleaf-toner/')->assertOk()->getContent()))
        ->toBe(SBL_BASE . '/product/some-other-product/');

    // The Arabic page canonicalises to the ARABIC address of the page the
    // override names, which is what the override means. Before this lane it
    // was handed through untouched and named the English one.
    expect(sblCanonical(test()->get('/ar/product/sbl-heartleaf-toner/')->assertOk()->getContent()))
        ->toBe(SBL_BASE . '/ar/product/some-other-product/');
});

it('leaves a canonical on another host exactly as the owner typed it', function () {
    ['product' => $product] = sblCatalogue();
    sblArabicOn();

    // A syndication canonical names a document this shop does not serve.
    // /ar/ in front of somebody else's URL is a 404.
    $product->seo = ['canonical' => 'https://elsewhere.example/syndicated/'];
    $product->save();

    $html = test()->get('/ar/product/sbl-heartleaf-toner/')->assertOk()->getContent();

    expect(sblCanonical($html))->toBe('https://elsewhere.example/syndicated/')
        // And no hreflang: this page cannot claim to be a language of a
        // document it does not own.
        ->and(sblAlternates($html))->toBe([]);
});

it('composes the deployment prefix outside the language, never inside it', function () {
    sblCatalogue();
    sblArabicOn();

    config(['kbb.base_path' => '/kbb-upgrade']);
    Url::forgetBase();
    app()->setLocale('ar');

    // site_url written WITHOUT the base path, which is one of the two ways this
    // shop has shipped it.
    $head = Seo::render(['title' => 'Shop', 'url' => SBL_BASE . '/kbb-upgrade/shop/']);

    expect($head)->toContain('<link rel="canonical" href="' . SBL_BASE . '/kbb-upgrade/ar/shop/">')
        ->and($head)->toContain('hreflang="ar" href="' . SBL_BASE . '/kbb-upgrade/ar/shop/"')
        ->and($head)->toContain('hreflang="en" href="' . SBL_BASE . '/kbb-upgrade/shop/"')
        ->and($head)->not->toContain('/ar/kbb-upgrade/');

    config(['kbb.base_path' => '']);
    Url::forgetBase();
    app()->setLocale('en');
});

/* ═══════════════ 2. hreflang as a reciprocal set ═══════════════ */

it('emits nothing at all about a second language while Arabic is off', function () {
    sblCatalogue();

    foreach (sblPageTypes() as $path) {
        $html = test()->get($path)->assertOk()->getContent();

        expect($html)->not->toContain('hreflang')
            ->and($html)->not->toContain('/ar/');

        // And /ar is absent rather than empty.
        test()->get(sblAr($path))->assertNotFound();
    }
});

it('emits a reciprocal hreflang cluster on every page type, in both directions', function () {
    sblCatalogue();
    sblArabicOn();

    foreach (sblPageTypes() as $path) {
        $english = sblAlternates(test()->get($path)->assertOk()->getContent());
        $arabic = sblAlternates(test()->get(sblAr($path))->assertOk()->getContent());

        $expected = [
            'en' => SBL_BASE . $path,
            'ar' => SBL_BASE . sblAr($path),
            'x-default' => SBL_BASE . $path,
        ];

        /*
         * IDENTICAL SETS FROM BOTH SIDES, INCLUDING EACH PAGE'S OWN ADDRESS.
         *
         * Google drops a cluster whose links do not reciprocate, and a page
         * that lists its alternates without listing itself reads as duplicate
         * content rather than as one document in two languages.
         *
         * /skin-quiz, /skincare-guide, an article and /app carry their own
         * <html> document instead of using layouts/store.blade.php, which is
         * where the foundation emitted this — so before this lane all four
         * served an Arabic URL with no hreflang on it whatsoever, in both
         * directions.
         */
        expect($english)->toBe($expected, "hreflang set on {$path}")
            ->and($arabic)->toBe($expected, 'hreflang set on ' . sblAr($path));
    }
});

it('points no hreflang alternate at a URL that redirects', function () {
    sblCatalogue();
    sblArabicOn();

    $walked = 0;

    foreach (sblPageTypes() as $path) {
        foreach ([$path, sblAr($path)] as $address) {
            foreach (sblAlternates(test()->get($address)->assertOk()->getContent()) as $href) {
                $target = parse_url($href, PHP_URL_PATH) ?: '/';
                $status = test()->call('GET', $target)->getStatusCode();

                // 3xx counts as a failure, not just 4xx: an hreflang must name
                // the canonical form of the page, and a URL the site then sends
                // the crawler away from is not it.
                expect($status)->toBe(200, "alternate {$target} (from {$address}) returns {$status}");

                $walked++;
            }
        }
    }

    expect($walked)->toBeGreaterThan(50, 'the sweep walked almost no alternates, so it proves nothing');
});

/* ═══════════════ 3. the sitemap ═══════════════ */

it('leaves the sitemap English, and free of any xhtml namespace, while Arabic is off', function () {
    sblCatalogue();

    $xml = test()->get('/sitemap.xml')->assertOk()->getContent();

    expect($xml)->toContain('<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">')
        ->and($xml)->not->toContain('xhtml')
        ->and($xml)->not->toContain('/ar/');
});

it('lists every URL once per language, each carrying the whole cluster', function () {
    sblCatalogue();

    $before = substr_count(test()->get('/sitemap.xml')->getContent(), '<url>');

    sblArabicOn();

    $xml = test()->get('/sitemap.xml')->assertOk()->getContent();

    expect($before)->toBeGreaterThan(8, 'the English sitemap is too short for this comparison to mean anything');

    // One sitemap, not an index: the address never changes when the switch
    // moves, which is the whole argument for this shape.
    expect(substr_count($xml, '<url>'))->toBe($before * 2)
        ->and($xml)->toContain('xmlns:xhtml="http://www.w3.org/1999/xhtml"');

    preg_match_all('#<url>(.*?)</url>#s', $xml, $blocks);

    foreach ($blocks[1] as $block) {
        preg_match('#<loc>(.*?)</loc>#', $block, $m);
        $loc = html_entity_decode($m[1]);

        preg_match_all('#<xhtml:link rel="alternate" hreflang="([^"]+)" href="([^"]+)"/>#', $block, $links, PREG_SET_ORDER);

        $alternates = [];

        foreach ($links as $set) {
            $alternates[$set[1]] = html_entity_decode($set[2]);
        }

        // Every entry names en, ar and x-default — including itself.
        expect(array_keys($alternates))->toBe(['en', 'ar', 'x-default'], "cluster keys for {$loc}")
            ->and(in_array($loc, $alternates, true))->toBeTrue("{$loc} is missing from its own cluster")
            ->and($alternates['x-default'])->toBe($alternates['en'], "x-default for {$loc}");
    }
});

it('says the same thing in the sitemap as on the page, for every URL in it', function () {
    sblCatalogue();
    sblArabicOn();

    $xml = test()->get('/sitemap.xml')->assertOk()->getContent();

    preg_match_all('#<url>(.*?)</url>#s', $xml, $blocks);

    expect(count($blocks[1]))->toBeGreaterThan(16, 'the sweep is not walking a real sitemap');

    foreach ($blocks[1] as $block) {
        preg_match('#<loc>(.*?)</loc>#', $block, $m);
        $loc = html_entity_decode($m[1]);
        $path = parse_url($loc, PHP_URL_PATH) ?: '/';

        preg_match_all('#<xhtml:link rel="alternate" hreflang="([^"]+)" href="([^"]+)"/>#', $block, $links, PREG_SET_ORDER);

        $fromSitemap = [];

        foreach ($links as $set) {
            $fromSitemap[$set[1]] = html_entity_decode($set[2]);
        }

        $response = test()->call('GET', $path);

        // A sitemap names the address that answers, never one the site then
        // sends a crawler away from.
        expect($response->getStatusCode())->toBe(200, "sitemap lists {$path} which returns " . $response->getStatusCode());

        $html = $response->getContent();

        // Self-canonicalising: a sitemap entry whose page points somewhere else
        // is asking Google to crawl a URL and then ignore it.
        expect(sblCanonical($html))->toBe($loc, "sitemap lists {$path}, whose page canonicalises elsewhere")
            // and the page and the sitemap agree about the cluster, which is
            // true by construction because both come from
            // Locale::alternatePaths() — this is the assertion that keeps it so.
            ->and(sblAlternates($html))->toBe($fromSitemap, "page and sitemap disagree about {$path}");
    }
});

it('advertises a noindex product in neither language', function () {
    ['product' => $product] = sblCatalogue();

    $product->seo = ['noindex' => true];
    $product->save();

    sblArabicOn();

    $xml = test()->get('/sitemap.xml')->assertOk()->getContent();

    expect($xml)->not->toContain('/product/sbl-heartleaf-toner/')
        ->and($xml)->not->toContain('/ar/product/sbl-heartleaf-toner/')
        // and the sitemap is not simply empty, which would pass the two above
        // for the wrong reason.
        ->and($xml)->toContain('/ar/shop/');
});

/* ═══════════════ 4. robots.txt, llms.txt, Indexability ═══════════════ */

it('keeps the private pages out of the crawl in every language that is live', function () {
    $body = test()->get('/robots.txt')->assertOk()->getContent();

    foreach (Indexability::PRIVATE_PREFIXES as $prefix) {
        expect($body)->toContain("Disallow: {$prefix}\n");
    }

    expect($body)->not->toContain('/ar/');

    sblArabicOn();

    $body = test()->get('/robots.txt')->assertOk()->getContent();

    /*
     * Before this lane robots.txt named none of these. With Arabic on, /ar/cart,
     * /ar/checkout, /ar/my-account and /ar/track-my-order are real 200-answering
     * addresses that the file said nothing about — private in English and
     * crawlable in Arabic, which is exactly the shape of leak CLAUDE.md records
     * this shop shipping before.
     */
    foreach (Indexability::PRIVATE_PREFIXES as $prefix) {
        expect($body)->toContain("Disallow: {$prefix}\n")
            ->and($body)->toContain('Disallow: /ar' . $prefix . "\n");
    }
});

it('cannot let the two lists of private paths drift apart', function () {
    $robots = (new ReflectionClass(\App\Http\Controllers\Store\SeoFilesController::class))
        ->getConstant('ROBOTS_PRIVATE');

    $a = $robots;
    $b = Indexability::PRIVATE_PREFIXES;
    sort($a);
    sort($b);

    // robots.txt keeps its own list only to keep its English bytes in the order
    // they have always been printed in. The SET has to be the same one, or a
    // prefix added to Indexability ships a page that says noindex while
    // robots.txt invites the crawl.
    expect($a)->toBe($b);
});

it('writes robots.txt rules at the path the crawler actually sees', function () {
    config(['kbb.base_path' => '/kbb-upgrade']);
    Url::forgetBase();
    sblArabicOn();

    $body = test()->get('/robots.txt')->assertOk()->getContent();

    // "Disallow: /checkout" on a host that serves this app from /kbb-upgrade
    // names a path at the DOMAIN root which this application does not serve, so
    // the rule protected nothing at all.
    expect($body)->toContain("Disallow: /kbb-upgrade/checkout\n")
        ->and($body)->toContain("Disallow: /kbb-upgrade/ar/checkout\n")
        ->and($body)->toContain("Disallow: /kbb-upgrade/admin\n")
        ->and($body)->not->toContain("Disallow: /checkout\n");

    config(['kbb.base_path' => '']);
    Url::forgetBase();
});

it('tells an agent which languages this shop is published in, and only when there are two', function () {
    $body = test()->get('/llms.txt')->assertOk()->getContent();

    expect($body)->not->toContain('## Languages')
        ->and($body)->not->toContain('/ar/');

    sblArabicOn();

    $body = test()->get('/llms.txt')->assertOk()->getContent();

    expect($body)->toContain('## Languages')
        ->and($body)->toContain(SBL_BASE . '/ar/shop/')
        ->and($body)->toContain(SBL_BASE . '/ar/skincare-guide/')
        ->and($body)->toContain('العربية');

    // And every address it offers answers, in both languages.
    preg_match_all('#\((https?://[^)]+)\)#', $body, $links);

    expect(count($links[1]))->toBeGreaterThan(3, 'llms.txt offered almost no links, so this sweep proves nothing');

    foreach ($links[1] as $link) {
        $path = parse_url($link, PHP_URL_PATH) ?: '/';

        expect(test()->call('GET', $path)->getStatusCode())
            ->toBe(200, "llms.txt lists {$path}");
    }
});

it('knows that /ar/checkout is the checkout', function () {
    // Off: /ar does not exist, so /ar/checkout is an ordinary path that 404s
    // and is not a private page of this shop at all.
    expect(Indexability::isPrivate('/ar/checkout'))->toBeFalse();

    sblArabicOn();

    /*
     * On: it is the checkout, and it has to answer that to any caller — not
     * only to the one that happens to read the path AFTER SetLocaleFromPath has
     * rewritten it. A path private at /checkout and public at /ar/checkout is
     * the leak this project has shipped before.
     */
    foreach (Indexability::PRIVATE_PREFIXES as $prefix) {
        expect(Indexability::isPrivate('/ar' . $prefix))->toBeTrue($prefix)
            ->and(Indexability::isPrivate('/ar' . $prefix . '/orders'))->toBeTrue($prefix);
    }

    // And a slug that merely begins with a locale code is not a locale.
    expect(Indexability::isPrivate('/argan-oil-cleanser/'))->toBeFalse();
});

it('serves the private pages as noindex under /ar too', function () {
    sblArabicOn();

    foreach (['/cart/', '/my-wishlist/', '/track-my-order/'] as $path) {
        expect(test()->get(sblAr($path))->assertOk()->getContent())
            ->toContain('<meta name="robots" content="noindex, nofollow">');
    }
});

it('keeps hreflang when the SEO Engine module is switched off', function () {
    sblCatalogue();
    sblArabicOn();

    /*
     * Store → Modules → SEO Engine governs what this shop CHOOSES to say about
     * itself. Which languages a document exists in is not a choice, and
     * dropping the tag would not make the Arabic pages go away — it would leave
     * two indexable copies of every page with nothing relating them.
     *
     * This is also the behaviour that was already shipping: the layout emitted
     * hreflang outside the engine's gate, and moving the block into
     * App\Support\Seo must not quietly put it behind one.
     */
    app(SettingsService::class)->setModule('seo_engine', false);

    $html = test()->get('/ar/cart/')->assertOk()->getContent();

    expect($html)->toContain('hreflang="en" href="' . SBL_BASE . '/cart/"')
        ->and($html)->toContain('hreflang="ar" href="' . SBL_BASE . '/ar/cart/"')
        ->and($html)->toContain('hreflang="x-default" href="' . SBL_BASE . '/cart/"')
        // and the engine really is off, or this proves nothing.
        ->and($html)->not->toContain('<meta property="og:site_name"');
});

it('retracts the cluster on a document the owner marked noindex, and only that kind', function () {
    ['brand' => $brand] = sblCatalogue();
    sblArabicOn();

    /*
     * An editorial noindex — `brands.seo` / `categories.seo`, set per row by the
     * owner — is the document saying "do not index me". Advertising three
     * alternates of a document that says that is two opposite claims in one
     * <head>, and Google drops the cluster rather than honouring half of it,
     * which costs the OTHER language its alternate too.
     */
    $brand->seo = ['noindex' => true];
    $brand->save();

    $html = test()->get('/ar/korean-skincare-brands/sbl-anua/')->assertOk()->getContent();

    expect($html)->toContain('<meta name="robots" content="noindex, nofollow">')
        ->and(sblAlternates($html))->toBe([]);

    /*
     * And the OTHER kind of noindex must still emit. The cart is per-visitor,
     * not editorial — an Arabic shopper's cart should link to the English one.
     * This is the assertion that stops the guard above being written as
     * "noindex" and quietly taking the storefront's own pages with it.
     */
    expect(sblAlternates(test()->get('/ar/cart/')->assertOk()->getContent()))->toBe([
        'en' => SBL_BASE . '/cart/',
        'ar' => SBL_BASE . '/ar/cart/',
        'x-default' => SBL_BASE . '/cart/',
    ]);
});
