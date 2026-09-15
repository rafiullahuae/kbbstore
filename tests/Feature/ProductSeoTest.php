<?php

/**
 * What Google actually receives for a product page.
 *
 * Every assertion here is made against the bytes a crawler is served: the page
 * is fetched over the HTTP stack, the <head> is parsed out of the response, the
 * ld+json blocks are strictly decoded, and the resulting graph is checked
 * against schema.org's shapes and Google's documented product requirements. An
 * assertion made against an array inside App\Support\Seo would pass while the
 * page emitted nothing at all — which is exactly how this engine sat fully
 * built and entirely disconnected for months (see SeoLayoutTest).
 *
 * The specific faults pinned here, each found by reading the emitted document:
 *
 *   1. BreadcrumbList carried ROOT-RELATIVE `item` values ("/shop/") on a page
 *      whose canonical was absolute. ProductController built the trail from
 *      Setting::map()['site_url'], and Setting::map() memoises in a
 *      process-level static, so it returned the map from before site_url was
 *      written. Google resolves `item` as an identifier and rejects a relative
 *      one.
 *
 *   2. priceValidUntil was FABRICATED. With no sale it was date('+1 year') — a
 *      claim the store cannot make. With a sale it was sale_ends_at whether or
 *      not the sale was still running, so a finished sale published a date in
 *      the past, and Google reads an elapsed priceValidUntil as an expired
 *      offer and drops the price from the rich result.
 *
 *   3. aggregateRating was built from the DEMO review fixture. ProductController
 *      replaces $summary with demo content when a product has no real reviews,
 *      and the seoCtx closure captured the replaced value — publishing a star
 *      average for reviews no visitor can find on the page.
 *
 *   4. Only the featured image was emitted, with a gallery sitting on the page.
 *
 *   5. 'onbackorder' was flattened into OutOfStock.
 *
 *   6. A noindexed product was still listed in sitemap.xml.
 */

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Review;
use App\Models\Setting;

const PSEO_BASE = 'https://kbeautybliss.test';

/** Settings written the way the admin screen writes them. */
function pseoSettings(array $values): void
{
    foreach ($values as $key => $value) {
        Setting::updateOrCreate(['key' => $key], ['value' => (string) $value]);
    }

    Setting::flushMap();
}

beforeEach(function () {
    pseoSettings([
        'site_url' => PSEO_BASE,
        'seo_site_name' => 'K-Beauty Bliss',
    ]);
});

/**
 * A product with everything a real one carries.
 *
 * @param  array<string, mixed>  $attributes
 */
function pseoProduct(array $attributes = [], bool $withCategory = true): Product
{
    $brand = Brand::firstOrCreate(['slug' => 'pseo-anua'], ['name' => 'Anua']);

    $product = Product::create(array_merge([
        'slug' => 'pseo-' . uniqid(),
        'name' => 'Heartleaf Pore Control Cleansing Oil 200ml',
        'sku' => 'ANUA-HL-200',
        'brand_id' => $brand->id,
        'status' => 'publish',
        'is_visible' => true,
        'price' => 9900,
        'stock_status' => 'instock',
        'short_description' => 'A gentle heartleaf cleansing oil that melts away sunscreen and makeup.',
        'image' => '/media/anua-main.jpg',
        'images' => ['/media/anua-main.jpg', '/media/anua-texture.jpg', '/media/anua-box.jpg'],
    ], $attributes));

    if ($withCategory) {
        $category = Category::firstOrCreate(
            ['slug' => 'pseo-cleansers'],
            ['name' => 'Cleansers', 'path' => 'skincare/pseo-cleansers']
        );
        $product->categories()->syncWithoutDetaching([$category->id]);
    }

    return $product;
}

/** The page a crawler is served, as a string. */
function pseoFetch(Product $product, string $query = ''): string
{
    return test()->get('/product/' . $product->slug . $query)->assertOk()->getContent();
}

