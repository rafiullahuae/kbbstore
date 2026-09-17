<?php

declare(strict_types=1);

/**
 * Lane DV — what this shop tells a search engine and a shared link.
 *
 * Every claim repaired in this phase so far was made in HTML a shopper reads,
 * on a screen somebody eventually looked at. This file is the other half of the
 * shop: the meta description, the Open Graph and Twitter cards, the JSON-LD,
 * /llms.txt and the sitemap. Nobody looks at any of it, the owner has no screen
 * that shows it to him, and it is the copy Google reprints in a result and
 * WhatsApp reprints in a preview card — so a false sentence there travels
 * further than the same sentence on the page.
 *
 * WHAT WAS BEING SAID. `seo_default_description` — the sentence every page
 * without one of its own emits three times over, and the sentence /llms.txt
 * quotes verbatim — read:
 *
 *   "Shop authentic Korean skincare in the UAE — serums, creams, moisturisers
 *    & beauty devices. 100% genuine, next-day delivery, glowing skin
 *    guaranteed."
 *
 * A delivery window App\Support\DeliveryLine contradicts in every country
 * including the UAE, a guarantee about a cosmetic outcome, and a fifth spelling
 * of the authenticity claim that 2.60.193 gave exactly one home. The migration
 * 2026_11_07_000000_honest_default_meta_description carries the reasoning on
 * each of the three.
 *
 * TWO TRAPS THIS FILE IS WRITTEN AROUND, both already paid for by other lanes.
 *
 *   A REGEX GUARD OVER PHP SOURCE READS COMMENTS AND QUOTED STRINGS AS CODE.
 *   Four lanes have now had a guard fail on its own explanatory prose — and the
 *   prose above would trip any search for "next-day delivery" in this very
 *   file. So NOTHING here scans source. Every assertion is made against bytes a
 *   crawler is actually served, and every banned phrase is ASSEMBLED AT RUN
 *   TIME from fragments (see dvBanned) so that this file does not contain the
 *   literals it forbids.
 *
 *   A CLASS-NAME SEARCH OF RENDERED HTML ALSO MATCHES THE PAGE'S INLINED CSS.
 *   Nothing here searches whole pages for words either: each case extracts the
 *   specific attribute, node or file it is about.
 */

use App\Models\Brand;
use App\Models\Category;
use App\Models\Page;
use App\Models\Product;
use App\Models\Review;
use App\Models\Setting;
use App\Services\SettingsService;
use App\Support\DemoReviews;
use App\Support\TrustClaims;
use App\Support\VatDisplay;
use Illuminate\Support\Facades\DB;

const DV_BASE = 'https://kbeautybliss.test';

function dvSettings(array $values = []): void
{
    foreach (array_merge(['site_url' => DV_BASE, 'seo_site_name' => 'K-Beauty Bliss'], $values) as $key => $value) {
        Setting::updateOrCreate(['key' => $key], ['value' => is_array($value) ? json_encode($value) : (string) $value]);
    }

    Setting::flushMap();
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
}

beforeEach(function () {
    dvSettings();
});

/**
 * The phrases no machine-facing string in this shop may carry, built from
 * fragments so this file never contains one whole.
 *
 * Each entry is [needle, what it costs]. The needles are lowercased and the
 * haystack is lowercased before the comparison, so a capitalised or
 * sentence-start spelling is caught too.
 *
 * @return list<array{0:string, 1:string}>
 */
function dvBanned(): array
{
    $next = 'next' . '-' . 'day';
    $nextSpaced = 'next' . ' ' . 'day';
    $guaranteed = 'guarantee';

    return [
        [$next . ' delivery', 'a delivery window DeliveryLine contradicts, in a string that cannot be per-country'],
        [$nextSpaced . ' delivery', 'the same window, spelled with a space'],
        ['glowing skin ' . $guaranteed, 'a guarantee about a cosmetic outcome, which nothing in this application can back'],
        ['skin ' . $guaranteed . 'd', 'a guarantee about a cosmetic outcome'],
    ];
}

