<?php

declare(strict_types=1);

/**
 * Category archives, brand landing pages and the four curated listings now say
 * what they are.
 *
 * ── THE DEFECT ──────────────────────────────────────────────────────────────
 *
 * Every listing on this storefront published the sitewide Organization node,
 * the sitewide WebSite node, a BreadcrumbList, and nothing else. `og:type` said
 * `website`, and no node in the document said the page was a list of products
 * or named one of them. That is the last open line of the plan's structured-data
 * item, and it applies to the highest-intent surface on the shop.
 *
 * ── WHAT IS ASSERTED, AND WHY IT IS ASSERTED ON A FETCHED PAGE ─────────────
 *
 * Every case below drives a real request through the full kernel and parses the
 * JSON-LD out of the rendered <head>. Not Seo::jsonLd() in isolation, and not a
 * regex over the controller: this lane's brief names both of those as the ways
 * this project has previously proved something that was not true. The canonical
 * and the ItemList have to agree, and they only exist together on a page.
 *
 * ── THE FOUR THINGS THAT CAN GO WRONG HERE ────────────────────────────────
 *
 * 1. THE PRICE. `products.price` is integer fils, AED × 100. A 126.00 AED serum
 *    is 12600 in the column, and an earlier pass at Product schema wired that
 *    column straight into the Offer -- caught in 2.60.36 before it shipped, and
 *    the plan records it. `it('publishes the price in dirhams, never the fils
 *    column')` is the guard. Two mutations were run against it and both make it
 *    red: casting the integer to a STRING under the `price` key (12600 arrives
 *    as an already-formatted price and is published verbatim), and passing the
 *    bare integer with `price_minor` dropped (Money::fromMajor multiplies it by
 *    a hundred again and publishes 12600.00).
 *
 *    A THIRD was run and does NOT go red, and it is recorded because it looks
 *    like it should: passing the bare integer under `price` while LEAVING
 *    `price_minor` in place. Seo::priceString() reads price_minor first, so the
 *    pair is genuinely redundant and the redundancy absorbs the mistake. That
 *    is a property of the design worth knowing about and not a hole in this
 *    guard -- the two mutations above cover the shapes that reach the wire.
 *
 * 2. THE SALE PRICE. A product on sale prints the sale price on the tile.
 *    Publishing the list price beside it is a mismatch Google reports against
 *    the merchant, so the schema reads effectivePrice() and the test sets up a
 *    sale to prove it. Mutating CollectionSchema to $product->price makes it
 *    red.
 *
 * 3. THE WRONG DOCUMENT. A filtered, sorted or searched view of an archive
 *    canonicalises to the CLEAN archive URL, deliberately. Hanging the rows
 *    that view drew off that canonical states that the clean archive contains
 *    them. The first cut of this work compared the canonical with the page's
 *    own URL to decide, which reports "self-canonical" for every page-one
 *    facet view because those two strings are equal there -- found against a
 *    running preview, fixed by asking Facets::narrowed() instead, and pinned by
 *    the three facet cases below.
 *
 * 4. PAGE TWO. A paginated archive keeps its own self-referencing canonical
 *    (Facets::canonicalUrl, and the plan's crawl-budget item). An ItemList
 *    whose positions restart at 1 on every page says the opposite -- that page
 *    two is the same list as page one -- so positions are the row's place in
 *    the whole listing and page two of a three-per-page archive publishes
 *    4, 5, 6.
 */

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use App\Services\SettingsService;
use App\Support\Locale;

const CS_BASE = 'http://localhost';

function csSettings(): void
{
    Setting::query()->updateOrCreate(['key' => 'site_url'], ['value' => CS_BASE, 'autoload' => true]);
    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
    \Illuminate\Support\Facades\Cache::flush();
    \App\Support\Facets::reset();
}

function csBrand(string $slug = 'cs-roundlab', string $name = 'Round Lab'): Brand
{
    return Brand::firstOrCreate(['slug' => $slug], ['name' => $name]);
}

function csCategory(): Category
{
    return Category::firstOrCreate(['slug' => 'cs-serums'], ['name' => 'Serums', 'path' => 'cs-serums']);
}

/**
 * A product in the category, priced in FILS because that is what the column is.
 *
 * @param  int  $price  integer minor units: 12600 is AED 126.00
 */
