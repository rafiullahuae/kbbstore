<?php

declare(strict_types=1);

use App\Models\Brand;
use App\Services\MenuDemo;
use Illuminate\Support\Facades\DB;

/**
 * Lane EC — a brand link in the menu either filters the shop by that brand, or
 * is not published at all.
 *
 * ── WHAT WAS BROKEN, IN TWO ROUNDS ──────────────────────────────────────────
 *
 * MenuDemo::tree() publishes sixty-five brand leaves and tops up the MOBILE
 * DRAWER with them whenever Store → Modules → "Demo content" is on, so they
 * reach real shoppers.
 *
 * ROUND ONE (found and fixed by Lane DS). They pointed at /brand/{slug}/ while
 * Brand::url() and the seeded menu both use /shop/?filter_brands={slug} — one
 * brand published at two addresses — and BrandController@legacyShow does
 * firstOrFail(), so every brand the shop has not imported was a 404. Sixty of
 * the sixty-five, on a fresh install.
 *
 * ROUND TWO, which is what this file is about. Moving them onto the canonical
 * address fixed the SHAPE and left the SLUG derived from the LABEL:
 * MenuDemo::slug() was Str::slug(), so "Dr.Althea" became `dralthea` while the
 * brand's real slug — the one the seeded menu carries in an explicit map, and
 * the one a WordPress import brings in — is `dr-althea`.
 *
 * AND A BRAND SLUG THAT NAMES NO BRAND IS NOT A 404. ShopController::
 * applyFacets() applies the facet whenever it is non-empty, so
 * /shop/?filter_brands=dralthea answers 200 with an empty grid reading "No
 * products match those filters". Measured on the demo fixture before the fix:
 * /shop/ rendered 24 cards, ?filter_brands=cosrx rendered 3, and
 * ?filter_brands=dralthea rendered 0. A shopper who clicked Dr.Althea in the
 * menu was told this shop stocks nothing by Dr.Althea. What was actually true
 * is that the menu named a brand the shop does not carry — which is a fact
 * about the menu, stated to the shopper as a fact about the catalogue.
 *
 * ── WHAT THIS FILE PINS ─────────────────────────────────────────────────────
 *
 * Not "those nine labels are fixed" — that passes until somebody adds a tenth.
 * The property, read off the data:
 *
 *   EVERY /shop/?filter_brands= URL MenuDemo EMITS NAMES A ROW IN `brands`.
 *
 * Plus the two halves that stop it being satisfied trivially: the tree must
 * still emit brand leaves at all (a walk over nothing passes every assertion
 * anybody writes), and a label the shop has no brand for must be ABSENT rather
 * than published — checked by removing a brand and by adding one.
 *
 * ── WHAT IS DELIBERATELY NOT PINNED HERE ────────────────────────────────────
 *
 * The same assertion over the STORED `menu_items` rows, which would fail today
 * and should. Those rows are written once, by a migration or by the admin's
 * "load demo menu" button, with the live site's real brand slugs — `dr-althea`
 * and the rest. Resolving them against the `brands` table at WRITE time is the
 * mistake App\Support\LegacyCategoryUrls was written to explain: on a shop
 * where the import has not run it would drop or rewrite twelve of seventeen
 * brand items permanently, and the import arriving later would not bring them
 * back. MenuDemo can be resolved because it is REBUILT ON EVERY RENDER: a brand
 * that arrives tomorrow shows up by itself, with no migration and nobody
 * remembering to come back.
 */

/** Every URL in a MenuDemo-shaped nested tree, at any depth, in order. */
function brandMenuUrls(array $items): array
{
    $out = [];

    foreach ($items as $item) {
        $url = trim((string) ($item['url'] ?? ''));

        if ($url !== '') {
            $out[] = $url;
        }

        $out = array_merge($out, brandMenuUrls($item['children'] ?? []));
    }

    return $out;
}

/** The brand slugs a set of URLs filters the shop by. */
function brandMenuSlugs(array $urls): array
{
    $out = [];

    foreach ($urls as $url) {
        $query = parse_url($url, PHP_URL_QUERY);

        if (! is_string($query) || $query === '') {
            continue;
        }

        parse_str($query, $params);

        foreach (array_filter(array_map('trim', explode(',', (string) ($params['filter_brands'] ?? '')))) as $slug) {
            $out[] = $slug;
        }
    }

    return array_values(array_unique($out));
}

