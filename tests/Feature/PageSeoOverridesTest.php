<?php

declare(strict_types=1);

use App\Models\Page;
use App\Models\Setting;
use App\Services\SettingsService;

/**
 * =============================================================================
 * THE `seo` COLUMN ON A CONTENT PAGE WAS WRITTEN BY THE ADMIN AND READ BY NOTHING
 * =============================================================================
 *
 * `pages` carries the same `{title, desc, og_image, canonical, noindex}` shape
 * that `products`, `categories`, `brands` and `posts` carry, and
 * `docs/SEO-GAP.md` §4 lists "Per-row SEO title/description" as **Have**, naming
 * `pages` among the five tables. That was true of the column and false of the
 * storefront: `Store\PageController::show()` passed the view a row and no SEO
 * context at all, so all seven routed content pages took the layout's defaults.
 *
 * Measured on a running server, with every field of the override saved on the
 * `faqs` row, BEFORE the fix:
 *
 *     seo.title     "OVERRIDE TITLE"         <title>Frequently Asked Questions · K-Beauty Bliss</title>
 *     seo.desc      "OVERRIDE DESCRIPTION…"  <meta name="description" content="Shop Korean skincare in the UAE — …">
 *     seo.canonical "…/somewhere-else/"      <link rel="canonical" href="…/faqs/">
 *     seo.noindex   true                     <meta name="robots" content="index, follow">
 *     /sitemap.xml                           still carried /faqs/
 *
 * ── WHY THE LAST TWO LINES ARE WORSE THAN THE FIRST TWO ─────────────────────
 *
 * `Support\SeoAudit::scanPages()` SKIPS a page whose `seo.noindex` is set — it
 * treats the row as deindexed and stops auditing it. So Store → SEO & Meta →
 * SEO Audit reported the page as out of the index because the owner had asked
 * for that, the page went on inviting the crawl, and the sitemap went on
 * submitting it. Two documents agreeing with each other and both disagreeing
 * with the owner, with the only screen that could have shown it up quietly
 * looking away. That is the shape of the /cart leak CLAUDE.md records.
 *
 * Every assertion below is made against the bytes a crawler is served.
 */
function psoSettings(array $values = []): void
{
    foreach (array_merge([
        'site_url' => 'https://kbeautybliss.test',
        'seo_site_name' => 'K-Beauty Bliss',
        'seo_default_description' => 'Korean skincare, delivered across the UAE.',
        'sitemap_enabled' => '1',
    ], $values) as $key => $value) {
        Setting::updateOrCreate(['key' => $key], ['value' => (string) $value, 'autoload' => true]);
    }

    Setting::flushMap();
    SettingsService::forgetMemo();
}

beforeEach(function () {
    psoSettings();
});

/** The `faqs` content page, with whatever override this case is about. */
function psoPage(?array $seo = null): Page
{
    return Page::updateOrCreate(
        ['slug' => 'faqs'],
        [
            'title' => 'Frequently Asked Questions',
            'status' => 'published',
            'content' => '<h3>How much is delivery?</h3><p>Charges are calculated for your address at checkout and shown in full before you pay.</p>',
            'seo' => $seo,
        ]
    );
}

/** The <head> of a page, so a match cannot come from the body or a script. */
function psoHead(string $path): string
{
    $html = test()->get($path)->assertOk()->getContent();

    expect((bool) preg_match('#<head>(.*?)</head>#s', $html, $m))
        ->toBeTrue($path . ' rendered no <head> at all; the parse is wrong, not the assertion.');

    return $m[1];
}

/* ───────────────────────────── rule 1 comes first ───────────────────────── */

it('leaves a content page with no override byte-for-byte what it was', function () {
    /*
     * RULE 1, AND IT IS THE FIRST CASE ON PURPOSE. The override is the whole
     * feature, and the shop as it ships has none saved on any of the seven
     * rows — so applying this must move nothing.
     *
     * The two values named here are the ones the fix could plausibly have
     * broken: the title comes from a Blade @section the controller cannot see,
     * and the description falls through Seo::describe() to a SITE-WIDE setting.
     * Passing either one unconditionally would have replaced both with
     * something built in the controller.
     *
     * MUTATION NOTE, RUN: dropping the `!== ''` guard on the title branch so
     * `title` is always passed makes this red (the <title> becomes the bare
     * page title with no site name) -- 6 failed across this file, because that
     * one guard is what keeps five other cases reading the page's own title.
     */
    psoPage(null);

    $head = psoHead('/faqs/');

    expect($head)->toContain('<title>Frequently Asked Questions · K-Beauty Bliss</title>')
        ->toContain('<meta name="description" content="Korean skincare, delivered across the UAE.">')
        ->toContain('<meta name="robots" content="index, follow">')
        ->toContain('<link rel="canonical" href="https://kbeautybliss.test/faqs/">');
});

