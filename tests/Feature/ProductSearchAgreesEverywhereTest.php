<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Product;

/**
 * Every product search in the admin finds the same products.
 *
 * The owner typed "anua" on New Order and got "Nothing in the catalogue matches
 * that", then typed the same word on an existing order and got three products.
 * Anua is a BRAND — no product name contains it — and the two screens were
 * searching different column sets: the catalogue endpoint matched name, sku,
 * slug and brand, the manual-order one only name and sku, and the coupon
 * picker only name and sku.
 *
 * So this is not "add brand search to one endpoint". It is that three searches
 * over one catalogue disagreed, and the operator has no way to know which one
 * they are looking at. The test is written as agreement between them for that
 * reason: a fourth search added later fails this unless it agrees too.
 */
function searchAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'Owner',
        'email' => 'search-' . uniqid() . '@example.com',
        'password' => bcrypt('secret-secret'),
        'role' => 'owner',
    ]);
}

function brandOnlyProduct(): array
{
    // The shape that broke: the search term appears ONLY in the brand name.
    $brand = Brand::create(['name' => 'Anua', 'slug' => 'anua-' . uniqid()]);

    $product = Product::create([
        'slug' => 'zinc-sunscreen-' . uniqid(),
        'name' => 'Zinc Sunscreen SPF50+',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 11900,
        'stock_status' => 'instock',
        'brand_id' => $brand->id,
    ]);

    expect(stripos($product->name, 'anua'))->toBeFalse('the fixture stopped testing what it exists to test');

    return [$brand, $product];
}

it('finds a product by its brand on the New Order search', function () {
    [, $product] = brandOnlyProduct();

    $body = $this->actingAs(searchAdmin(), 'admin')
        ->getJson('/admin-api/manual-orders/products?q=anua')
        ->assertOk()
        ->json();

    $ids = collect($body['products'] ?? [])->pluck('id')->all();

    expect(in_array($product->id, $ids, true))
        ->toBeTrue('New Order still says nothing in the catalogue matches a brand name');
});

it('finds a product by its brand on the coupon restriction picker', function () {
    [, $product] = brandOnlyProduct();

    $body = $this->actingAs(searchAdmin(), 'admin')
        ->getJson('/admin-api/coupons/manage/lookup?kind=product&q=anua')
        ->assertOk()
        ->json();

    $ids = collect($body['items'] ?? [])->pluck('id')->all();

    expect(in_array($product->id, $ids, true))
        ->toBeTrue('the coupon product picker cannot find a product by its brand');
});

it('returns the same product from all three admin searches', function () {
    /*
     * The agreement itself. Any one of these drifting is the bug the owner hit,
     * whichever direction it drifts in.
     */
    [, $product] = brandOnlyProduct();
    $admin = searchAdmin();

    $found = [];

    $found['new order'] = collect(
        $this->actingAs($admin, 'admin')
            ->getJson('/admin-api/manual-orders/products?q=anua')->assertOk()
            ->json('products') ?? []
    )->pluck('id')->all();

    $found['coupon picker'] = collect(
        $this->actingAs($admin, 'admin')
            ->getJson('/admin-api/coupons/manage/lookup?kind=product&q=anua')->assertOk()
            ->json('items') ?? []
    )->pluck('id')->all();

    // Each endpoint names its own collection; the shapes differ and that is
    // fine, the AGREEMENT about which products match is what this pins.
    $found['catalogue'] = collect(
        $this->actingAs($admin, 'admin')
            ->getJson('/admin-api/catalog/products?search=anua&per_page=8')->assertOk()
            ->json('products') ?? []
    )->pluck('id')->all();

    $missing = [];

    foreach ($found as $where => $ids) {
        if (! in_array($product->id, $ids, true)) {
            $missing[] = $where;
        }
    }

    expect($missing)->toBe(
        [],
        'these admin searches disagree with the others about the same catalogue: ' . implode(', ', $missing)
    );
});