/*
|------------------------------------------------------------------------------
| 1. Every brand link the demo menu emits resolves to a real brand
|------------------------------------------------------------------------------
*/

it('emits no brand filter for a brand this shop does not carry', function () {
    $slugs = brandMenuSlugs(brandMenuUrls(MenuDemo::tree()));

    // The floor, first. Without it this file passes perfectly against a tree
    // that emits no brand links whatsoever, which is the failure mode the whole
    // change risks introducing.
    expect(count($slugs))->toBeGreaterThan(
        4,
        'MenuDemo emitted almost no brand links at all. The lookup in brandIndex() is '
        . 'matching nothing, so the drawer has quietly lost its Brands panel rather than '
        . 'been corrected.',
    );

    $known = DB::table('brands')->pluck('slug')->all();
    $orphans = array_values(array_diff($slugs, $known));

    expect($orphans)->toBe(
        [],
        'The demo menu filters the shop by brand slugs no `brands` row has. Each one '
        . 'answers 200 with an empty grid, so the shopper is told the shop stocks '
        . 'nothing by that brand: ' . implode(', ', $orphans),
    );
});

it('filters the shop for real behind every brand link it emits', function () {
    // The property end to end rather than against the table: follow each link
    // and check the page is a brand listing and not the whole shop.
    $slugs = brandMenuSlugs(brandMenuUrls(MenuDemo::tree()));

    expect(count($slugs))->toBeGreaterThan(4, 'MenuDemo emitted almost no brand links; this test is measuring nothing.');

    $unfiltered = $this->get('/shop/')->assertOk()->getContent();
    $everything = preg_match_all('#<div[^>]*\sclass="pc"[^>]*>#i', $unfiltered);

    expect($everything)->toBeGreaterThan(
        4,
        'The bare shop rendered almost no product cards, so "narrower than the whole shop" '
        . 'cannot be measured. The card selector is stale or the fixture is empty.',
    );

    $wrong = [];

    foreach ($slugs as $slug) {
        $html = $this->get('/shop/?filter_brands=' . $slug)->assertOk()->getContent();
        $cards = preg_match_all('#<div[^>]*\sclass="pc"[^>]*>#i', $html);

        // Counted with preg_match_all over the ELEMENTS: the storefront inlines
        // its stylesheet, so a substring search for `pc` also matches CSS text.
        if ($cards >= $everything) {
            $wrong[] = $slug . ' (' . $cards . ' of ' . $everything . ' cards — the filter did nothing)';
        }
    }

    expect($wrong)->toBe(
        [],
        'A brand link rendered the whole shop rather than that brand\'s listing: ' . implode(', ', $wrong),
    );
});

/*
|------------------------------------------------------------------------------
| 2. The slug is looked up, not transformed
|------------------------------------------------------------------------------
*/

it('publishes a brand at the slug the brands table gives it, not Str::slug of its label', function () {
    /*
     * The named regression, in the shape that produced it. "Dr.Althea" is one
     * of the seventeen Trending labels; Str::slug() makes `dralthea` of it and
     * the live site's slug is `dr-althea`. With the brand present, the menu must
     * publish the brand's own slug.
     */
    Brand::create(['name' => 'Dr.Althea', 'slug' => 'dr-althea', 'position' => 900]);

    $urls = brandMenuUrls(MenuDemo::tree());

    expect(in_array('/shop/?filter_brands=dr-althea', $urls, true))
        ->toBeTrue('An imported brand did not reach the demo menu at its own slug.');

    expect(in_array('/shop/?filter_brands=dralthea', $urls, true))
        ->toBeFalse('The demo menu is still deriving the brand slug from the label with Str::slug().');
});

it('matches a label to its brand through punctuation and spacing', function () {
    // "SKIN 1004" is the published label; DemoCatalogueSeeder spells the brand
    // "SKIN1004", slug `skin1004`. Neither the slug nor the name matches the
    // label character for character, and the shop does carry the brand, so
    // dropping the item would be the wrong answer too.
    $slugs = brandMenuSlugs(brandMenuUrls(MenuDemo::tree()));

    expect(in_array('skin1004', $slugs, true))
        ->toBeTrue('"SKIN 1004" did not find the SKIN1004 brand, so a brand the shop carries is missing from the menu.');
});