/** Assert one machine-facing string carries none of them. */
function dvAssertHonest(?string $value, string $where): void
{
    if ($value === null || $value === '') {
        return;
    }

    $haystack = mb_strtolower($value);

    foreach (dvBanned() as [$needle, $cost]) {
        expect(str_contains($haystack, $needle))->toBeFalse(
            $where . ' says "' . $needle . '" — ' . $cost . '. Full text: ' . $value
        );
    }
}

/** One <meta> value from a page, decoded as a crawler reads it. */
function dvMeta(string $html, string $attr, string $key): ?string
{
    $pattern = '#<meta ' . $attr . '="' . preg_quote($key, '#') . '" content="(.*?)"#s';

    return preg_match($pattern, $html, $m) === 1
        ? html_entity_decode($m[1], ENT_QUOTES)
        : null;
}

/**
 * Every ld+json node on a page, decoded strictly — a block a crawler cannot
 * parse must fail rather than be skipped.
 *
 * @return list<array<string, mixed>>
 */
function dvJsonLd(string $html): array
{
    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);

    return array_map(
        fn (string $json) => json_decode($json, true, 512, JSON_THROW_ON_ERROR),
        $m[1]
    );
}

function dvNode(string $html, string $type): ?array
{
    foreach (dvJsonLd($html) as $node) {
        if (($node['@type'] ?? null) === $type) {
            return $node;
        }
    }

    return null;
}

/** A product that is really on the storefront. */
function dvProduct(array $attributes = []): Product
{
    $brand = Brand::firstOrCreate(['slug' => 'dv-anua'], ['name' => 'Anua']);
    $category = Category::firstOrCreate(
        ['slug' => 'dv-cleansers'],
        ['name' => 'Cleansers', 'path' => 'dv-cleansers']
    );

    $product = Product::create(array_merge([
        'slug' => 'dv-' . uniqid(),
        'name' => 'Heartleaf Pore Control Cleansing Oil',
        'sku' => 'DV-HL-200',
        'brand_id' => $brand->id,
        'status' => 'publish',
        'is_visible' => true,
        'price' => 9900,
        'stock_status' => 'instock',
        'image' => '/media/dv-main.jpg',
    ], $attributes));

    $product->categories()->syncWithoutDetaching([$category->id]);

    return $product;
}

/*
|------------------------------------------------------------------------------
| 1. The sentence itself
|------------------------------------------------------------------------------
*/

it('ships a default meta description that promises no delivery window and guarantees no outcome', function () {
    /*
     * Read back out of the settings table the migration set wrote, not out of
     * the migration file — what matters is the value a server ends up holding
     * after the package is applied, which is also what the owner sees in the
     * Default description box on Store → SEO & Meta.
     */
    $stored = (string) Setting::query()->where('key', 'seo_default_description')->value('value');

    expect($stored)->not->toBe('', 'the shop ships with no default description at all');

    dvAssertHonest($stored, 'The shipped default meta description');
});

it('makes no trust claim the owner could not take back', function () {
    /*
     * "100% genuine" was the FIFTH spelling of a claim that had just been given
     * a single owner-editable home, and the first fix for it was to COMPOSE
     * this sentence out of TrustClaims — quote the one box rather than spell
     * the claim a fifth way.
     *
     * TrustClaimsAreTheOwnersTest refused it, and was right. That file clears
     * `home_brands_note` and then asserts the wording appears nowhere on the
     * home page; with the claim copied into this setting it was still in the
     * page's own <head>, so emptying the box no longer removed the claim. A
     * settings row written once by a migration cannot follow the Claims tab
     * afterwards — it is a second home for the claim, which is the defect
     * TrustClaims exists to end.
     *
     * So the rule is the stronger one: the shipped default carries no trust
     * claim at all. It says what the shop sells. An owner who wants a claim in
     * his search result types it into the Default description box himself,
     * where he can see it and take it out again.
     *
     * Checked against every claim TrustClaims knows about rather than against
     * the one spelling that was here, so a claim added to CLAIMS later cannot
     * be copied in here without this failing.
     */
    $stored = mb_strtolower((string) Setting::query()->where('key', 'seo_default_description')->value('value'));

    foreach (TrustClaims::CLAIMS as $key => $wording) {
        expect(str_contains($stored, mb_strtolower($wording)))->toBeFalse(
            'The default meta description repeats the trust claim "' . $key . '". Clearing that claim on the '
            . 'Claims tab would no longer remove it from the page, because this copy of it cannot follow the box.'
        );
    }

    // And no sixth spelling of the authenticity claim either, written out
    // longhand instead of quoted. The fragment is assembled so this file does
    // not itself contain the banned spelling — the same discipline as
    // dvBanned().
    expect(str_contains($stored, '100' . '%'))->toBeFalse(
        'The default meta description states a percentage claim about the business. Stored: ' . $stored
    );
});

