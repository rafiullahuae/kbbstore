<?php

/*
 * Search Console -> Product snippets, "Improve item appearance" (Lane PS).
 *
 * WHAT THE OWNER SAW: 0 invalid, 1.82K valid, and two warnings on 1,791 items
 * each -- Missing field "aggregateRating" and Missing field "review".
 *
 * WHAT IT WAS, measured rather than guessed:
 *
 *  1. 1,791 is more than twice the ~781 live products because every listing
 *     page (category, brand, /shop/, the curated lists) published each of its
 *     rows as a full schema.org Product with an Offer. Google counts each one
 *     as a Product-snippet item, and a listing row has no reviews to publish,
 *     so every one of them carried both warnings. Google's rule is "use markup
 *     for a specific product, not a category or list of products": listing
 *     rows are now plain ListItems (url + name) and the Product lives only on
 *     its own page.
 *
 *  2. A product page with real, approved reviews published an aggregateRating
 *     but never a single `review`, though it draws the reviews on the page.
 *     It now publishes the first few the page draws -- from the collection it
 *     already loaded, so no query of its own.
 *
 *  3. A product with no approved reviews still publishes neither, and that
 *     warning is CORRECT: inventing a rating is the one structured-data
 *     offence Google answers with a manual action.
 *
 * Mutations, each one red here:
 *   - drop `'review_items' => ...` in ProductController::show()  -> "publishes the reviews"
 *   - give the Product-row branch in Seo's collection block its `item` back
 *     (@type Product with offers)                                -> the three listing cases
 *   - remove ->approved() from the review list query             -> "pending reviews publish nothing"
 *   - return $raw from ReviewSchema::author()                    -> "never the surname, the email or the IP"
 *   - drop JSON_HEX_TAG from Seo::encodeJsonLd()                 -> "</script> in a review cannot close the block"
 *   - drop mb_scrub() in ReviewSchema::text()                    -> "a broken byte does not cost the product its node"
 */

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Review;
use App\Models\Setting;
use App\Services\ProductSections;
use App\Services\SettingsService;
use App\Support\ReviewSchema;
use Illuminate\Support\Facades\DB;

const PS_BASE = 'http://localhost';

beforeEach(function () {
    Setting::query()->updateOrCreate(['key' => 'site_url'], ['value' => PS_BASE, 'autoload' => true]);
    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
    \Illuminate\Support\Facades\Cache::flush();
    \App\Support\Facets::reset();
});

function psCategory(): Category
{
    return Category::firstOrCreate(['slug' => 'ps-serums'], ['name' => 'Serums', 'path' => 'ps-serums']);
}

function psBrand(): Brand
{
    return Brand::firstOrCreate(['slug' => 'ps-roundlab'], ['name' => 'Round Lab']);
}

function psProduct(string $slug, array $extra = []): Product
{
    $product = Product::create(array_merge([
        'slug' => $slug,
        'name' => 'PS '.$slug,
        'sku' => 'PS-'.strtoupper($slug),
        'brand_id' => psBrand()->id,
        'status' => 'publish',
        'is_visible' => true,
        'price' => 9900,
        'stock_status' => 'instock',
        'type' => 'simple',
        'image' => '/media/ps.jpg',
        'short_description' => 'A product for the review snippet test.',
    ], $extra));

    $product->categories()->syncWithoutDetaching([psCategory()->id]);

    return $product;
}

function psReview(Product $product, array $extra = []): Review
{
    $review = (new Review)->forceFill(array_merge([
        'product_id' => $product->id,
        'author_name' => 'Fatima Al Mansoori',
        'author_email' => 'fatima.secret@example.com',
        'rating' => 5,
        'title' => 'Love it',
        'content' => 'My skin has never felt this calm.',
        'status' => 'approved',
    ], $extra));
    $review->forceFill(['ip' => '203.0.113.77'])->save();

    return $review;
}

/** @return list<array<string, mixed>> every JSON-LD block, each one decoded strictly */
function psBlocks(string $html): array
{
    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);

    return array_map(static fn ($raw) => json_decode($raw, true, 512, JSON_THROW_ON_ERROR), $m[1]);
}

function psProductNode(string $html): ?array
{
    foreach (psBlocks($html) as $node) {
        if (($node['@type'] ?? null) === 'Product') {
            return $node;
        }
    }

    return null;
}