function csProduct(string $slug, string $name, int $price, array $extra = []): Product
{
    $product = Product::firstOrCreate(
        ['slug' => $slug],
        array_merge([
            'name' => $name,
            'brand_id' => csBrand()->id,
            'status' => 'publish',
            'is_visible' => true,
            'price' => $price,
            'stock_status' => 'instock',
            'type' => 'simple',
        ], $extra)
    );

    $product->forceFill(array_merge(['price' => $price], $extra))->save();
    $product->categories()->syncWithoutDetaching([csCategory()->id]);

    return $product;
}

/**
 * Every JSON-LD node in a rendered page, parsed.
 *
 * PARSED, not matched. A regex over a page reads the inlined CSS and the
 * JavaScript as well as the data, and this project has already shipped a guard
 * that matched a class name inside a <style> block. json_decode either produces
 * the node or the test fails on the spot.
 *
 * @return array<int, array<string, mixed>>
 */
function csNodes(string $html): array
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

/** The CollectionPage node, or null. */
function csCollection(string $uri): ?array
{
    $html = test()->get($uri)->assertOk()->getContent();

    foreach (csNodes($html) as $node) {
        if (($node['@type'] ?? null) === 'CollectionPage') {
            return $node;
        }
    }

    return null;
}

/** The canonical this page emits, for the cases that compare the two. */
function csCanonical(string $uri): string
{
    $html = test()->get($uri)->assertOk()->getContent();

    expect(preg_match('#<link rel="canonical" href="([^"]+)">#', $html, $m))->toBe(1);

    return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
}

beforeEach(function () {
    csSettings();
});

/* ───────────────────────────── the type itself ──────────────────────────── */

it('gives a category archive a CollectionPage rather than a plain web page', function () {
    csProduct('cs-snail-essence', 'Snail Essence', 9600);

    $node = csCollection('/product-category/cs-serums/');

    expect($node)->not->toBeNull('A category archive published no CollectionPage at all.');
    expect($node['name'])->toBe('Serums');
    expect($node['url'])->toBe(CS_BASE . '/product-category/cs-serums/');
});

it('names the listing rather than repeating the site name from the title', function () {
    csProduct('cs-snail-essence', 'Snail Essence', 9600);

    $html = test()->get('/product-category/cs-serums/')->assertOk()->getContent();

    expect(preg_match('#<title>(.*?)</title>#s', $html, $m))->toBe(1);

    $title = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');

    // The <title> is templated and carries the site name; the node's name is
    // the listing. If they are ever the same string the template has changed
    // and this deserves a second look rather than a silent pass.
    expect($title)->toContain('Serums');
    expect(csCollection('/product-category/cs-serums/')['name'])->toBe('Serums');
});

it('leaves og:type alone — a collection is still a website to Open Graph', function () {
    csProduct('cs-snail-essence', 'Snail Essence', 9600);

    $html = test()->get('/product-category/cs-serums/')->assertOk()->getContent();

    expect($html)->toContain('<meta property="og:type" content="website">');
});

/* ─────────────────────────────── the list ───────────────────────────────── */

it('lists the products actually on the page, in the order they appear', function () {
    csProduct('cs-a-toner', 'A Toner', 5000);
    csProduct('cs-b-essence', 'B Essence', 6000);
    csProduct('cs-c-serum', 'C Serum', 7000);

    $node = csCollection('/product-category/cs-serums/');
    $list = $node['mainEntity'];

    expect($list['@type'])->toBe('ItemList');
    expect($list['numberOfItems'])->toBe(3);

    $names = array_map(static fn ($e) => $e['item']['name'], $list['itemListElement']);
    $positions = array_column($list['itemListElement'], 'position');

    expect($positions)->toBe([1, 2, 3]);

    // The same order the grid draws, read off the page rather than assumed:
    // the tiles carry data-name, so the two orders are compared directly.
    $html = test()->get('/product-category/cs-serums/')->assertOk()->getContent();
    preg_match_all('#data-name="([^"]+)"#', $html, $m);

    $onPage = array_values(array_unique($m[1]));

    expect($names)->toBe(array_slice($onPage, 0, count($names)));
});

it('points each entry at the product page URL, absolute', function () {
    csProduct('cs-a-toner', 'A Toner', 5000);

    $item = csCollection('/product-category/cs-serums/')['mainEntity']['itemListElement'][0]['item'];

    expect($item['url'])->toBe(CS_BASE . '/product/cs-a-toner/');
    expect($item['offers']['url'])->toBe(CS_BASE . '/product/cs-a-toner/');
});

