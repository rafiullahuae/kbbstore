<?php

/**
 * The SEO & Meta screen saves; the storefront has to read what it saved.
 *
 * Each case here is one of the ways that connection was broken:
 *
 *   - title_is_final was forced on for every page, so the title template,
 *     the separator and the homepage title were written by the admin and
 *     read by nothing;
 *   - the screen posts every field on every save, so an untouched field is
 *     stored as '' and `?? $default` returned '' rather than the default --
 *     an empty <title>, an empty separator, a sitemap of relative URLs;
 *   - an unknown or misspelled token reached the browser tab as literal
 *     braces, and dropping it left the separator dangling;
 *   - JSON-LD was encoded with JSON_UNESCAPED_SLASHES, so an org_name of
 *     "</script><script>..." closed the block and executed.
 *
 * The last one shipped. Settings are admin-authored but they are still
 * untrusted input at render time: the admin panel is reachable over the
 * network and these values land in <meta content>, in HTML attributes and
 * inside a <script> body.
 */

use App\Models\Setting;
use App\Support\Seo;

/** Write settings the way the admin screen does, and make readers see them. */
function seoSettings(array $values): void
{
    foreach ($values as $key => $value) {
        Setting::updateOrCreate(['key' => $key], ['value' => (string) $value]);
    }

    Setting::flushMap();
}

beforeEach(function () {
    // A clean, fully-filled baseline; individual tests blank out what they test.
    seoSettings([
        'site_url' => 'https://kbeautybliss.test',
        'seo_site_name' => 'K-Beauty Bliss',
        'seo_separator' => '|',
        'seo_title_template' => '{title} {sep} {sitename}',
    ]);
});

/* ---------------------------------------------------------------- template */

it('renders the saved title template, separator and site name', function () {
    seoSettings([
        'seo_separator' => '–',
        'seo_title_template' => '{sitename} {sep} {title}',
    ]);

    $html = Seo::render(['title' => 'Snail Mucin Essence']);

    expect($html)->toContain('<title>K-Beauty Bliss – Snail Mucin Essence</title>');
});

it('does not skip the template just because a page supplied a title', function () {
    // The layout used to set title_is_final on every page, which is the one
    // flag that bypasses everything below seo_title_template.
    $html = Seo::render(['title' => 'All brands']);

    expect($html)->toContain('<title>All brands | K-Beauty Bliss</title>');
});

it('does not repeat a site name the page title already carries', function () {
    $html = Seo::render(['title' => 'Cart · K-Beauty Bliss']);

    expect($html)->toContain('<title>Cart · K-Beauty Bliss</title>');
});

it('uses the homepage title and description for the home page', function () {
    seoSettings([
        'seo_home_title' => 'Korean skincare, delivered in the UAE',
        'seo_home_description' => 'Authentic K-beauty, shipped from Dubai.',
    ]);

    $html = Seo::render(['type' => 'home', 'title' => 'ignored fallback']);

    expect($html)->toContain('<title>Korean skincare, delivered in the UAE</title>')
        ->toContain('Authentic K-beauty, shipped from Dubai.');
});

it('resolves the yoast-style tokens a per-product SEO title can carry', function () {
    // The per-product panel inserts %%title%% / %%sitename%% / %%sep%% chips
    // and the stored value reaches the storefront verbatim.
    $html = Seo::render([
        'title' => 'Rice Toner %%sep%% %%sitename%%',
        'title_is_final' => true,
    ]);

    expect($html)->toContain('<title>Rice Toner | K-Beauty Bliss</title>')
        ->not->toContain('%%');
});

/* ------------------------------------------------------- blank means unset */

it('falls back to defaults when the admin saved blank fields', function () {
    // Exactly what the screen writes for a field nobody typed in.
    seoSettings([
        'seo_separator' => '',
        'seo_title_template' => '',
        'robots_index' => '',
        'robots_follow' => '',
    ]);

    $html = Seo::render(['title' => 'Sunscreen']);

    expect($html)->toContain('<title>Sunscreen | K-Beauty Bliss</title>')
        ->toContain('<meta name="robots" content="index, follow">');
});