/** Every node of @type Product anywhere in the page's JSON-LD, nested or not. */
function psAllProducts(mixed $node): int
{
    if (! is_array($node)) {
        return 0;
    }

    $n = ($node['@type'] ?? null) === 'Product' ? 1 : 0;

    foreach ($node as $key => $child) {
        // typeOfGood inside a set's includesObject is a component, not an item.
        if ($key !== 'includesObject') {
            $n += psAllProducts($child);
        }
    }

    return $n;
}

/**
 * Google's Product snippet structure, checked strictly: what is required is
 * present and of the right shape, and no rating is zero.
 * https://developers.google.com/search/docs/appearance/structured-data/product-snippet
 */
function psAssertGoogleProductSnippet(array $node): void
{
    expect($node['@context'] ?? null)->toBe('https://schema.org')
        ->and($node['@type'] ?? null)->toBe('Product')
        ->and($node['name'] ?? '')->toBeString()->not->toBe('');

    // One of review, aggregateRating or offers is required.
    expect(isset($node['review']) || isset($node['aggregateRating']) || isset($node['offers']))->toBeTrue();

    if (isset($node['aggregateRating'])) {
        $a = $node['aggregateRating'];
        expect($a['@type'])->toBe('AggregateRating')
            ->and((float) $a['ratingValue'])->toBeGreaterThanOrEqual(1.0)->toBeLessThanOrEqual(5.0)
            ->and($a['reviewCount'] ?? $a['ratingCount'] ?? 0)->toBeInt()->toBeGreaterThan(0)
            ->and($a['bestRating'])->toBe('5')
            ->and($a['worstRating'])->toBe('1');
    }

    if (isset($node['review'])) {
        expect($node['review'])->toBeArray()->not->toBeEmpty()
            ->and(isset($node['aggregateRating']))->toBeTrue('a review without the rating it belongs to');
        expect(count($node['review']))->toBeLessThanOrEqual(ReviewSchema::MAX);

        foreach ($node['review'] as $r) {
            expect($r['@type'])->toBe('Review')
                ->and($r['author']['@type'])->toBe('Person')
                ->and($r['author']['name'])->toBeString()->not->toBe('')
                ->and($r['author']['name'])->not->toContain('@')
                ->and($r['reviewRating']['@type'])->toBe('Rating')
                ->and((int) $r['reviewRating']['ratingValue'])->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(5)
                ->and($r['reviewRating']['bestRating'])->toBe('5')
                ->and($r['reviewRating']['worstRating'])->toBe('1');

            if (isset($r['datePublished'])) {
                expect($r['datePublished'])->toMatch('/^\d{4}-\d{2}-\d{2}$/');
            }
        }
    }

    if (isset($node['offers'])) {
        $o = $node['offers'];
        expect($o['@type'])->toBeIn(['Offer', 'AggregateOffer'])
            ->and($o['priceCurrency'])->toBe('AED')
            ->and(str_starts_with((string) ($o['availability'] ?? ''), 'https://schema.org/'))->toBeTrue();
    }
}

/* ─────────────────────────── the product page ─────────────────────────── */

it('publishes the rating AND the reviews the page draws, for real approved reviews', function () {
    $p = psProduct('ps-reviewed');
    psReview($p, ['rating' => 5, 'created_at' => now()->subDays(3)]);
    psReview($p, ['author_name' => 'Omar', 'rating' => 4, 'title' => '', 'content' => 'Good.', 'created_at' => now()->subDays(2)]);

    $html = $this->get('/product/ps-reviewed/')->assertOk()->getContent();
    $node = psProductNode($html);

    psAssertGoogleProductSnippet($node);

    expect($node['aggregateRating']['ratingValue'])->toBe('4.5')
        ->and($node['aggregateRating']['reviewCount'])->toBe(2)
        ->and($node['review'])->toHaveCount(2);

    // Newest first, the page's own default order.
    expect($node['review'][0]['author']['name'])->toBe('Omar')
        ->and($node['review'][0])->not->toHaveKey('name')
        ->and($node['review'][1]['author']['name'])->toBe('Fatima M.')
        ->and($node['review'][1]['name'])->toBe('Love it')
        ->and($node['review'][1]['reviewBody'])->toBe('My skin has never felt this calm.')
        ->and($node['review'][1]['datePublished'])->toBe(now()->subDays(3)->format('Y-m-d'));

    // What Google is told is what the page shows: score and count.
    expect($html)->toContain('<div class="sr-avg">4.5</div>')
        ->and($html)->toContain('My skin has never felt this calm.');
});