/* ─────────────────────────────── the price ──────────────────────────────── */

it('publishes the price in dirhams, never the fils column', function () {
    // 12600 fils is AED 126.00. The number that must never appear is 12600.
    csProduct('cs-relief-sun', 'Relief Sun', 12600);

    $node = csCollection('/product-category/cs-serums/');
    $offer = $node['mainEntity']['itemListElement'][0]['item']['offers'];

    expect($offer['price'])->toBe('126.00');
    expect($offer['priceCurrency'])->toBe('AED');

    // Not ->not->toContain(): Pest's toContain() is variadic, so a message
    // passed as a second argument becomes a second NEEDLE and the assertion
    // passes whatever the value is. Eight guards in this repository have been
    // caught asserting nothing that way.
    expect(str_contains(json_encode($node), '12600'))
        ->toBeFalse('The raw fils column reached the schema — a 126 AED serum is being advertised at 12,600.');
});

it('publishes the sale price a shopper is actually offered', function () {
    csProduct('cs-birch-sun', 'Birch Sun', 8600, [
        'sale_price' => 6020,
        'sale_starts_at' => now()->subDay(),
        'sale_ends_at' => now()->addDay(),
    ]);

    $offer = csCollection('/product-category/cs-serums/')['mainEntity']['itemListElement'][0]['item']['offers'];

    expect($offer['price'])->toBe('60.20');
});

it('agrees with the price the tile prints', function () {
    csProduct('cs-relief-sun', 'Relief Sun', 12600);

    $html = test()->get('/product-category/cs-serums/')->assertOk()->getContent();

    expect(preg_match('#data-price="([^"]+)"#', $html, $m))->toBe(1);

    $node = csCollection('/product-category/cs-serums/');

    expect($node['mainEntity']['itemListElement'][0]['item']['offers']['price'])->toBe($m[1]);
});

it('says out of stock when the product is', function () {
    csProduct('cs-gone', 'Gone', 5000, ['stock_status' => 'outofstock']);

    $offer = csCollection('/product-category/cs-serums/')['mainEntity']['itemListElement'][0]['item']['offers'];

    expect($offer['availability'])->toBe('https://schema.org/OutOfStock');
});

/* ────────────────── the wrong document: facets and search ───────────────── */

it('publishes no list on a sorted view, which canonicalises elsewhere', function () {
    csProduct('cs-a-toner', 'A Toner', 5000);
    csProduct('cs-b-essence', 'B Essence', 6000);

    $node = csCollection('/product-category/cs-serums/?orderby=price');

    expect($node)->not->toBeNull();
    expect($node['url'])->toBe(csCanonical('/product-category/cs-serums/?orderby=price'));
    expect(array_key_exists('mainEntity', $node))
        ->toBeFalse('A sorted view canonicalises to the clean archive and published its own rows as that archive\'s list.');
});

it('publishes no list on a brand-filtered view', function () {
    csProduct('cs-a-toner', 'A Toner', 5000);

    $node = csCollection('/shop/?filter_brands=cs-roundlab');

    expect($node)->not->toBeNull();
    expect(array_key_exists('mainEntity', $node))
        ->toBeFalse('A filtered shop view published its filtered rows as the whole shop\'s list.');
});

it('publishes no list on a search, whose results are not the shop', function () {
    csProduct('cs-a-toner', 'A Toner', 5000);

    $node = csCollection('/shop/?s=toner');

    expect($node)->not->toBeNull();
    expect(array_key_exists('mainEntity', $node))
        ->toBeFalse('A search result set was published as the shop\'s own product list.');
});

it('keeps the list on the clean archive, so the guard is not simply off', function () {
    csProduct('cs-a-toner', 'A Toner', 5000);

    $node = csCollection('/shop/');

    expect($node['mainEntity']['numberOfItems'])->toBeGreaterThan(0);
});

it('publishes no list when the owner has canonicalised the archive elsewhere', function () {
    csProduct('cs-a-toner', 'A Toner', 5000);

    $category = csCategory();
    $category->forceFill(['seo' => ['canonical' => CS_BASE . '/shop/']])->save();
    csSettings();

    $node = csCollection('/product-category/cs-serums/');

    expect($node['url'])->toBe(CS_BASE . '/shop/');
    expect(array_key_exists('mainEntity', $node))
        ->toBeFalse('The archive hung its own rows off the URL the owner canonicalised to.');
});

/* ──────────────────────────────── page two ─────────────────────────────── */

