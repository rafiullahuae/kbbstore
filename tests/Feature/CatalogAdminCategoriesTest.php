<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\CategoriesApiController;
use App\Models\AdminUser;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\CatalogAdminRoutes;

/**
 * Catalog → Categories: the endpoint behind the tab that used to be a
 * hard-coded preview.
 *
 * Fixtures use "t-" slugs for the same reason BrandUrlTest and
 * BrandLogoDisplayTest do — the demo catalogue a migration seeds is already in
 * the table, and the first test in a process is not isolated (see tests/Pest.php).
 */

/* -------------------------------------------------------------- the guard */

it('rejects an unauthenticated caller on every category route', function () {
    CatalogAdminRoutes::wire($this->app);

    $category = Category::create(['slug' => 't-guarded', 'name' => 'T Guarded']);

    $this->getJson('/admin-api/categories')->assertStatus(401);
    $this->postJson('/admin-api/categories', ['name' => 'T Sneaky'])->assertStatus(401);
    $this->postJson('/admin-api/categories/reorder', ['order' => [$category->id]])->assertStatus(401);
    $this->putJson('/admin-api/categories/' . $category->id, ['name' => 'T Sneaky'])->assertStatus(401);
    $this->deleteJson('/admin-api/categories/' . $category->id)->assertStatus(401);

    // Not merely rejected — nothing was written either.
    expect(Category::query()->where('slug', 't-guarded')->exists())->toBeTrue()
        ->and(Category::query()->where('name', 'T Sneaky')->exists())->toBeFalse();
});

it('mounts the category routes into a group that already carries the admin guard', function () {
    // The header of routes/catalog-admin.php tells the integrator to require it
    // inside web.php's admin-api group, beside the brands require. If that
    // group ever stopped being guarded, mounting there would publish a
    // catalogue editor, so it is asserted rather than assumed.
    $existing = collect(app('router')->getRoutes()->getRoutes())
        ->first(fn ($r) => $r->uri() === 'admin-api/brands' && $r->methods()[0] === 'GET');

    expect($existing)->not->toBeNull()
        ->and($existing->middleware())->toContain('auth:admin');
});

it('defines list, create, reorder, update and delete against the categories controller', function () {
    $before = Route::getRoutes()->getRoutes();

    Route::prefix('admin-api')->group(base_path('routes/catalog-admin.php'));

    $added = collect(Route::getRoutes()->getRoutes())
        ->reject(fn ($r) => in_array($r, $before, true))
        ->values()
        ->filter(fn ($r) => str_starts_with($r->uri(), 'admin-api/categories'));

    $byUri = $added->keyBy(fn ($r) => $r->methods()[0] . ' ' . $r->uri());

    expect($byUri->keys()->all())->toEqualCanonicalizing([
        'GET admin-api/categories',
        'POST admin-api/categories',
        'POST admin-api/categories/reorder',
        'PUT admin-api/categories/{category}',
        'DELETE admin-api/categories/{category}',
    ]);

    expect($byUri['GET admin-api/categories']->getAction('controller'))
        ->toBe(CategoriesApiController::class . '@index')
        ->and($byUri['DELETE admin-api/categories/{category}']->getAction('controller'))
        ->toBe(CategoriesApiController::class . '@destroy');

    /*
     * /categories/reorder must be registered BEFORE /categories/{category},
     * or a POST to it matches the parameterised route and 404s on a
     * non-numeric id — a bug that reads as "reorder was never shipped".
     */
    $order = $added->values()->map(fn ($r) => $r->uri())->all();

    expect(array_search('admin-api/categories/reorder', $order, true))
        ->toBeLessThan(array_search('admin-api/categories/{category}', $order, true));
});

/* ---------------------------------------------------------------- the API */