/**
 * Every ld+json block on the page, strictly decoded.
 *
 * STRICT on purpose: JSON_THROW_ON_ERROR means a block that is not valid JSON
 * fails the test rather than being silently skipped, and a crawler that cannot
 * parse the block ignores the whole thing. html_entity_decode is NOT applied —
 * Seo::encodeJsonLd escapes <, >, & and quotes as \uXXXX, which is valid JSON
 * and decodes natively.
 *
 * @return list<array<string, mixed>>
 */
function pseoJsonLd(string $html): array
{
    preg_match_all(
        '#<script type="application/ld\+json">(.*?)</script>#s',
        $html,
        $matches
    );

    $nodes = [];

    foreach ($matches[1] as $raw) {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        expect($decoded)->toBeArray();

        $nodes[] = $decoded;
    }

    return $nodes;
}

/** The one node of a given @type, or null. */
function pseoNode(string $html, string $type): ?array
{
    foreach (pseoJsonLd($html) as $node) {
        if (($node['@type'] ?? null) === $type) {
            return $node;
        }
    }

    return null;
}

/** The <head> only, so a body mention can never satisfy a head assertion. */
function pseoHead(string $html): string
{
    preg_match('#<head>(.*?)</head>#s', $html, $m);

    return $m[1] ?? '';
}

/** The content of a meta tag in the head, by name= or property=. */
function pseoMeta(string $html, string $key): ?string
{
    $head = pseoHead($html);
    $pattern = '#<meta (?:name|property)="' . preg_quote($key, '#') . '" content="([^"]*)"#';

    return preg_match($pattern, $head, $m) ? html_entity_decode($m[1], ENT_QUOTES, 'UTF-8') : null;
}

/* ─────────────────────── schema.org / Google validation ─────────────────── */

/** schema.org ItemAvailability values this store can legitimately emit. */
const PSEO_AVAILABILITY = [
    'https://schema.org/InStock',
    'https://schema.org/OutOfStock',
    'https://schema.org/BackOrder',
    'https://schema.org/PreOrder',
    'https://schema.org/SoldOut',
];

const PSEO_CONDITION = [
    'https://schema.org/NewCondition',
    'https://schema.org/UsedCondition',
    'https://schema.org/RefurbishedCondition',
    'https://schema.org/DamagedCondition',
];

/**
 * Assert a Product node satisfies schema.org's shapes and Google's product
 * requirements. Everything checked here is a rule from the published spec, not
 * a restatement of what the builder happens to do.
 */
function pseoAssertValidProduct(array $node): void
{
    // schema.org: a node needs a context and a type.
    expect($node['@context'] ?? null)->toBe('https://schema.org');
    expect($node['@type'] ?? null)->toBe('Product');

    // Google: `name` is the one required Product property.
    expect($node['name'] ?? '')->toBeString()->not->toBe('');

    // image: every entry absolute. Google rejects a relative image outright.
    $images = (array) ($node['image'] ?? []);
    expect($images)->not->toBeEmpty();

    foreach ($images as $image) {
        expect($image)->toBeString();
        expect(str_starts_with($image, 'https://') || str_starts_with($image, 'http://'))
            ->toBeTrue('image must be absolute, got: ' . $image);
    }

    // brand is a Brand/Organization node, never a bare string.
    if (isset($node['brand'])) {
        expect($node['brand'])->toBeArray();
        expect($node['brand']['@type'] ?? null)->toBeIn(['Brand', 'Organization']);
        expect($node['brand']['name'] ?? '')->not->toBe('');
    }

    if (isset($node['url'])) {
        expect(str_starts_with($node['url'], 'https://'))->toBeTrue();
    }

    if (isset($node['offers'])) {
        pseoAssertValidOffer($node['offers']);
    }

    if (isset($node['aggregateRating'])) {
        pseoAssertValidAggregateRating($node['aggregateRating']);
    }
}

