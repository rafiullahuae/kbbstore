<?php

declare(strict_types=1);

/**
 * The A–Z brand directory says what it is.
 *
 * ── THE DEFECT ──────────────────────────────────────────────────────────────
 *
 * Lane FQ gave CollectionPage/ItemList to the category archives, the brand
 * LANDING pages and the four curated listings, and deliberately left
 * BrandController::index() alone, because it lists brands rather than products
 * and passed no SEO context at all -- so giving it one changes its title and
 * its description defaults. Fetched against a running preview before the
 * change, the shop's brand index published:
 *
 *     <title>            All brands | KBB
 *     <meta description> Shop Korean skincare in the UAE - serums, creams,
 *                        moisturisers and beauty devices from Korean beauty
 *                        brands.
 *     JSON-LD            Organization, WebSite, and nothing else.
 *
 * The description is the store-wide default, which is the identical sentence
 * the homepage and the cart publish, and is about products this page does not
 * sell.
 *
 * ── WHY EVERY CASE FETCHES ─────────────────────────────────────────────────
 *
 * Each one drives a real request through the full kernel and parses the
 * JSON-LD out of the rendered <head>. Not Seo::jsonLd() in isolation and not a
 * regex over the controller: a regex over a source file reads that file's own
 * comments as code, and this lane's brief names calling the method directly as
 * the way five Arabic page types once "proved" a canonical they did not
 * publish. The title, the description and the list only exist together on a
 * page, and the first two are the things this change could have broken by
 * accident.
 */

use App\Models\Brand;
use App\Models\Product;
use App\Models\Setting;
use App\Services\SettingsService;

const BD_BASE = 'http://localhost';

function bdirSettings(): void
{
    Setting::query()->updateOrCreate(['key' => 'site_url'], ['value' => BD_BASE, 'autoload' => true]);
    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
    \Illuminate\Support\Facades\Cache::flush();
}

function bdirBrand(string $slug, string $name, array $extra = []): Brand
{
    $brand = Brand::firstOrCreate(['slug' => $slug], array_merge(['name' => $name], $extra));

    $brand->forceFill($extra)->save();

    return $brand;
}

/** A visible product, so the brand counts as stocked. */
function bdirProduct(Brand $brand): Product
{
    return Product::firstOrCreate(
        ['slug' => 'bd-p-'.$brand->slug],
        [
            'name' => $brand->name.' Serum',
            'brand_id' => $brand->id,
            'status' => 'publish',
            'is_visible' => true,
            'price' => 12600,
            'stock_status' => 'instock',
            'type' => 'simple',
        ]
    );
}

/**
 * Every JSON-LD node in a rendered page, parsed rather than matched. A regex
 * over a page reads the inlined CSS and JavaScript as data; json_decode either
 * produces the node or the test fails here.
 *
 * @return array<int, array<string, mixed>>
 */
function bdirNodes(string $html): array
{
    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);

    $out = [];

    foreach ($m[1] as $json) {
        $decoded = json_decode($json, true);

        expect($decoded)->toBeArray("A JSON-LD block on the page is not valid JSON: {$json}");

        $out[] = $decoded;
    }

    return $out;
}

/** The directory's CollectionPage node, or null. */
function bdirCollection(): ?array
{
    $html = test()->get('/korean-skincare-brands/')->assertOk()->getContent();

    foreach (bdirNodes($html) as $node) {
        if (($node['@type'] ?? null) === 'CollectionPage') {
            return $node;
        }
    }

    return null;
}

/**
 * One entry's `item` node, found by name.
 *
 * By name and not by index: the migration set seeds demo brands, so position 0
 * in this list is whichever of them sorts first and not the one the case just
 * created.
 *
 * @return array<string, mixed>
 */
function bdirEntryNamed(string $name): array
{
    foreach (bdirCollection()['mainEntity']['itemListElement'] as $entry) {
        if (($entry['item']['name'] ?? null) === $name) {
            return $entry['item'];
        }
    }

    throw new RuntimeException("No ItemList entry named {$name}.");
}

