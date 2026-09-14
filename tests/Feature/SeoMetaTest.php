<?php

/**
 * The storefront <head> against what the "SEO & Meta" admin screen saves.
 *
 * The screen wrote seo_title_template, seo_home_title, og_default_image and
 * the rest into the settings table, and the storefront read none of them: the
 * layout passed title_is_final on every page, which is the one flag that skips
 * the title template. These tests pin the wiring end to end, plus the two ways
 * it can go wrong once it exists — a blank setting producing an empty <title>
 * or a literal "{sitename}", and a setting escaping the attribute it sits in.
 *
 * The keys asserted here are the exact ones the admin screen posts (see the
 * save payload in resources/views/admin/app.blade.php and the $allowed list in
 * Admin\AdminController::updateSettings). They are not invented.
 */

use App\Models\Setting;
use App\Services\Seo\MetaBuilder;
use App\Services\Seo\SeoSettings;

/** Build the head data straight from a settings map, with no cache in the way. */
function seo(array $settings = [], array $ctx = []): array
{
    return (new MetaBuilder(new SeoSettings($settings)))->build($ctx);
}

/** The content of one meta tag, by name/property. */
function metaContent(array $head, string $key): ?string
{
    foreach ($head['meta'] as $tag) {
        if ($tag['key'] === $key) {
            return $tag['content'];
        }
    }

    return null;
}

// --------------------------------------------------------------- templating

it('substitutes the saved title template', function () {
    $head = seo([
        'seo_title_template' => '{title} {sep} {sitename}',
        'seo_separator' => '—',
        'seo_site_name' => 'K-Beauty Bliss',
    ], ['title' => 'Cart']);

    expect($head['title'])->toBe('Cart — K-Beauty Bliss');
});

it('honours a template that reorders or drops placeholders', function () {
    $head = seo([
        'seo_title_template' => '{sitename}: buy {title} online',
        'seo_site_name' => 'KBB',
    ], ['title' => 'Retinol']);

    expect($head['title'])->toBe('KBB: buy Retinol online');
});

it('accepts the Yoast-style placeholders the product editor writes', function () {
    $head = seo([
        'seo_title_template' => '%%title%% %%sep%% %%sitename%%',
        'seo_separator' => '|',
        'seo_site_name' => 'KBB',
    ], ['title' => 'Serum']);

    expect($head['title'])->toBe('Serum | KBB');
});

it('does not repeat a site name the page already appended', function () {
    // Every store view writes its own "· K-Beauty Bliss" suffix into
    // @section('title'); templating it again would double the site name.
    $head = seo([
        'seo_title_template' => '{title} {sep} {sitename}',
        'seo_separator' => '|',
        'seo_site_name' => 'K-Beauty Bliss',
    ], ['title' => 'Cart · K-Beauty Bliss']);

    expect($head['title'])->toBe('Cart | K-Beauty Bliss');
});

it('uses the homepage title setting on the homepage only', function () {
    $settings = ['seo_home_title' => 'Korean skincare, delivered in the UAE', 'seo_site_name' => 'KBB'];

    expect(seo($settings, ['type' => 'home', 'title' => 'ignored'])['title'])
        ->toBe('Korean skincare, delivered in the UAE');

    expect(seo($settings, ['type' => 'website', 'title' => 'Shop'])['title'])
        ->toBe('Shop | KBB');
});

// ----------------------------------------------------------- empty settings

it('falls back to a real title when the template setting is blank', function () {
    // The admin screen posts every field on every save, so an untouched field
    // is stored as '' — not absent. `?? $default` does not catch that.
    $head = seo([
        'seo_title_template' => '',
        'seo_separator' => '',
        'seo_site_name' => '',
        'store_name' => 'K-Beauty Bliss',
    ], ['title' => 'Cart']);

    expect($head['title'])->toBe('Cart | K-Beauty Bliss');
});