function pseoAssertValidOffer(array $offer): void
{
    expect($offer['@type'] ?? null)->toBe('Offer');

    // Google: price and priceCurrency are required on the Offer, and price must
    // be a plain number — no symbol, no thousands separator.
    expect($offer['price'] ?? null)->toBeString();
    expect($offer['price'])->toMatch('/^-?\d+(\.\d+)?$/');

    expect($offer['priceCurrency'] ?? null)->toMatch('/^[A-Z]{3}$/');

    // availability must be a real schema.org ItemAvailability.
    expect($offer['availability'] ?? null)->toBeIn(PSEO_AVAILABILITY);

    if (isset($offer['itemCondition'])) {
        expect($offer['itemCondition'])->toBeIn(PSEO_CONDITION);
    }

    // priceValidUntil, where present, must be ISO-8601 AND still in the future.
    // An elapsed date is read as an expired offer.
    if (isset($offer['priceValidUntil'])) {
        expect($offer['priceValidUntil'])->toMatch('/^\d{4}-\d{2}-\d{2}$/');
        expect($offer['priceValidUntil'] >= date('Y-m-d'))
            ->toBeTrue('priceValidUntil is in the past: ' . $offer['priceValidUntil']);
    }

    if (isset($offer['shippingDetails'])) {
        $s = $offer['shippingDetails'];
        expect($s['@type'] ?? null)->toBe('OfferShippingDetails');
        expect($s['shippingRate']['@type'] ?? null)->toBe('MonetaryAmount');
        // The rate is money: an exact decimal string, never a bare float cast.
        expect($s['shippingRate']['value'] ?? null)->toMatch('/^-?\d+(\.\d+)?$/');
        expect($s['shippingRate']['currency'] ?? null)->toMatch('/^[A-Z]{3}$/');
        expect($s['shippingDestination']['addressCountry'] ?? null)->toMatch('/^[A-Z]{2}$/');
    }

    if (isset($offer['hasMerchantReturnPolicy'])) {
        $r = $offer['hasMerchantReturnPolicy'];
        expect($r['@type'] ?? null)->toBe('MerchantReturnPolicy');
        expect($r['applicableCountry'] ?? null)->toMatch('/^[A-Z]{2}$/');
        expect($r['returnPolicyCategory'] ?? null)->toStartWith('https://schema.org/');

        if (($r['returnPolicyCategory'] ?? '') === 'https://schema.org/MerchantReturnFiniteReturnWindow') {
            expect($r['merchantReturnDays'] ?? null)->toBeInt()->toBeGreaterThan(0);
        }
    }
}

function pseoAssertValidAggregateRating(array $rating): void
{
    expect($rating['@type'] ?? null)->toBe('AggregateRating');
    expect($rating['ratingValue'] ?? null)->toMatch('/^\d+(\.\d+)?$/');

    // Google: ratingValue plus at least one of reviewCount / ratingCount.
    expect(isset($rating['reviewCount']) || isset($rating['ratingCount']))->toBeTrue();

    $best = (float) ($rating['bestRating'] ?? 5);
    $worst = (float) ($rating['worstRating'] ?? 1);
    $value = (float) $rating['ratingValue'];

    expect($value)->toBeGreaterThanOrEqual($worst)->toBeLessThanOrEqual($best);

    if (isset($rating['reviewCount'])) {
        expect($rating['reviewCount'])->toBeInt()->toBeGreaterThan(0);
    }
}

function pseoAssertValidBreadcrumb(array $node): void
{
    expect($node['@type'] ?? null)->toBe('BreadcrumbList');

    $items = $node['itemListElement'] ?? [];
    expect($items)->toBeArray()->not->toBeEmpty();

    foreach ($items as $i => $item) {
        expect($item['@type'] ?? null)->toBe('ListItem');
        // position must be 1-based and contiguous.
        expect($item['position'] ?? null)->toBe($i + 1);
        expect($item['name'] ?? '')->not->toBe('');
        expect($item['item'] ?? null)->toBeString();
        expect(str_starts_with($item['item'], 'https://'))
            ->toBeTrue('breadcrumb item must be an absolute URL, got: ' . ($item['item'] ?? 'null'));
    }
}

/* ──────────────────────────── the whole document ────────────────────────── */

it('emits a Product graph that satisfies schema.org and Google', function () {
    $product = pseoProduct();
    Review::create(['product_id' => $product->id, 'author_name' => 'Sara', 'rating' => 5, 'content' => 'Lovely', 'status' => 'approved']);
    Review::create(['product_id' => $product->id, 'author_name' => 'Mona', 'rating' => 4, 'content' => 'Good', 'status' => 'approved']);

    $html = pseoFetch($product);

    pseoAssertValidProduct(pseoNode($html, 'Product'));
    pseoAssertValidBreadcrumb(pseoNode($html, 'BreadcrumbList'));
});