beforeEach(function () {
    bdirSettings();
});

it('publishes an ItemList of the brands the page draws', function () {
    bdirProduct(bdirBrand('bd-anua', 'Anua'));
    bdirProduct(bdirBrand('bd-cosrx', 'COSRX'));

    $node = bdirCollection();

    expect($node)->not->toBeNull('The brand directory publishes no CollectionPage node at all.');
    expect($node['mainEntity']['@type'])->toBe('ItemList');

    $names = array_column(array_column($node['mainEntity']['itemListElement'], 'item'), 'name');

    // Alphabetical, which is what orderBy('brands.name') produces and what an
    // A-Z directory promises. Positions therefore have to agree with it.
    expect($names)->toContain('Anua');
    expect($names)->toContain('COSRX');
    expect(array_search('Anua', $names, true))
        ->toBeLessThan(array_search('COSRX', $names, true));
});

it('names each entry as a Brand, not as a Product', function () {
    /*
     * A brand landing page has no price, no availability and no SKU. Published
     * as `Product` -- the type every other caller of this branch wants, and the
     * default -- each entry would be a product node missing every required
     * field, which is not a weaker rich result but an invalid one, ninety-odd
     * times on one page.
     *
     * MUTATION: delete the `schema_type` key from BrandDirectorySchema::from().
     * Seo falls back to 'Product' and this goes red on the first expectation.
     */
    bdirProduct(bdirBrand('bd-round', 'Round Lab'));

    $items = array_column(bdirCollection()['mainEntity']['itemListElement'], 'item');

    foreach ($items as $item) {
        expect($item['@type'])->toBe('Brand');
        expect($item)->not->toHaveKey('offers');
        expect($item)->not->toHaveKey('sku');
    }

    /*
     * A MUTATION THAT DOES NOT GO RED, recorded because it looks as though it
     * should. Removing the `$rowType === 'Product'` guard in front of
     * Seo::priceString() -- so a brand row is asked for an offer like any other
     * -- leaves every case here green, because a brand row carries neither
     * `price` nor `price_minor` and priceString() answers null on its own. The
     * guard is therefore belt to the data's braces: it is what keeps the
     * absence of an offer a decision rather than an accident of which keys
     * BrandDirectorySchema happens to emit today. Stated rather than tested
     * for, because the only way to make it fail would be to hand the builder a
     * price it has no business having.
     */
});

it('points each entry at the landing page, never at the filtered shop listing', function () {
    /*
     * URL Contract U-05 keeps Brand::url() pointing at
     * /shop/?filter_brands={slug}, which is the filterable listing and not this
     * tile's destination. BrandController::seoCtx() records at length that
     * canonicalising one to the other tells Google the landing page does not
     * exist.
     *
     * MUTATION: build the row url from $brand->url() instead. The assertion
     * below goes red on the query string.
     */
    bdirProduct(bdirBrand('bd-skin', 'SKIN1004'));

    $html = test()->get('/korean-skincare-brands/')->assertOk()->getContent();

    foreach (bdirNodes($html) as $node) {
        if (($node['@type'] ?? null) !== 'CollectionPage') {
            continue;
        }

        foreach ($node['mainEntity']['itemListElement'] as $entry) {
            $url = $entry['item']['url'];

            expect($url)->toStartWith(BD_BASE.'/korean-skincare-brands/');
            expect(str_contains($url, '?'))->toBeFalse("Entry url carries a query string: {$url}");
            // The href the page actually draws for the same brand.
            expect($html)->toContain('href="'.substr($url, strlen(BD_BASE)).'"');
        }
    }
});

