<?php

/**
 * What the shop tells a crawler about which of its URLs are real.
 *
 * ProductSeoTest covers the product page's own <head> and its structured data.
 * This file is the layer above it: the crawl surface. Which URLs answer 200,
 * which of those declare themselves indexable, which canonical each one names,
 * and whether the sitemap, robots.txt and the pages themselves agree about any
 * of it.
 *
 * Every assertion is made against the bytes a crawler is served — the response
 * status, the parsed <head>, the rendered anchors — never against a template or
 * a helper's return value. Each one below failed on the tip this lane branched
 * from; the comment on each says what was being served instead and what it
 * cost.
 */

use App\Models\Brand;
use App\Models\Category;
use App\Models\Page;
use App\Models\Post;
use App\Models\Product;
use App\Models\Setting;

const SCS_BASE = 'https://kbeautybliss.test';

function scsSettings(array $values = []): void
{
    foreach (array_merge(['site_url' => SCS_BASE, 'seo_site_name' => 'K-Beauty Bliss'], $values) as $key => $value) {
        Setting::updateOrCreate(['key' => $key], ['value' => (string) $value]);
    }

    Setting::flushMap();
}

beforeEach(function () {
    scsSettings();
});

/**
 * The <head> of a page, so a match cannot come from body copy or a script.
 *
 * LANE EC: this used to return '' when the pattern missed, which is the
 * parse-then-assert-nothing shape. Every caller today asserts ->toContain(),
 * which fails on an empty string, so nothing was actually inert — but the first
 * `->not->toContain()` written against it would have been, and a helper whose
 * failure mode is "hand back nothing" gets that wrong silently. It fails here
 * instead, once, where the parse is.
 */
function scsHead(string $html): string
{
    expect((bool) preg_match('#<head>(.*?)</head>#s', $html, $m))
        ->toBeTrue('The page rendered no <head> element at all; the parse is wrong, not the assertion below it.');

    return $m[1];
}

function scsGet(string $path): string
{
    return test()->get($path)->assertOk()->getContent();
}

/** Products that really are on the storefront, in a named category. */
function scsCatalogue(int $count, ?Category $category = null): Category
{
    $category ??= Category::create(['name' => 'Cleansers', 'slug' => 'scs-cleansers', 'path' => 'scs-cleansers']);
    $brand = Brand::firstOrCreate(['slug' => 'scs-anua'], ['name' => 'Anua']);

    for ($i = 0; $i < $count; $i++) {
        $product = Product::create([
            'slug' => 'scs-product-' . $i,
            'name' => 'Heartleaf Cleansing Oil ' . $i,
            'brand_id' => $brand->id,
            'status' => 'publish',
            'is_visible' => true,
            'price' => 9900,
            'stock_status' => 'instock',
        ]);

        $product->categories()->syncWithoutDetaching([$category->id]);
    }

    return $category;
}

/* ───────────────────────── pagination as a crawl path ───────────────────── */

it('paginates a category archive within that category, not into the whole shop', function () {
    $category = scsCatalogue(4);
    scsSettings(['products_per_page' => 2]);

    $html = scsGet('/product-category/' . $category->slug . '/');

    // Facets::build() hard-coded /shop/ as the base for every URL it made,
    // including the paginator's. The "next page" control on a category archive
    // therefore pointed at page two of the ENTIRE catalogue: a shopper paging
    // forward left the category, and — the expensive half — a crawler had no
    // path from any category archive to anything past its first page. Every
    // product that only appears on page two of its category lost its only
    // categorical crawl path, while Facets::canonicalUrl() was meanwhile
    // publishing /product-category/{slug}/?paged=2 as a canonical URL that
    // nothing on the site linked to.
    expect($html)->toContain('href="/product-category/' . $category->slug . '/?paged=2"')
        ->not->toContain('href="/shop/?paged=2"');
});

