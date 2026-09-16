<?php

declare(strict_types=1);

/**
 * [kbb_products min_price=… max_price=…] — the bound, and the column it is
 * compared against.
 *
 * TWO DEFECTS, ONE LINE EACH.
 *
 *  1. The bound was built as `(int) $a['min_price'] * 100`. A PHP cast binds
 *     tighter than a multiply, so `min_price="12.50"` became 12 * 100 = 1200:
 *     half a dirham dropped off every bound with a decimal in it, silently, in
 *     the direction that lets cheaper products through.
 *
 *  2. The comparison was against this file's own
 *     `COALESCE(NULLIF(sale_price, 0), price)`, which reads `sale_price`
 *     without looking at sale_starts_at or sale_ends_at. A sale scheduled to
 *     open next week was already discounting today's filter, so a block priced
 *     "under AED 100" listed a product whose card said AED 200.
 *
 * Both now go through the house helpers — Fils::parse() for the bound,
 * App\Support\EffectivePrice for the column — which is the same pair the shop's
 * own price facet uses, so a block and the shop agree about what a product
 * costs.
 */

use App\Models\Product;
use App\Support\Shortcodes;

function shortcodeProduct(string $slug, int $priceFils, ?int $saleFils = null, array $window = []): Product
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

beforeEach(function () {
    Shortcodes::flush();
    Product::query()->delete();
});

it('keeps the fils in a decimal price bound', function () {
    shortcodeProduct('exactly1250', 1250);   // AED 12.50

    // A bound of AED 12.50 must include a product at AED 12.50. Truncated to
    // AED 12 it also would — so the honest test is the bound just above it.
    $html = Shortcodes::render('[kbb_products min_price="12.51"]');
    expect($html)->not->toContain('EXACTLY1250');

    Shortcodes::flush();

    $html = Shortcodes::render('[kbb_products min_price="12.50"]');
    expect($html)->toContain('EXACTLY1250');
});

it('applies a max bound at the fil, not the dirham', function () {
    shortcodeProduct('exactly1250', 1250);

    $html = Shortcodes::render('[kbb_products max_price="12.49"]');
    expect($html)->not->toContain('EXACTLY1250');
});

it('does not let an unstarted sale satisfy a price bound', function () {
    // Card price is AED 200: the sale has not opened yet.
    shortcodeProduct('future', 20000, 5000, ['sale_starts_at' => now()->addWeek()]);

    $html = Shortcodes::render('[kbb_products max_price="100"]');
    expect($html)->not->toContain('FUTURE');
});

it('lets a running sale satisfy a price bound', function () {
    shortcodeProduct('running', 20000, 5000, ['sale_starts_at' => now()->subDay()]);

    $html = Shortcodes::render('[kbb_products max_price="100"]');
    expect($html)->toContain('RUNNING');
});
