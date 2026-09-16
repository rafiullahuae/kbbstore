<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;

/**
 * Two rules that the retired create form enforced and the editor did not.
 *
 * Consolidating three product screens into one removed the duplicates — and
 * quietly removed these with them. A subtractive change is exactly where a
 * guard goes missing without anything turning red, because the tests that
 * covered it are deleted alongside the screen they belonged to.
 */
function pegAdmin(): AdminUser
{
    $a = AdminUser::create([
        'name' => 'Guard Admin',
        'email' => 'peg-'.uniqid().'@kbb.test',
        'password' => bcrypt('secret-secret'),
    ]);

    test()->actingAs($a, 'admin');

    return $a;
}

function pegPayload(array $over = []): array
{
    $brand = Brand::create(['name' => 'PEG Brand '.uniqid(), 'slug' => 'peg-brand-'.uniqid()]);
    $cat = Category::create(['name' => 'PEG Cat '.uniqid(), 'slug' => 'peg-cat-'.uniqid()]);

    return array_merge([
        'name' => 'PEG Product '.uniqid(),
        'slug' => 'peg-product-'.uniqid(),
        'brand_id' => $brand->id,
        'category_ids' => [$cat->id],
        'primary_category_id' => $cat->id,
        'price' => '99.50',
        'status' => 'publish',
        'is_visible' => true,
        'stock_status' => 'instock',
    ], $over);
}

it('refuses a product that is published and invisible at once', function () {
    pegAdmin();

    // Published + invisible saves cleanly and then appears nowhere — not the
    // shop, not its category page, not the sitemap — while the editor says
    // "Published". Refused rather than silently producing a ghost.
    test()->postJson('/admin-api/product-editor-create', pegPayload(['is_visible' => false]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('is_visible');

    // Private + invisible is the legitimate way to keep something off the shop.
    test()->postJson('/admin-api/product-editor-create', pegPayload([
        'status' => 'private',
        'is_visible' => false,
    ]))->assertSuccessful();
});

it('refuses the same combination on an existing product, not just on create', function () {
    pegAdmin();

    $created = test()->postJson('/admin-api/product-editor-create', pegPayload())
        ->assertSuccessful()->json();

    $id = $created['product']['id'] ?? $created['id'] ?? null;
    expect($id)->not->toBeNull();

    test()->postJson('/admin-api/product-editor-save/'.$id, [
        'status' => 'publish',
        'is_visible' => false,
    ])->assertStatus(422)->assertJsonValidationErrors('is_visible');

    expect((bool) Product::find($id)->is_visible)->toBeTrue();
});

it('refuses an image address that is not http(s) or a path on this site', function () {
    pegAdmin();

    /*
     * data:image/svg+xml is a scriptable document wearing an image's name.
     * The retired form refused it; the editor accepted any string up to 500
     * characters. Defence in depth rather than a hole anyone can walk through
     * today — an SVG loaded through <img> does not execute script in a current
     * browser — but the value is operator-supplied, stored, and where it is
     * rendered next is not the validator's to assume.
     */
    foreach ([
        'data:image/svg+xml;base64,PHN2Zz48c2NyaXB0PmFsZXJ0KDEpPC9zY3JpcHQ+PC9zdmc+',
        'javascript:alert(1)',
        '//evil.example.com/x.png',
    ] as $bad) {
        test()->postJson('/admin-api/product-editor-create', pegPayload(['image' => $bad]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('image');
    }

    // The two shapes that must keep working.
    foreach (['/media/real.jpg', 'https://cdn.example.com/real.jpg'] as $good) {
        test()->postJson('/admin-api/product-editor-create', pegPayload(['image' => $good]))
            ->assertSuccessful();
    }
});

it('applies the same image rule to the gallery and to the social share image', function () {
    pegAdmin();

    test()->postJson('/admin-api/product-editor-create', pegPayload([
        'images' => ['/media/ok.jpg', 'data:image/svg+xml;base64,PHN2Zz48L3N2Zz4='],
    ]))->assertStatus(422)->assertJsonValidationErrors('images.1');

    test()->postJson('/admin-api/product-editor-create', pegPayload([
        'seo' => ['og_image' => 'javascript:alert(1)'],
    ]))->assertStatus(422)->assertJsonValidationErrors('seo.og_image');
});