it('never renders an empty title', function () {
    foreach ([[], ['seo_title_template' => '   '], ['seo_home_title' => '']] as $settings) {
        expect(trim(seo($settings, ['title' => ''])['title']))->not->toBe('');
        expect(trim(seo($settings, ['type' => 'home', 'title' => ''])['title']))->not->toBe('');
    }
});

it('never renders a literal unreplaced placeholder', function () {
    $head = seo([
        'seo_title_template' => '{title} {sep} {sitname} {%%oops%%} {}',
        'seo_site_name' => 'KBB',
    ], ['title' => 'Cart']);

    expect($head['title'])
        ->not->toContain('{')
        ->not->toContain('}')
        ->not->toContain('%%')
        ->toContain('Cart');
});

it('drops a dangling separator when a placeholder resolves to nothing', function () {
    $head = seo([
        'seo_title_template' => '{page} {sep} {title} {sep} {sitename}',
        'seo_separator' => '|',
        'seo_site_name' => 'KBB',
    ], ['title' => 'Cart']);

    expect($head['title'])->toBe('Cart | KBB');
});

it('falls back to the default template when the saved one resolves to nothing', function () {
    $head = seo([
        'seo_title_template' => '{page}',
        'seo_separator' => '|',
        'seo_site_name' => 'KBB',
    ], ['title' => 'Cart']);

    expect($head['title'])->toBe('Cart | KBB');
});

// ------------------------------------------------- description / og / cards

it('uses the default description as a fallback and the home one at home', function () {
    $settings = [
        'seo_default_description' => 'Authentic K-beauty, shipped from Dubai.',
        'seo_home_description' => 'The homepage blurb.',
    ];

    expect(metaContent(seo($settings, ['title' => 'Shop']), 'description'))
        ->toBe('Authentic K-beauty, shipped from Dubai.');

    expect(metaContent(seo($settings, ['type' => 'home', 'title' => 'Home']), 'description'))
        ->toBe('The homepage blurb.');

    // A page's own description always wins.
    expect(metaContent(seo($settings, ['title' => 'X', 'description' => 'Page copy.']), 'description'))
        ->toBe('Page copy.');
});

it('omits the description tag entirely rather than emitting an empty one', function () {
    $head = seo(['seo_default_description' => '   '], ['title' => 'Shop']);

    expect(metaContent($head, 'description'))->toBeNull();
});

it('uses the default share image for Open Graph and Twitter', function () {
    $head = seo([
        'og_default_image' => 'https://cdn.example.com/og.jpg',
        'twitter_handle' => 'kbeautybliss',
    ], ['title' => 'Shop']);

    expect(metaContent($head, 'og:image'))->toBe('https://cdn.example.com/og.jpg')
        ->and(metaContent($head, 'twitter:image'))->toBe('https://cdn.example.com/og.jpg')
        ->and(metaContent($head, 'twitter:card'))->toBe('summary_large_image')
        ->and(metaContent($head, 'twitter:site'))->toBe('@kbeautybliss');
});

it('makes a relative share image and the canonical absolute', function () {
    $head = seo([
        'site_url' => 'https://kbeautybliss.com/',
        'og_default_image' => '/wp-content/uploads/og.jpg',
    ], ['title' => 'Shop', 'url' => '/shop/']);

    expect(metaContent($head, 'og:image'))->toBe('https://kbeautybliss.com/wp-content/uploads/og.jpg')
        ->and($head['canonical'])->toBe('https://kbeautybliss.com/shop/')
        ->and(metaContent($head, 'og:url'))->toBe('https://kbeautybliss.com/shop/');
});

it('falls back to a summary card with no image configured', function () {
    $head = seo([], ['title' => 'Shop']);

    expect(metaContent($head, 'twitter:card'))->toBe('summary')
        ->and(metaContent($head, 'og:image'))->toBeNull();
});