it('emits exactly one of each singular head tag', function () {
    $html = pseoFetch(pseoProduct());
    $head = pseoHead($html);

    expect(substr_count($head, '<title>'))->toBe(1);
    expect(substr_count($head, '<meta name="description"'))->toBe(1);
    expect(substr_count($head, 'rel="canonical"'))->toBe(1);
    expect(substr_count($head, 'property="og:type"'))->toBe(1);
    expect(substr_count($head, 'name="robots"'))->toBe(1);
});

it('declares og:type product with the price properties that make it mean something', function () {
    $html = pseoFetch(pseoProduct());

    expect(pseoMeta($html, 'og:type'))->toBe('product');
    expect(pseoMeta($html, 'product:price:amount'))->toBe('99.00');
    expect(pseoMeta($html, 'product:price:currency'))->toBe('AED');
    expect(pseoMeta($html, 'product:availability'))->toBe('in stock');
});

it('matches twitter:card to whether an image actually exists', function () {
    $withImage = pseoFetch(pseoProduct());
    expect(pseoMeta($withImage, 'twitter:card'))->toBe('summary_large_image');
    expect(pseoMeta($withImage, 'twitter:image'))->toStartWith('https://');

    $withoutImage = pseoFetch(pseoProduct(['image' => null, 'images' => []]));
    expect(pseoMeta($withoutImage, 'twitter:card'))->toBe('summary');
});

/* ───────────────────────────────── images ───────────────────────────────── */

it('gives Google the whole gallery, absolute and de-duplicated', function () {
    $html = pseoFetch(pseoProduct());
    $node = pseoNode($html, 'Product');

    expect($node['image'])->toBe([
        PSEO_BASE . '/media/anua-main.jpg',
        PSEO_BASE . '/media/anua-texture.jpg',
        PSEO_BASE . '/media/anua-box.jpg',
    ]);
});

it('emits a bare string when a product genuinely has one image', function () {
    $html = pseoFetch(pseoProduct(['images' => []]));

    expect(pseoNode($html, 'Product')['image'])->toBe(PSEO_BASE . '/media/anua-main.jpg');
});

it('never repeats the featured image when it also heads the gallery', function () {
    $html = pseoFetch(pseoProduct([
        'image' => '/media/same.jpg',
        'images' => ['/media/same.jpg', '/media/other.jpg'],
    ]));

    expect(pseoNode($html, 'Product')['image'])->toBe([
        PSEO_BASE . '/media/same.jpg',
        PSEO_BASE . '/media/other.jpg',
    ]);
});

/* ───────────────────────────── price and offer ──────────────────────────── */

it('states the price exactly, from the integer fils', function () {
    $html = pseoFetch(pseoProduct(['price' => 12345]));

    expect(pseoNode($html, 'Product')['offers']['price'])->toBe('123.45');
});

it('carries the full precision of a three-decimal currency', function () {
    // The reason the price may not travel through a float or through
    // number_format(..., 2): Money's own exponent is a setting, and at three
    // decimals (KWD, OMR — both currencies this store's Currencies table
    // carries) a two-decimal format silently drops a digit, so the price a
    // crawler compares against the page is a tenth of a unit out.
    //
    // This is also the case that distinguishes the exact integer path from the
    // old float one at all: at two decimals the two agree on every value, so a
    // test that only ever looks at AED cannot tell them apart.
    pseoSettings(['currency' => 'KWD', 'currency_decimals' => '3']);
    \App\Support\Money::forgetConfig();

    // 9900 minor units at exponent 3 is 9.900, not 9.90.
    $html = pseoFetch(pseoProduct(['price' => 9900]));
    $offer = pseoNode($html, 'Product')['offers'];

    expect($offer['priceCurrency'])->toBe('KWD');
    expect($offer['price'])->toBe('9.900');
    expect(pseoMeta($html, 'product:price:amount'))->toBe('9.900');
});

