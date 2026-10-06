<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Services\SettingsService;
use App\Services\SiteLayout;
use App\Support\SoldOut;
use Illuminate\Support\Facades\DB;

/**
 * SOLD-OUT PRODUCTS: AS USUAL, AT THE VERY END, OR HIDDEN. (Lane SX)
 *
 * The owner: "i should have option on category / brands etc backend setting
 * page, where i can exclude the sold out products or show at very end."
 *
 * THE DEFECT ON THE SHOP: there was no such option. A sold-out product sat
 * wherever the curated order put it -- third on Super Sale, wearing "Sold out"
 * -- and the only way to move it was to reorder the whole category by hand,
 * or to unpublish the product and lose its page.
 */
function sxAdmin(string $role = 'owner'): AdminUser
{
    return AdminUser::create(['name' => 'O', 'email' => 'sx-'.uniqid().'@x.test', 'password' => 'secret-secret', 'role' => $role]);
}

function sxProduct(string $slug, string $stock = 'instock', ?Brand $brand = null): Product
{
    return Product::create([
        'slug' => $slug, 'name' => ucwords(str_replace('-', ' ', $slug)), 'type' => 'simple', 'status' => 'publish',
        'is_visible' => true, 'price' => 1000, 'stock_status' => $stock, 'brand_id' => $brand?->id,
    ]);
}

/** A category whose own curated order is $slugs, in that order. */
function sxCategory(string $slug, array $products, ?string $mode = null): Category
{
    $cat = Category::create(['name' => ucfirst($slug), 'slug' => $slug, 'path' => $slug, SoldOut::COLUMN => $mode]);

    foreach (array_values($products) as $i => $p) {
        $p->categories()->attach($cat->id, [\App\Support\ScopeOrder::CATEGORY_COLUMN => $i]);
    }

    return $cat;
}

function sxShop(string $mode): void
{
    app(SiteLayout::class)->save(['sold_out' => $mode]);
    \App\Models\Setting::flushMap();
    SettingsService::forgetMemo();
}

/** Product slugs in the order the page's own content draws them. */
function sxOrder(string $html, array $only): array
{
    $from = strpos($html, '<main id="content">');
    $html = $from === false ? $html : substr($html, $from, strrpos($html, '</main>') - $from);
    preg_match_all('#/product/([a-z0-9-]+)/#', $html, $m);

    return array_values(array_filter(array_unique($m[1]), fn ($s) => in_array($s, $only, true)));
}

/** Six products, curated a..f; b and e are sold out (e on backorder). */
function sxSix(): array
{
    $p = [];
    foreach (['a' => 'instock', 'b' => 'outofstock', 'c' => 'instock', 'd' => 'instock', 'e' => 'onbackorder', 'f' => 'instock'] as $k => $stock) {
        $p[$k] = sxProduct('sx-'.$k, $stock);
    }

    return $p;
}

const SX_SIX = ['sx-a', 'sx-b', 'sx-c', 'sx-d', 'sx-e', 'sx-f'];

beforeEach(function () {
    SettingsService::forgetMemo();
    \App\Models\Setting::flushMap();
});

it('changes nothing by default: the curated order, sold-out products where it puts them', function () {
    /*
     * The option ships at today's page. MUTATION: ship `sold_out` at 'end' in
     * SiteLayout::SCHEMA and the order below is a, c, d, f, b, e -- red.
     */
    sxCategory('sx-cat', sxSix());

    expect(SoldOut::shopDefault())->toBe('show')
        ->and(sxOrder($this->get('/collections/sx-cat/')->assertOk()->getContent(), SX_SIX))
        ->toBe(SX_SIX);

    // And "show" is not merely the same rows: it is the same SQL.
    $q = Product::query()->visible()->orderBy('products.position');
    $before = $q->toSql();
    expect(SoldOut::apply($q, 'show')->toSql())->toBe($before);
});

