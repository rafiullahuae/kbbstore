<?php

declare(strict_types=1);

use App\Models\AttributeValue;
use App\Models\Attribute;
use App\Models\Product;
use App\Models\ProductVariant;

/**
 * A product with a size or a shade must load.
 *
 * Product::attributeValues() and ProductVariant::attributeValues() both left
 * belongsToMany() to guess the pivot table. It guesses by sorting the two model
 * names — attribute_value + product_variant — and this schema names them the
 * other way round, so both relations pointed at a table that does not exist.
 * Every read threw "no such table", which meant:
 *
 *   - any product page for a product with a variant returned 500
 *   - the checkout returned 500 for a basket holding a variant line
 *   - the cart page returned 500 for the same basket
 *
 * The suite never caught it because nothing in it, or in any seeder, had ever
 * created a ProductVariant row. That is the whole reason this file exists: the
 * fixture, not the assertion, is the valuable part. Adding one size in the
 * admin would have taken that product's page down.
 */
function variantProduct(): Product
{
    return Product::create([
        'slug' => 'variant-' . uniqid(),
        'name' => 'Variant Product',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 12900,
        'stock_status' => 'instock',
    ]);
}

it('names a pivot table that actually exists on both relations', function () {
    /*
     * Asserted against the schema rather than against a hard-coded string, so
     * renaming the table in a migration fails here instead of in production.
     */
    foreach ([
        'Product::attributeValues' => (new Product)->attributeValues()->getTable(),
        'ProductVariant::attributeValues' => (new ProductVariant)->attributeValues()->getTable(),
    ] as $relation => $table) {
        expect(Schema::hasTable($table))
            ->toBeTrue("{$relation} points at '{$table}', which does not exist");
    }
});

it('loads a product page for a product that has variants', function () {
    $product = variantProduct();

    ProductVariant::create([
        'product_id' => $product->id,
        'price' => 12900,
        'stock_status' => 'instock',
    ]);

    $this->get('/product/' . $product->slug . '/')->assertOk();
});

it('reads the attribute values attached to a variant', function () {
    /*
     * Not just "does not throw" — the pivot has to round-trip, or a relation
     * quietly pointed at some other real table would pass the smoke test above.
     */
    $product = variantProduct();

    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'price' => 12900,
        'stock_status' => 'instock',
    ]);

    $attribute = Attribute::create(['name' => 'Size', 'slug' => 'size-' . uniqid()]);

    $value = AttributeValue::create([
        'attribute_id' => $attribute->id,
        'name' => '50ml',
        'slug' => 'ml50-' . uniqid(),
    ]);

    $variant->attributeValues()->attach($value->id);
    $product->attributeValues()->attach($value->id);

    expect($variant->fresh()->attributeValues->pluck('id')->all())->toBe([$value->id])
        ->and($product->fresh()->attributeValues->pluck('id')->all())->toBe([$value->id]);

    // And the eager loads the storefront actually performs.
    $loaded = Product::with('variants.attributeValues')->find($product->id);

    expect($loaded->variants->first()->attributeValues->first()->name)->toBe('50ml');
});
