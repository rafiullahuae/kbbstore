<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Category;
use App\Models\Product;
use App\Support\CategoryPath;
use Illuminate\Support\Facades\DB;
use Tests\Support\CategoryLaneRoutes;

/**
 * /product-category/{nested/path}/ is an indexed URL with inbound links, so
 * what it does with a path it does not recognise is a contract, not an
 * implementation detail.
 *
 * Fixtures use "aq-" slugs for the same reason CatalogAdminCategoriesTest uses
 * "t-" ones: a migration seeds a demo catalogue into the table, and the first
 * test in a process is not isolated (see tests/Pest.php).
 */
function aqAdmin(): AdminUser
{
    return AdminUser::query()->firstOrCreate(
        ['email' => 'lane-aq@kbb.test'],
        ['name' => 'Lane AQ', 'password' => bcrypt('secret-aq')]
    );
}

function aqCategory(string $slug, string $name, ?int $parentId = null): Category
{
    $c = Category::create(['slug' => $slug, 'name' => $name, 'parent_id' => $parentId]);
    $c->update(['path' => $c->buildPath(), 'depth' => substr_count($c->buildPath(), '/')]);

    return $c->fresh();
}

function aqProduct(string $slug, Category $cat, string $status = 'publish', bool $visible = true): Product
{
    $p = Product::create([
        'slug' => $slug,
        'name' => strtoupper($slug),
        'price' => 5000,
        'status' => $status,
        'is_visible' => $visible,
        'stock_status' => 'instock',
        'type' => 'simple',
    ]);

    DB::table('category_product')->insert(['category_id' => $cat->id, 'product_id' => $p->id]);

    return $p;
}

/* ------------------------------------------------- the bug, as it ships today */

it('documents that the SHIPPED archive route soft-404s every unknown path', function () {
    // Deliberately NOT wiring this lane's controller: this asserts the
    // behaviour of routes/web.php as it stands, so that the fix below is
    // demonstrably a change and not a restatement.
    aqCategory('aq-live-cat', 'AQ Live Cat');

    // A category that does not exist. The shipped closure looks up
    // basename($path), gets null, and ShopController renders "Shop all".
    $this->get('/product-category/aq-totally-invented/')
        ->assertStatus(200)
        ->assertSee('Shop all', false);

    // Any prefix at all validates, because only the last segment is read.
    $this->get('/product-category/utter/nonsense/aq-live-cat/')->assertStatus(200);
})->group('shipped-behaviour');

/* ------------------------------------------------------------- the resolver */

it('404s a path whose leaf names no category', function () {
    CategoryLaneRoutes::wireStorefront($this->app);

    $this->get('/product-category/aq-no-such-thing/')->assertStatus(404);
    $this->get('/product-category/aq-no/aq-such/aq-thing/')->assertStatus(404);
});

it('serves a real nested path and 301s a path with the wrong ancestry', function () {
    CategoryLaneRoutes::wireStorefront($this->app);

    $parent = aqCategory('aq-skincare', 'AQ Skincare');
    aqCategory('aq-cleansers', 'AQ Cleansers', (int) $parent->id);

    // The canonical address.
    $this->get('/product-category/aq-skincare/aq-cleansers/')->assertStatus(200);

    // The leaf is real, the ancestry is invented: one page, one address.
    $this->get('/product-category/made-up/aq-cleansers/')
        ->assertStatus(301)
        ->assertRedirect(CategoryPath::url('aq-skincare/aq-cleansers'));

    // The bare leaf of a nested category is also non-canonical.
    $this->get('/product-category/aq-cleansers/')
        ->assertStatus(301)
        ->assertRedirect(CategoryPath::url('aq-skincare/aq-cleansers'));
});

/* ------------------------------------------------------------------ renames */

it('does not move a URL when only the display name changes', function () {
    CategoryLaneRoutes::wire($this->app);
    CategoryLaneRoutes::wireStorefront($this->app);

    $cat = aqCategory('aq-sun-care', 'AQ Sun Care');

    $this->actingAs(aqAdmin(), 'admin')
        ->putJson('/admin-api/categories/' . $cat->id, [
            'name' => 'AQ Suncare & SPF',
            'slug' => $cat->slug,
        ])
        ->assertOk()
        ->assertJsonPath('redirects', []);

    expect($cat->fresh()->slug)->toBe('aq-sun-care');

    // The indexed URL is untouched and still 200s.
    $this->get('/product-category/aq-sun-care/')->assertStatus(200);
});