it('never publishes the surname, the email or the IP', function () {
    $p = psProduct('ps-private');
    psReview($p);
    psReview($p, ['author_name' => 'someone@example.com', 'rating' => 4]);

    $html = $this->get('/product/ps-private/')->assertOk()->getContent();
    $json = implode("\n", array_map('json_encode', psBlocks($html)));

    expect($json)->not->toContain('Mansoori')
        ->and($json)->not->toContain('fatima.secret')
        ->and($json)->not->toContain('someone@example.com')
        ->and($json)->not->toContain('203.0.113.77');

    // The email-named review is dropped from `review`, not published nameless;
    // it still counts in the rating, which is what the page shows.
    $node = psProductNode($html);
    expect($node['review'])->toHaveCount(1)
        ->and($node['aggregateRating']['reviewCount'])->toBe(2);
});

it('keeps pending and spam reviews out of both fields', function () {
    $p = psProduct('ps-pending');
    psReview($p, ['status' => 'pending']);
    psReview($p, ['status' => 'spam', 'rating' => 1]);

    $node = psProductNode($this->get('/product/ps-pending/')->assertOk()->getContent());

    psAssertGoogleProductSnippet($node);
    expect($node)->not->toHaveKey('aggregateRating')->not->toHaveKey('review');
});

it('publishes neither field for a product with no reviews, and never a zero', function () {
    psProduct('ps-none');

    $html = $this->get('/product/ps-none/')->assertOk()->getContent();
    $node = psProductNode($html);

    psAssertGoogleProductSnippet($node);
    expect($node)->not->toHaveKey('aggregateRating')->not->toHaveKey('review')
        ->and($html)->not->toContain('"ratingValue":"0"')
        ->and($html)->not->toContain('"reviewCount":0');
});

it('cannot be broken out of by </script> in a review', function () {
    $p = psProduct('ps-xss');
    psReview($p, ['title' => '</script><script>alert(1)</script>', 'content' => 'Nice </script><img src=x onerror=alert(2)>']);

    $html = $this->get('/product/ps-xss/')->assertOk()->getContent();
    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);

    foreach ($m[1] as $raw) {
        expect($raw)->not->toContain('<')->not->toContain('>');
    }

    $node = psProductNode($html);
    expect($node['review'][0]['name'])->toBe('</script><script>alert(1)</script>');
});

it('does not cost the product its node over a broken byte in an imported review', function () {
    $p = psProduct('ps-bytes');
    psReview($p, ['content' => "Caf\xE9 cream, great"]);

    $node = psProductNode($this->get('/product/ps-bytes/')->assertOk()->getContent());

    expect($node)->not->toBeNull()
        ->and($node['offers'])->toBeArray()
        ->and($node['review'][0]['reviewBody'])->toStartWith('Caf');
});

it('drops a review whose rating is off the 1-5 scale from the review list', function () {
    $p = psProduct('ps-zero');
    psReview($p, ['rating' => 5]);
    psReview($p, ['rating' => 0, 'author_name' => 'Zed']);

    $node = psProductNode($this->get('/product/ps-zero/')->assertOk()->getContent());

    expect(array_column(array_column($node['review'], 'author'), 'name'))->toBe(['Fatima M.']);
});

it('publishes at most a few reviews', function () {
    $p = psProduct('ps-many');

    for ($i = 1; $i <= 7; $i++) {
        psReview($p, ['author_name' => "Reviewer {$i}", 'created_at' => now()->subDays($i)]);
    }

    $node = psProductNode($this->get('/product/ps-many/')->assertOk()->getContent());

    expect($node['review'])->toHaveCount(ReviewSchema::MAX)
        ->and($node['aggregateRating']['reviewCount'])->toBe(7);
});

