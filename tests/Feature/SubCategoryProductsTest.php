<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use App\Services\SettingsService;
use App\Services\SiteLayout;
use App\Support\CategoryRollup;
use App\Support\ScopeOrder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * A PARENT CATEGORY ALSO LISTS ITS SUB-CATEGORIES' PRODUCTS. (Lane SC)
 *
 * The owner: "we have sub categories, but i want that all the products of sub
 * categories should also show in the main parent category too, automatically.
 * give this option on backend also."
 *
 * THE DEFECT ON THE SHOP: /collections/skincare/ listed only the products with
 * a `category_product` row for Skincare itself. Everything filed under Toners
 * or Serums -- which is how the WooCommerce import files most of the catalogue
 * -- was missing from the parent, so a parent showed one card, or "No
 * products", over a tree full of stock.
 */
function scxAdmin(string $role = 'owner'): AdminUser
{
    return AdminUser::create(['name' => 'O', 'email' => 'scx-'.uniqid().'@x.test', 'password' => 'secret-secret', 'role' => $role]);
}

function scxProduct(string $slug, int $price = 1000, string $stock = 'instock', ?Brand $brand = null): Product
{
    return Product::create([
        'slug' => $slug, 'name' => ucwords(str_replace('-', ' ', $slug)), 'type' => 'simple', 'status' => 'publish',
        'is_visible' => true, 'price' => $price, 'stock_status' => $stock, 'brand_id' => $brand?->id,
    ]);
}

function scxCategory(string $slug, ?Category $parent = null, ?string $mode = null): Category
{
    return Category::create([
        'name' => ucfirst($slug), 'slug' => $slug, 'path' => $slug, 'short_url' => true,
        'parent_id' => $parent?->id, CategoryRollup::COLUMN => $mode,
    ]);
}

function scxFile(Product $p, Category $c, ?int $position = null): void
{
    $p->categories()->attach($c->id, [ScopeOrder::CATEGORY_COLUMN => $position]);
}

function scxSettings(array $layout = [], array $plain = []): void
{
    if ($layout !== []) {
        app(SiteLayout::class)->save($layout);
    }
    foreach ($plain as $k => $v) {
        Setting::query()->updateOrCreate(['key' => $k], ['value' => (string) $v, 'autoload' => true]);
    }
    Setting::flushMap();
    SettingsService::forgetMemo();
    Cache::flush();
    CategoryRollup::flush();
}

/** Product slugs in the order the page's own grid draws them. */
function scxSlugs(string $html, string $prefix = 'scx-'): array
{
    $from = strpos($html, '<main id="content">');
    $html = $from === false ? $html : substr($html, $from, strrpos($html, '</main>') - $from);
    preg_match_all('#/product/([a-z0-9-]+)/#', $html, $m);

    return array_values(array_filter(array_unique($m[1]), fn ($s) => str_starts_with($s, $prefix)));
}

/**
 * Skincare > Toners > Mists, Skincare > Serums.
 *   own     filed under Skincare itself (position 0)
 *   both    filed under Skincare (position 1) AND Toners
 *   toner   Toners only
 *   mist    Mists only (a grandchild)
 *   serum   Serums only
 *   twice   Toners AND Serums
 */
function scxTree(): array
{
    $skin = scxCategory('scx-skincare');
    $toners = scxCategory('scx-toners', $skin);
    $mists = scxCategory('scx-mists', $toners);
    $serums = scxCategory('scx-serums', $skin);

    $p = [];
    foreach (['own' => 4000, 'both' => 2000, 'toner' => 9000, 'mist' => 30000, 'serum' => 6000, 'twice' => 12000] as $k => $price) {
        $p[$k] = scxProduct('scx-'.$k, $price, $k === 'serum' ? 'outofstock' : 'instock');
    }
    scxFile($p['own'], $skin, 0);
    scxFile($p['both'], $skin, 1);
    scxFile($p['both'], $toners);
    scxFile($p['toner'], $toners);
    scxFile($p['mist'], $mists);
    scxFile($p['serum'], $serums);
    scxFile($p['twice'], $toners);
    scxFile($p['twice'], $serums);

    return ['skin' => $skin, 'toners' => $toners, 'mists' => $mists, 'serums' => $serums, 'p' => $p];
}

