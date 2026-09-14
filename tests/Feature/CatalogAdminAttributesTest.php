<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AttributesApiController;
use App\Models\AdminUser;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\CatalogAdminRoutes;

/**
 * Catalog → Attributes: the endpoint behind the other tab that used to be a
 * hard-coded preview.
 *
 * The relationship these tests are really pinning: nothing joins a product to
 * an ATTRIBUTE. Products join to its VALUES through product_attribute_value,
 * and variants join to them through product_variant_attribute_value. Both
 * pivots cascade off the value, and the value cascades off the attribute, so a
 * plain DELETE on `pa_color` would strip every variable product of the terms
 * its variants are defined by — leaving variants on sale with nothing left to
 * say what they are. That is what the force/refuse split exists to stop, and
 * it is the case with the most assertions below.
 */

/** A product, a variant of it, and one attribute value defining that variant. */
function seedAttributeInUse(string $tag): array
{
    $attribute = Attribute::create([
        'slug' => 't-' . $tag, 'name' => 'T ' . ucfirst($tag),
        'is_variation_axis' => true, 'is_filterable' => true,
    ]);

    $value = AttributeValue::create([
        'attribute_id' => $attribute->id, 'slug' => 't-' . $tag . '-pink', 'name' => 'T Pink',
    ]);

    $product = Product::create([
        'slug' => 't-' . $tag . '-tint', 'name' => 'T ' . ucfirst($tag) . ' Tint',
        'type' => 'variable', 'status' => 'publish', 'is_visible' => true, 'price' => 100,
    ]);

    $variant = ProductVariant::create([
        'product_id' => $product->id, 'sku' => 't-' . $tag . '-tint-pink',
        'price' => 100, 'stock_status' => 'instock',
    ]);

    DB::table('product_attribute_value')->insert([
        'product_id' => $product->id, 'attribute_value_id' => $value->id,
    ]);
    DB::table('product_variant_attribute_value')->insert([
        'product_variant_id' => $variant->id, 'attribute_value_id' => $value->id,
    ]);

    return [$attribute, $value, $product, $variant];
}

/* -------------------------------------------------------------- the guard */

it('rejects an unauthenticated caller on every attribute route', function () {
    CatalogAdminRoutes::wire($this->app);

    $attribute = Attribute::create(['slug' => 't-guarded-attr', 'name' => 'T Guarded']);
    $value = AttributeValue::create([
        'attribute_id' => $attribute->id, 'slug' => 't-guarded-term', 'name' => 'T Guarded Term',
    ]);

    $base = '/admin-api/attributes';

    $this->getJson($base)->assertStatus(401);
    $this->postJson($base, ['name' => 'T Sneaky'])->assertStatus(401);
    $this->putJson($base . '/' . $attribute->id, ['name' => 'T Sneaky'])->assertStatus(401);
    $this->deleteJson($base . '/' . $attribute->id)->assertStatus(401);
    $this->postJson($base . '/' . $attribute->id . '/values', ['name' => 'T Sneaky'])->assertStatus(401);
    $this->putJson($base . '/' . $attribute->id . '/values/' . $value->id, ['name' => 'T Sneaky'])->assertStatus(401);
    $this->deleteJson($base . '/' . $attribute->id . '/values/' . $value->id)->assertStatus(401);

    // Not merely rejected — nothing was written or removed either.
    expect(Attribute::query()->whereKey($attribute->id)->exists())->toBeTrue()
        ->and(AttributeValue::query()->whereKey($value->id)->exists())->toBeTrue()
        ->and(Attribute::query()->where('name', 'T Sneaky')->exists())->toBeFalse()
        ->and(AttributeValue::query()->where('name', 'T Sneaky')->exists())->toBeFalse();
});

it('defines the seven attribute routes against the attributes controller', function () {
    $before = Route::getRoutes()->getRoutes();

    Route::prefix('admin-api')->group(base_path('routes/catalog-admin.php'));

    $added = collect(Route::getRoutes()->getRoutes())
        ->reject(fn ($r) => in_array($r, $before, true))
        ->values()
        ->filter(fn ($r) => str_starts_with($r->uri(), 'admin-api/attributes'));

    $byUri = $added->keyBy(fn ($r) => $r->methods()[0] . ' ' . $r->uri());

    expect($byUri->keys()->all())->toEqualCanonicalizing([
        'GET admin-api/attributes',
        'POST admin-api/attributes',
        'PUT admin-api/attributes/{attribute}',
        'DELETE admin-api/attributes/{attribute}',
        'POST admin-api/attributes/{attribute}/values',
        'PUT admin-api/attributes/{attribute}/values/{value}',
        'DELETE admin-api/attributes/{attribute}/values/{value}',
    ]);

    expect($byUri['GET admin-api/attributes']->getAction('controller'))
        ->toBe(AttributesApiController::class . '@index')
        ->and($byUri['DELETE admin-api/attributes/{attribute}/values/{value}']->getAction('controller'))
        ->toBe(AttributesApiController::class . '@destroyValue');
});