it('sends the paginator to the URL the next page canonicalises to', function () {
    $category = scsCatalogue(4);
    scsSettings(['products_per_page' => 2]);

    // The link and the canonical are built by two different methods —
    // Facets::pageUrl() and Facets::canonicalUrl() — off two different bases,
    // which is how they came to disagree. This walks the link the way a
    // crawler does rather than asserting a literal: whatever the paginator
    // emits is followed, and the page it reaches must name that same URL as
    // its own canonical.
    //
    // Asserting the canonical literally would not have caught the defect:
    // canonicalUrl() was already publishing /product-category/{slug}/?paged=2
    // correctly. It was the link that pointed somewhere else, so the canonical
    // named a URL nothing linked to.
    $page1 = scsGet('/product-category/' . $category->slug . '/');

    preg_match('#href="([^"]*paged=2)"#', $page1, $m);

    expect($m[1] ?? null)->not->toBeNull('page one emitted no link to page two');

    $next = html_entity_decode($m[1]);

    // Walked, not asserted as a literal: the link and the canonical are built
    // by two different methods off two different bases, which is how they came
    // to disagree. Asserting the canonical alone would not have caught the
    // defect — canonicalUrl() was already publishing
    // /product-category/{slug}/?paged=2 correctly, and it was the LINK that
    // pointed at /shop/?paged=2, so the canonical named a URL nothing on the
    // site linked to. Both halves are checked here: the link has to stay
    // inside the archive, and the page it reaches has to name it back.
    expect($next)->toStartWith('/product-category/' . $category->slug . '/');

    expect(scsHead(scsGet($next)))->toContain('<link rel="canonical" href="' . SCS_BASE . $next . '">');
});

it('404s a listing page number past the last page instead of re-serving page one', function () {
    scsCatalogue(3);
    // Large enough that the whole seeded catalogue is one page, so "past the
    // last page" is unambiguous rather than dependent on how many demo
    // products the migration set happens to insert.
    scsSettings(['products_per_page' => 500]);

    // forPage(min($page, $lastPage)) clamped an out-of-range page back onto
    // page one, so /shop/?paged=2, ?paged=57 and ?paged=4000 each answered 200
    // with the page-one grid AND named themselves as their own canonical. That
    // is an unbounded, self-canonicalising duplicate of page one on /shop/ and
    // on every category archive — crawl budget spent on copies of a page that
    // is already indexed.
    foreach (['/shop?paged=2', '/shop?paged=57', '/shop?paged=4000'] as $path) {
        test()->get($path)->assertNotFound();
    }

    test()->get('/product-category/scs-cleansers/?paged=9')->assertNotFound();

    // The page that does exist is untouched.
    test()->get('/shop')->assertOk();
});

it('404s a collection page number past the last page', function () {
    // /new-in?page=99 answered 200 with an empty grid and its own canonical.
    // Thin rather than duplicate, but the supply is just as unbounded.
    test()->get('/new-in?page=99')->assertNotFound();
    test()->get('/new-in')->assertOk();
});

/* ───────────────────────────── indexability ─────────────────────────────── */

it('tells a crawler not to index the per-visitor pages robots.txt already hides', function () {
    // robots.txt has always said of these paths that "a cart, an account area
    // and a wishlist are different for every visitor and useless in a result",
    // and every one of them was serving `index, follow` with a self-referencing
    // canonical. A Disallow only stops the fetch; it does not stop the URL
    // being indexed from a link, and a URL Google may not crawl is one whose
    // noindex it can never see. The page has to say it itself.
    foreach (['/cart', '/my-account', '/my-wishlist', '/track-my-order'] as $path) {
        expect(scsHead(scsGet($path)))
            ->toContain('<meta name="robots" content="noindex, nofollow">');
    }

    $robots = test()->get('/robots.txt')->assertOk()->getContent();

    foreach (['/cart', '/my-account', '/my-wishlist', '/track-my-order'] as $path) {
        expect($robots)->toContain('Disallow: ' . $path);
    }
});

it('keeps the pages that are meant to rank indexable', function () {
    scsCatalogue(2);

    // The other half of the same rule, so a prefix list cannot quietly grow to
    // cover the catalogue. /cart must not deindex a product or an article whose
    // slug merely starts with the same letters.
    Post::create([
        'slug' => 'cartons-of-cleanser',
        'title' => 'Cartons of cleanser',
        'body' => '<p>Body.</p>',
        'status' => 'published',
        'published_at' => now()->subDay(),
    ]);

    foreach (['/', '/shop', '/product-category/scs-cleansers/', '/product/scs-product-0/', '/cartons-of-cleanser/'] as $path) {
        expect(scsHead(scsGet($path)))
            ->toContain('<meta name="robots" content="index, follow">');
    }
});