it('never renders an empty title', function () {
    seoSettings([
        'seo_site_name' => '',
        'store_name' => '',
        'seo_title_template' => '',
        'seo_separator' => '',
        'seo_home_title' => '',
    ]);

    foreach ([[], ['type' => 'home'], ['title' => ''], ['title' => '', 'title_is_final' => true]] as $ctx) {
        $html = Seo::render($ctx);

        expect($html)->toMatch('/<title>\S[^<]*<\/title>/')
            ->not->toContain('<title></title>');
    }
});

it('falls back to a blank site_url rather than emitting a relative canonical', function () {
    seoSettings(['site_url' => '']);

    $html = Seo::render(['title' => 'Shop', 'url' => '/shop/']);

    expect($html)->toMatch('#<link rel="canonical" href="https?://[^"]+/shop/">#');
});

/* -------------------------------------------------------- placeholders */

it('never prints a literal placeholder into the title', function () {
    seoSettings(['seo_title_template' => '{title} {sep} {sitname}']);

    $html = Seo::render(['title' => 'Cleanser']);

    preg_match_all('/<(?:title)>([^<]*)<|<meta [^>]*content="([^"]*)"/', $html, $m, PREG_SET_ORDER);
    $visible = implode(' ', array_map(fn ($set) => ($set[1] ?? '') . ($set[2] ?? ''), $m));

    expect($html)->toContain('<title>Cleanser</title>')
        ->and($visible)->not->toContain('{sitname}')
        ->and($visible)->not->toMatch('/\{[A-Za-z]/');
});

it('cleans up a separator left dangling by a dropped token', function () {
    seoSettings(['seo_separator' => '|', 'seo_title_template' => '{unknown} {sep} {title} {sep} {nothing}']);

    $html = Seo::render(['title' => 'Ampoule']);

    expect($html)->toContain('<title>Ampoule</title>');
});

it('never prints a literal placeholder into the meta description', function () {
    seoSettings(['seo_default_description' => 'Shop at {sitename} {sep} {nosuchtoken}']);

    $html = Seo::render(['title' => 'Shop']);

    expect($html)->toContain('content="Shop at K-Beauty Bliss"')
        ->not->toContain('{nosuchtoken}');
});

/* ---------------------------------------------------------- canonical URL */

it('makes a root-relative canonical absolute', function () {
    $html = Seo::render(['title' => 'Shop', 'url' => '/shop/']);

    expect($html)->toContain('<link rel="canonical" href="https://kbeautybliss.test/shop/">')
        ->toContain('<meta property="og:url" content="https://kbeautybliss.test/shop/">');
});

it('leaves an already-absolute canonical alone', function () {
    $html = Seo::render(['title' => 'Shop', 'url' => 'https://cdn.example.com/shop/']);

    expect($html)->toContain('<link rel="canonical" href="https://cdn.example.com/shop/">');
});

it('does not repeat the base path when site_url already carries it', function () {
    // Production serves the app from /kbb-upgrade and APP_URL includes it,
    // while Url::to() also prefixes it.
    seoSettings(['site_url' => 'https://kbeautybliss.test/kbb-upgrade']);

    $html = Seo::render(['title' => 'Shop', 'url' => '/kbb-upgrade/shop/']);

    expect($html)->toContain('href="https://kbeautybliss.test/kbb-upgrade/shop/"')
        ->not->toContain('/kbb-upgrade/kbb-upgrade/');
});

it('collapses a base path a caller already doubled', function () {
    // ShopController/ProductController/PageController each build
    // site_url . $model->url(), and url() already carries the base path.
    seoSettings(['site_url' => 'https://kbeautybliss.test/kbb-upgrade']);

    $html = Seo::render([
        'title' => 'Serum',
        'url' => 'https://kbeautybliss.test/kbb-upgrade/kbb-upgrade/product/serum/',
    ]);

    expect($html)->toContain('href="https://kbeautybliss.test/kbb-upgrade/product/serum/"')
        ->not->toContain('/kbb-upgrade/kbb-upgrade/');
});

/* ----------------------------------------------------------------- escaping */

it('cannot be broken out of JSON-LD by a setting containing a script tag', function () {
    $payload = '</script><script>alert(1)</script>';

    seoSettings(['org_name' => $payload]);

    $html = Seo::render(['title' => 'Home']);

    // The block must still be one block: the only </script> closing an
    // ld+json element is the one this class wrote.
    preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);

    expect($m)->not->toBeEmpty()
        ->and($m[1])->not->toContain('</script>')
        ->and($m[1])->not->toContain('<script>')
        ->and($html)->not->toContain($payload);

    // ...and it is still valid JSON that decodes back to the original text.
    $node = json_decode($m[1], true);
    expect($node['name'])->toBe($payload);
});