it('puts sold-out products after every in-stock one, each group in the curated order', function () {
    /*
     * MUTATION, RUN: make SoldOut::apply() APPEND its key (orderByRaw) instead
     * of prepending it -- the curated key decides first, the sold-out key never
     * gets a say, and the page is a..f again: red. Remove the call from
     * ShopController -- red the same way.
     */
    $p = sxSix();
    // A curated order that is NOT alphabetical, so "each group keeps the
    // page's order" cannot pass by sorting on name.
    sxCategory('sx-cat', [$p['f'], $p['e'], $p['a'], $p['b'], $p['d'], $p['c']]);
    sxShop('end');

    expect(sxOrder($this->get('/collections/sx-cat/')->assertOk()->getContent(), SX_SIX))
        ->toBe(['sx-f', 'sx-a', 'sx-d', 'sx-c', 'sx-e', 'sx-b']);
});

it('hides sold-out products from the grid, the count, the ItemList and every ?paged= batch, with no gap or duplicate', function () {
    /*
     * 30 products, every third sold out: 20 to list, 12 a batch ("Load more
     * on scroll", the shipped mode), so page 1 holds 12 and page 2 holds 8.
     *
     * MUTATION, RUN: apply the WHERE after the count in ShopController -- the
     * count reads 30, the page claims a third batch, and ?paged=3 answers 200
     * with nothing new. Drop the WHERE -- sold-out slugs appear: red.
     */
    $all = [];
    $instock = [];
    for ($i = 1; $i <= 30; $i++) {
        $slug = sprintf('sx-p%02d', $i);
        $all[] = $p = sxProduct($slug, $i % 3 === 0 ? 'outofstock' : 'instock');
        if ($i % 3 !== 0) {
            $instock[] = $slug;
        }
    }
    sxCategory('sx-cat', array_reverse($all));   // curated: p30 first
    $expected = array_reverse($instock);
    $slugs = array_map(fn ($p) => $p->slug, $all);

    sxShop('hide');
    app(SiteLayout::class)->save(['show_count' => true]);
    \App\Models\Setting::flushMap();
    SettingsService::forgetMemo();

    $html = $this->get('/collections/sx-cat/')->assertOk()->getContent();
    expect($html)->toContain('<span class="gcount">')
        ->and($html)->toMatch('#<span class="gcount">[^<]*<b>20</b>#');

    $seen = [];
    foreach ([1, 2] as $n) {
        $json = $this->get('/collections/sx-cat/?paged='.$n.'&kbbbatch=1')->assertOk()->json();
        expect($json['last'])->toBe(2);
        $seen = [...$seen, ...sxOrder($json['html'], $slugs)];
    }

    expect($seen)->toBe($expected)                        // every one, in order, once
        ->and(count($seen))->toBe(count(array_unique($seen)));
    $this->get('/collections/sx-cat/?paged=3')->assertNotFound();

    // The listing's JSON-LD never names a hidden product.
    preg_match('#<script type="application/ld\+json"[^>]*>(.*?)</script>#s', $html, $ld);
    foreach ($slugs as $i => $slug) {
        if (! in_array($slug, $instock, true)) {
            expect($ld[1] ?? '')->not->toContain('/product/'.$slug.'/');
        }
    }

    // The product page itself still opens.
    $this->get('/product/sx-p03/')->assertOk();
});

it('lets a category override the shop default, both ways', function () {
    /*
     * MUTATION: make SoldOut::for() ignore the row and return shopDefault() --
     * the 'show' category hides and the 'hide' category shows: red.
     */
    $p = sxSix();
    $q = [];
    foreach (['a' => 'instock', 'b' => 'outofstock'] as $k => $stock) {
        $q[$k] = sxProduct('sx2-'.$k, $stock);
    }
    sxCategory('sx-keep', $p, 'show');
    sxCategory('sx-hide', [$q['b'], $q['a']], 'hide');
    sxCategory('sx-follow', [$q['b'], $q['a']]);

    sxShop('hide');
    expect(sxOrder($this->get('/collections/sx-keep/')->getContent(), SX_SIX))->toBe(SX_SIX)
        ->and(sxOrder($this->get('/collections/sx-follow/')->getContent(), ['sx2-a', 'sx2-b']))->toBe(['sx2-a']);

    sxShop('show');
    expect(sxOrder($this->get('/collections/sx-hide/')->getContent(), ['sx2-a', 'sx2-b']))->toBe(['sx2-a'])
        ->and(sxOrder($this->get('/collections/sx-follow/')->getContent(), ['sx2-a', 'sx2-b']))->toBe(['sx2-b', 'sx2-a']);
});