/* ───────────────────────── one URL, one canonical ───────────────────────── */

it('names the same canonical whether or not the request carried a trailing slash', function () {
    // Laravel's router rtrims the path before matching, so /cart and /cart/
    // both answer 200 with identical content and neither redirects to the
    // other. The layout mirrored whichever form was requested into the
    // canonical, so one document published two self-referencing canonicals and
    // a single inbound link written without the slash split its signals. Every
    // internal link and every sitemap entry uses the slashed form; that is the
    // one form the page now declares.
    //
    // The request path is forced rather than passed to get(): Laravel's test
    // client trims a trailing slash off the URI it is given
    // (MakesHttpRequests::prepareUrlForRequest), so both spellings arrive here
    // identically and the assertion would prove nothing.
    $slashed = test()->call('GET', '/cart/')->assertOk()->getContent();
    $bare = test()->call('GET', '/cart')->assertOk()->getContent();

    expect(scsHead($slashed))->toContain('<link rel="canonical" href="' . SCS_BASE . '/cart/">');
    expect(scsHead($bare))->toContain('<link rel="canonical" href="' . SCS_BASE . '/cart/">');
});

it('gives each curated listing its own description and a self-referencing canonical', function () {
    scsCatalogue(2);

    // The four collection pages passed no SEO context at all, so all four
    // published the store-wide default meta description — the same sentence as
    // the homepage and as every other page without one — and a canonical built
    // from the path alone, which dropped ?page. /new-in?page=2 therefore
    // canonicalised to /new-in, which tells Google page two does not exist and
    // takes everything only reachable from it along. On a "New In" listing
    // that is specifically the stock that has just landed.
    $head = scsHead(scsGet('/new-in'));

    expect($head)->toContain('The latest Korean skincare to land')
        ->toContain('<link rel="canonical" href="' . SCS_BASE . '/new-in/">');

    // Two collections must not describe themselves identically.
    $best = scsHead(scsGet('/best-sellers'));

    // The sentence now follows the data (App\Support\RepeatPurchase): this test
    // builds no orders, so the honest answer here is the units-sold wording.
    // The test's actual point -- two collections must not describe themselves
    // identically -- is unchanged.
    expect($best)->toContain(\App\Support\RepeatPurchase::intro())
        ->toContain('<link rel="canonical" href="' . SCS_BASE . '/best-sellers/">');
});

/* ──────────────────────────────── sitemap ───────────────────────────────── */

it('submits the brand index at the address that answers rather than the one that redirects', function () {
    $sitemap = test()->get('/sitemap.xml')->assertOk()->getContent();

    // /brands/ 301s to /korean-skincare-brands/ (BrandController::legacyIndex).
    // Submitting the redirect asks Google to fetch a URL it is then sent away
    // from — the same defect the /shop -> /shop/ entry was corrected for, one
    // line below where it was corrected.
    expect($sitemap)->toContain('<loc>' . SCS_BASE . '/korean-skincare-brands/</loc>')
        ->not->toContain('<loc>' . SCS_BASE . '/brands/</loc>');
});

it('submits the brand landing pages and the curated listings', function () {
    scsCatalogue(1);

    $sitemap = test()->get('/sitemap.xml')->assertOk()->getContent();

    // /korean-skincare-brands/{slug}/ has been a real, indexable page since
    // Phase 9 and not one of them was ever in the sitemap; on a catalogue of
    // ninety-three brands the only crawl path to a brand page was the A-Z
    // listing. The four curated listings are linked from the site header and
    // were missing too.
    expect($sitemap)->toContain('<loc>' . SCS_BASE . '/korean-skincare-brands/scs-anua/</loc>');

    foreach (['new-in', 'best-sellers', 'super-sale', 'everything-under-54-aed'] as $collection) {
        expect($sitemap)->toContain('<loc>' . SCS_BASE . '/' . $collection . '/</loc>');
    }
});