it('uses the configured currency rather than a hardcoded AED', function () {
    pseoSettings(['currency' => 'SAR']);
    \App\Support\Money::forgetConfig();

    $html = pseoFetch(pseoProduct());

    expect(pseoNode($html, 'Product')['offers']['priceCurrency'])->toBe('SAR');
    expect(pseoMeta($html, 'product:price:currency'))->toBe('SAR');
});

it('reports a live sale as the date the price stops applying', function () {
    $ends = now()->addDays(10);
    $html = pseoFetch(pseoProduct([
        'price' => 9900,
        'sale_price' => 7900,
        'sale_ends_at' => $ends,
    ]));

    $offer = pseoNode($html, 'Product')['offers'];

    expect($offer['price'])->toBe('79.00');
    expect($offer['priceValidUntil'])->toBe($ends->toDateString());
});

it('omits priceValidUntil entirely when no sale window says when the price ends', function () {
    $html = pseoFetch(pseoProduct());

    // The old code invented date('+1 year') here. A store cannot promise its
    // price for a year, and an invented value is a claim, not a blank.
    expect(pseoNode($html, 'Product')['offers'])->not->toHaveKey('priceValidUntil');
});

it('never publishes a priceValidUntil in the past after a sale has ended', function () {
    $html = pseoFetch(pseoProduct([
        'price' => 9900,
        'sale_price' => 7900,
        'sale_starts_at' => now()->subDays(30),
        'sale_ends_at' => now()->subDays(5),
    ]));

    $offer = pseoNode($html, 'Product')['offers'];

    // The sale is over, so the ordinary price is what is advertised...
    expect($offer['price'])->toBe('99.00');
    // ...and there is no elapsed date telling Google the offer expired.
    expect($offer)->not->toHaveKey('priceValidUntil');
});

it('does not claim a price ends when a sale has not started yet', function () {
    $html = pseoFetch(pseoProduct([
        'price' => 9900,
        'sale_price' => 7900,
        'sale_starts_at' => now()->addDays(5),
        'sale_ends_at' => now()->addDays(20),
    ]));

    $offer = pseoNode($html, 'Product')['offers'];

    expect($offer['price'])->toBe('99.00');
    expect($offer)->not->toHaveKey('priceValidUntil');
});

it('states the condition of the goods', function () {
    $html = pseoFetch(pseoProduct());

    expect(pseoNode($html, 'Product')['offers']['itemCondition'])
        ->toBe('https://schema.org/NewCondition');
});

/* ─────────────────────────── merchant listing ───────────────────────────── */

it('emits no shipping or returns terms until an admin has confirmed them', function () {
    // The merchant block stays off by default on purpose: shipping and return
    // terms published wrong are a misrepresentation to a shopper, which is
    // worse than a listing that simply does not carry them.
    $offer = pseoNode(pseoFetch(pseoProduct()), 'Product')['offers'];

    expect($offer)->not->toHaveKey('shippingDetails');
    expect($offer)->not->toHaveKey('hasMerchantReturnPolicy');
});

it('states shipping as exact money once the merchant block is on', function () {
    pseoSettings([
        'enable_merchant' => '1',
        'merchant_ship_cost' => '12.50',
        'merchant_ship_free_over' => '200',
        'merchant_ship_country' => 'AE',
    ]);

    $offer = pseoNode(pseoFetch(pseoProduct(['price' => 9900])), 'Product')['offers'];
    $rate = $offer['shippingDetails']['shippingRate'];

    // "12.50", not "12.5": the old line cast a float straight to a string.
    expect($rate['value'])->toBe('12.50');
    expect($rate['currency'])->toBe('AED');
    expect($offer['shippingDetails']['shippingDestination']['addressCountry'])->toBe('AE');
});

it('reports free shipping when the product clears the threshold', function () {
    pseoSettings([
        'enable_merchant' => '1',
        'merchant_ship_cost' => '12.50',
        'merchant_ship_free_over' => '200',
    ]);

    // 250.00 is over the 200 threshold, so this one genuinely ships free.
    $offer = pseoNode(pseoFetch(pseoProduct(['price' => 25000])), 'Product')['offers'];

    expect($offer['shippingDetails']['shippingRate']['value'])->toBe('0.00');
});

