<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\CategoryLaneRoutes;

/**
 * An edit made in the admin has to reach the storefront.
 *
 * Two separate failures live here, and both were silent.
 *
 * 1. NOTHING IN CategoriesApiController FLUSHED ANY CACHE. ShopController::
 *    flushSidebarCache() has existed all along and four other admin
 *    controllers call it. The one screen whose entire job is editing
 *    categories did not. So creating, renaming, re-parenting, reordering or
 *    deleting a category left the shop sidebar and the homepage tiles showing
 *    the previous catalogue for the rest of a 900-second cache entry — long
 *    enough for the owner to decide the save had not worked and do it again.
 *
 * 2. THE REORDER WROTE A COLUMN NOBODY READ. The sidebar was ordered by
 *    `products_count` descending, so `categories.position` — the column the
 *    ▲▼ buttons write — was never consulted by anything on the storefront.
 *    The feature was decorative.
 *
 * The cache store is `array` under phpunit.xml, which is a real store for the
 * duration of one test, so a missing flush genuinely fails here rather than
 * being papered over by a null driver.
 */
function syncAdmin(): AdminUser
{
    return AdminUser::query()->firstOrCreate(
        ['email' => 'sync-aq@kbb.test'],
        ['name' => 'Sync AQ', 'password' => bcrypt('secret-aq')]
    );
}

function syncProduct(string $slug, Category $cat): Product
{
    $p = Product::create([
        'slug' => $slug, 'name' => strtoupper($slug), 'price' => 1500,
        'status' => 'publish', 'is_visible' => true, 'stock_status' => 'instock',
        'type' => 'simple',
    ]);

    DB::table('category_product')->insert(['category_id' => $cat->id, 'product_id' => $p->id]);

    return $p;
}

it('shows a newly created category on the storefront without waiting for a cache to expire', function () {
    CategoryLaneRoutes::wire($this->app);
    CategoryLaneRoutes::wireStorefront($this->app);

    // Warm the sidebar cache, the way a visitor would.
    $this->get('/shop')->assertStatus(200);
    expect(Cache::has('kbb.shop.cats'))->toBeTrue();

    $created = $this->actingAs(syncAdmin(), 'admin')
        ->postJson('/admin-api/categories', ['name' => 'AQ Fresh Category'])
        ->assertStatus(201)
        ->json('category');

    // The write must have invalidated the cached sidebar.
    expect(Cache::has('kbb.shop.cats'))->toBeFalse();

    // And the category is reachable at its real path immediately.
    syncProduct('aq-fresh-p', Category::find($created['id']));

    $this->get('/product-category/' . $created['slug'] . '/')
        ->assertStatus(200)
        ->assertSee('AQ Fresh Category', false);
});

it('changes the order the shop sidebar shows when categories are reordered', function () {
    CategoryLaneRoutes::wire($this->app);

    // Deliberately unequal sizes, and deliberately the WRONG way round for a
    // size ordering: "AQ Small" has fewer products but is going to be dragged
    // to the top. Under the old ordering it could never get there.
    $big = Category::create(['slug' => 'aq-ord-big', 'name' => 'AQ Ord Big', 'position' => 0]);
    $small = Category::create(['slug' => 'aq-ord-small', 'name' => 'AQ Ord Small', 'position' => 0]);

    syncProduct('aq-ord-b1', $big);
    syncProduct('aq-ord-b2', $big);
    syncProduct('aq-ord-b3', $big);
    syncProduct('aq-ord-s1', $small);

    $order = fn () => collect($this->get('/shop')->viewData('cats'))
        ->pluck('slug')
        ->filter(fn ($s) => str_starts_with($s, 'aq-ord-'))
        ->values()
        ->all();

    // Before: biggest first, because that is all the sidebar knew how to do.
    expect($order())->toBe(['aq-ord-big', 'aq-ord-small']);

    $this->actingAs(syncAdmin(), 'admin')
        ->postJson('/admin-api/categories/reorder', ['order' => [$small->id, $big->id]])
        ->assertOk();

    // After: the curated order wins, and the reorder flushed the cache so the
    // change is visible on the very next request.
    expect($order())->toBe(['aq-ord-small', 'aq-ord-big']);
});

it('changes the order the shop sidebar shows when brands are reordered', function () {
    CategoryLaneRoutes::wire($this->app);

    $a = Brand::create(['slug' => 'aq-brand-alpha', 'name' => 'AQ Brand Alpha', 'position' => 0]);
    $z = Brand::create(['slug' => 'aq-brand-zulu', 'name' => 'AQ Brand Zulu', 'position' => 0]);

    foreach ([[$a, 'aq-ba-1'], [$z, 'aq-bz-1']] as [$brand, $slug]) {
        Product::create([
            'slug' => $slug, 'name' => strtoupper($slug), 'price' => 1500,
            'status' => 'publish', 'is_visible' => true, 'stock_status' => 'instock',
            'type' => 'simple', 'brand_id' => $brand->id,
        ]);
    }

    $order = fn () => collect($this->get('/shop')->viewData('brands'))
        ->pluck('slug')
        ->filter(fn ($s) => str_starts_with($s, 'aq-brand-'))
        ->values()
        ->all();

    expect($order())->toBe(['aq-brand-alpha', 'aq-brand-zulu']);

    $this->actingAs(syncAdmin(), 'admin')
        ->postJson('/admin-api/brands-tree/reorder', ['order' => [$z->id, $a->id]])
        ->assertOk();

    expect($order())->toBe(['aq-brand-zulu', 'aq-brand-alpha']);
});

it('flushes the storefront caches on every structural category write', function () {
    CategoryLaneRoutes::wire($this->app);

    $cat = Category::create(['slug' => 'aq-flush', 'name' => 'AQ Flush']);
    $cat->update(['path' => 'aq-flush']);

    $spare = Category::create(['slug' => 'aq-flush-spare', 'name' => 'AQ Flush Spare']);
    $spare->update(['path' => 'aq-flush-spare']);

    $admin = syncAdmin();

    $writes = [
        'update' => fn () => $this->actingAs($admin, 'admin')
            ->putJson('/admin-api/categories/' . $cat->id, ['name' => 'AQ Flush 2', 'slug' => 'aq-flush']),
        'reorder' => fn () => $this->actingAs($admin, 'admin')
            ->postJson('/admin-api/categories/reorder', ['order' => [$cat->id]]),
        'merge' => fn () => $this->actingAs($admin, 'admin')
            ->postJson('/admin-api/categories/' . $spare->id . '/merge', ['target_id' => $cat->id]),
        'delete' => fn () => $this->actingAs($admin, 'admin')
            ->deleteJson('/admin-api/categories/' . $cat->id . '?force=1'),
    ];

    foreach ($writes as $label => $call) {
        Cache::put('kbb.shop.cats', ['stale'], 900);
        Cache::put('kbb.home.cats', ['stale'], 900);

        $call()->assertSuccessful();

        expect(Cache::has('kbb.shop.cats'))->toBeFalse("{$label} left the shop sidebar cache stale")
            ->and(Cache::has('kbb.home.cats'))->toBeFalse("{$label} left the homepage tile cache stale");
    }
});