const SCX_ALL = ['scx-own', 'scx-both', 'scx-mist', 'scx-serum', 'scx-toner', 'scx-twice'];

beforeEach(function () {
    // Arrows at a page size that holds the whole tree, said out loud: this
    // file is about WHICH products, not about the load mode.
    scxSettings(['load_mode' => 'arrows'], ['products_per_page' => 24]);
});

it('lists the child and grandchild products on the parent, each product once, by default', function () {
    /*
     * MUTATION: drop the third argument to ScopeOrder::inCategory() in
     * ShopController::index() and Skincare lists [own, both] -- red. Drop the
     * GROUP BY in ScopeOrder and "both" and "twice" are listed twice, and the
     * count reads 8 -- red.
     */
    $t = scxTree();

    expect(CategoryRollup::shopDefault())->toBe(CategoryRollup::INCLUDE);

    $r = $this->get('/collections/scx-skincare/')->assertOk();
    $slugs = scxSlugs($r->getContent());

    // The parent's own curated order first, then the rest by the page's own
    // tie-break (featured, then name).
    expect($slugs)->toBe(SCX_ALL)
        ->and($r->viewData('total'))->toBe(6)
        ->and($r->viewData('products')->pluck('slug')->duplicates()->all())->toBe([]);

    // A child includes ITS children too; a leaf is unchanged.
    expect(scxSlugs($this->get('/collections/scx-toners/')->getContent()))->toBe(['scx-both', 'scx-mist', 'scx-toner', 'scx-twice'])
        ->and(scxSlugs($this->get('/collections/scx-mists/')->getContent()))->toBe(['scx-mist']);
});

it('appears once when a product is filed under both the parent and a child', function () {
    /*
     * MUTATION: replace the GROUP BY product_id derived table with a plain
     * `category_id IN (...)` join and "scx-both" is two rows -- the count is
     * 2 and the grid holds it twice: red.
     */
    $skin = scxCategory('scx-skincare');
    $kid = scxCategory('scx-kid', $skin);
    $p = scxProduct('scx-both');
    scxFile($p, $skin, 0);
    scxFile($p, $kid, 0);

    $r = $this->get('/collections/scx-skincare/')->assertOk();
    expect($r->viewData('total'))->toBe(1)
        ->and($r->viewData('products'))->toHaveCount(1)
        ->and(substr_count($r->getContent(), 'href="'.url('/product/scx-both/').'"'))
        ->toBe(substr_count($this->get('/collections/scx-kid/')->getContent(), 'href="'.url('/product/scx-both/').'"'));
});

it('restores the old page when the shop setting is Only its own products, SQL and all', function () {
    /*
     * MUTATION: make CategoryRollup::idsFor() ignore the mode and the parent
     * still lists all six with the setting off: red.
     */
    scxTree();
    scxSettings(['sub_products' => 'own']);

    $r = $this->get('/collections/scx-skincare/')->assertOk();
    expect(scxSlugs($r->getContent()))->toBe(['scx-own', 'scx-both'])
        ->and($r->viewData('total'))->toBe(2);

    // And "only its own" is not merely the same rows: it is the same SQL the
    // page ran before this lane.
    $old = ScopeOrder::inCategory(Product::query(), 7)->toSql();
    expect(ScopeOrder::inCategory(Product::query(), 7, [7])->toSql())->toBe($old)
        ->and(ScopeOrder::inCategory(Product::query(), 7, [])->toSql())->toBe($old)
        ->and(ScopeOrder::inCategory(Product::query(), 7, [7, 8])->toSql())->not->toBe($old);
});