it('treats an override of empty strings as no override', function () {
    /*
     * The SEO panel posts every field on every save, so a row the owner opened
     * and closed again holds '' in each key rather than no key. `?? $default`
     * accepts '' happily -- the exact trap Services\Seo\SeoSettings exists for
     * -- and a blank title reaching `title_is_final` would publish an EMPTY
     * <title> on a content page.
     *
     * MUTATION NOTE, RUN: changing the four guards from `trim(...) !== ''` to
     * `isset(...)` makes this red on the title and the description -- 1 failed.
     */
    psoPage(['title' => '', 'desc' => '', 'og_image' => '', 'canonical' => '', 'noindex' => false]);

    $head = psoHead('/faqs/');

    expect($head)->toContain('<title>Frequently Asked Questions · K-Beauty Bliss</title>')
        ->toContain('<meta name="description" content="Korean skincare, delivered across the UAE.">')
        ->toContain('<meta name="robots" content="index, follow">');
});

/* ──────────────────────────── the four overrides ─────────────────────────── */

it('publishes the per-row title a content page was given', function () {
    /*
     * RED WITHOUT THE FIX: the <title> was the layout's, and the typed value
     * reached nothing.
     *
     * MUTATION NOTE, RUN: deleting the title branch from
     * PageController::show() makes this red -- 1 failed.
     */
    psoPage(['title' => 'Delivery, returns and the questions we get asked most']);

    expect(psoHead('/faqs/'))
        ->toContain('<title>Delivery, returns and the questions we get asked most</title>');
});

it('resolves the Yoast tokens a content page title override can carry', function () {
    /*
     * The per-row panel inserts %%title%% / %%sitename%% / %%sep%% chips, and
     * TitleTemplate::render() DELETES a token it was not handed -- so without
     * `title_token` a page whose override is Yoast's shipped default publishes
     * the site name alone. That defect reached production on 671 product pages
     * and Store\ProductController and PageController::post() both carry the key
     * for it; show() did not, because show() carried nothing.
     *
     * MUTATION NOTE, RUN: removing the `title_token` line makes this red -- the
     * title comes out " | K-Beauty Bliss" with the page name gone -- 1 failed.
     */
    psoPage(['title' => '%%title%% %%sep%% %%sitename%%']);

    expect(psoHead('/faqs/'))
        ->toContain('<title>Frequently Asked Questions | K-Beauty Bliss</title>')
        ->not->toContain('%%');
});

it('publishes the per-row description instead of the site-wide one', function () {
    /*
     * RED WITHOUT THE FIX: every content page shared one description -- which
     * SeoAudit's own `duplicate_description` check would report on a shop with
     * seven of them, while the only field that could have fixed it did nothing.
     *
     * MUTATION NOTE, RUN: deleting the description branch makes this red -- 1 failed.
     */
    psoPage(['desc' => 'How delivery, returns and order tracking work at K-Beauty Bliss.']);

    expect(psoHead('/faqs/'))
        ->toContain('<meta name="description" content="How delivery, returns and order tracking work at K-Beauty Bliss.">')
        ->not->toContain('Korean skincare, delivered across the UAE.');
});

it('honours a per-row canonical override on a content page', function () {
    psoPage(['canonical' => 'https://kbeautybliss.test/delivery/']);

    expect(psoHead('/faqs/'))
        ->toContain('<link rel="canonical" href="https://kbeautybliss.test/delivery/">');
});

