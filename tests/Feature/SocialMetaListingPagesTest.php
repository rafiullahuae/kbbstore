<?php

/**
 * The Open Graph card a brand page and a category archive actually publish.
 *
 * ── WHAT WAS WRONG
 *
 * ProductSeoTest covers the product page's share card in detail. Nothing
 * covered the two page types a shopper is just as likely to paste into a chat.
 *
 * BrandController::show() passed no $seoCtx at all, so layouts/store.blade.php
 * applied its defaults and every brand landing page on the site published one
 * identical card: the store-wide default description — the same sentence the
 * homepage and the cart publish — the store-wide default share image, and no
 * breadcrumb. The brand's own `description` and `logo` columns, both rendered
 * on the page itself, reached the <head> of nothing. Verified by fetching
 * /korean-skincare-brands/round-lab/ from a running preview before the change.
 *
 * ShopController passed a description, a canonical and a breadcrumb but no
 * image, so a category with its own banner photograph — the picture at the top
 * of the page a shopper is looking at as they copy the URL — shared as the
 * generic shop card.
 *
 * ── HOW IT IS ASSERTED
 *
 * Against the bytes of the fetched page, never against a context array. A
 * $seoCtx key with a typo in it, or a layout that stopped merging $seoCtx,
 * would satisfy an array assertion and publish nothing.
 *
 * The base-path case is pinned too, and it is the one a test on the default
 * path cannot see. Production serves this app from /kbb-upgrade: Url::to()
 * adds that prefix and the `site_url` setting already carries it, so a
 * canonical built by concatenating the two naively publishes
 * https://host/kbb-upgrade/kbb-upgrade/… — a URL that 404s, in a tag whose
 * whole job is to be the address of this page. Seo::canonical() absorbs the
 * one duplicate; the test proves the brand page's construction is a shape it
 * absorbs.
 */

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;

const SOCIAL_BASE = 'https://kbeautybliss.test';

function socialSettings(array $values): void
{
    foreach ($values as $key => $value) {
        Setting::updateOrCreate(['key' => $key], ['value' => (string) $value]);
    }

    Setting::flushMap();
}

beforeEach(function () {
    socialSettings([
        'site_url' => SOCIAL_BASE,
        'seo_site_name' => 'K-Beauty Bliss',
        'og_default_image' => '/wp-content/uploads/share-default.jpg',
    ]);
});

/** One `<meta>` value from the fetched document, or null. */
function socialMeta(string $html, string $key): ?string
{
    $pattern = '#<meta (?:property|name)="' . preg_quote($key, '#') . '" content="([^"]*)"#i';

    return preg_match($pattern, $html, $m) === 1
        ? html_entity_decode($m[1], ENT_QUOTES, 'UTF-8')
        : null;
}

function socialCanonical(string $html): ?string
{
    return preg_match('#<link rel="canonical" href="([^"]*)"#i', $html, $m) === 1 ? $m[1] : null;
}

/** The BreadcrumbList node, strictly decoded. */
function socialBreadcrumb(string $html): ?array
{
    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $matches);

    foreach ($matches[1] as $raw) {
        $node = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        if (($node['@type'] ?? null) === 'BreadcrumbList') {
            return $node;
        }
    }

    return null;
}

function socialBrand(array $attributes = []): Brand
{
    return Brand::create(array_merge([
        'slug' => 'social-round-lab',
        'name' => 'Round Lab',
    ], $attributes));
}

/* ─────────────────────────────── brand pages ────────────────────────────── */

it('gives a brand page the brand\'s own description, not the store\'s', function () {
    $brand = socialBrand([
        'description' => 'Round Lab bottles the mineral water of Dokdo into barrier-first suncare.',
    ]);

    $html = test()->get('/korean-skincare-brands/' . $brand->slug)->assertOk()->getContent();

    expect(socialMeta($html, 'og:description'))
        ->toBe('Round Lab bottles the mineral water of Dokdo into barrier-first suncare.');

    // og: and twitter: must not drift apart — they are two readers of one fact.
    expect(socialMeta($html, 'twitter:description'))
        ->toBe(socialMeta($html, 'og:description'));

    expect(socialMeta($html, 'description'))->toBe(socialMeta($html, 'og:description'));
});