/*
|------------------------------------------------------------------------------
| 3. Absent brands are absent, not linked
|------------------------------------------------------------------------------
*/

it('drops a brand leaf when the brand is removed and restores it when one arrives', function () {
    $before = brandMenuSlugs(brandMenuUrls(MenuDemo::tree()));

    expect(in_array('cosrx', $before, true))->toBeTrue('The fixture has no COSRX brand; this test is measuring nothing.');

    DB::table('products')->where('brand_id', DB::table('brands')->where('slug', 'cosrx')->value('id'))
        ->update(['brand_id' => null]);
    DB::table('brands')->where('slug', 'cosrx')->delete();

    $after = brandMenuSlugs(brandMenuUrls(MenuDemo::tree()));

    expect(in_array('cosrx', $after, true))
        ->toBeFalse('The demo menu still links COSRX after the brand was deleted, so the tree is not resolved per render.');

    // And the leaf is gone rather than the whole panel: the other brands stay.
    expect(count($after))->toBeGreaterThan(
        3,
        'Removing one brand emptied the Brands panel; the lookup is failing wholesale rather than per label.',
    );

    Brand::create(['name' => 'COSRX', 'slug' => 'cosrx', 'position' => 901]);

    expect(in_array('cosrx', brandMenuSlugs(brandMenuUrls(MenuDemo::tree())), true))
        ->toBeTrue('A brand added back did not return to the menu on the next render.');
});

it('keeps the brand directory link on a panel whose leaves have all been dropped', function () {
    // With no brands at all, every leaf goes — and "Trending Brands" and "All
    // Brands" must still take the shopper to the directory rather than becoming
    // dead accordion headers.
    DB::table('products')->update(['brand_id' => null]);
    DB::table('brands')->delete();

    $tree = MenuDemo::tree();
    $urls = brandMenuUrls($tree);

    expect(brandMenuSlugs($urls))->toBe([], 'A brand filter survived the brands table being emptied.');

    expect(in_array('/korean-skincare-brands/', $urls, true))
        ->toBeTrue('The Brands node lost its own link to the directory.');

    // And the rest of the menu is untouched — the category leaves do not depend
    // on the brands table and must not disappear with it.
    expect(count($urls))->toBeGreaterThan(10, 'Emptying the brands table took the rest of the demo menu with it.');
});

/*
|------------------------------------------------------------------------------
| 4. The legacy per-brand address Google still holds
|------------------------------------------------------------------------------
*/

it('redirects the retired /brand/{slug}/ address to the canonical brand page', function () {
    // These URLs are in Google's index from the WordPress site. A brand the
    // shop carries must 301 to the address it lives at now, not 404.
    $response = $this->get('/brand/cosrx/');

    expect($response->getStatusCode())->toBe(301, '/brand/cosrx/ no longer redirects; an indexed URL has gone dead.');

    expect(str_ends_with((string) $response->headers->get('Location'), '/korean-skincare-brands/cosrx/'))
        ->toBeTrue('/brand/cosrx/ redirects somewhere other than the canonical brand page: '
            . $response->headers->get('Location'));

    // And the target is really there, so the 301 is not a hop to a 404.
    $this->get('/korean-skincare-brands/cosrx/')->assertOk();
});

it('answers 404 rather than a soft landing for a brand this shop does not carry', function () {
    /*
     * DELIBERATE, and the opposite of item 1's rule only in appearance. A menu
     * the application CONTROLS must not publish a link it cannot honour — that
     * is what the tests above are for. An inbound URL from somebody else's
     * index is a question, and "no such brand here" is the true answer to it.
     * Redirecting every unknown slug to the directory would make a soft 404:
     * Google treats a 200 that answers nothing worse than an honest 404, and
     * the shopper would land on an A–Z with no idea why.
     */
    $this->get('/brand/dr-althea/')->assertNotFound();
    $this->get('/brand/no-such-brand-at-all/')->assertNotFound();
});