it('lets a brand choose for its own page, in the brand\'s own order', function () {
    /*
     * MUTATION: drop the SoldOut::apply() tap in BrandController::show() --
     * the brand page keeps b where its order puts it: red.
     */
    $brand = Brand::create(['name' => 'Sx Brand', 'slug' => 'sx-brand', SoldOut::COLUMN => 'end']);
    $other = Brand::create(['name' => 'Sx Other', 'slug' => 'sx-other']);
    $mine = [];
    foreach (['d' => 'instock', 'b' => 'outofstock', 'a' => 'instock', 'c' => 'instock'] as $k => $stock) {
        $mine[$k] = sxProduct('sxb-'.$k, $stock, $brand);
    }
    foreach (array_values($mine) as $i => $prod) {
        DB::table('products')->where('id', $prod->id)->update(['brand_position' => $i]);
    }
    sxProduct('sxo-a', 'outofstock', $other);
    sxProduct('sxo-b', 'instock', $other);

    expect(sxOrder($this->get('/brands/sx-brand/')->assertOk()->getContent(), ['sxb-a', 'sxb-b', 'sxb-c', 'sxb-d']))
        ->toBe(['sxb-d', 'sxb-a', 'sxb-c', 'sxb-b'])
        // The other brand follows the shop: as usual.
        ->and(sxOrder($this->get('/brands/sx-other/')->getContent(), ['sxo-a', 'sxo-b']))->toBe(['sxo-a', 'sxo-b']);

    $brand->update([SoldOut::COLUMN => 'hide']);
    expect(sxOrder($this->get('/brands/sx-brand/')->getContent(), ['sxb-a', 'sxb-b', 'sxb-c', 'sxb-d']))
        ->toBe(['sxb-d', 'sxb-a', 'sxb-c']);
});

it('follows the Super Sale category on /super-sale/, and the shop on the other listings', function () {
    /*
     * MUTATION: drop the SoldOut::apply() from the campaign branch of
     * CollectionController::show() -- /super-sale/ still lists b: red. Drop
     * Category's saved hook -- the cached map keeps the old choice: red on
     * the second half.
     */
    $p = sxSix();
    $sale = sxCategory('super-sale', $p);

    expect(sxOrder($this->get('/super-sale/')->assertOk()->getContent(), SX_SIX))->toBe(SX_SIX);

    $sale->update([SoldOut::COLUMN => 'hide']);
    expect(sxOrder($this->get('/super-sale/')->getContent(), SX_SIX))->toBe(['sx-a', 'sx-c', 'sx-d', 'sx-f']);

    // New In is no category: it follows the shop, "end". Only this test's
    // six are live, so the seeded catalogue cannot push them off page one.
    DB::table('products')->where('slug', 'not like', 'sx-%')->update(['status' => 'draft']);
    sxShop('end');
    $newIn = sxOrder($this->get('/new-in/')->assertOk()->getContent(), SX_SIX);
    expect(array_slice($newIn, -2))->toEqualCanonicalizing(['sx-b', 'sx-e'])
        ->and(array_slice($newIn, 0, 4))->toBe(['sx-f', 'sx-d', 'sx-c', 'sx-a']);   // newest first, as before
});

it('applies the shop default to search and to the [kbb_products] shortcode', function () {
    $p = sxSix();
    sxCategory('sx-cat', $p);
    sxShop('hide');

    $search = sxOrder($this->get('/shop/?s=Sx')->assertOk()->getContent(), SX_SIX);
    expect($search)->not->toContain('sx-b')->and($search)->not->toContain('sx-e')->and($search)->toContain('sx-a');

    $grid = \App\Support\Shortcodes::render('[kbb_products category="sx-cat" limit="10" orderby="name" order="asc"]');
    expect(sxOrder($grid, SX_SIX))->toBe(['sx-a', 'sx-c', 'sx-d', 'sx-f']);

    // A hand-picked list, "at the end": in stock in the written order, then sold out.
    sxShop('end');
    $ids = implode(',', [$p['e']->id, $p['c']->id, $p['b']->id, $p['a']->id]);
    expect(sxOrder(\App\Support\Shortcodes::render('[kbb_products ids="'.$ids.'"]'), SX_SIX))
        ->toBe(['sx-c', 'sx-a', 'sx-e', 'sx-b']);
});