it('falls back to the store description rather than inventing one for a brand with none', function () {
    socialSettings(['seo_default_description' => 'Shop Korean skincare in the UAE.']);

    $brand = socialBrand(['description' => null]);

    $html = test()->get('/korean-skincare-brands/' . $brand->slug)->assertOk()->getContent();

    // The honest answer for a brand the owner has not written about is the
    // store's own sentence, which is true of it — not a generated claim about
    // a catalogue this page never counted.
    expect(socialMeta($html, 'og:description'))->toBe('Shop Korean skincare in the UAE.');
});

it('shares a brand page with the brand\'s logo, absolute', function () {
    $brand = socialBrand(['logo' => '/wp-content/uploads/2024/03/round-lab.png']);

    $html = test()->get('/korean-skincare-brands/' . $brand->slug)->assertOk()->getContent();

    expect(socialMeta($html, 'og:image'))
        ->toBe(SOCIAL_BASE . '/wp-content/uploads/2024/03/round-lab.png');

    // A relative og:image is dropped silently by every scraper, which is the
    // whole reason Seo::absolute() exists.
    expect(socialMeta($html, 'og:image'))->toStartWith('https://');
    expect(socialMeta($html, 'twitter:image'))->toBe(socialMeta($html, 'og:image'));
    expect(socialMeta($html, 'twitter:card'))->toBe('summary_large_image');
});

it('prefers the banner photograph over the logo when the owner has turned one on', function () {
    $brand = socialBrand([
        'logo' => '/wp-content/uploads/round-lab.png',
        'banner' => [
            'enabled' => true,
            'style' => 'full',
            'image' => '/wp-content/uploads/round-lab-hero.jpg',
            'heading' => 'Round Lab',
        ],
    ]);

    $html = test()->get('/korean-skincare-brands/' . $brand->slug)->assertOk()->getContent();

    // The share card and the top of the page show the same picture.
    expect(socialMeta($html, 'og:image'))->toBe(SOCIAL_BASE . '/wp-content/uploads/round-lab-hero.jpg');
});

it('does not publish a banner image the page itself does not draw', function () {
    // `tint` never renders a photograph even with one stored, and PageBanner
    // drops the image for exactly that reason. Sharing it would put a picture
    // in the card that is nowhere on the page.
    $brand = socialBrand([
        'logo' => null,
        'banner' => [
            'enabled' => true,
            'style' => 'tint',
            'image' => '/wp-content/uploads/not-drawn.jpg',
            'heading' => 'Round Lab',
        ],
    ]);

    $html = test()->get('/korean-skincare-brands/' . $brand->slug)->assertOk()->getContent();

    expect(socialMeta($html, 'og:image'))->toBe(SOCIAL_BASE . '/wp-content/uploads/share-default.jpg');
});

it('falls back to the store share image for a brand with no picture of its own', function () {
    $brand = socialBrand(['logo' => null]);

    $html = test()->get('/korean-skincare-brands/' . $brand->slug)->assertOk()->getContent();

    expect(socialMeta($html, 'og:image'))->toBe(SOCIAL_BASE . '/wp-content/uploads/share-default.jpg');
});

it('canonicalises a brand page to itself, with a breadcrumb that resolves', function () {
    $brand = socialBrand();

    $html = test()->get('/korean-skincare-brands/' . $brand->slug)->assertOk()->getContent();

    $expected = SOCIAL_BASE . '/korean-skincare-brands/' . $brand->slug . '/';

    expect(socialCanonical($html))->toBe($expected);
    expect(socialMeta($html, 'og:url'))->toBe($expected);

    $crumb = socialBreadcrumb($html);

    expect($crumb)->not->toBeNull();
    expect($crumb['itemListElement'])->toHaveCount(3);
    expect(end($crumb['itemListElement'])['name'])->toBe('Round Lab');
    expect(end($crumb['itemListElement'])['item'])->toBe($expected);

    // Google resolves `item` as an identifier and rejects a relative one, so
    // every step must be absolute — the fault ProductSeoTest pinned on the
    // product page, which this trail must not reintroduce.
    foreach ($crumb['itemListElement'] as $step) {
        expect($step['item'])->toStartWith('https://');
    }
});