it('numbers page two by its place in the whole listing, not from one again', function () {
    Setting::query()->updateOrCreate(['key' => 'products_per_page'], ['value' => '3', 'autoload' => true]);
    csSettings();

    foreach (['a', 'b', 'c', 'd', 'e'] as $i => $letter) {
        csProduct('cs-p-' . $letter, strtoupper($letter) . ' Product', 5000 + $i * 100);
    }

    $one = csCollection('/product-category/cs-serums/');
    $two = csCollection('/product-category/cs-serums/?paged=2');

    expect(array_column($one['mainEntity']['itemListElement'], 'position'))->toBe([1, 2, 3]);
    expect(array_column($two['mainEntity']['itemListElement'], 'position'))->toBe([4, 5]);

    // Page two is its own document and its node says so.
    expect($two['url'])->toBe(CS_BASE . '/product-category/cs-serums/?paged=2');
    expect($two['url'])->toBe(csCanonical('/product-category/cs-serums/?paged=2'));

    // And the two lists name different products.
    $namesOne = array_map(static fn ($e) => $e['item']['name'], $one['mainEntity']['itemListElement']);
    $namesTwo = array_map(static fn ($e) => $e['item']['name'], $two['mainEntity']['itemListElement']);

    expect(array_intersect($namesOne, $namesTwo))->toBe([]);
});

/* ────────────────────── brands and curated collections ─────────────────── */

it('gives a brand landing page a CollectionPage naming that brand only', function () {
    $brand = csBrand('cs-cosrx', 'COSRX');

    csProduct('cs-mucin', 'Mucin Essence', 9600, ['brand_id' => $brand->id]);
    csProduct('cs-other', 'Other Brand Thing', 4000);

    $node = csCollection('/korean-skincare-brands/cs-cosrx/');

    expect($node)->not->toBeNull('A brand landing page published no CollectionPage.');
    expect($node['name'])->toBe('COSRX');
    expect($node['url'])->toBe(CS_BASE . '/korean-skincare-brands/cs-cosrx/');

    $names = array_map(static fn ($e) => $e['item']['name'], $node['mainEntity']['itemListElement']);

    expect($names)->toBe(['Mucin Essence']);
    expect($node['mainEntity']['itemListElement'][0]['item']['brand']['name'])->toBe('COSRX');
});

it('gives each curated listing a CollectionPage of its own products', function () {
    csProduct('cs-new-thing', 'New Thing', 7700);

    foreach ([
        '/new-in/' => 'New In',
        '/best-sellers/' => 'Best Sellers',
        '/super-sale/' => 'Super Sale',
        '/everything-under-54-aed/' => 'Everything under AED 54',
    ] as $uri => $name) {
        $node = csCollection($uri);

        expect($node)->not->toBeNull("{$uri} published no CollectionPage.");
        expect($node['name'])->toBe($name);
        expect($node['url'])->toBe(CS_BASE . $uri);
    }
});

it('numbers a curated listing page two by its place in the whole listing', function () {
    // CollectionController::PER_PAGE is 24, so page two needs 25 rows.
    for ($i = 0; $i < 26; $i++) {
        csProduct('cs-bulk-' . $i, 'Bulk ' . str_pad((string) $i, 2, '0', STR_PAD_LEFT), 5000 + $i);
    }

    $two = csCollection('/new-in/?page=2');

    expect($two['mainEntity']['itemListElement'][0]['position'])->toBe(25);
    expect($two['url'])->toBe(CS_BASE . '/new-in/?page=2');
});

/* ──────────────────────────────── Arabic ───────────────────────────────── */

it('keeps the Arabic archive and its list on Arabic addresses', function () {
    Setting::query()->updateOrCreate(['key' => Locale::SETTING_ENABLED], ['value' => '1', 'autoload' => true]);
    csSettings();

    csProduct('cs-a-toner', 'A Toner', 5000);

    $node = csCollection('/ar/product-category/cs-serums/');

    expect($node['url'])->toBe(CS_BASE . '/ar/product-category/cs-serums/');

    // The whole cluster, not just the page: an ItemList of English product URLs
    // on an Arabic archive is the same defect as an English canonical on an
    // Arabic page, which five page types shipped before 2.60.109.
    expect($node['mainEntity']['itemListElement'][0]['item']['url'])
        ->toBe(CS_BASE . '/ar/product/cs-a-toner/');
});

/* ────────────────────────── the deployment prefix ──────────────────────── */