it('reads the robots settings and lets a page force noindex', function () {
    expect(metaContent(seo(['robots_index' => 'noindex', 'robots_follow' => 'nofollow'], []), 'robots'))
        ->toBe('noindex, nofollow');

    // A junk value must not reach the tag.
    expect(metaContent(seo(['robots_index' => 'sometimes'], []), 'robots'))->toBe('index, follow');

    expect(metaContent(seo([], ['noindex' => true]), 'robots'))->toBe('noindex, nofollow');
});

it('emits the verification tokens the screen saves', function () {
    $head = seo(['google_site_verification' => 'abc123', 'bing_site_verification' => 'def456']);

    expect(metaContent($head, 'google-site-verification'))->toBe('abc123')
        ->and(metaContent($head, 'msvalidate.01'))->toBe('def456');
});

it('only emits an analytics id that looks like one', function () {
    expect(seo(['ga' => 'G-ABCD1234'])['ga'])->toBe('G-ABCD1234');
    expect(seo(['ga' => "');alert(1);//"])['ga'])->toBeNull();
    expect(seo(['ga' => ''])['ga'])->toBeNull();
});

// ------------------------------------------------------------------ escaping

it('keeps a hostile setting as data, for Blade to escape', function () {
    $payload = '"><script>alert(1)</script>';

    // The builder deliberately does not escape: it hands back values, and the
    // layout prints every one of them through {{ }}.
    $head = seo([
        'seo_site_name' => $payload,
        'seo_default_description' => $payload,
        'og_default_image' => $payload,
        'twitter_handle' => $payload,
    ], ['title' => 'Shop']);

    expect(metaContent($head, 'og:site_name'))->toContain('<script>');

    // ...and Blade's {{ }} is what makes it inert on the page.
    expect(e(metaContent($head, 'og:site_name')))
        ->not->toContain('"><script>')
        ->toContain('&lt;script&gt;')
        ->toContain('&quot;');
});

it('escapes every setting that reaches the head of a rendered page', function () {
    $payload = '"><script>alert(1)</script>';

    $hostile = [
        'seo_site_name' => $payload,
        'seo_title_template' => '{title} {sep} {sitename}',
        'seo_default_description' => $payload,
        'og_default_image' => $payload,
        'twitter_handle' => $payload,
        'org_name' => '</script><script>alert(2)</script>',
        'site_url' => 'https://kbeautybliss.com',
    ];

    foreach ($hostile as $key => $value) {
        // updateOrCreate, not insert: a migration already seeds
        // seo_default_description.
        Setting::updateOrCreate(['key' => $key], ['value' => $value]);
    }

    Setting::flushMap();

    $html = $this->get('/cart')->getContent();
    $head = substr($html, 0, (int) (strpos($html, '</head>') ?: strlen($html)));

    expect($head)->toContain('<title>')
        ->and($head)->not->toContain('<script>alert(1)</script>')
        ->and($head)->not->toContain('</script><script>alert(2)</script>');

    // No meta content attribute was closed early.
    preg_match_all('/<meta [^>]*content="([^"]*)"/', $head, $m);
    foreach ($m[1] as $content) {
        expect($content)->not->toContain('<')->not->toContain('"');
    }

    // The JSON-LD block cannot close its own <script>.
    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $head, $ld);
    expect($ld[1])->not->toBeEmpty();
    foreach ($ld[1] as $json) {
        expect($json)->not->toContain('<')
            ->and(json_decode($json, true))->toBeArray();
    }
});

it('reads a setting written after the first read in the same process', function () {
    // Setting::map() memoises in a process-level static, so a value written
    // after its first call is invisible for the life of the process — which in
    // the test suite is every remaining test. The SEO reader deliberately does
    // not go through it.
    Setting::map();

    Setting::updateOrCreate(['key' => 'seo_title_template'], ['value' => 'FRESH {title}']);
    Setting::flushMap();

    expect((new MetaBuilder(new SeoSettings))->build(['title' => 'Cart'])['title'])
        ->toBe('FRESH Cart');
});
