<?php

declare(strict_types=1);

/**
 * GTIN and variant-level offers — the two things Phase 12 recorded as blocked.
 *
 * ── WHAT THE PLAN SAYS, AND WHY IT IS NO LONGER TRUE ───────────────────────
 *
 * The structured-data item closes with: "Still open: GTIN and variant-level
 * offers — genuinely blocked, no GTIN/barcode column exists anywhere in the
 * schema and there's no existing source of truth for it."
 *
 * Both halves have stopped being true and neither stopped quietly.
 *
 *   GTIN. `products.gtin` was added by
 *   2026_10_05_000000_add_product_editor_columns — "THE PRODUCT IDENTIFIER
 *   GOOGLE ACTUALLY WANTS", indexed, 14 characters. The product editor
 *   collects it and App\Support\Gtin validates the mod-10 check digit on the
 *   way in. Nothing published it.
 *
 *   VARIANT OFFERS. `product_variants` has carried `price`, `sale_price`,
 *   `stock_status` and `sku` since the ORIGINAL schema migration, and
 *   store/product.blade.php prints a price on every option row. So the page
 *   showed a shopper three prices while the document told Google one. That was
 *   never blocked on anything; it was listed beside the GTIN line and inherited
 *   its verdict.
 *
 * ── WHY EVERY CASE FETCHES ─────────────────────────────────────────────────
 *
 * Real requests through the full kernel, with the JSON-LD parsed out of the
 * rendered <head>. Calling Seo::jsonLd() directly would prove that a method
 * returns an array, which is the kind of proof this project has been burned by.
 */

use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Services\SettingsService;

const PI_BASE = 'http://localhost';

/**
 * A real EAN-13 whose check digit agrees with its body, and the same number
 * with the last digit changed.
 *
 * The pair is the point: the two differ by ONE character, which is exactly the
 * mistake the check digit exists to catch and exactly what a test asserting
 * only on the good one would miss.
 */
const PI_GOOD_GTIN = '4006381333931';

const PI_BAD_GTIN = '4006381333930';

function piSettings(): void
{
    Setting::query()->updateOrCreate(['key' => 'site_url'], ['value' => PI_BASE, 'autoload' => true]);
    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
    \Illuminate\Support\Facades\Cache::flush();
}

/** @param  array<string, mixed>  $extra */
function piProduct(string $slug, array $extra = []): Product
{
    $brand = Brand::firstOrCreate(['slug' => 'pi-brand'], ['name' => 'PI Brand']);

    $product = Product::firstOrCreate(
        ['slug' => $slug],
        [
            'name' => 'PI '.$slug,
            'brand_id' => $brand->id,
            'status' => 'publish',
            'is_visible' => true,
            // Integer FILS. 12600 is AED 126.00.
            'price' => 12600,
            'stock_status' => 'instock',
            'type' => 'simple',
        ]
    );

    $product->forceFill($extra)->save();

    return $product;
}

/** @param  array<int, array{0: string, 1: int, 2: string}>  $rows  [sku, price in fils, stock_status] */
function piVariants(Product $product, array $rows): void
{
    ProductVariant::query()->where('product_id', $product->id)->delete();

    foreach ($rows as $i => [$sku, $price, $stock]) {
        ProductVariant::create([
            'product_id' => $product->id,
            'sku' => $sku,
            'price' => $price,
            'stock_status' => $stock,
            'position' => $i,
        ]);
    }

    $product->forceFill(['type' => 'variable'])->save();
}

/**
 * The Product node off a fetched product page.
 *
 * @return array<string, mixed>
 */
function piNode(string $slug): array
{
    $html = test()->get('/product/'.$slug.'/')->assertOk()->getContent();

    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);

    foreach ($m[1] as $json) {
        $decoded = json_decode($json, true);

        expect($decoded)->toBeArray("A JSON-LD block on the page is not valid JSON: {$json}");

        if (($decoded['@type'] ?? null) === 'Product') {
            return $decoded;
        }
    }

    throw new RuntimeException("No Product node on /product/{$slug}/.");
}

beforeEach(function () {
    piSettings();
});

it('publishes a valid GTIN', function () {
    /*
     * MUTATION: remove the `$node['gtin'] = $gtin;` block from Seo. Red.
     */
    piProduct('pi-barcode', ['gtin' => PI_GOOD_GTIN]);

    expect(piNode('pi-barcode')['gtin'])->toBe(PI_GOOD_GTIN);
});