it('does not double the deployment base path into every entry URL', function () {
    /*
     * The one case self::canonical() exists for and self::absolute() does not
     * cover, so this is what makes the choice of helper in Seo's collection
     * branch load-bearing rather than a preference.
     *
     * Every listing builds its rows as site_url . $product->url(), and
     * Product::url() goes through Url::to(), which already carries the
     * deployment prefix. On this host site_url ALSO carries it — the app is
     * served from easywebsol.com/kbb-upgrade — so the concatenation produces
     * /kbb-upgrade/kbb-upgrade/product/x/, which 404s. Seo::canonical()
     * collapses the duplicate; Seo::absolute() publishes it. The breadcrumb
     * trail carries the same note against the same mistake.
     */
    config(['kbb.base_path' => 'kbb-upgrade']);
    \App\Support\Url::forgetBase();

    csProduct('cs-a-toner', 'A Toner', 5000);

    // AFTER csSettings(), which writes site_url itself: site_url on this host
    // carries the deployment prefix too, and that is the whole point of the
    // case. Setting it before the helper runs leaves the helper's own value in
    // place and the test passes without ever building the doubled URL.
    Setting::query()->updateOrCreate(
        ['key' => 'site_url'],
        ['value' => 'http://localhost/kbb-upgrade', 'autoload' => true]
    );
    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
    \Illuminate\Support\Facades\Cache::flush();

    $node = csCollection('/product-category/cs-serums/');

    expect($node)->not->toBeNull();

    $url = $node['mainEntity']['itemListElement'][0]['item']['url'];

    expect($url)->toBe('http://localhost/kbb-upgrade/product/cs-a-toner/');
    expect(substr_count($url, '/kbb-upgrade/'))
        ->toBe(1, 'The deployment prefix was published twice in one entry URL.');
});

/* ───────────────────────── the admin's own preview ─────────────────────── */

it('shows a listing as a CollectionPage in the Schema Inspector too', function () {
    /*
     * Admin\SchemaInspectorApiController's header promises that "what's shown
     * here can never drift from what actually ships", and it went on reporting
     * `website` for a category after the archive started publishing
     * CollectionPage. An inspector that disagrees with the page is worse than
     * no inspector: it is the screen an owner checks BEFORE deciding the engine
     * is working.
     *
     * The one thing it deliberately does not reproduce is the product list --
     * that would be a second copy of ShopController's query -- so it says so in
     * its warnings rather than showing an empty list and letting the owner
     * conclude the page has none.
     */
    csProduct('cs-a-toner', 'A Toner', 5000);
    csCategory();

    $admin = \App\Models\AdminUser::create([
        'name' => 'CS Owner',
        'email' => 'cs-owner-' . uniqid() . '@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);

    $body = test()->actingAs($admin, 'admin')
        ->getJson('/admin-api/schema-inspect?type=category&slug=cs-serums')
        ->assertOk()
        ->json();

    $types = array_column($body['nodes'], '@type');

    expect($types)->toContain('CollectionPage');

    expect(implode(' ', $body['warnings']))->toContain('ItemList');
});

it('publishes exactly the image the tile draws', function () {
    /*
     * `products.image` is already a usable address — the card renders it as
     * src="{{ $product->image }}" with nothing applied — so the only correct
     * thing to publish is that string with the site root in front of it.
     *
     * The mutation this exists for is running it through Url::media(), which
     * prefixes the WordPress uploads root and turns a complete path into a 404
     * in the one field Google uses to draw the picture. Asserted against the
     * tile's own src rather than against a literal, so the schema and the page
     * cannot be right about different pictures.
     */
    /*
     * A path OUTSIDE /wp-content/uploads, and that is the whole point of the
     * fixture: Url::media() passes a path that already carries the uploads root
     * through untouched, so a case built on one would pass with the mutation
     * applied and prove nothing. The Media Library writes outside that root,
     * and this is the shape that breaks.
     */
    csProduct('cs-shot', 'Shot', 5000, ['image' => '/storage/media/2026/01/shot.jpg']);

    $html = test()->get('/product-category/cs-serums/')->assertOk()->getContent();

    expect(preg_match('#<img class="ph-img" src="([^"]+)"#', $html, $m))->toBe(1);

    $item = csCollection('/product-category/cs-serums/')['mainEntity']['itemListElement'][0]['item'];

    expect($item['image'])->toBe(CS_BASE . $m[1]);
});