it('reads as a sentence rather than a truncated one', function () {
    // Removing a clause must not leave the description hollow or dangling: it
    // still says what the shop is and still ends in a full stop.
    $stored = trim((string) Setting::query()->where('key', 'seo_default_description')->value('value'));

    expect($stored)->toEndWith('.');
    expect($stored)->toContain('Korean skincare');
    // Long enough to describe the shop, short enough to survive the ~155
    // character desktop cutoff whole.
    expect(mb_strlen($stored))->toBeGreaterThan(60)->toBeLessThan(156);
    expect($stored)->not->toContain('  ');
});

/*
|------------------------------------------------------------------------------
| 2. Every surface that reprints it
|------------------------------------------------------------------------------
*/

it('promises no delivery window on any page that falls back to the sitewide description', function () {
    /*
     * The pages nobody thinks of as SEO pages, and they are most of the shop:
     * only ShopController, CollectionController and ProductController pass a
     * description of their own. Everything below gets the sitewide one, three
     * times over — description, og:description and twitter:description — which
     * is also the text a share card prints.
     */
    Page::updateOrCreate(
        ['slug' => 'delivery'],
        ['title' => 'Delivery', 'content' => '<p>How we ship.</p>', 'status' => 'published']
    );
    Page::updateOrCreate(
        ['slug' => 'about'],
        ['title' => 'About', 'content' => '<p>Who we are.</p>', 'status' => 'published']
    );
    dvProduct();

    foreach (['/', '/delivery/', '/about/', '/skin-quiz/', '/korean-skincare-brands/'] as $path) {
        $html = test()->get($path)->assertOk()->getContent();

        foreach ([['name', 'description'], ['property', 'og:description'], ['name', 'twitter:description']] as [$attr, $key]) {
            dvAssertHonest(dvMeta($html, $attr, $key), $path . ' ' . $key);
        }
    }
});

it('promises no delivery window in the structured data of a product with no description of its own', function () {
    /*
     * A product whose `short_description` is blank hands Seo no description, so
     * schema.org/Product.description became the sitewide sentence — the shop's
     * delivery promise and its outcome guarantee published as a description OF
     * A SPECIFIC PRODUCT, which is where Google reads a Product's own copy from.
     */
    $product = dvProduct(['short_description' => null]);

    $html = test()->get('/product/' . $product->slug)->assertOk()->getContent();
    $node = dvNode($html, 'Product');

    expect($node)->not->toBeNull();
    dvAssertHonest($node['description'] ?? null, 'schema.org Product.description');
});

it('promises no delivery window in llms.txt', function () {
    // The file an AI crawler reads, which quotes the sitewide description
    // verbatim to the audience least able to check it against the shop.
    dvSettings(['llms_enabled' => '1']);

    $body = test()->get('/llms.txt')->assertOk()->getContent();

    dvAssertHonest($body, '/llms.txt');
});

/*
|------------------------------------------------------------------------------
| 3. llms.txt names addresses that answer
|------------------------------------------------------------------------------
*/

it('lists no key page in llms.txt that the site then redirects or 404s', function () {
    /*
     * Both links this file offered were redirects: /blog 301s to
     * /skincare-guide/ and /shop canonicalises to /shop/. The same sweep
     * SeoCrawlSurfaceTest runs over the sitemap, applied to the other generated
     * file, which shares the sitemap's helper and sits forty lines from it.
     *
     * 3xx counts as a failure, not just 4xx: a "key page" list exists to name
     * where each page lives, and an entry that redirects names somewhere else.
     */
    dvSettings(['llms_enabled' => '1']);

    $body = test()->get('/llms.txt')->assertOk()->getContent();

    preg_match_all('#\]\((https?://[^)]+)\)#', $body, $links);

    expect(count($links[1]))->toBeGreaterThan(0, 'llms.txt offered no links at all, so this sweep proves nothing.');

    foreach ($links[1] as $link) {
        $path = parse_url($link, PHP_URL_PATH) ?: '/';
        $status = test()->call('GET', $path)->getStatusCode();

        expect($status)->toBe(200, 'llms.txt lists ' . $path . ' which returns ' . $status);
    }
});

