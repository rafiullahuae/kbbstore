<?php

declare(strict_types=1);

/**
 * The shop's price filter and price sort, against the price on the card.
 *
 * THE DEFECT. Product::effectivePrice() is what every card, rail and product
 * page prints: `sale_price` when a sale is running, `price` otherwise, with the
 * sale_starts_at / sale_ends_at window honoured. ShopController's price bucket
 * and its "Price: low to high" sort read the raw `price` column instead.
 *
 * So a product marked down from AED 200 to AED 50 printed AED 50 on its card,
 * was filed under "AED 150 – 300", was missing from "AED 54 – 150", and sorted
 * as though it cost AED 200. A shopper filtering by budget was shown everything
 * EXCEPT the discounted stock — which is the stock the store most wants to
 * move — and the Sale badge on the card contradicted the filter that had just
 * excluded it.
 *
 * Asserted through the page, on the products the controller actually hands the
 * view, because the number the shopper reads is the one that has to agree.
 */

use App\Models\Product;

function facetProduct(string $slug, int $priceFils, ?int $saleFils = null, array $window = []): Product
{
    return Product::create(array_merge([
        'slug' => $slug,
        'name' => strtoupper($slug),
        'status' => 'publish',
        'is_visible' => true,
        'stock_status' => 'instock',
        'price' => $priceFils,
        'sale_price' => $saleFils,
    ], $window));
}

/** The names the shop page was given, in the order it was given them. */
function facetNames(string $url): array
{
    return test()->get($url)->assertOk()->original->getData()['products']->pluck('name')->all();
}

it('files a discounted product in the bucket its card shows', function () {
    // AED 200 down to AED 100. Facets::BUCKETS declares '54-150' as AED 54–150
    // and '150-300' as AED 150–300, so the two answers are unambiguous.
    facetProduct('markdown', 20000, 10000);

    expect(facetNames('/shop?price=54-150'))->toContain('MARKDOWN');
    expect(facetNames('/shop?price=150-300'))->not->toContain('MARKDOWN');
});

it('ignores a sale that has not started yet, exactly as the card does', function () {
    facetProduct('future', 20000, 5000, ['sale_starts_at' => now()->addWeek()]);

    // The card still prints AED 200 — effectivePrice() refuses a sale whose
    // window has not opened — so the filter has to agree with that, not with
    // the bare sale_price column.
    expect(facetNames('/shop?price=150-300'))->toContain('FUTURE');
    expect(facetNames('/shop?price=54-150'))->not->toContain('FUTURE');
});

it('ignores a sale that has already ended', function () {
    facetProduct('expired', 20000, 5000, ['sale_ends_at' => now()->subDay()]);

    expect(facetNames('/shop?price=150-300'))->toContain('EXPIRED');
    expect(facetNames('/shop?price=54-150'))->not->toContain('EXPIRED');
});

it('sorts low to high by the charged price, not the pre-sale one', function () {
    // The demo catalogue is cleared first, so the assertion is about three
    // products and not about where they land among twenty-seven others.
    Product::query()->delete();
    facetProduct('sorta', 10000);              // AED 100
    facetProduct('sortb', 20000, 6000);        // AED 200 -> AED 60
    facetProduct('sortc', 12000);              // AED 120

    $names = facetNames('/shop?orderby=plow');

    expect(array_slice($names, 0, 3))->toBe(['SORTB', 'SORTA', 'SORTC']);
});

it('keeps an unstarted sale out of the On-sale filter', function () {
    // Product::isOnSale() is effectivePrice() < price, so this product carries
    // no Sale badge and prints AED 200 — but ?sale=1 asked the raw columns
    // whether sale_price was set and lower, which it is, all week before the
    // sale opens.
    facetProduct('notyet', 20000, 5000, ['sale_starts_at' => now()->addWeek()]);
    facetProduct('livesale', 20000, 5000, ['sale_starts_at' => now()->subDay()]);
    facetProduct('over', 20000, 5000, ['sale_ends_at' => now()->subDay()]);

    $names = facetNames('/shop?sale=1');

    expect($names)->toContain('LIVESALE')
        ->not->toContain('NOTYET')
        ->not->toContain('OVER');
});

it('sorts high to low by the charged price too', function () {
    Product::query()->delete();
    facetProduct('hia', 10000);
    facetProduct('hib', 20000, 6000);
    facetProduct('hic', 12000);

    expect(array_slice(facetNames('/shop?orderby=phigh'), 0, 3))->toBe(['HIC', 'HIA', 'HIB']);
});