it('costs the same queries for 3 products as for 40, in every mode', function () {
    /*
     * Rule 4: a page's cost stays FLAT as the catalogue grows. MUTATION: read
     * the category's choice with a query per product (or lazily per card) and
     * the 40 count is higher: red.
     */
    $count = function (string $url): int {
        $this->get($url)->assertOk();   // warm
        $n = 0;
        DB::listen(function () use (&$n) { $n++; });
        $this->get($url)->assertOk();
        DB::flushQueryLog();

        return $n;
    };

    foreach (['end', 'hide'] as $mode) {
        $small = sxCategory('sx-s-'.$mode, array_map(fn ($i) => sxProduct("sx-s{$mode}{$i}", $i % 2 ? 'outofstock' : 'instock'), range(1, 3)), $mode);
        $big = sxCategory('sx-b-'.$mode, array_map(fn ($i) => sxProduct("sx-b{$mode}{$i}", $i % 2 ? 'outofstock' : 'instock'), range(1, 40)), $mode);

        $a = $count('/collections/'.$small->slug.'/');
        $b = $count('/collections/'.$big->slug.'/');
        expect($b)->toBe($a);
    }

    $brandA = Brand::create(['name' => 'Sx Small', 'slug' => 'sx-small', SoldOut::COLUMN => 'end']);
    $brandB = Brand::create(['name' => 'Sx Big', 'slug' => 'sx-big', SoldOut::COLUMN => 'end']);
    foreach (range(1, 3) as $i) { sxProduct("sx-bs{$i}", $i % 2 ? 'outofstock' : 'instock', $brandA); }
    foreach (range(1, 40) as $i) { sxProduct("sx-bb{$i}", $i % 2 ? 'outofstock' : 'instock', $brandB); }
    expect($count('/brands/sx-big/'))->toBe($count('/brands/sx-small/'));
});

it('stores one of the select\'s own options or the default, and nothing else', function () {
    /*
     * MUTATION: drop the Rule::in() from CategoriesApiController -- 'bogus'
     * saves (and is then ignored by the shop, a screen lying about the page):
     * red on the 422.
     */
    $admin = sxAdmin();
    $cat = sxCategory('sx-cat', []);
    $brand = Brand::create(['name' => 'Sx Brand', 'slug' => 'sx-brand']);

    $put = fn (string $url, array $extra) => $this->actingAs($admin, 'admin')->putJson($url, $extra);

    $put('/admin-api/categories/'.$cat->id, ['name' => 'Sx cat', 'slug' => 'sx-cat', SoldOut::COLUMN => 'hide'])->assertOk();
    expect($cat->fresh()->getAttribute(SoldOut::COLUMN))->toBe('hide');

    // A save from a screen that does not draw the select leaves it alone.
    $put('/admin-api/categories/'.$cat->id, ['name' => 'Sx cat', 'slug' => 'sx-cat'])->assertOk();
    expect($cat->fresh()->getAttribute(SoldOut::COLUMN))->toBe('hide');

    // "Use the shop default" is '' on the wire and NULL in the column.
    $put('/admin-api/categories/'.$cat->id, ['name' => 'Sx cat', 'slug' => 'sx-cat', SoldOut::COLUMN => ''])->assertOk();
    expect($cat->fresh()->getAttribute(SoldOut::COLUMN))->toBeNull();

    $put('/admin-api/categories/'.$cat->id, ['name' => 'Sx cat', 'slug' => 'sx-cat', SoldOut::COLUMN => 'bogus'])->assertStatus(422);
    expect($cat->fresh()->getAttribute(SoldOut::COLUMN))->toBeNull();

    $put('/admin-api/brands/'.$brand->id, ['name' => 'Sx Brand', 'slug' => 'sx-brand', SoldOut::COLUMN => 'end'])->assertOk();
    expect($brand->fresh()->getAttribute(SoldOut::COLUMN))->toBe('end');
    $put('/admin-api/brands/'.$brand->id, ['name' => 'Sx Brand', 'slug' => 'sx-brand', SoldOut::COLUMN => '<b>x</b>'])->assertStatus(422);
    expect($brand->fresh()->getAttribute(SoldOut::COLUMN))->toBe('end');

    // The two edit screens read their current choice from the listings.
    expect(collect($this->actingAs($admin, 'admin')->getJson('/admin-api/categories')->assertOk()->json('categories') ?? [])
        ->firstWhere('id', $cat->id))->toHaveKey(SoldOut::COLUMN);

    // The shop default: one of its own three, or a 422 naming the field.
    $this->actingAs($admin, 'admin')->postJson('/admin-api/site-layout', ['settings' => ['sold_out' => 'everything']])->assertStatus(422);
    $this->actingAs($admin, 'admin')->postJson('/admin-api/site-layout', ['settings' => ['sold_out' => 'end']])->assertOk();
    \App\Models\Setting::flushMap();
    SettingsService::forgetMemo();
    expect(SoldOut::shopDefault())->toBe('end');
});