/*
|------------------------------------------------------------------------------
| 4. The sitelinks searchbox has to search this shop
|------------------------------------------------------------------------------
*/

it('advertises a search URL that really filters the catalogue', function () {
    /*
     * The WebSite node published /shop?q={search_term_string} and nothing in
     * this application has ever read `q`. The shop's own box is
     * <form method="get" action="/shop/"> with <input name="s">, and
     * ShopController reads query('s') in all four places it asks — so a visitor
     * who typed into the search box Google draws under this shop's result was
     * sent to a URL that ignored the term and served the whole catalogue.
     *
     * Asserted by USING the template rather than by matching its text: the
     * term is substituted, the URL is fetched, and the page has to come back
     * filtered. A template that names the wrong parameter cannot pass that even
     * if somebody later changes which parameter is "correct".
     */
    dvProduct(['name' => 'Rice Toner Deluxe', 'slug' => 'dv-rice-toner']);
    dvProduct(['name' => 'Snail Mucin Essence', 'slug' => 'dv-snail-essence']);

    $home = test()->get('/')->assertOk()->getContent();
    $site = dvNode($home, 'WebSite');

    expect($site)->not->toBeNull();

    $template = $site['potentialAction']['target']['urlTemplate'] ?? '';
    expect($template)->toContain('{search_term_string}');

    $url = str_replace('{search_term_string}', 'Snail', $template);
    $path = parse_url($url, PHP_URL_PATH) ?: '/';
    $query = parse_url($url, PHP_URL_QUERY) ?: '';

    // The advertised address must answer directly. A 301 here is Google
    // spending a fetch to be sent elsewhere, the defect the sitemap's /shop/
    // entry was corrected for.
    $response = test()->call('GET', $path . ($query !== '' ? '?' . $query : ''));
    expect($response->getStatusCode())->toBe(200, $path . ' answered ' . $response->getStatusCode());

    $listing = $response->getContent();

    expect(str_contains($listing, 'Snail Mucin Essence'))->toBeTrue(
        'The advertised search URL did not return the product that matches the term.'
    );
    expect(str_contains($listing, 'Rice Toner Deluxe'))->toBeFalse(
        'The advertised search URL returned the whole catalogue: the term was dropped between Google and the shelf.'
    );
});

/*
|------------------------------------------------------------------------------
| 5. The Offer and the page have to agree about money
|------------------------------------------------------------------------------
*/

/** The money the product page actually prints, as an amount. */
function dvPrintedPrice(string $html): float
{
    /*
     * The price ELEMENT, extracted with an anchored regex rather than searched
     * for by class name — a bare class-name search of a rendered page also
     * matches the page's own inlined CSS, which is the trap two other lanes
     * recorded. Money::format() nests spans inside .now, so the whole price
     * block is taken and then flattened.
     */
    preg_match('#<div class="bb-price"[^>]*>(.*?)</div>#s', $html, $block);

    expect($block[1] ?? null)->not->toBeNull('no price block was found on the product page');

    $flat = html_entity_decode(strip_tags($block[1]), ENT_QUOTES);

    expect(preg_match('/\d[\d,]*(?:\.\d+)?/', $flat, $shown))->toBe(1, 'no price was printed: ' . $flat);

    return (float) str_replace(',', '', $shown[0]);
}

it('publishes the same money the product page prints', function () {
    // The shape the catalogue actually carries: WooCommerce runs the live shop
    // in whole dirhams and every price in storage/catalog/products.json is a
    // whole number of them.
    $product = dvProduct(['price' => 12300]);

    $html = test()->get('/product/' . $product->slug)->assertOk()->getContent();
    $offer = dvNode($html, 'Product')['offers'] ?? null;

    expect($offer)->not->toBeNull();
    expect((float) $offer['price'])->toBe(dvPrintedPrice($html));
});