it('301s the old path when the slug really changes, instead of breaking it', function () {
    CategoryLaneRoutes::wire($this->app);
    CategoryLaneRoutes::wireStorefront($this->app);

    $cat = aqCategory('aq-old-slug', 'AQ Old Slug');

    $this->actingAs(aqAdmin(), 'admin')
        ->putJson('/admin-api/categories/' . $cat->id, [
            'name' => 'AQ New Name',
            'slug' => 'aq-new-slug',
        ])
        ->assertOk();

    $this->get('/product-category/aq-new-slug/')->assertStatus(200);

    // The indexed address survives as a 301 rather than 404ing — and, more to
    // the point, rather than going on answering 200 with "Shop all".
    $this->get('/product-category/aq-old-slug/')
        ->assertStatus(301)
        ->assertRedirect(CategoryPath::url('aq-new-slug'));
});

it('redirects every descendant URL when a parent is re-parented', function () {
    CategoryLaneRoutes::wire($this->app);
    CategoryLaneRoutes::wireStorefront($this->app);

    $root = aqCategory('aq-root', 'AQ Root');
    $mid = aqCategory('aq-mid', 'AQ Mid', (int) $root->id);
    aqCategory('aq-leaf', 'AQ Leaf', (int) $mid->id);

    // aq-mid moves to the top level, taking aq-leaf with it.
    $this->actingAs(aqAdmin(), 'admin')
        ->putJson('/admin-api/categories/' . $mid->id, [
            'name' => 'AQ Mid',
            'slug' => 'aq-mid',
            'parent_id' => null,
        ])
        ->assertOk();

    // The child's old URL is the one that is easy to forget, so it is the one
    // asserted hardest: a whole branch of indexed URLs moved, not just the row
    // the operator edited.
    $this->get('/product-category/aq-root/aq-mid/aq-leaf/')
        ->assertStatus(301)
        ->assertRedirect(CategoryPath::url('aq-mid/aq-leaf'));

    $this->get('/product-category/aq-root/aq-mid/')
        ->assertStatus(301)
        ->assertRedirect(CategoryPath::url('aq-mid'));

    $this->get('/product-category/aq-mid/aq-leaf/')->assertStatus(200);
});

/* ------------------------------------------------------------------ deletes */

it('refuses to delete a category that has anything attached, and says what', function () {
    CategoryLaneRoutes::wire($this->app);

    $cat = aqCategory('aq-busy', 'AQ Busy');
    aqProduct('aq-busy-p1', $cat);

    $this->actingAs(aqAdmin(), 'admin')
        ->deleteJson('/admin-api/categories/' . $cat->id)
        ->assertStatus(422)
        ->assertJsonPath('error', 'category_in_use')
        ->assertJsonPath('products_count', 1);

    expect(Category::query()->whereKey($cat->id)->exists())->toBeTrue();
});

it('points a deleted category at its parent, and 404s one deleted from the top level', function () {
    CategoryLaneRoutes::wire($this->app);
    CategoryLaneRoutes::wireStorefront($this->app);

    $parent = aqCategory('aq-keep', 'AQ Keep');
    $child = aqCategory('aq-goes', 'AQ Goes', (int) $parent->id);
    $lonely = aqCategory('aq-lonely', 'AQ Lonely');

    $this->actingAs(aqAdmin(), 'admin')
        ->deleteJson('/admin-api/categories/' . $child->id . '?force=1')
        ->assertOk();

    $this->get('/product-category/aq-keep/aq-goes/')
        ->assertStatus(301)
        ->assertRedirect(CategoryPath::url('aq-keep'));

    $this->actingAs(aqAdmin(), 'admin')
        ->deleteJson('/admin-api/categories/' . $lonely->id . '?force=1')
        ->assertOk();

    // Nowhere sensible to send it, so the honest answer.
    $this->get('/product-category/aq-lonely/')->assertStatus(404);
});

/* ------------------------------------------------------------------- merges */

