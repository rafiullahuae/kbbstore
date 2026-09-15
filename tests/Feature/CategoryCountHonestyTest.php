<?php

declare(strict_types=1);

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Tests\Support\CategoryLaneRoutes;

/**
 * A product count beside a category must equal what that category's page lists.
 *
 * THE BUG THIS PINS. CategoriesApiController::index() counted the pivot with
 * `products.deleted_at IS NULL` and nothing else. The page it labels renders
 * Product::visible() — status='publish' AND is_visible=1 — through
 * whereHas('categories', …). So a category holding one live product, one draft
 * and one hidden product was labelled "3" beside a page listing 1.
 *
 * A count that disagrees with its own page is worse than no count: it is the
 * number the owner merchandises against, and it silently overstates every
 * category in proportion to how much unpublished work is sitting in it.
 *
 * The assertion is deliberately made against the RENDERED PAGE's own total,
 * not against a second copy of the page's query. Re-running the query would
 * pass even if the controller and the view had drifted apart; reading the
 * total the view was handed cannot.
 */
function honestAdmin(): \App\Models\AdminUser
{
    return \App\Models\AdminUser::query()->firstOrCreate(
        ['email' => 'honest-aq@kbb.test'],
        ['name' => 'Honest AQ', 'password' => bcrypt('secret-aq')]
    );
}

function honestProduct(string $slug, ?Category $cat, string $status, bool $visible, ?Brand $brand = null): Product
{
    $p = Product::create([
        'slug' => $slug,
        'name' => strtoupper($slug),
        'price' => 4200,
        'status' => $status,
        'is_visible' => $visible,
        'stock_status' => 'instock',
        'type' => 'simple',
        'brand_id' => $brand?->id,
    ]);

    if ($cat !== null) {
        DB::table('category_product')->insert(['category_id' => $cat->id, 'product_id' => $p->id]);
    }

    return $p;
}

it('labels a category with the number its archive page actually lists', function () {
    CategoryLaneRoutes::wire($this->app);
    CategoryLaneRoutes::wireStorefront($this->app);

    $cat = Category::create(['slug' => 'aq-count', 'name' => 'AQ Count']);
    $cat->update(['path' => 'aq-count']);

    honestProduct('aq-count-live-1', $cat, 'publish', true);
    honestProduct('aq-count-live-2', $cat, 'publish', true);
    honestProduct('aq-count-draft', $cat, 'draft', true);      // not on the page
    honestProduct('aq-count-hidden', $cat, 'publish', false);  // not on the page

    $row = collect(
        $this->actingAs(honestAdmin(), 'admin')
            ->getJson('/admin-api/categories')->json('categories')
    )->firstWhere('slug', 'aq-count');

    $pageTotal = $this->get('/product-category/aq-count/')
        ->assertStatus(200)
        ->viewData('total');

    expect($row['products_count'])->toBe($pageTotal)
        ->and($row['products_count'])->toBe(2);

    // The unpublished work is still visible to the owner, just not as the
    // headline — "0 products" on a category they just filled reads as a bug.
    expect($row['filed_count'])->toBe(4);
});

it('labels a brand with the number its brand page actually lists', function () {
    CategoryLaneRoutes::wire($this->app);

    $brand = Brand::create(['slug' => 'aq-brand', 'name' => 'AQ Brand']);

    honestProduct('aq-brand-live', null, 'publish', true, $brand);
    honestProduct('aq-brand-draft', null, 'draft', true, $brand);
    honestProduct('aq-brand-hidden', null, 'publish', false, $brand);

    $row = collect(
        $this->actingAs(honestAdmin(), 'admin')
            ->getJson('/admin-api/brands-tree')->json('brands')
    )->firstWhere('slug', 'aq-brand');

    // Brand::url() is /shop/?filter_brands={slug} — URL contract U-05.
    $pageTotal = $this->get('/shop?filter_brands=aq-brand')
        ->assertStatus(200)
        ->viewData('total');

    expect($row['products_count'])->toBe($pageTotal)
        ->and($row['products_count'])->toBe(1)
        ->and($row['filed_count'])->toBe(3);
});