it('never invents the return method or who pays for it', function () {
    pseoSettings([
        'enable_merchant' => '1',
        'merchant_return_days' => '14',
        'merchant_ship_country' => 'AE',
    ]);

    $policy = pseoNode(pseoFetch(pseoProduct()), 'Product')['offers']['hasMerchantReturnPolicy'];

    expect($policy['merchantReturnDays'])->toBe(14);
    expect($policy['returnPolicyCategory'])
        ->toBe('https://schema.org/MerchantReturnFiniteReturnWindow');

    // returnMethod and returnFees were hardcoded to ReturnByMail and
    // FreeReturn. A store that charges for returns and publishes FreeReturn has
    // told Google something untrue about its own terms.
    expect($policy)->not->toHaveKey('returnMethod');
    expect($policy)->not->toHaveKey('returnFees');
});

it('states the return method and fees once an admin has set them', function () {
    pseoSettings([
        'enable_merchant' => '1',
        'merchant_return_days' => '14',
        'merchant_return_method' => 'ReturnByMail',
        'merchant_return_fees' => 'FreeReturn',
    ]);

    $policy = pseoNode(pseoFetch(pseoProduct()), 'Product')['offers']['hasMerchantReturnPolicy'];

    expect($policy['returnMethod'])->toBe('https://schema.org/ReturnByMail');
    expect($policy['returnFees'])->toBe('https://schema.org/FreeReturn');
});

it('validates the whole merchant graph against the spec', function () {
    pseoSettings([
        'enable_merchant' => '1',
        'merchant_ship_cost' => '12.50',
        'merchant_return_days' => '14',
        'merchant_return_method' => 'ReturnByMail',
        'merchant_return_fees' => 'FreeReturn',
    ]);

    $product = pseoProduct(['sale_price' => 7900, 'sale_ends_at' => now()->addDays(7)]);
    Review::create(['product_id' => $product->id, 'author_name' => 'Sara', 'rating' => 5, 'content' => 'Lovely', 'status' => 'approved']);

    pseoAssertValidProduct(pseoNode(pseoFetch($product), 'Product'));
});

/* ──────────────────────────────── stock ─────────────────────────────────── */

it('distinguishes backorder from out of stock', function () {
    $back = pseoFetch(pseoProduct(['stock_status' => 'onbackorder']));
    expect(pseoNode($back, 'Product')['offers']['availability'])
        ->toBe('https://schema.org/BackOrder');
    expect(pseoMeta($back, 'product:availability'))->toBe('available for order');

    $out = pseoFetch(pseoProduct(['stock_status' => 'outofstock']));
    expect(pseoNode($out, 'Product')['offers']['availability'])
        ->toBe('https://schema.org/OutOfStock');
    expect(pseoMeta($out, 'product:availability'))->toBe('out of stock');
});

/* ────────────────────────────── ratings ─────────────────────────────────── */

it('emits an aggregateRating only when real approved reviews exist', function () {
    $product = pseoProduct();

    $bare = pseoFetch($product);
    expect(pseoNode($bare, 'Product'))->not->toHaveKey('aggregateRating');

    Review::create(['product_id' => $product->id, 'author_name' => 'Sara', 'rating' => 5, 'content' => 'Lovely', 'status' => 'approved']);

    $rated = pseoFetch($product);
    $rating = pseoNode($rated, 'Product')['aggregateRating'];

    expect($rating['ratingValue'])->toBe('5');
    expect($rating['reviewCount'])->toBe(1);
    expect($rating['bestRating'])->toBe('5');
    expect($rating['worstRating'])->toBe('1');
});

it('ignores a pending review, which no visitor can see', function () {
    $product = pseoProduct();
    Review::create(['product_id' => $product->id, 'author_name' => 'Spam', 'rating' => 1, 'content' => 'x', 'status' => 'pending']);

    expect(pseoNode(pseoFetch($product), 'Product'))->not->toHaveKey('aggregateRating');
});