it('lets one category override the shop setting, both ways', function () {
    /*
     * MUTATION: read the shop default in CategoryRollup::modeFor() without
     * looking at the row first and both halves go red.
     */
    $t = scxTree();

    // Shop includes; Skincare says only its own.
    $t['skin']->update([CategoryRollup::COLUMN => 'own']);
    expect(scxSlugs($this->get('/collections/scx-skincare/')->getContent()))->toBe(['scx-own', 'scx-both'])
        // Toners still follows the shop.
        ->and(scxSlugs($this->get('/collections/scx-toners/')->getContent()))->toHaveCount(4);

    // Shop only-own; Skincare says include.
    scxSettings(['sub_products' => 'own']);
    $t['skin']->update([CategoryRollup::COLUMN => 'include']);
    expect(scxSlugs($this->get('/collections/scx-skincare/')->getContent()))->toBe(SCX_ALL)
        ->and(scxSlugs($this->get('/collections/scx-toners/')->getContent()))->toBe(['scx-both', 'scx-toner', 'scx-twice']);
});

it('filters, sorts, counts and pages the combined list, with the canonical on the category', function () {
    /*
     * MUTATION: apply the rollup after forPage() (or count before it) and
     * the totals below disagree with the grid: red.
     */
    $brand = Brand::create(['name' => 'Scx Brand', 'slug' => 'scx-brand']);
    $t = scxTree();
    $t['p']['mist']->update(['brand_id' => $brand->id]);
    $t['p']['own']->update(['brand_id' => $brand->id]);

    $base = '/collections/scx-skincare/';

    // Brand: one in a grandchild, one the parent's own.
    $r = $this->get($base.'?filter_brands=scx-brand')->assertOk();
    expect(scxSlugs($r->getContent()))->toBe(['scx-own', 'scx-mist'])->and($r->viewData('total'))->toBe(2);

    // Price: AED 54-150 holds toner (90) and serum (60) and twice (120).
    $r = $this->get($base.'?price=54-150')->assertOk();
    expect(scxSlugs($r->getContent()))->toBe(['scx-serum', 'scx-toner', 'scx-twice'])->and($r->viewData('total'))->toBe(3);

    // In stock: everything but the serum.
    $r = $this->get($base.'?instock=1')->assertOk();
    expect($r->viewData('total'))->toBe(5)->and(scxSlugs($r->getContent()))->not->toContain('scx-serum');

    // Sort by price, low to high, across parent, child and grandchild.
    expect(scxSlugs($this->get($base.'?orderby=plow')->getContent()))
        ->toBe(['scx-both', 'scx-own', 'scx-serum', 'scx-toner', 'scx-twice', 'scx-mist'])
        ->and(scxSlugs($this->get($base.'?orderby=phigh')->getContent()))
        ->toBe(['scx-mist', 'scx-twice', 'scx-toner', 'scx-serum', 'scx-own', 'scx-both']);

    // Pages of four: 4 + 2, no product twice, none missing.
    scxSettings([], ['products_per_page' => 4]);
    $one = $this->get($base)->assertOk();
    $two = $this->get($base.'?paged=2')->assertOk();
    $a = scxSlugs($one->getContent());
    $b = scxSlugs($two->getContent());
    expect($one->viewData('total'))->toBe(6)->and($one->viewData('lastPage'))->toBe(2)
        ->and(count($a))->toBe(4)->and(count($b))->toBe(2)
        ->and(array_values(array_intersect($a, $b)))->toBe([])
        ->and(collect([...$a, ...$b])->sort()->values()->all())->toBe(collect(SCX_ALL)->sort()->values()->all());
    $this->get($base.'?paged=3')->assertNotFound();

    // SEO: the canonical stays the category's own address; rel next/prev walk it.
    $canonical = url('/collections/scx-skincare').'/';
    expect($one->getContent())->toContain('<link rel="canonical" href="'.$canonical.'">')
        ->and($one->getContent())->toContain('rel="next" href="/collections/scx-skincare/?paged=2"')
        ->and($two->getContent())->toContain('<link rel="canonical" href="'.$canonical.'?paged=2">')
        ->and($two->getContent())->toContain('rel="prev" href="/collections/scx-skincare/"');

    // "Load more": the next batch is page two of the same combined list.
    $batch = $this->get($base.'?paged=2&kbbbatch=1')->assertOk()->json();
    expect(scxSlugs((string) $batch['html']))->toBe($b)->and($batch['last'])->toBe(2);
});