it('keeps the title it was already serving', function () {
    /*
     * The reason FQ left this page alone: handing a page an $seoCtx changes
     * what layouts/store.blade.php would otherwise default to. The title was
     * already the right two words, so the context deliberately carries no
     * `title` and no `title_is_final`, and the page keeps "All brands | KBB"
     * byte for byte -- verified against a running preview before and after.
     *
     * MUTATION: add 'title' => 'Brands' to indexSeoCtx(). Red.
     */
    bdirProduct(bdirBrand('bd-iso', 'Isntree'));

    $html = test()->get('/korean-skincare-brands/')->assertOk()->getContent();

    expect(preg_match('#<title>(.*?)</title>#s', $html, $m))->toBe(1);
    expect(trim($m[1]))->toBe('All brands | KBB');
});

it('describes itself with its own sentence, not the store-wide default', function () {
    /*
     * The <meta description> is the paragraph under the <h1>, which is counted
     * from the same query the tiles are drawn from. Asserted against the
     * rendered paragraph rather than against a literal, so the tag and the page
     * cannot be right about different sentences -- the reason the string is
     * built once in the controller and passed to both.
     *
     * MUTATION: drop `'description' => $subtitle` from indexSeoCtx(). The page
     * falls back to the store-wide "Shop Korean skincare in the UAE ..." and
     * the comparison with the paragraph goes red.
     */
    bdirProduct(bdirBrand('bd-med', 'Medicube'));
    bdirBrand('bd-empty', 'Empty House');

    $html = test()->get('/korean-skincare-brands/')->assertOk()->getContent();

    expect(preg_match('#<p class="brw-sub">(.*?)</p>#s', $html, $paragraph))->toBe(1);
    expect(preg_match('#<meta name="description" content="([^"]*)">#', $html, $meta))->toBe(1);

    $sentence = html_entity_decode(trim($paragraph[1]), ENT_QUOTES | ENT_HTML5);

    expect(html_entity_decode($meta[1], ENT_QUOTES | ENT_HTML5))->toBe($sentence);

    /*
     * And it is the real count, not a fixed sentence. Counted from the
     * database rather than written as a literal, because the migration set
     * seeds demo brands and a hard-coded number would be a test about the
     * seeder. The empty brand is listed and is excluded from the stocked
     * figure, which is the behaviour the sentence is claiming.
     */
    $total = Brand::query()->count();
    $stocked = Brand::query()->whereHas('products', fn ($q) => $q->visible())->count();

    expect($total)->toBeGreaterThan($stocked);
    expect($sentence)->toContain($total.' brands');
    expect($sentence)->toContain($stocked.' with products');

    // The node agrees with the tag, and its name is the heading rather than the
    // templated title -- "All brands | KBB" would name the shop twice in one
    // document, once here and once in the Organization node beside it.
    $node = bdirCollection();
    expect($node['description'])->toBe($sentence);
    expect($node['name'])->toBe('All brands');
});

it('lists a brand with nothing in stock, because the page draws it', function () {
    /*
     * The directory lists an empty brand muted rather than hidden and says so
     * in its own subtitle. An ItemList that dropped it would disagree with the
     * visible page about what is on the visible page.
     */
    bdirProduct(bdirBrand('bd-full', 'Full House'));
    bdirBrand('bd-none', 'No Stock House');

    $names = array_column(array_column(bdirCollection()['mainEntity']['itemListElement'], 'item'), 'name');

    expect($names)->toContain('No Stock House');
});

it('publishes a logo only when the tiles actually draw one', function () {
    /*
     * `names` display mode draws no logo on any tile. Publishing one anyway
     * would describe a picture the document does not contain, which is the
     * mismatch this whole family of work exists to avoid.
     *
     * MUTATION: pass `true` for $withLogos unconditionally in indexSeoCtx().
     * The second half goes red.
     */
    bdirProduct(bdirBrand('bd-logo', 'Logo House', ['logo' => '/uploads/logo-house.png']));

    $entry = bdirEntryNamed('Logo House');
    expect($entry['logo'])->toBe(BD_BASE.'/uploads/logo-house.png');

    Setting::query()->updateOrCreate(['key' => 'brands_display'], ['value' => 'names', 'autoload' => true]);
    bdirSettings();

    expect(bdirEntryNamed('Logo House'))->not->toHaveKey('logo');
});