/* ---------------------------------------------------------------- the API */

describe('signed in as an admin', function () {
    beforeEach(function () {
        CatalogAdminRoutes::wire($this->app);

        $this->admin = AdminUser::create([
            'name' => 'T Admin', 'email' => 't-attr-admin@example.test',
            'password' => 'password-long-enough', 'role' => 'owner',
        ]);

        $this->actingAs($this->admin, 'admin');
    });

    it('creates an attribute, deriving the slug from the name', function () {
        $this->postJson('/admin-api/attributes', [
            'name' => 'T Shades', 'query_var' => 'filter_t_shades', 'is_variation_axis' => true,
        ])->assertStatus(201)->assertJsonPath('attribute.slug', 't-shades');

        $row = Attribute::query()->where('slug', 't-shades')->first();

        expect($row->query_var)->toBe('filter_t_shades')
            ->and((bool) $row->is_variation_axis)->toBeTrue()
            ->and((bool) $row->is_filterable)->toBeTrue();
    });

    it('answers a duplicate attribute slug with a 422, not a 500', function () {
        Attribute::create(['slug' => 't-color', 'name' => 'T Color']);

        $this->postJson('/admin-api/attributes', ['name' => 'T Color', 'slug' => 't-color'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('slug');

        // And through the derived slug, which is how a duplicate NAME would
        // otherwise reach the database as a QueryException.
        $this->postJson('/admin-api/attributes', ['name' => 'T Color'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('slug');

        expect(Attribute::query()->where('slug', 't-color')->count())->toBe(1);
    });

    it('answers a duplicate filter parameter with a 422', function () {
        Attribute::create(['slug' => 't-qv-one', 'name' => 'T QV One', 'query_var' => 'filter_t_qv']);

        $this->postJson('/admin-api/attributes', ['name' => 'T QV Two', 'query_var' => 'filter_t_qv'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('query_var');
    });

    it('edits an attribute and keeps its own slug', function () {
        $attribute = Attribute::create(['slug' => 't-size', 'name' => 'T Size']);

        $this->putJson('/admin-api/attributes/' . $attribute->id, [
            'name' => 'T Size / Volume', 'slug' => 't-size', 'is_filterable' => false,
        ])->assertOk();

        expect($attribute->fresh()->name)->toBe('T Size / Volume')
            ->and((bool) $attribute->fresh()->is_filterable)->toBeFalse();
    });

    it('creates, edits and deletes a term under its attribute', function () {
        $attribute = Attribute::create(['slug' => 't-terms', 'name' => 'T Terms']);

        $this->postJson('/admin-api/attributes/' . $attribute->id . '/values', [
            'name' => 'T Rose Beige', 'swatch_color' => '#E0567B',
        ])->assertStatus(201)->assertJsonPath('value.slug', 't-rose-beige');

        $value = AttributeValue::query()->where('slug', 't-rose-beige')->first();

        expect((int) $value->attribute_id)->toBe((int) $attribute->id)
            ->and($value->swatch_color)->toBe('#E0567B');

        $this->putJson('/admin-api/attributes/' . $attribute->id . '/values/' . $value->id, [
            'name' => 'T Rose', 'slug' => 't-rose-beige',
        ])->assertOk();

        expect($value->fresh()->name)->toBe('T Rose');

        $this->deleteJson('/admin-api/attributes/' . $attribute->id . '/values/' . $value->id)
            ->assertOk();

        expect(AttributeValue::query()->whereKey($value->id)->exists())->toBeFalse();
    });

    it('scopes a term slug to its own attribute, and refuses a duplicate within one', function () {
        $size = Attribute::create(['slug' => 't-dup-size', 'name' => 'T Dup Size']);
        $shades = Attribute::create(['slug' => 't-dup-shades', 'name' => 'T Dup Shades']);

        $this->postJson('/admin-api/attributes/' . $size->id . '/values', ['name' => 'T Large'])
            ->assertStatus(201);

        // The same term name under a DIFFERENT attribute is legitimate — the
        // table's own unique index is (attribute_id, slug), and refusing this
        // would be wrong.
        $this->postJson('/admin-api/attributes/' . $shades->id . '/values', ['name' => 'T Large'])
            ->assertStatus(201);

        // Twice under the same attribute is not.
        $this->postJson('/admin-api/attributes/' . $size->id . '/values', ['name' => 'T Large'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('slug');

        expect(AttributeValue::query()->where('slug', 't-large')->count())->toBe(2);
    });

    it('refuses to touch a term reached through the wrong attribute', function () {
        $mine = Attribute::create(['slug' => 't-own-mine', 'name' => 'T Own Mine']);
        $theirs = Attribute::create(['slug' => 't-own-theirs', 'name' => 'T Own Theirs']);
        $value = AttributeValue::create([
            'attribute_id' => $theirs->id, 'slug' => 't-own-term', 'name' => 'T Own Term',
        ]);

        $this->putJson('/admin-api/attributes/' . $mine->id . '/values/' . $value->id, [
            'name' => 'T Hijacked',
        ])->assertStatus(404);

        $this->deleteJson('/admin-api/attributes/' . $mine->id . '/values/' . $value->id)
            ->assertStatus(404);

        expect($value->fresh()->name)->toBe('T Own Term');
    });

    it('refuses a swatch image that is not one the browser will treat as an image', function () {
        $attribute = Attribute::create(['slug' => 't-swatch', 'name' => 'T Swatch']);

        $this->postJson('/admin-api/attributes/' . $attribute->id . '/values', [
            'name' => 'T Payload',
            'swatch_image' => 'data:image/svg+xml;base64,PHN2Zz48c2NyaXB0Pg==',
        ])->assertStatus(422)->assertJsonValidationErrors('swatch_image');

        expect(AttributeValue::query()->where('name', 'T Payload')->exists())->toBeFalse();
    });

    it('lists attributes with their terms and how much of the catalogue stands on each', function () {
        [$attribute, $value] = seedAttributeInUse('listed');

        $row = collect($this->getJson('/admin-api/attributes')->assertOk()->json('attributes'))
            ->firstWhere('slug', 't-listed');

        expect((int) $row['values_count'])->toBe(1)
            ->and((int) $row['products_count'])->toBe(1)
            ->and((int) $row['variants_count'])->toBe(1)
            ->and($row['values'])->toHaveCount(1)
            ->and($row['values'][0]['slug'])->toBe('t-listed-pink')
            ->and((int) $row['values'][0]['products_count'])->toBe(1)
            ->and((int) $row['values'][0]['variants_count'])->toBe(1);
    });

    it('refuses to delete an attribute whose terms are in use, then obeys force without destroying anything', function () {
        [$attribute, $value, $product, $variant] = seedAttributeInUse('doomed');

        $this->deleteJson('/admin-api/attributes/' . $attribute->id)
            ->assertStatus(422)
            ->assertJsonPath('error', 'attribute_in_use')
            ->assertJsonPath('products_count', 1)
            ->assertJsonPath('variants_count', 1);

        expect(Attribute::query()->whereKey($attribute->id)->exists())->toBeTrue()
            ->and(AttributeValue::query()->whereKey($value->id)->exists())->toBeTrue();

        $this->deleteJson('/admin-api/attributes/' . $attribute->id . '?force=1')->assertOk();

        // The attribute and its terms go, and both pivots are cleared. The
        // product and the variant are still there — this endpoint never
        // deletes saleable rows, only the links to them.
        expect(Attribute::query()->whereKey($attribute->id)->exists())->toBeFalse()
            ->and(AttributeValue::query()->whereKey($value->id)->exists())->toBeFalse()
            ->and(Product::query()->whereKey($product->id)->exists())->toBeTrue()
            ->and(ProductVariant::query()->whereKey($variant->id)->exists())->toBeTrue()
            ->and(DB::table('product_attribute_value')->where('product_id', $product->id)->count())->toBe(0)
            ->and(DB::table('product_variant_attribute_value')->where('product_variant_id', $variant->id)->count())->toBe(0);
    });

    it('refuses to delete a single term that is in use, then obeys force without destroying anything', function () {
        [$attribute, $value, $product, $variant] = seedAttributeInUse('term');

        $path = '/admin-api/attributes/' . $attribute->id . '/values/' . $value->id;

        $this->deleteJson($path)
            ->assertStatus(422)
            ->assertJsonPath('error', 'value_in_use')
            ->assertJsonPath('products_count', 1)
            ->assertJsonPath('variants_count', 1);

        expect(AttributeValue::query()->whereKey($value->id)->exists())->toBeTrue();

        $this->deleteJson($path . '?force=1')->assertOk();

        expect(AttributeValue::query()->whereKey($value->id)->exists())->toBeFalse()
            ->and(Attribute::query()->whereKey($attribute->id)->exists())->toBeTrue()
            ->and(Product::query()->whereKey($product->id)->exists())->toBeTrue()
            ->and(ProductVariant::query()->whereKey($variant->id)->exists())->toBeTrue();
    });

    it('deletes an unused attribute and its unused terms without ceremony', function () {
        $attribute = Attribute::create(['slug' => 't-unused-attr', 'name' => 'T Unused Attr']);
        $value = AttributeValue::create([
            'attribute_id' => $attribute->id, 'slug' => 't-unused-term', 'name' => 'T Unused Term',
        ]);

        $this->deleteJson('/admin-api/attributes/' . $attribute->id)->assertOk();

        expect(Attribute::query()->whereKey($attribute->id)->exists())->toBeFalse()
            ->and(AttributeValue::query()->whereKey($value->id)->exists())->toBeFalse();
    });
});