it('costs the same queries for 1, 5 or 30 sub-categories and for 3 or 300 products', function () {
    /*
     * Rule 4 and the speed freeze: flat in the tree and in the catalogue.
     * MUTATION: resolve the descendants with a query per level (or per child)
     * instead of the cached tree, and the 30-child count is higher: red.
     */
    $count = function (string $url): int {
        $this->get($url)->assertOk();   // warm
        app()->forgetScopedInstances();
        SettingsService::forgetMemo();
        $n = 0;
        DB::listen(function () use (&$n) { $n++; });
        $this->get($url)->assertOk();

        return $n;
    };

    $grow = function (int $kids, int $products) {
        $p = scxCategory("scx-p{$kids}-{$products}");
        $children = [];
        for ($k = 0; $k < $kids; $k++) {
            // Every other child one level deeper, so depth grows too.
            $children[] = scxCategory("scx-c{$kids}-{$products}-{$k}", $k % 2 && $children ? $children[$k - 1] : $p);
        }
        $rows = [];
        for ($i = 0; $i < $products; $i++) {
            $rows[] = ['slug' => "scx-g{$kids}-{$products}-{$i}", 'name' => "Scx G {$i}", 'type' => 'simple', 'status' => 'publish',
                'is_visible' => 1, 'price' => 1000, 'stock_status' => 'instock', 'created_at' => now(), 'updated_at' => now()];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('products')->insert($chunk);
        }
        $ids = DB::table('products')->where('slug', 'like', "scx-g{$kids}-{$products}-%")->pluck('id')->all();
        $pivot = [];
        foreach ($ids as $i => $id) {
            $pivot[] = ['category_id' => $children[$i % $kids]->id, 'product_id' => $id];
        }
        foreach (array_chunk($pivot, 200) as $chunk) {
            DB::table('category_product')->insert($chunk);
        }

        return "/collections/scx-p{$kids}-{$products}/";
    };

    $counts = [];
    foreach ([[1, 3], [5, 3], [30, 3], [1, 300], [30, 300]] as [$kids, $products]) {
        $counts["{$kids}x{$products}"] = $count($grow($kids, $products));
    }

    expect(array_unique(array_values($counts)))->toHaveCount(1, json_encode($counts));

    // And the same as a category with no children at all.
    $leaf = scxCategory('scx-leaf');
    scxFile(scxProduct('scx-leafp'), $leaf);
    expect($count('/collections/scx-leaf/'))->toBe($counts['1x3']);
});

it('works the same on the Arabic page', function () {
    /*
     * MUTATION: as the first test -- the Arabic archive is the same
     * controller, so dropping the rollup turns this red too.
     */
    Setting::query()->updateOrCreate(['key' => \App\Support\Locale::SETTING_ENABLED], ['value' => '1', 'autoload' => true]);
    scxSettings();
    scxTree();

    $r = $this->get('/ar/collections/scx-skincare/')->assertOk();
    $html = $r->getContent();
    expect($r->viewData('total'))->toBe(6)
        ->and(substr_count($html, '/ar/product/scx-mist/'))->toBeGreaterThan(0)
        ->and(substr_count($html, '/ar/product/scx-serum/'))->toBeGreaterThan(0)
        ->and($html)->toContain('<link rel="canonical" href="'.url('/ar/collections/scx-skincare').'/">');
});