it('publishes nothing at all when the check digit disagrees', function () {
    /*
     * THE CASE THIS WHOLE ITEM TURNS ON. The brief's instruction is that a
     * wrong GTIN is worse than none, because Google MATCHES PRODUCTS ON IT: a
     * transposed pair of digits does not make a weaker listing, it attaches
     * this shop's price and stock to a different product.
     *
     * The stored value differs from the good one by its final character alone,
     * so a reader that published the column verbatim would look perfectly
     * correct here.
     *
     * TWO MUTATIONS, both red:
     *   - publish `$p['gtin']` directly instead of going through Gtin.
     *   - keep Gtin::normalise() and drop the `Gtin::isValid()` half.
     *     normalise() checks the LENGTH and the characters, not the checksum,
     *     so a 13-digit number with a wrong last digit passes it — which is
     *     precisely why the two calls are not the same call twice.
     */
    piProduct('pi-typo', ['gtin' => PI_BAD_GTIN]);

    expect(piNode('pi-typo'))->not->toHaveKey('gtin');
});

it('publishes no gtin key for a product that has none', function () {
    piProduct('pi-nobarcode');

    expect(piNode('pi-nobarcode'))->not->toHaveKey('gtin');
});

it('publishes a price range when the options are priced differently', function () {
    /*
     * The page prints 89.00, 210.00 and 310.00 on three option rows and used to
     * publish only the parent's 126.00.
     *
     * MUTATION: return null from Seo::aggregateOffer() unconditionally. Red on
     * the @type.
     */
    $product = piProduct('pi-range');
    piVariants($product, [
        ['PI-30', 8900, 'instock'],
        ['PI-100', 21000, 'instock'],
        ['PI-200', 31000, 'outofstock'],
    ]);

    $offers = piNode('pi-range')['offers'];

    expect($offers['@type'])->toBe('AggregateOffer');
    expect($offers['lowPrice'])->toBe('89.00');
    expect($offers['highPrice'])->toBe('310.00');
    expect($offers['offerCount'])->toBe(3);
});

it('publishes the range in dirhams, never the fils column', function () {
    /*
     * THE FILS TRAP IN ITS NEWEST DISGUISE. `product_variants.price` is integer
     * minor units, so an AED 89 option is 8900 in the column, and a range built
     * straight off those integers tells every search engine the cheapest size
     * costs 8,900 dirhams. That is the mistake this project made once on the
     * Product node, caught in 2.60.36 before it shipped, and the plan records
     * it for exactly this reason.
     *
     * MUTATION: `$aggregate['lowPrice'] = (string) min($minors)`. Red — 8900.
     *
     * ── A MUTATION THAT DOES NOT GO RED, AND WHY IT IS RECORDED ────────────
     *
     * The first draft of this case asserted something else: that the range must
     * be computed on the INTEGERS because min() over the decimal strings would
     * sort "12.00" below "9.00". Run as a mutation — lowPrice/highPrice from
     * min()/max() of array_column($rows, 'price') — every case in this file
     * stayed GREEN, because PHP compares two numeric strings numerically and
     * min(['9.00', '12.00']) really is '9.00'.
     *
     * The integers are still the right choice and the reason is narrower than
     * the one first written down: the string version is correct only while
     * Money::decimalString() emits a bare decimal. min(['1,299.00', '890.00'])
     * is '1,299.00' — a string with a comma in it is not numeric, so PHP falls
     * back to comparing character by character — and a product ranging from 890
     * to 1,299 would then publish a lowPrice ABOVE its highPrice, which Google
     * reads as an invalid offer and drops the price for. That is a fact about
     * the formatter, not about this method, so it is stated here rather than
     * asserted with a fixture that would be testing Money.
     *
     * The fixture below is still the one where the two orderings would
     * disagree under character comparison: 900 and 1200 fils.
     */
    $product = piProduct('pi-stringsort');
    piVariants($product, [
        ['PI-A', 900, 'instock'],
        ['PI-B', 1200, 'instock'],
    ]);

    $offers = piNode('pi-stringsort')['offers'];

    expect($offers['lowPrice'])->toBe('9.00');
    expect($offers['highPrice'])->toBe('12.00');

    foreach ($offers['offers'] as $child) {
        expect($child['price'])->toMatch('/^\d+\.\d{2}$/');
    }
});

it('publishes each option with its own stock status', function () {
    /*
     * The page tags a sold-out option "Sold out" and greys it. A document
     * saying every option is in stock contradicts what is on the screen.
     *
     * MUTATION: build the child's `availability` from $p rather than from the
     * variant row. Red.
     */
    $product = piProduct('pi-stock');
    piVariants($product, [
        ['PI-IN', 8900, 'instock'],
        ['PI-OUT', 21000, 'outofstock'],
    ]);

    $children = piNode('pi-stock')['offers']['offers'];

    $bySku = array_column($children, null, 'sku');

    expect($bySku['PI-IN']['availability'])->toBe('https://schema.org/InStock');
    expect($bySku['PI-OUT']['availability'])->toBe('https://schema.org/OutOfStock');
    expect($bySku['PI-IN']['price'])->toBe('89.00');
    expect($bySku['PI-OUT']['price'])->toBe('210.00');
});