it('keeps the edit endpoints behind their capabilities', function () {
    $cat = sxCategory('sx-cat', []);
    $brand = Brand::create(['name' => 'Sx Brand', 'slug' => 'sx-brand']);

    $this->putJson('/admin-api/categories/'.$cat->id, ['name' => 'X', SoldOut::COLUMN => 'hide'])->assertStatus(401);
    $this->actingAs(sxAdmin('support'), 'admin')
        ->putJson('/admin-api/brands/'.$brand->id, ['name' => 'X', 'slug' => 'sx-brand', SoldOut::COLUMN => 'hide'])->assertForbidden();

    expect($cat->fresh()->getAttribute(SoldOut::COLUMN))->toBeNull()
        ->and($brand->fresh()->getAttribute(SoldOut::COLUMN))->toBeNull();
});

it('draws the three controls where the report says they are', function () {
    // Appearance → Site layout → Product grid → "Sold-out products".
    expect(SiteLayout::TABS['grid'][2])->toContain('sold_out')
        ->and(SiteLayout::SCHEMA['sold_out'][2])->toBe('show')
        ->and(array_keys(SiteLayout::SCHEMA['sold_out'][4]))->toBe(SoldOut::MODES);

    // Catalog → Categories → Edit and Catalog → Brands → Edit.
    foreach (['category-tree-screen' => 'ct-soldout', 'brands-editor-screen' => 'bz-soldout'] as $file => $id) {
        $src = file_get_contents(resource_path("views/admin/partials/{$file}.blade.php"));
        expect(substr_count($src, '<select id="'.$id.'">'))->toBe(1)
            ->and(substr_count($src, "sold_out_mode: val('".$id."')"))->toBe(1);
    }
});

it('keeps the shop and both edit screens working on a server whose migration has not run yet', function () {
    /*
     * A package can be applied before its migration runs (CLAUDE.md, the
     * `migrations` flag). The defect that would look like: Catalog → Brands
     * and Catalog → Categories answer 500 on a column that is not there.
     * MUTATION: drop the try/catch in BrandsApiController::index() -- red.
     */
    $admin = sxAdmin();
    $p = sxSix();
    sxCategory('sx-cat', $p);
    Brand::create(['name' => 'Sx Brand', 'slug' => 'sx-brand']);

    foreach (['categories', 'brands'] as $table) {
        \Illuminate\Support\Facades\Schema::table($table, fn ($t) => $t->dropColumn(SoldOut::COLUMN));
    }
    SoldOut::flush();

    $this->actingAs($admin, 'admin')->getJson('/admin-api/categories')->assertOk();
    $this->actingAs($admin, 'admin')->getJson('/admin-api/brands')->assertOk();
    $cat = Category::where('slug', 'sx-cat')->first();
    $this->actingAs($admin, 'admin')->putJson('/admin-api/categories/'.$cat->id, ['name' => 'Sx cat', 'slug' => 'sx-cat', SoldOut::COLUMN => 'hide'])->assertOk();

    sxShop('end');
    expect(sxOrder($this->get('/collections/sx-cat/')->assertOk()->getContent(), SX_SIX))
        ->toBe(['sx-a', 'sx-c', 'sx-d', 'sx-f', 'sx-b', 'sx-e'])
        ->and(sxOrder($this->get('/super-sale/')->assertOk()->getContent(), SX_SIX))->toBeArray();
});