describe('signed in as an admin', function () {
    beforeEach(function () {
        CatalogAdminRoutes::wire($this->app);

        $this->admin = AdminUser::create([
            'name' => 'T Admin', 'email' => 't-cat-admin@example.test',
            'password' => 'password-long-enough', 'role' => 'owner',
        ]);

        $this->actingAs($this->admin, 'admin');
    });

    it('creates a category, deriving the slug from the name', function () {
        $this->postJson('/admin-api/categories', [
            'name' => 'T Sun Care',
            'description' => 'SPF and after-sun.',
        ])->assertStatus(201)->assertJsonPath('category.slug', 't-sun-care');

        expect(Category::query()->where('slug', 't-sun-care')->value('path'))->toBe('t-sun-care');
    });

    it('answers a duplicate slug with a 422, not a 500', function () {
        Category::create(['slug' => 't-serums', 'name' => 'T Serums']);

        $this->postJson('/admin-api/categories', ['name' => 'T Serums', 'slug' => 't-serums'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('slug');

        // Same again through the derived slug — a duplicate NAME must not slip
        // past the uniqueness rule and land as a QueryException.
        $this->postJson('/admin-api/categories', ['name' => 'T Serums'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('slug');

        expect(Category::query()->where('slug', 't-serums')->count())->toBe(1);
    });

    it('edits a category and keeps its own slug', function () {
        $category = Category::create(['slug' => 't-toners', 'name' => 'T Toners']);

        $this->putJson('/admin-api/categories/' . $category->id, [
            'name' => 'T Toners & Mists', 'slug' => 't-toners',
        ])->assertOk();

        expect($category->fresh()->name)->toBe('T Toners & Mists');
    });

    it('builds the nested path and depth, and rebuilds them when a parent slug changes', function () {
        $parent = Category::create(['slug' => 't-skincare', 'name' => 'T Skincare']);

        $this->postJson('/admin-api/categories', [
            'name' => 'T Face Cleansers', 'parent_id' => $parent->id,
        ])->assertStatus(201);

        $child = Category::query()->where('slug', 't-face-cleansers')->first();

        expect($child->path)->toBe('t-skincare/t-face-cleansers')
            ->and((int) $child->depth)->toBe(1);

        // Renaming the parent's slug has to rewrite the child's cached path —
        // SeoFilesController reads that column straight out to build the
        // sitemap, so a stale one is a 404 published to search engines.
        $this->putJson('/admin-api/categories/' . $parent->id, [
            'name' => 'T Skincare', 'slug' => 't-skin-care',
        ])->assertOk();

        expect($child->fresh()->path)->toBe('t-skin-care/t-face-cleansers');
    });

    it('refuses a parent that would make a loop, and refuses being its own parent', function () {
        $parent = Category::create(['slug' => 't-loop-a', 'name' => 'T Loop A']);
        $child = Category::create(['slug' => 't-loop-b', 'name' => 'T Loop B', 'parent_id' => $parent->id]);

        $this->putJson('/admin-api/categories/' . $parent->id, [
            'name' => 'T Loop A', 'slug' => 't-loop-a', 'parent_id' => $child->id,
        ])->assertStatus(422)->assertJsonValidationErrors('parent_id');

        $this->putJson('/admin-api/categories/' . $parent->id, [
            'name' => 'T Loop A', 'slug' => 't-loop-a', 'parent_id' => $parent->id,
        ])->assertStatus(422)->assertJsonValidationErrors('parent_id');

        expect($parent->fresh()->parent_id)->toBeNull();
    });

    it('refuses an image URL that is not one the browser will treat as an image', function () {
        $this->postJson('/admin-api/categories', [
            'name' => 'T Payload',
            'image' => 'data:image/svg+xml;base64,PHN2Zz48c2NyaXB0Pg==',
        ])->assertStatus(422)->assertJsonValidationErrors('image');

        expect(Category::query()->where('name', 'T Payload')->exists())->toBeFalse();
    });

    it('sanitises a slug that tries to be a path rather than a segment', function () {
        $this->postJson('/admin-api/categories', ['name' => 'T Bad', 'slug' => '../../etc/passwd'])
            ->assertStatus(201)
            ->assertJsonPath('category.slug', 'etcpasswd');

        expect(Category::query()->pluck('slug')->contains(fn (string $s) => str_contains($s, '/')))
            ->toBeFalse();
    });

    it('lists categories with what is attached to each', function () {
        $parent = Category::create(['slug' => 't-listed', 'name' => 'T Listed']);
        Category::create(['slug' => 't-listed-kid', 'name' => 'T Listed Kid', 'parent_id' => $parent->id]);

        $product = Product::create([
            'slug' => 't-listed-serum', 'name' => 'T Listed Serum',
            'category_id' => $parent->id, 'status' => 'publish', 'is_visible' => true, 'price' => 100,
        ]);
        $product->categories()->attach($parent->id);

        $row = collect($this->getJson('/admin-api/categories')->assertOk()->json('categories'))
            ->firstWhere('slug', 't-listed');

        expect((int) $row['products_count'])->toBe(1)
            ->and((int) $row['primary_count'])->toBe(1)
            ->and((int) $row['children_count'])->toBe(1);
    });

    it('does not count a soft-deleted product against a category', function () {
        $category = Category::create(['slug' => 't-softdel', 'name' => 'T Softdel']);

        $product = Product::create([
            'slug' => 't-softdel-serum', 'name' => 'T Softdel Serum',
            'category_id' => $category->id, 'status' => 'publish', 'is_visible' => true, 'price' => 100,
        ]);
        $product->categories()->attach($category->id);
        $product->delete();

        $row = collect($this->getJson('/admin-api/categories')->assertOk()->json('categories'))
            ->firstWhere('slug', 't-softdel');

        expect((int) $row['products_count'])->toBe(0)
            ->and((int) $row['primary_count'])->toBe(0);
    });

    it('refuses to delete a category with products or children, then obeys force', function () {
        $parent = Category::create(['slug' => 't-doomed', 'name' => 'T Doomed']);
        $grand = Category::create(['slug' => 't-doomed-gp', 'name' => 'T Doomed GP']);
        $parent->update(['parent_id' => $grand->id]);
        $child = Category::create(['slug' => 't-doomed-kid', 'name' => 'T Doomed Kid', 'parent_id' => $parent->id]);

        $product = Product::create([
            'slug' => 't-doomed-serum', 'name' => 'T Doomed Serum',
            'category_id' => $parent->id, 'status' => 'publish', 'is_visible' => true, 'price' => 100,
        ]);
        $product->categories()->attach($parent->id);

        $this->deleteJson('/admin-api/categories/' . $parent->id)
            ->assertStatus(422)
            ->assertJsonPath('error', 'category_in_use')
            ->assertJsonPath('products_count', 1)
            ->assertJsonPath('primary_count', 1)
            ->assertJsonPath('children_count', 1);

        expect(Category::query()->whereKey($parent->id)->exists())->toBeTrue();

        $this->deleteJson('/admin-api/categories/' . $parent->id . '?force=1')->assertOk();

        // The category goes. The product stays — detached, not deleted — and
        // the child is re-parented to the deleted category's own parent rather
        // than orphaned to the root.
        expect(Category::query()->whereKey($parent->id)->exists())->toBeFalse()
            ->and($product->fresh())->not->toBeNull()
            ->and($product->fresh()->category_id)->toBeNull()
            ->and($product->fresh()->categories()->count())->toBe(0)
            ->and((int) $child->fresh()->parent_id)->toBe((int) $grand->id)
            ->and($child->fresh()->path)->toBe('t-doomed-gp/t-doomed-kid');
    });

    it('deletes an unused category without ceremony', function () {
        $category = Category::create(['slug' => 't-unused', 'name' => 'T Unused']);

        $this->deleteJson('/admin-api/categories/' . $category->id)->assertOk();

        expect(Category::query()->whereKey($category->id)->exists())->toBeFalse();
    });

    it('reorders one set of siblings and leaves unknown ids alone', function () {
        $a = Category::create(['slug' => 't-ord-a', 'name' => 'T Ord A', 'position' => 9]);
        $b = Category::create(['slug' => 't-ord-b', 'name' => 'T Ord B', 'position' => 9]);
        $c = Category::create(['slug' => 't-ord-c', 'name' => 'T Ord C', 'position' => 9]);

        $this->postJson('/admin-api/categories/reorder', [
            'order' => [$c->id, $a->id, 999999, $b->id],
        ])->assertOk();

        expect((int) $c->fresh()->position)->toBe(0)
            ->and((int) $a->fresh()->position)->toBe(1)
            ->and((int) $b->fresh()->position)->toBe(2);
    });

    it('never runs a reorder without an order', function () {
        $this->postJson('/admin-api/categories/reorder', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('order');
    });

    it('leaves updated_at alone when it rebuilds the cached path', function () {
        // path/depth feed the sitemap; updated_at feeds its <lastmod>. Moving
        // one category must not tell search engines every category changed.
        $parent = Category::create(['slug' => 't-stamp', 'name' => 'T Stamp']);
        $other = Category::create(['slug' => 't-stamp-other', 'name' => 'T Stamp Other']);

        DB::table('categories')->where('id', $other->id)
            ->update(['updated_at' => '2020-01-01 00:00:00']);

        $this->postJson('/admin-api/categories', [
            'name' => 'T Stamp Kid', 'parent_id' => $parent->id,
        ])->assertStatus(201);

        expect((string) DB::table('categories')->where('id', $other->id)->value('updated_at'))
            ->toStartWith('2020-01-01');
    });
});