it('leaves a simple product exactly as it was', function () {
    /*
     * The regression this change is most likely to have caused. A product with
     * no options must publish the single Offer it always published, with its
     * price and no range at all.
     *
     * MUTATION: drop the `count($variants) < 2` guard from aggregateOffer().
     * A simple product has no variant rows so the loop produces none, and the
     * distinct-price check would then be reached with an empty set — red here
     * if the guard were replaced by something that let an empty list through.
     */
    piProduct('pi-simple');

    $offers = piNode('pi-simple')['offers'];

    expect($offers['@type'])->toBe('Offer');
    expect($offers['price'])->toBe('126.00');
    expect($offers)->not->toHaveKey('lowPrice');
    expect($offers)->not->toHaveKey('offerCount');
});

it('publishes one Offer when every option costs the same', function () {
    /*
     * A range whose ends are equal is not a range. The single Offer already
     * states that number, and states it with less to go wrong.
     *
     * MUTATION: delete the `count(array_unique($minors)) < 2` early return.
     * Red — the node becomes an AggregateOffer with lowPrice equal to
     * highPrice.
     */
    $product = piProduct('pi-flat');
    piVariants($product, [
        ['PI-F1', 8900, 'instock'],
        ['PI-F2', 8900, 'instock'],
    ]);

    $offers = piNode('pi-flat')['offers'];

    expect($offers['@type'])->toBe('Offer');
    expect($offers)->not->toHaveKey('lowPrice');
});

it('keeps the merchant terms on the aggregate and the price statement on each option', function () {
    /*
     * itemCondition, shipping and returns describe the shop and are the same
     * for every option, so they stay on the AggregateOffer. `priceSpecification`
     * is the one that names a price, so it moves DOWN — left on the aggregate
     * it would carry the parent's single figure beside a lowPrice and a
     * highPrice that disagree with it, which is a VAT statement about a price
     * the document no longer claims.
     *
     * MUTATION: stop unsetting `priceSpecification` on the aggregate. Red on
     * the first expectation.
     */
    $product = piProduct('pi-terms');
    piVariants($product, [
        ['PI-T1', 8900, 'instock'],
        ['PI-T2', 21000, 'instock'],
    ]);

    $offers = piNode('pi-terms')['offers'];

    expect($offers)->not->toHaveKey('priceSpecification');
    expect($offers)->not->toHaveKey('price');
    expect($offers['itemCondition'])->toBe('https://schema.org/NewCondition');

    foreach ($offers['offers'] as $child) {
        expect($child['priceSpecification']['price'])->toBe($child['price']);
        expect($child['itemCondition'])->toBe('https://schema.org/NewCondition');
    }
});

it('shows the same thing in the Schema Inspector as it ships', function () {
    /*
     * The inspector's whole promise is that nothing shown on it can drift from
     * what a real page publishes — it calls the same renderer through
     * Seo::inspect(). It built its own product context, so the two fields added
     * in this release would have been missing from the preview while present on
     * the page, which is worse than having no inspector at all.
     *
     * MUTATION: remove `gtin` and `variants` from
     * SchemaInspectorApiController::productCtx(). Red on both halves.
     */
    $product = piProduct('pi-inspect', ['gtin' => PI_GOOD_GTIN]);
    piVariants($product, [
        ['PI-I1', 8900, 'instock'],
        ['PI-I2', 21000, 'instock'],
    ]);

    $admin = \App\Models\AdminUser::create([
        'name' => 'PI Owner',
        'email' => 'pi-owner-'.uniqid().'@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);

    $body = test()->actingAs($admin, 'admin')
        ->getJson('/admin-api/schema-inspect?type=product&slug=pi-inspect')
        ->assertOk()
        ->json();

    $node = collect($body['nodes'])->firstWhere('@type', 'Product');

    expect($node['gtin'])->toBe(PI_GOOD_GTIN);
    expect($node['offers']['@type'])->toBe('AggregateOffer');
    expect($node['offers']['lowPrice'])->toBe('89.00');

    /*
     * And the warning list does not fire on correct output. The old check read
     * `offers.price` unconditionally, so every variable product would have been
     * reported as having no price the moment ranges shipped — a warning that
     * fires on correct output is how an owner learns to ignore the warnings.
     */
    expect(implode(' ', $body['warnings']))->not->toContain('no price');
});
