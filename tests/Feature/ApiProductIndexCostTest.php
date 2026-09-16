<?php

declare(strict_types=1);

/**
 * What GET /api/products costs the server.
 *
 * /api/* is unauthenticated by design — CLAUDE.md says so in as many words — so
 * every visitor and every crawler may call this endpoint as often as it likes.
 * That makes its cost per request a SECURITY property, not a performance one:
 * the cheapest denial of service against this store is the request the store
 * invites anyone to make.
 *
 * The index selected no columns, so Eloquent hydrated every column of every
 * visible product — including `description`, which the product editor allows up
 * to 200,000 characters of — and then threw all of it away, because
 * Product::toApi() publishes eleven named fields and `description` is not one
 * of them. Measured on 250 products carrying a 20KB description each, the
 * request moved 6MB through memory to emit 54KB of JSON.
 *
 * Every sibling endpoint on this surface already caps its result set:
 * Api\PostController::index limits 100, Api\ReviewController::index caps its
 * `limit` at 100, Api\ProductController::reviews limits 100. This one was the
 * outlier, and it reads the widest rows in the schema.
 */

use App\Models\Product;
use Illuminate\Support\Facades\DB;

it('does not hydrate product columns the public index never returns', function () {
    Product::create([
        'slug' => 'cost-probe',
        'name' => 'Cost probe',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 1000,
        'stock_status' => 'instock',
        'description' => str_repeat('x', 20000),
    ]);

    $selects = [];

    DB::listen(function ($query) use (&$selects) {
        if (str_contains($query->sql, 'from "products"') || str_contains($query->sql, 'from `products`')) {
            $selects[] = $query->sql;
        }
    });

    $this->getJson('/api/products')->assertOk();

    expect($selects)->not->toBeEmpty();

    foreach ($selects as $sql) {
        // `select *` is the defect: it pulls description, how_to_use,
        // ingredients and the SEO blob into memory on a public, unthrottled
        // endpoint that publishes none of them.
        expect(str_contains($sql, 'select *'))->toBeFalse("still selects everything: {$sql}");
        expect(str_contains($sql, '"products".*'))->toBeFalse("still selects everything: {$sql}");
    }
});

it('still publishes exactly the fields Product::toApi() promises', function () {
    /*
     * The other half, and the reason the fix is safe: narrowing the columns
     * must change what the endpoint COSTS without changing what it RETURNS.
     * The storefront category page reads this contract.
     */
    Product::create([
        'slug' => 'shape-probe',
        'name' => 'Shape probe',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 1234,
        'sale_price' => 999,
        'stock_status' => 'instock',
        'short_description' => 'Short one.',
        'description' => 'The long body nobody asked for.',
    ]);

    $body = $this->getJson('/api/products')->assertOk()->json();

    $row = collect($body)->firstWhere('slug', 'shape-probe');

    expect($row)->not->toBeNull();

    expect(array_keys($row))->toEqualCanonicalizing([
        'slug', 'name', 'brand', 'price', 'sale_price', 'image',
        'images', 'rating', 'review_count', 'stock_status', 'short_description',
    ]);

    expect($row['name'])->toBe('Shape probe');
    expect($row['short_description'])->toBe('Short one.');

    // And the heavy column is still not published.
    expect($row)->not->toHaveKey('description');
});