it('never derives a star rating from demo content', function () {
    // Demo content dresses an empty page while the catalogue is being filled.
    // It must never reach the structured data: a star average Google can show
    // against a page displaying no such reviews is a manual-action trigger.
    pseoSettings(['demo_content' => '1']);

    $product = pseoProduct();
    $html = pseoFetch($product);

    // First prove demo content really is switched on for this render —
    // otherwise the assertion below passes for the wrong reason and would go on
    // passing after the guard was removed.
    expect($html)->toContain('Aisha M.');

    expect(pseoNode($html, 'Product'))->not->toHaveKey('aggregateRating');
});

/* ─────────────────────── canonical, duplicates, noindex ─────────────────── */

it('keeps one canonical whatever query string a legacy link carries', function () {
    $product = pseoProduct();
    $expected = PSEO_BASE . '/product/' . $product->slug . '/';

    // URL contract U-02: ?add-to-cart={id} links are live in the wild.
    foreach (['', '?add-to-cart=' . $product->publicId(), '?utm_source=newsletter&utm_medium=email'] as $query) {
        $html = pseoFetch($product, $query);

        expect(pseoMeta($html, 'og:url'))->toBe($expected);
        expect(pseoHead($html))->toContain('<link rel="canonical" href="' . $expected . '">');
        // The Offer must point at the same place the canonical does.
        expect(pseoNode($html, 'Product')['offers']['url'])->toBe($expected);
    }
});

it('honours a per-product noindex on the page and in the sitemap together', function () {
    $indexed = pseoProduct();
    $hidden = pseoProduct(['seo' => ['noindex' => true]]);

    $html = pseoFetch($hidden);
    expect(pseoMeta($html, 'robots'))->toBe('noindex, nofollow');

    $sitemap = test()->get('/sitemap.xml')->assertOk()->getContent();

    // A sitemap that submits a noindexed URL spends crawl budget asking Google
    // to fetch a page whose only instruction is to go away, and Search Console
    // reports the pair as an error.
    expect($sitemap)->toContain('/product/' . $indexed->slug . '/');
    expect($sitemap)->not->toContain('/product/' . $hidden->slug . '/');
});

it('keeps a draft or private product out of both the page and the sitemap', function () {
    $draft = pseoProduct(['status' => 'draft']);
    $private = pseoProduct(['status' => 'private']);
    $hiddenFromCatalogue = pseoProduct(['is_visible' => false]);

    foreach ([$draft, $private, $hiddenFromCatalogue] as $product) {
        test()->get('/product/' . $product->slug)->assertNotFound();
    }

    $sitemap = test()->get('/sitemap.xml')->assertOk()->getContent();

    foreach ([$draft, $private, $hiddenFromCatalogue] as $product) {
        expect($sitemap)->not->toContain('/product/' . $product->slug . '/');
    }
});

it('lists product URLs in the sitemap in their canonical, trailing-slash form', function () {
    $product = pseoProduct();
    $sitemap = test()->get('/sitemap.xml')->assertOk()->getContent();

    // A sitemap entry that redirects is a wasted fetch; /product/{slug} 301s to
    // the slashed form.
    expect($sitemap)->toContain('<loc>' . PSEO_BASE . '/product/' . $product->slug . '/</loc>');

    // And every <loc> is absolute: Search Console rejects a relative one.
    preg_match_all('#<loc>(.*?)</loc>#', $sitemap, $locs);
    expect($locs[1])->not->toBeEmpty();

    foreach ($locs[1] as $loc) {
        expect(str_starts_with($loc, 'https://'))->toBeTrue('sitemap <loc> not absolute: ' . $loc);
    }
});

it('submits the shop in the same form the shop canonicalises to', function () {
    $sitemap = test()->get('/sitemap.xml')->assertOk()->getContent();

    // /shop was listed unslashed while the page canonicalises to /shop/, so the
    // sitemap was pointing Google at a URL that then sent it somewhere else.
    expect($sitemap)->toContain('<loc>' . PSEO_BASE . '/shop/</loc>');
    expect($sitemap)->not->toContain('<loc>' . PSEO_BASE . '/shop</loc>');
});