it('counts a parent with its sub-categories in the filter rail and narrows the same way', function () {
    /*
     * The rail's "Skincare 6" has to be the 6 that ticking it shows.
     * MUTATION: drop the expandSlugs() branch in ShopController::applyFacets()
     * and ticking Skincare on /shop/ narrows to 2 beside a count of 6: red.
     */
    scxTree();
    scxSettings(['filters_d' => true]);

    $cats = $this->get('/shop/')->assertOk()->viewData('cats')->keyBy('slug');
    expect((int) $cats['scx-skincare']->products_count)->toBe(6)
        ->and((int) $cats['scx-toners']->products_count)->toBe(4)
        ->and((int) $cats['scx-mists']->products_count)->toBe(1);

    $r = $this->get('/shop/?product_cat=scx-skincare')->assertOk();
    expect($r->viewData('total'))->toBe(6)
        ->and(collect(scxSlugs($r->getContent()))->sort()->values()->all())->toBe(collect(SCX_ALL)->sort()->values()->all());

    // Off: the rail and the filter are what they were.
    scxSettings(['sub_products' => 'own']);
    $cats = $this->get('/shop/')->assertOk()->viewData('cats')->keyBy('slug');
    expect((int) $cats['scx-skincare']->products_count)->toBe(2)
        ->and($this->get('/shop/?product_cat=scx-skincare')->viewData('total'))->toBe(2);
});

it('gives a parent a homepage tile and a search suggestion with the count its page lists', function () {
    /*
     * MUTATION: drop the CategoryRollup branch in HomeController::index() and
     * Skincare's tile says 2 products beside a page that lists 6: red.
     */
    $t = scxTree();
    // A parent with nothing of its own -- the usual WooCommerce shape --
    // got no tile and no suggestion at all before.
    $empty = scxCategory('scx-bodycare');
    scxFile(scxProduct('scx-lotion'), scxCategory('scx-lotions', $empty));
    \Tests\Support\LegacyHomeSections::on(['categories']);
    scxSettings();

    $tiles = $this->get('/')->assertOk()->viewData('categories')->keyBy('slug');
    expect((int) ($tiles['scx-skincare']->products_count ?? 0))->toBe(6)
        ->and((int) ($tiles['scx-bodycare']->products_count ?? 0))->toBe(1);

    $suggest = fn (string $q) => collect(collect($this->getJson('/api/search?q='.$q)->assertOk()->json('groups') ?? [])
        ->firstWhere('key', 'categories')['items'] ?? [])->pluck('meta', 'label')->all();
    expect($suggest('scx-skincare')['Scx-skincare'] ?? null)->toBe('6 products')
        ->and($suggest('scx-bodycare')['Scx-bodycare'] ?? null)->toBe('1 products');
});