it('publishes the exact price that is charged, not the rounded one that is printed', function () {
    /*
     * A GENUINE DISAGREEMENT, AND THE ONE PLACE IN THIS SWEEP WHERE THE PAGE
     * DOES NOT WIN.
     *
     * With no `currency_decimals` row the storefront prints whole dirhams while
     * still reading its stored integers as fils — Money::displayDecimals()
     * returns 0 and Money::minorExponent() returns 2, an asymmetry that class
     * documents as the live site's existing configuration rather than a default
     * anybody chose. So a product stored at 12,350 fils is PRINTED as 124 and
     * CHARGED as 123.50.
     *
     * The rule everywhere else here is that the markup follows the page. It
     * must not follow it into this: the figure in an Offer is the figure a
     * shopper is asked to pay, and rounding it to match a display setting would
     * publish a price this shop does not charge — to Google, to Merchant Center
     * and to every comparison service that reads the node. The exact amount
     * stays; the discrepancy is the STOREFRONT's to close by setting
     * `currency_decimals` to 2, which moves the display and the minor unit
     * together.
     *
     * Pinned rather than merely noted, because "make the markup match the page"
     * is exactly the correction a later reader would apply here by reflex.
     */
    $product = dvProduct(['price' => 12350]);

    $html = test()->get('/product/' . $product->slug)->assertOk()->getContent();
    $offer = dvNode($html, 'Product')['offers'];

    expect($offer['price'])->toBe('123.50');

    // And the page really is rounding, so this case describes something that
    // happens rather than guarding a hypothetical.
    expect(dvPrintedPrice($html))->toBe(124.0);
});

it('says whether the published price already contains the tax the buyer pays', function () {
    /*
     * The bare shelf price is right — it is the figure the page prints — but on
     * its own it does not say whether tax is inside it. Under an `exclusive`
     * rule it is not: the result shows AED 100 and the checkout charges 105.
     * `valueAddedTaxIncluded` on a UnitPriceSpecification is the field for it.
     *
     * The shipped mode is `display`, in which nothing is ever added to any
     * total, so the shelf price is final for every destination and the answer
     * is true. No tax arithmetic is asserted here and none is performed:
     * App\Support\TaxRule remains the single authority and this only reflects
     * what it already decides.
     */
    dvSettings(['vat_enabled' => '1', 'tax_mode' => VatDisplay::MODE_DISPLAY]);

    $product = dvProduct();
    $offer = dvNode(test()->get('/product/' . $product->slug)->assertOk()->getContent(), 'Product')['offers'];

    $spec = $offer['priceSpecification'] ?? null;

    expect($spec)->not->toBeNull();
    expect($spec['@type'])->toBe('UnitPriceSpecification');
    expect($spec['valueAddedTaxIncluded'])->toBeTrue();

    // The same two values the Offer carries, so the pair cannot disagree.
    expect($spec['price'])->toBe($offer['price']);
    expect($spec['priceCurrency'])->toBe($offer['priceCurrency']);
});

it('admits the price is ex-tax where the shop actually charges tax on top', function () {
    dvSettings([
        'vat_enabled' => '1',
        'tax_mode' => VatDisplay::MODE_LIVE,
        'vat_rate' => '5',
        'vat_basis' => 'exclusive',
    ]);

    $product = dvProduct();
    $offer = dvNode(test()->get('/product/' . $product->slug)->assertOk()->getContent(), 'Product')['offers'];

    expect($offer['priceSpecification']['valueAddedTaxIncluded'])->toBeFalse();
});

it('claims nothing about tax when the shop gives two answers', function () {
    /*
     * The same rule as the delivery window one line of reasoning up: a
     * structured-data Offer is one document per URL and cannot be per-country.
     * With the UAE inclusive and Saudi exclusive there is no sitewide answer,
     * so the field is not emitted. A missing recommended field costs a warning;
     * a wrong one costs a shopper who was shown a price the checkout exceeds.
     */
    dvSettings([
        'vat_enabled' => '1',
        'tax_mode' => VatDisplay::MODE_LIVE,
        'vat_rate' => '5',
        'vat_basis' => 'inclusive',
        'vat_country_rates' => ['SA' => '15'],
        'vat_country_bases' => ['SA' => 'exclusive'],
    ]);

    $product = dvProduct();
    $offer = dvNode(test()->get('/product/' . $product->slug)->assertOk()->getContent(), 'Product')['offers'];

    expect($offer)->not->toHaveKey('priceSpecification');
});