it('merges a category into another, moving its products and 301ing its URL', function () {
    CategoryLaneRoutes::wire($this->app);
    CategoryLaneRoutes::wireStorefront($this->app);

    $from = aqCategory('aq-from', 'AQ From');
    $to = aqCategory('aq-to', 'AQ To');

    $shared = aqProduct('aq-shared', $from);
    aqProduct('aq-only-from', $from);

    // Already filed under both — the case a plain insert would raise on.
    DB::table('category_product')->insert(['category_id' => $to->id, 'product_id' => $shared->id]);

    $this->actingAs(aqAdmin(), 'admin')
        ->postJson('/admin-api/categories/' . $from->id . '/merge', ['target_id' => $to->id])
        ->assertOk()
        ->assertJsonPath('ok', true);

    expect(Category::query()->whereKey($from->id)->exists())->toBeFalse();

    $inTarget = DB::table('category_product')->where('category_id', $to->id)->count();
    expect($inTarget)->toBe(2);

    $this->get('/product-category/aq-from/')
        ->assertStatus(301)
        ->assertRedirect(CategoryPath::url('aq-to'));
});

it('refuses to merge a category into itself or into its own descendant', function () {
    CategoryLaneRoutes::wire($this->app);

    $parent = aqCategory('aq-parent-m', 'AQ Parent M');
    $child = aqCategory('aq-child-m', 'AQ Child M', (int) $parent->id);

    $this->actingAs(aqAdmin(), 'admin')
        ->postJson('/admin-api/categories/' . $parent->id . '/merge', ['target_id' => $parent->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors('target_id');

    $this->actingAs(aqAdmin(), 'admin')
        ->postJson('/admin-api/categories/' . $parent->id . '/merge', ['target_id' => $child->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors('target_id');

    expect(Category::query()->whereKey($parent->id)->exists())->toBeTrue();
});

/* ------------------------------------------------------------------- guards */

it('rejects anonymous, a storefront customer and a plain web user on every lane route', function () {
    CategoryLaneRoutes::wire($this->app);

    $cat = aqCategory('aq-guarded', 'AQ Guarded');
    $target = aqCategory('aq-guard-target', 'AQ Guard Target');

    // Asserted from the REGISTERED routes, not from the file: RouteRegistrar::
    // middleware() REPLACES rather than appends, so only the router knows
    // whether the guard actually survived the grouping.
    $calls = [
        ['postJson', '/admin-api/categories/' . $cat->id . '/merge', ['target_id' => $target->id]],
        ['getJson', '/admin-api/categories/redirects', null],
        ['deleteJson', '/admin-api/categories/redirects/1', null],
        ['getJson', '/admin-api/brands-tree', null],
        ['postJson', '/admin-api/brands-tree/reorder', ['order' => [1]]],
    ];

    foreach ($calls as [$method, $uri, $payload]) {
        $this->{$method}($uri, $payload ?? [])->assertStatus(401);
    }

    // A logged-in storefront CUSTOMER is not an admin. This is the case a
    // guard written as `auth` rather than `auth:admin` would wave through.
    $customer = \App\Models\Customer::query()->firstOrCreate(
        ['email' => 'aq-shopper@kbb.test'],
        ['name' => 'AQ Shopper', 'password' => bcrypt('secret-aq')]
    );

    foreach ($calls as [$method, $uri, $payload]) {
        $this->actingAs($customer, 'customer')->{$method}($uri, $payload ?? [])->assertStatus(401);
    }

    // Nothing was written by any of that.
    expect(Category::query()->whereKey($cat->id)->exists())->toBeTrue();
});

it('registers every lane route against the admin guard', function () {
    CategoryLaneRoutes::wire($this->app);

    $expected = [
        'POST admin-api/categories/{category}/merge',
        'GET admin-api/categories/redirects',
        'DELETE admin-api/categories/redirects/{redirect}',
        'GET admin-api/brands-tree',
        'POST admin-api/brands-tree/reorder',
    ];

    $byKey = collect(app('router')->getRoutes()->getRoutes())
        ->keyBy(fn ($r) => $r->methods()[0] . ' ' . $r->uri());

    foreach ($expected as $key) {
        expect($byKey->has($key))->toBeTrue("route missing: {$key}");
        expect($byKey[$key]->middleware())->toContain('auth:admin');
    }
});

it('keeps /categories/redirects reachable behind the parameterised category routes', function () {
    CategoryLaneRoutes::wire($this->app);

    // /categories/{category} is constrained to [0-9]+, so a literal
    // "redirects" segment cannot be swallowed by it. This is the exact shape
    // that made /orders/list unreachable behind /orders/{id}.
    $matched = app('router')->getRoutes()
        ->match(\Illuminate\Http\Request::create('/admin-api/categories/redirects', 'GET'));

    expect($matched->getActionName())
        ->toContain('CategoryRedirectsApiController@index');
});