it('never lists a URL in the sitemap that the site then 404s', function () {
    pseoProduct();

    $sitemap = test()->get('/sitemap.xml')->assertOk()->getContent();
    preg_match_all('#<loc>(.*?)</loc>#', $sitemap, $locs);

    // Keep the sweep honest: it must actually be walking a real sitemap, not
    // passing because the file came back with two entries in it.
    expect(count($locs[1]))->toBeGreaterThan(6);

    // A sitemap entry that 404s is a crawl-budget tax and a Search Console
    // error. Category URLs in particular were listed under /category/{slug} for
    // a long time, which has never been a route on this site.
    foreach ($locs[1] as $loc) {
        $path = parse_url(html_entity_decode($loc), PHP_URL_PATH) ?: '/';

        $status = test()->get($path)->getStatusCode();

        expect($status)->toBeLessThan(
            400,
            'sitemap lists ' . $path . ' which returns ' . $status
        );
    }
});

/* ────────────────────── shop pagination and filters ─────────────────────── */

it('self-canonicalises a paginated shop page rather than pointing at page one', function () {
    pseoProduct();

    $page2 = test()->get('/shop?paged=2')->assertOk()->getContent();

    // Google's guidance is that a paginated page is its own page: canonicalising
    // /shop?paged=2 to /shop tells it page two does not exist, and everything
    // only reachable from page two goes with it.
    expect(pseoHead($page2))
        ->toContain('<link rel="canonical" href="' . PSEO_BASE . '/shop/?paged=2">');
});

it('canonicalises a filtered or sorted listing back to the clean URL', function () {
    pseoProduct();

    // A facet combination is a view of the same set, not a new document, and
    // there are more combinations than a crawler should ever be asked to fetch.
    foreach (['?orderby=price', '?filter_brands=pseo-anua', '?instock=1'] as $query) {
        $html = test()->get('/shop' . $query)->assertOk()->getContent();

        expect(pseoHead($html))
            ->toContain('<link rel="canonical" href="' . PSEO_BASE . '/shop/">');
    }
});

it('keeps the live /shop/page/{n}/ address redirecting rather than serving a duplicate', function () {
    // URL contract U-07: /shop/page/2/ is indexed on the live site, so it must
    // keep resolving — as one 301 to the canonical form, not as a second copy
    // of the same listing under a second URL.
    test()->get('/shop/page/2')->assertRedirect();
    test()->get('/shop/page/1')->assertRedirect();
});

/* ──────────────────────── page experience / LCP ─────────────────────────── */

it('preloads the main gallery shot, which is the LCP element', function () {
    $html = pseoFetch(pseoProduct());

    // The gallery paints the main shot as a CSS background, which the preload
    // scanner cannot see. Without this hint the largest paint on the page waits
    // for the stylesheet.
    expect(pseoHead($html))->toContain(
        '<link rel="preload" as="image" href="/media/anua-main.jpg" fetchpriority="high">'
    );
});

it('emits no preload when a product has no image to preload', function () {
    $html = pseoFetch(pseoProduct(['image' => null, 'images' => []]));

    expect(pseoHead($html))->not->toContain('rel="preload" as="image"');
});

/* ───────────────────────────── query budget ─────────────────────────────── */

it('adds no queries to the product page', function () {
    $product = pseoProduct();
    Review::create(['product_id' => $product->id, 'author_name' => 'Sara', 'rating' => 5, 'content' => 'Lovely', 'status' => 'approved']);

    // Warm anything cached on first render, so this counts steady state.
    pseoFetch($product);

    $queries = 0;
    \Illuminate\Support\Facades\DB::listen(function () use (&$queries) {
        $queries++;
    });

    pseoFetch($product);

    // StorefrontQueryBudgetTest holds the product page at 30. The SEO work
    // reads only what the page had already loaded — the gallery is on the
    // model, the review summary is the grouped query the page already ran —
    // so it must cost nothing. MySQL 1140 shipped here twice; a count is the
    // only thing that notices an aggregate creeping back in.
    expect($queries)->toBeLessThanOrEqual(30);
});