it('keeps the base path exactly once in everything it publishes', function () {
    // Production: the app is served from /kbb-upgrade and site_url carries it.
    config(['kbb.base_path' => 'kbb-upgrade']);
    \App\Support\Url::forgetBase();
    socialSettings(['site_url' => SOCIAL_BASE . '/kbb-upgrade']);

    $brand = socialBrand(['logo' => '/wp-content/uploads/round-lab.png']);

    $html = test()->get('/korean-skincare-brands/' . $brand->slug)->assertOk()->getContent();

    $expected = SOCIAL_BASE . '/kbb-upgrade/korean-skincare-brands/' . $brand->slug . '/';

    expect(socialCanonical($html))->toBe($expected);
    expect(socialMeta($html, 'og:url'))->toBe($expected);

    // Not /kbb-upgrade/kbb-upgrade/ anywhere in the document's own URLs.
    expect(socialCanonical($html))->not->toContain('kbb-upgrade/kbb-upgrade');
    expect(socialMeta($html, 'og:image'))->not->toContain('kbb-upgrade/kbb-upgrade');

    foreach (socialBreadcrumb($html)['itemListElement'] as $step) {
        expect($step['item'])->toStartWith(SOCIAL_BASE . '/kbb-upgrade/');
        expect($step['item'])->not->toContain('kbb-upgrade/kbb-upgrade');
    }
});

/* ───────────────────────────── category pages ───────────────────────────── */

it('shares a category with its own banner photograph', function () {
    $category = Category::create([
        'slug' => 'social-sunscreens',
        'name' => 'Sunscreens',
        'path' => 'social-sunscreens',
        'banner' => [
            'enabled' => true,
            'style' => 'full',
            'image' => '/wp-content/uploads/sunscreens-hero.jpg',
            'heading' => 'Sunscreens',
        ],
    ]);

    $product = Product::create([
        'slug' => 'social-spf', 'name' => 'Birch Juice Moisturizing Sun',
        'status' => 'publish', 'is_visible' => true, 'price' => 6900,
        'stock_status' => 'instock',
    ]);
    $product->categories()->syncWithoutDetaching([$category->id]);

    $html = test()->get('/product-category/' . $category->slug)->assertOk()->getContent();

    expect(socialMeta($html, 'og:image'))->toBe(SOCIAL_BASE . '/wp-content/uploads/sunscreens-hero.jpg');
    expect(socialMeta($html, 'twitter:image'))->toBe(socialMeta($html, 'og:image'));
});

it('leaves a listing with no banner on the store share image', function () {
    $html = test()->get('/shop')->assertOk()->getContent();

    expect(socialMeta($html, 'og:image'))->toBe(SOCIAL_BASE . '/wp-content/uploads/share-default.jpg');
});

/* ──────────────────────── nothing private gets out ──────────────────────── */

it('publishes nothing from the settings table that is not meant to be public', function () {
    // Everything on this list is reachable from the settings table that feeds
    // Seo::render(). None of it may reach a <head>, which is public forever.
    socialSettings([
        'admin_path' => 'kbb-secret-console',
        'indexnow_key' => 'aaaabbbbccccdddd',
        'smtp_password' => 'hunter2-smtp',
        'stripe_secret' => 'sk_live_notreal',
        'admin_email' => 'owner@kbeautybliss.test',
    ]);

    $brand = socialBrand(['logo' => '/wp-content/uploads/round-lab.png']);

    $pages = [
        '/korean-skincare-brands/' . $brand->slug,
        '/korean-skincare-brands',
        '/shop',
        '/',
    ];

    foreach ($pages as $path) {
        $head = explode('</head>', test()->get($path)->assertOk()->getContent(), 2)[0];

        foreach (['kbb-secret-console', 'aaaabbbbccccdddd', 'hunter2-smtp',
            'sk_live_notreal', 'owner@kbeautybliss.test'] as $secret) {
            expect($head)->not->toContain($secret, "{$secret} leaked into the <head> of {$path}");
        }
    }
});