it('leaves a brand with nothing live out of the sitemap', function () {
    Brand::create(['name' => 'Empty Shelf', 'slug' => 'scs-empty']);

    $sitemap = test()->get('/sitemap.xml')->assertOk()->getContent();

    // A brand page with an empty grid is a thin page, and asking Google to
    // fetch a set of them is the same crawl-budget tax the noindex-product
    // skip in this file already avoids.
    expect($sitemap)->not->toContain('/korean-skincare-brands/scs-empty/');
});

it('submits only the content pages that are published and routed', function () {
    Page::updateOrCreate(['slug' => 'about'], ['title' => 'About', 'content' => '<p>Hi.</p>', 'status' => 'published']);
    Page::updateOrCreate(['slug' => 'faqs'], ['title' => 'FAQs', 'content' => '<p>Hi.</p>', 'status' => 'draft']);
    Page::updateOrCreate(['slug' => 'scs-unrouted'], ['title' => 'Nope', 'content' => '<p>Hi.</p>', 'status' => 'published']);

    $sitemap = test()->get('/sitemap.xml')->assertOk()->getContent();

    expect($sitemap)->toContain('<loc>' . SCS_BASE . '/about/</loc>')
        // A draft 404s through PageController::show(), and a sitemap entry
        // that 404s is a Search Console error.
        ->not->toContain('<loc>' . SCS_BASE . '/faqs/</loc>')
        // Only the seven slugs routes/web.php actually routes.
        ->not->toContain('scs-unrouted');
});

it('lists no URL that the site then redirects or 404s', function () {
    scsCatalogue(2);
    Page::updateOrCreate(['slug' => 'about'], ['title' => 'About', 'content' => '<p>Hi.</p>', 'status' => 'published']);

    $sitemap = test()->get('/sitemap.xml')->assertOk()->getContent();
    preg_match_all('#<loc>(.*?)</loc>#', $sitemap, $locs);

    // Keep the sweep honest: it must be walking a real sitemap.
    expect(count($locs[1]))->toBeGreaterThan(12);

    foreach ($locs[1] as $loc) {
        $path = parse_url(html_entity_decode($loc), PHP_URL_PATH) ?: '/';
        $status = test()->call('GET', $path)->getStatusCode();

        // 3xx counts as a failure here, not just 4xx: a sitemap exists to name
        // the canonical address of each page, and an entry that redirects
        // names one that is not.
        expect($status)->toBe(200, 'sitemap lists ' . $path . ' which returns ' . $status);
    }
});

/* ──────────────────────────────── headings ──────────────────────────────── */

it('serves exactly one h1 on an article whose body carries its own', function () {
    Post::create([
        'slug' => 'heartleaf-explained',
        'title' => 'Heartleaf extract, explained',
        'excerpt' => 'What heartleaf does.',
        'body' => '<p>Intro.</p><h1 class="wp-block-heading">Pasted from WordPress</h1><h2>Sub</h2>',
        'status' => 'published',
        'published_at' => now()->subDay(),
    ]);

    $html = scsGet('/heartleaf-explained/');

    // RichText::clean() already documents the rule — h1 is absent from both of
    // its allowlists because "the page template owns the page's single h1" —
    // but it is only called on the product editor's write path. Article bodies
    // and CMS page content are echoed through the shortcode engine and
    // otherwise untouched, and the templates emit their own h1 for the title
    // just above, so an h1 in the body made two.
    preg_match_all('#<h1[^>]*>#i', $html, $h1s);

    expect($h1s[0])->toHaveCount(1);

    // Demoted, not deleted: an h1 in imported copy is almost always a real
    // section heading that was tagged too strongly, and the attributes it
    // carries survive.
    expect($html)->toContain('<h2 class="wp-block-heading">Pasted from WordPress</h2>');
});

it('serves exactly one h1 on a content page whose body carries its own', function () {
    Page::updateOrCreate(
        ['slug' => 'delivery'],
        ['title' => 'Delivery', 'content' => '<p>Intro.</p><H1>Shipping times</H1>', 'status' => 'published']
    );

    $html = scsGet('/delivery');

    preg_match_all('#<h1[^>]*>#i', $html, $h1s);

    expect($h1s[0])->toHaveCount(1);
    expect($html)->toContain('<h2>Shipping times</h2>');
});