it('stores one of the select\'s own options or the default, and nothing else', function () {
    /*
     * MUTATION: drop the Rule::in() from CategoriesApiController -- 'bogus'
     * saves: red.
     */
    $admin = scxAdmin();
    $t = scxTree();
    $put = fn (array $extra) => $this->actingAs($admin, 'admin')
        ->putJson('/admin-api/categories/'.$t['skin']->id, ['name' => 'Scx skincare', 'slug' => 'scx-skincare'] + $extra);

    $put([CategoryRollup::COLUMN => 'own'])->assertOk();
    expect($t['skin']->fresh()->getAttribute(CategoryRollup::COLUMN))->toBe('own');
    // The very next page view follows the save: the model hook evicted the tree.
    expect(scxSlugs($this->get('/collections/scx-skincare/')->getContent()))->toBe(['scx-own', 'scx-both']);

    // A save from a tab that does not draw the select leaves it alone.
    $put([])->assertOk();
    expect($t['skin']->fresh()->getAttribute(CategoryRollup::COLUMN))->toBe('own');

    $put([CategoryRollup::COLUMN => ''])->assertOk();
    expect($t['skin']->fresh()->getAttribute(CategoryRollup::COLUMN))->toBeNull();

    $put([CategoryRollup::COLUMN => 'bogus'])->assertStatus(422);
    $put([CategoryRollup::COLUMN => '<b>x</b>'])->assertStatus(422);

    $index = $this->actingAs($admin, 'admin')->getJson('/admin-api/categories')->assertOk();
    $row = collect($index->json('categories'))->firstWhere('slug', 'scx-skincare');
    expect($index->json('sub_products_default'))->toBe('include')
        ->and($row)->toHaveKey(CategoryRollup::COLUMN)
        ->and($row['listed_count'])->toBe(6)
        ->and($row['products_count'])->toBe(2);

    $this->actingAs($admin, 'admin')->postJson('/admin-api/site-layout', ['settings' => ['sub_products' => 'everything']])->assertStatus(422);
    $this->actingAs($admin, 'admin')->postJson('/admin-api/site-layout', ['settings' => ['sub_products' => 'own']])->assertOk();
    Setting::flushMap();
    SettingsService::forgetMemo();
    expect(CategoryRollup::shopDefault())->toBe('own');
});

it('keeps the edit endpoint behind its capability', function () {
    $t = scxTree();
    $this->putJson('/admin-api/categories/'.$t['skin']->id, ['name' => 'X', CategoryRollup::COLUMN => 'own'])->assertStatus(401);
    expect($t['skin']->fresh()->getAttribute(CategoryRollup::COLUMN))->toBeNull();
});

it('draws both controls where the report says they are', function () {
    // Appearance → Site layout → Product grid → "Sub-category products on a parent category".
    expect(SiteLayout::TABS['grid'][2])->toContain('sub_products')
        ->and(SiteLayout::SCHEMA['sub_products'][2])->toBe('include')
        ->and(array_keys(SiteLayout::SCHEMA['sub_products'][4]))->toBe(CategoryRollup::MODES);

    // Catalog → Categories → Edit → "Sub-category products", exactly once.
    $src = file_get_contents(resource_path('views/admin/partials/category-tree-screen.blade.php'));
    expect(substr_count($src, '<select id="ct-subprod">'))->toBe(1)
        ->and(substr_count($src, "sub_products: val('ct-subprod')"))->toBe(1);
});

it('keeps the shop and the edit screen working on a server whose migration has not run yet', function () {
    /*
     * A package can land before its migration runs. MUTATION: drop the
     * fallback read in CategoriesApiController::index() -- Catalog →
     * Categories answers 500: red.
     */
    $admin = scxAdmin();
    $t = scxTree();
    \Illuminate\Support\Facades\Schema::table('categories', fn ($t) => $t->dropColumn(CategoryRollup::COLUMN));
    scxSettings();

    $this->actingAs($admin, 'admin')->getJson('/admin-api/categories')->assertOk();
    $this->actingAs($admin, 'admin')
        ->putJson('/admin-api/categories/'.$t['skin']->id, ['name' => 'Scx skincare', 'slug' => 'scx-skincare', CategoryRollup::COLUMN => 'own'])
        ->assertOk();

    // The shop follows the shop setting for every category: Include.
    expect(scxSlugs($this->get('/collections/scx-skincare/')->assertOk()->getContent()))->toBe(SCX_ALL);
});

it('does not loop on a parent cycle an old import left behind', function () {
    $a = scxCategory('scx-a');
    $b = scxCategory('scx-b', $a);
    DB::table('categories')->where('id', $a->id)->update(['parent_id' => $b->id]);
    CategoryRollup::flush();

    expect(CategoryRollup::subtree($a->id))->toBe([$a->id, $b->id])
        ->and(CategoryRollup::subtree($b->id))->toBe([$b->id, $a->id]);
});