it('publishes the base path exactly once in every item url', function () {
    /*
     * THE LANDMINE THIS LANE WAS HANDED BY NAME: item URLs go through
     * Seo::canonical() so the base path is not published twice.
     *
     * `site_url` on the live host already carries /kbb-upgrade, and Url::to()
     * adds it again — so `$base . Url::to($path)` is a doubled prefix before
     * anything reconciles it. Seo's collection branch runs every row through
     * canonical(), which collapses the one duplicate. A test on the default
     * (empty) base path cannot see any of this, which is why this case sets it.
     *
     * MUTATION: write the builder's url as the bare literal
     * '/korean-skincare-brands/' . $slug . '/' with no Url::to(). Red — the
     * prefix disappears entirely and the published canonical 404s on the live
     * host. MUTATION 2: return $row['url'] unchanged instead of calling
     * self::canonical() in Seo's collection branch. Red — /kbb-upgrade appears
     * twice.
     */
    config(['kbb.base_path' => '/kbb-upgrade']);
    \App\Support\Url::forgetBase();

    /*
     * site_url is written AFTER bdirSettings(), not before it. That helper
     * writes site_url itself (to the bare BD_BASE) and then flushes, so setting
     * the prefixed value first and calling it second silently puts the bare one
     * back — which is how the first run of this case passed with the
     * Seo::canonical() call MUTATED OUT. With the bare base there is only one
     * prefix to begin with and nothing to collapse, so the case proved nothing.
     * Order matters here and the comment is the only thing that says so.
     */
    bdirSettings();

    Setting::query()->updateOrCreate(
        ['key' => 'site_url'],
        ['value' => BD_BASE.'/kbb-upgrade', 'autoload' => true]
    );
    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
    \Illuminate\Support\Facades\Cache::flush();

    try {
        bdirProduct(bdirBrand('bd-base', 'Base Path House'));

        $entry = bdirEntryNamed('Base Path House');

        expect($entry['url'])->toBe(BD_BASE.'/kbb-upgrade/korean-skincare-brands/bd-base/');
        expect(substr_count($entry['url'], '/kbb-upgrade'))->toBe(1);
    } finally {
        config(['kbb.base_path' => '']);
        \App\Support\Url::forgetBase();
    }
});

it('still carries the base path when site_url does not', function () {
    /*
     * THE OTHER HALF, and it needs its own case because the two configurations
     * catch DIFFERENT mistakes — found by running both mutations against both.
     *
     *   site_url ALREADY carries /kbb-upgrade (the case above, and the live
     *   host's own configuration). Url::to() adds a second one and
     *   Seo::canonical() collapses it. Removing canonical() is red here;
     *   writing the path as a bare literal is GREEN, because the base supplies
     *   the prefix on its own.
     *
     *   site_url carries only the host (this case — an owner who typed the
     *   domain without the subfolder, which the SEO screen accepts). Url::to()
     *   is now the ONLY thing supplying the prefix. Writing the path as a bare
     *   literal is red; removing canonical() is green, because there is no
     *   duplicate to collapse.
     *
     * One case cannot see both, so there are two. A canonical that 404s on the
     * live host is the class of bug a test on the default base path cannot see
     * at all, which is the reason either of them exists.
     */
    bdirSettings();

    config(['kbb.base_path' => '/kbb-upgrade']);
    \App\Support\Url::forgetBase();

    try {
        bdirProduct(bdirBrand('bd-base2', 'Half Base House'));

        expect(bdirEntryNamed('Half Base House')['url'])
            ->toBe(BD_BASE.'/kbb-upgrade/korean-skincare-brands/bd-base2/');
    } finally {
        config(['kbb.base_path' => '']);
        \App\Support\Url::forgetBase();
    }
});