it('says nothing about a tax the shop does not charge', function () {
    dvSettings(['vat_enabled' => '0']);

    $product = dvProduct();
    $offer = dvNode(test()->get('/product/' . $product->slug)->assertOk()->getContent(), 'Product')['offers'];

    expect($offer)->not->toHaveKey('priceSpecification');
});

/*
|------------------------------------------------------------------------------
| 6. No star rating that a shopper cannot see
|------------------------------------------------------------------------------
*/

it('never builds an aggregateRating out of rows the seeder invented', function () {
    /*
     * 2.60.193 closed this through App\Support\DemoReviews, and ProductSeoTest
     * pins the DemoContent FIXTURE half of it. The DATABASE half is the one
     * that reaches this markup: demo reviews arrive by two separate routes —
     * DemoReviewsSeeder stamps `source = 'demo'`, and the admin panel's Seed
     * button records its rows in `demo_seed_log` — and a rating built from
     * either is a star rating in Google's results over a page that shows no
     * reviews at all. That pairing is the textbook trigger for a
     * structured-data manual action, and inventing customer reviews is unlawful
     * in the UAE, the EU and the UK regardless of who reads them.
     *
     * Checked here, from the markup side, because that is where the cost lands.
     */
    $product = dvProduct();

    Review::create([
        'product_id' => $product->id, 'author_name' => 'Aisha M.', 'rating' => 5,
        'content' => 'Invented.', 'status' => 'approved', 'source' => DemoReviews::SOURCE,
    ]);

    $logged = Review::create([
        'product_id' => $product->id, 'author_name' => 'Fatima K.', 'rating' => 5,
        'content' => 'Also invented.', 'status' => 'approved',
    ]);

    // The exact shape Admin\DemoContentController::log() writes: the table has
    // `type`, `model`, `record_id` and a single `created_at`, and no
    // updated_at.
    DB::table('demo_seed_log')->insert([
        'type' => 'reviews',
        'model' => Review::class,
        'record_id' => $logged->id,
        'created_at' => now(),
    ]);

    $html = test()->get('/product/' . $product->slug)->assertOk()->getContent();

    expect(dvNode($html, 'Product'))->not->toHaveKey('aggregateRating');

    // And the page shows neither of them, so the markup and the page agree
    // about a product with no reviews rather than agreeing to hide the same
    // fabrication from one audience.
    expect(str_contains($html, 'Aisha M.'))->toBeFalse();
    expect(str_contains($html, 'Fatima K.'))->toBeFalse();
});

it('publishes a rating only when the page shows the reviews behind it', function () {
    $product = dvProduct();

    Review::create([
        'product_id' => $product->id, 'author_name' => 'Real Buyer', 'rating' => 4,
        'content' => 'Genuinely bought this.', 'status' => 'approved',
    ]);

    $html = test()->get('/product/' . $product->slug)->assertOk()->getContent();
    $rating = dvNode($html, 'Product')['aggregateRating'] ?? null;

    expect($rating)->not->toBeNull();
    expect($rating['reviewCount'])->toBe(1);
    expect(str_contains($html, 'Real Buyer'))->toBeTrue(
        'The markup claims a review the page does not show.'
    );
});

/*
|------------------------------------------------------------------------------
| 7. Availability follows the page
|------------------------------------------------------------------------------
*/

it('never tells a search engine a sold-out product is in stock', function () {
    $product = dvProduct(['stock_status' => 'outofstock']);

    $html = test()->get('/product/' . $product->slug)->assertOk()->getContent();
    $offer = dvNode($html, 'Product')['offers'];

    expect($offer['availability'])->toBe('https://schema.org/OutOfStock');
    expect(dvMeta($html, 'property', 'product:availability'))->toBe('out of stock');
});