it('publishes reviews only while the review section is shown', function () {
    $p = psProduct('ps-hidden');
    psReview($p);

    $sections = array_map(static fn () => ['desktop' => true, 'mobile' => true], ProductSections::REGISTRY);
    $sections['reviews'] = ['desktop' => false, 'mobile' => false];
    app(ProductSections::class)->save($sections);
    SettingsService::forgetMemo();

    $node = psProductNode($this->get('/product/ps-hidden/')->assertOk()->getContent());
    // The rating line still shows the score, so the rating stays.
    expect($node)->toHaveKey('aggregateRating')->not->toHaveKey('review');

    $sections['rating'] = $sections['capsule'] = ['desktop' => false, 'mobile' => false];
    app(ProductSections::class)->save($sections);
    SettingsService::forgetMemo();

    $node = psProductNode($this->get('/product/ps-hidden/')->assertOk()->getContent());
    expect($node)->not->toHaveKey('aggregateRating')->not->toHaveKey('review');
});

it('follows the same rules on the Arabic page, reviews as written', function () {
    Setting::query()->updateOrCreate(['key' => \App\Support\Locale::SETTING_ENABLED], ['value' => '1', 'autoload' => true]);
    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
    \Illuminate\Support\Facades\Cache::flush();

    $p = psProduct('ps-arabic');
    psReview($p, ['author_name' => 'مريم أحمد', 'content' => 'منتج رائع']);

    $node = psProductNode($this->get('/ar/product/ps-arabic/')->assertOk()->getContent());

    psAssertGoogleProductSnippet($node);
    expect($node['review'][0]['author']['name'])->toBe('مريم أ.')
        ->and($node['review'][0]['reviewBody'])->toBe('منتج رائع');
});

it('costs the product page no query for the reviews it publishes', function () {
    $p = psProduct('ps-cost');
    psReview($p);
    $this->get('/product/ps-cost/')->assertOk();

    DB::enableQueryLog();
    $this->get('/product/ps-cost/')->assertOk();
    $withReviews = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], '"reviews"'))->count();
    DB::disableQueryLog();

    // reviewSummary() and the visible list: the same two it always ran.
    expect($withReviews)->toBe(2);
});

/* ─────────────────────────── listing pages ─────────────────────────── */

it('publishes no Product on a category, brand or shop page, only links to them', function (string $uri) {
    psProduct('ps-l-one');
    psProduct('ps-l-two');
    psReview(Product::where('slug', 'ps-l-one')->first());

    $html = $this->get($uri)->assertOk()->getContent();
    $blocks = psBlocks($html);

    expect(psAllProducts($blocks))->toBe(0);

    $list = collect($blocks)->firstWhere('@type', 'CollectionPage')['mainEntity'] ?? null;
    expect($list['@type'] ?? null)->toBe('ItemList');

    foreach ($list['itemListElement'] as $i => $el) {
        expect($el['@type'])->toBe('ListItem')
            ->and($el['position'])->toBe($i + 1)
            ->and($el['url'])->toStartWith(PS_BASE.'/product/')
            ->and($el)->not->toHaveKey('item')->not->toHaveKey('offers');
    }

    expect(array_column($list['itemListElement'], 'url'))->toContain(PS_BASE.'/product/ps-l-one/');
})->with([
    'category' => '/collections/ps-serums/',
    'brand' => '/brands/ps-roundlab/',
    'shop' => '/shop/',
]);

it('keeps a listing page flat in queries, 3 products or 40', function () {
    for ($i = 1; $i <= 3; $i++) {
        psProduct("ps-flat-{$i}");
    }
    $this->get('/collections/ps-serums/')->assertOk();

    DB::enableQueryLog();
    $this->get('/collections/ps-serums/')->assertOk();
    $small = count(DB::getQueryLog());

    for ($i = 4; $i <= 40; $i++) {
        psProduct("ps-flat-{$i}");
    }
    DB::flushQueryLog();
    $this->get('/collections/ps-serums/')->assertOk();
    $big = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($big)->toBe($small);
});

/* ─────────────────────────── the name rule itself ─────────────────────────── */

it('turns a typed name into first name and initial, or refuses it', function (string $raw, ?string $want) {
    expect(ReviewSchema::author($raw))->toBe($want);
})->with([
    ['Fatima Al Mansoori', 'Fatima M.'],
    ['  sara   ', 'sara'],
    ['Omar k', 'Omar K.'],
    ['someone@example.com', null],
    ['Visit https://spam.example', null],
    ['shop.com', null],
    ['12345', null],
    ['', null],
    ['Ali 7', 'Ali'],
]);