it('cannot be broken out of an HTML attribute by a setting containing a quote', function () {
    $payload = '"><script>alert(1)</script>';

    seoSettings(['seo_site_name' => $payload, 'twitter_handle' => $payload]);

    $html = Seo::render(['title' => 'Home', 'description' => $payload]);

    expect($html)->not->toContain('"><script>')
        ->and($html)->not->toContain('<script>alert(1)</script>');
});

it('escapes a script payload arriving as a page title', function () {
    $html = Seo::render(['title' => '</title><script>alert(1)</script>', 'title_is_final' => true]);

    expect($html)->not->toContain('</title><script>')
        ->and($html)->not->toContain('<script>alert(1)');
});

/* ---------------------------------------------------------------- analytics */

it('emits gtag only for a well-formed measurement id', function () {
    seoSettings(['ga' => 'G-AB12CD34EF']);

    expect(Seo::render([]))->toContain("gtag('config','G-AB12CD34EF')");
});

it('refuses an analytics id that is not one', function () {
    // htmlspecialchars() is no defence inside a <script> body: the browser
    // HTML-decodes the element's contents before the JS parser sees them.
    foreach (["G-OK'));alert(1);//", '<script>alert(1)</script>', 'G-OK" onload="x'] as $bad) {
        seoSettings(['ga' => $bad]);

        $html = Seo::render([]);

        expect($html)->not->toContain('googletagmanager.com')
            ->and($html)->not->toContain('alert(1)');
    }
});

/* ------------------------------------------------- fresh writes are visible */

it('sees a setting written after the first read in the same process', function () {
    expect(Seo::render(['title' => 'X']))->toContain('K-Beauty Bliss');

    seoSettings(['seo_site_name' => 'Glow Lab']);

    // Setting::map() memoises in a process-level static and would still be
    // holding the old value here; the SEO reader goes to the cache entry.
    expect(Seo::render(['title' => 'X']))->toContain('Glow Lab');
});

/* --------------------------------------------------------- generated files */

it('writes absolute urls into the sitemap even with site_url blank', function () {
    seoSettings(['site_url' => '', 'sitemap_enabled' => '']);

    $xml = $this->get('/sitemap.xml')->assertOk()->getContent();

    expect($xml)->toContain('<loc>http')
        ->and($xml)->not->toMatch('#<loc>/#');
});

it('advertises an absolute sitemap url in robots.txt', function () {
    seoSettings(['site_url' => '', 'robots_txt' => '']);

    $body = $this->get('/robots.txt')->assertOk()->getContent();

    expect($body)->toMatch('#Sitemap: https?://#');
});

it('serves llms.txt when the toggle was saved blank', function () {
    seoSettings(['llms_enabled' => '', 'seo_default_description' => '']);

    $body = $this->get('/llms.txt')->assertOk()->getContent();

    expect($body)->toContain('# K-Beauty Bliss')
        ->and($body)->toContain('](http');
});

/*
 * Every storefront route is declared with a trailing slash and every internal
 * link carries one, but Url::to() trims it (UrlGenerator::format does). Left
 * alone, the page served at /korean-skincare-brands/ canonicalises to
 * /korean-skincare-brands -- pointing search engines one redirect away from the
 * page they are already on. The layout puts it back; this proves the SEO layer
 * does not strip it again on the way through.
 */
it('preserves a trailing slash on the canonical', function () {
    $html = App\Support\Seo::render([
        'title' => 'All brands',
        'url' => 'https://kbeautybliss.test/korean-skincare-brands/',
    ]);

    expect($html)->toContain('<link rel="canonical" href="https://kbeautybliss.test/korean-skincare-brands/">');
});

it('leaves a path that has no trailing slash alone', function () {
    $html = App\Support\Seo::render([
        'title' => 'Sitemap',
        'url' => 'https://kbeautybliss.test/robots.txt',
    ]);

    expect($html)->toContain('<link rel="canonical" href="https://kbeautybliss.test/robots.txt">');
});