/**
 * The count above spells out `status='publish' AND is_visible=1` in a
 * query-builder subquery because Product::visible() is an Eloquent scope and
 * cannot be applied there. That duplication is the risk: if visible() ever
 * gains a third condition, the count drifts from the page again and nothing
 * says so.
 *
 * This test is the tripwire. It reads the scope's own compiled SQL and asserts
 * the admin count filters on exactly the same columns.
 */
it('fails if Product::visible() gains a condition the category count does not mirror', function () {
    // Identifier quoting is dialect-specific — SQLite wraps in double quotes,
    // MySQL in backticks — so the quotes are stripped before matching rather
    // than baked into the needle. Asserting on `"status"` passed on SQLite and
    // failed on MySQL with an empty array, which is this repo's whole reason
    // for running the suite on both engines.
    $scopeSql = str_replace(['"', '`'], '', Product::query()->visible()->toSql());

    $conditions = [];

    foreach (['status', 'is_visible', 'deleted_at'] as $column) {
        if (preg_match('/\b'.preg_quote($column, '/').'\b/', $scopeSql) === 1) {
            $conditions[] = $column;
        }
    }

    sort($conditions);

    expect($conditions)->toBe(['deleted_at', 'is_visible', 'status'],
        'Product::visible() changed shape. CategoriesApiController::index() and '
        .'BrandsTreeApiController::index() spell its conditions out by hand — '
        .'update them both, or the counts stop matching the pages they label.');
});

it('keeps page two of the redirects list reporting the same total as page one', function () {
    CategoryLaneRoutes::wire($this->app);

    $target = Category::create(['slug' => 'aq-redir-target', 'name' => 'AQ Redir Target']);
    $target->update(['path' => 'aq-redir-target']);

    // More than one page of them (PER_PAGE is 50).
    $rows = [];

    for ($i = 0; $i < 60; $i++) {
        $rows[] = [
            'from_path' => 'aq-old-path-' . $i,
            'category_id' => $target->id,
            'reason' => 'slug',
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    DB::table('category_redirects')->insert($rows);

    $p1 = $this->actingAs(honestAdmin(), 'admin')
        ->getJson('/admin-api/categories/redirects?page=1')->assertOk()->json();

    $p2 = $this->actingAs(honestAdmin(), 'admin')
        ->getJson('/admin-api/categories/redirects?page=2')->assertOk()->json();

    // The failure this pins: a surviving OFFSET on the aggregate makes every
    // total read zero from page two on, with a 200 and no error anywhere.
    expect($p2['total'])->toBe($p1['total'])
        ->and($p1['total'])->toBeGreaterThanOrEqual(60)
        ->and($p1['redirects'])->toHaveCount(50)
        ->and(count($p2['redirects']))->toBeGreaterThan(0);
});

it('escapes LIKE wildcards in the redirect search', function () {
    CategoryLaneRoutes::wire($this->app);

    $target = Category::create(['slug' => 'aq-esc-target', 'name' => 'AQ Esc Target']);

    DB::table('category_redirects')->insert([
        ['from_path' => 'aq-esc-literal_underscore', 'category_id' => $target->id, 'reason' => 'slug',
            'created_at' => now(), 'updated_at' => now()],
        ['from_path' => 'aq-esc-literalXunderscore', 'category_id' => $target->id, 'reason' => 'slug',
            'created_at' => now(), 'updated_at' => now()],
    ]);

    // "_" is a single-character wildcard in LIKE. Unescaped, this matches both
    // rows; escaped with ESCAPE '!', it matches only the literal underscore.
    $hits = $this->actingAs(honestAdmin(), 'admin')
        ->getJson('/admin-api/categories/redirects?q=' . urlencode('literal_underscore'))
        ->assertOk()->json('redirects');

    expect(collect($hits)->pluck('from_path')->all())->toBe(['aq-esc-literal_underscore']);

    // A lone "%" must not return the whole table.
    $pct = $this->actingAs(honestAdmin(), 'admin')
        ->getJson('/admin-api/categories/redirects?q=' . urlencode('%'))
        ->assertOk()->json('redirects');

    expect($pct)->toBe([]);
});