it('cannot let a canonical override break out of its own attribute, and is reported when it is unsafe', function () {
    /*
     * RULE 5, IN THE TWO HALVES THAT ARE ACTUALLY TRUE TODAY.
     *
     * The first half holds by construction: every value Support\Seo prints goes
     * through htmlspecialchars with ENT_QUOTES, so an override carrying a quote
     * and a tag cannot close the attribute or open an element. Asserted here
     * because this commit is what first lets a `pages` row reach that printer.
     *
     * The second half is the SEO Audit screen. `SeoAudit::canonicalIsSafe()`
     * accepts a root-relative path or an https URL and nothing else, and
     * `scanPages()` runs it over `pages.seo.canonical` -- so a `javascript:` or
     * `http://` or off-site override is REPORTED under "Canonical points
     * somewhere unsafe". It is not blocked at render time, and that is the
     * existing, deliberate design for all five tables that carry the column
     * (docs/SEO-FEATURE-MATRIX.md: "An override pointing at another domain or a
     * downgraded http:// is caught by the SEO Audit screen"). This commit makes
     * `pages` behave like the other four rather than changing the rule.
     *
     * WHAT IS *NOT* ASSERTED, AND WHY IT IS WRITTEN DOWN INSTEAD:
     * Support\Seo::canonicalAbsolute() returns any `scheme:` value verbatim, so
     * a `javascript:` override IS published in href -- on products, categories,
     * brands, posts and now pages alike. That file is Lane S5's this round; the
     * exact change and the case to add with it are in
     * docs/SEO-ROUND-5-VERIFICATION.md under "Needs S5". Asserting the defect
     * away here would make this file red the moment S5 lands the fix, and
     * asserting the defect as-is would pin it.
     *
     * MUTATION NOTE, RUN: printing the canonical without htmlspecialchars in
     * Support\Seo::render() makes the first expectation red -- 1 failed.
     */
    psoPage(['canonical' => '"><script>alert(1)</script>']);

    $head = psoHead('/faqs/');

    expect($head)->not->toContain('<script>alert(1)</script>')
        ->toContain('&quot;&gt;&lt;script&gt;');

    expect(\App\Support\SeoAudit::canonicalIsSafe('javascript:alert(1)'))->toBeFalse();
    expect(\App\Support\SeoAudit::canonicalIsSafe('http://kbeautybliss.test/faqs/'))->toBeFalse();
    expect(\App\Support\SeoAudit::canonicalIsSafe('https://kbeautybliss.test/faqs/'))->toBeTrue();
});

/* ───────────────── noindex: the page and the sitemap together ────────────── */

it('takes a content page out of the index when the owner asked for it', function () {
    /*
     * RED WITHOUT THE FIX: "index, follow". The owner had ticked noindex, the
     * SEO Audit screen had stopped auditing the row BECAUSE he had ticked it
     * (SeoAudit::scanPages() skips a noindexed page), and the page went on
     * inviting the crawl.
     *
     * MUTATION NOTE, RUN: deleting the noindex branch from show() makes this
     * red -- 1 failed.
     */
    psoPage(['noindex' => true]);

    expect(psoHead('/faqs/'))->toContain('<meta name="robots" content="noindex, nofollow">');
});

it('stops submitting a content page the owner has taken out of the index', function () {
    /*
     * THE SITEMAP HALF, and it is a different file from the one above.
     * SeoFilesController::isNoindex() already existed, with a comment saying
     * "a page that sets noindex must drop out of this file whichever table it
     * lives in" -- and the `pages` query did not select the column, so the rule
     * could not be applied to the one table the comment was written next to.
     *
     * Asserted in both directions in one case, because "absent" on its own is
     * also what a broken sitemap looks like.
     *
     * MUTATION NOTE, RUN: removing the isNoindex() guard from the pages loop
     * makes the second expectation red while the first stays green -- 2 failed
     * across this file (this case and the one below it).
     */
    psoPage(null);
    expect(test()->get('/sitemap.xml')->getContent())->toContain('<loc>https://kbeautybliss.test/faqs/</loc>');

    psoPage(['noindex' => true]);
    expect(test()->get('/sitemap.xml')->getContent())->not->toContain('<loc>https://kbeautybliss.test/faqs/</loc>');
});

it('keeps the other content pages in the sitemap when one is noindexed', function () {
    /*
     * The guard is per row, not per block. A `continue` written as a `break`,
     * or a `where` clause on the query, would have dropped every page after the
     * noindexed one -- and with the seven ordered by id, that is six pages out
     * of the sitemap for one tick.
     *
     * MUTATION NOTE, RUN: changing the `continue` to `break` makes this red
     * while the case above stays green -- 1 failed.
     */
    Page::updateOrCreate(['slug' => 'about'], [
        'title' => 'About Us', 'status' => 'published', 'content' => '<p>Hi.</p>', 'seo' => null,
    ]);
    Page::updateOrCreate(['slug' => 'delivery'], [
        'title' => 'Shipping & Delivery', 'status' => 'published', 'content' => '<p>Hi.</p>', 'seo' => null,
    ]);
    psoPage(['noindex' => true]);

    $sitemap = test()->get('/sitemap.xml')->getContent();

    expect($sitemap)->toContain('<loc>https://kbeautybliss.test/about/</loc>')
        ->toContain('<loc>https://kbeautybliss.test/delivery/</loc>')
        ->not->toContain('<loc>https://kbeautybliss.test/faqs/</loc>');
});
