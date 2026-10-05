<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;

/**
 * EVERY CATEGORY AND EVERY BRAND KEEPS ITS OWN ORDER. (Lane SO)
 *
 * THE DEFECT ON THE SHOP, in the owner's words: "I have fixed the sorting on
 * Medicube brand. and then i did the super sale category. The medicube
 * products are also in super sale category ... when i did the sorting of Super
 * Sale category, then it also effected the Medicube brand's sorting too."
 * Catalog → Reorder wrote one global `products.position`; saving Super Sale
 * renumbered the Medicube products it shares, and the Medicube brand page,
 * which sorted on the same column, moved.
 */
function scoqAdmin(string $role = 'owner'): AdminUser
{
    return AdminUser::create(['name' => 'O', 'email' => 'so-'.uniqid().'@x.test', 'password' => 'secret-secret', 'role' => $role]);
}

function scoqProduct(string $slug, ?Brand $brand = null, array $extra = []): Product
{
    return Product::create($extra + [
        'slug' => $slug, 'name' => ucwords(str_replace('-', ' ', $slug)), 'type' => 'simple', 'status' => 'publish',
        'is_visible' => true, 'price' => 1000, 'stock_status' => 'instock', 'brand_id' => $brand?->id,
    ]);
}

/** Product slugs in the order the page draws them, first appearance only. */
function scoqOrder(string $html, array $only): array
{
    preg_match_all('#/product/([a-z0-9-]+)/#', $html, $m);

    return array_values(array_filter(array_unique($m[1]), fn ($s) => in_array($s, $only, true)));
}

/**
 * The page's own content: the layout's <main id="content">, without the site
 * header, its menu and the footer, which the owner's brief leaves alone.
 */
function scoqContent(string $html): string
{
    $from = strpos($html, '<main id="content">');
    expect($from)->not->toBeFalse();

    return substr($html, $from, strrpos($html, '</main>') - $from);
}

/** Save a whole scope's order the way the Reorder screen's Save does. */
function scoqSave($test, AdminUser $admin, string $type, int $id, array $ids): void
{
    $test->actingAs($admin, 'admin')
        ->postJson("/admin-api/catalog/reorder/{$type}/{$id}/save-page", ['page' => 1, 'per_page' => 50, 'product_ids' => $ids])
        ->assertOk();
}

/** What the Reorder screen lists for a scope, in order. */
function scoqScreen($test, AdminUser $admin, string $type, int $id): array
{
    return collect($test->actingAs($admin, 'admin')->getJson("/admin-api/catalog/reorder/{$type}/{$id}/products?per_page=50")
        ->assertOk()->json('products'))->pluck('id')->all();
}

beforeEach(function () {
    SettingsService::forgetMemo();
});

it('keeps the Medicube brand page in its order when Super Sale is reordered (the owner\'s report)', function () {
    /*
     * The owner's exact sequence: order the brand, then order the category
     * the brand's products also sit in.
     *
     * MUTATION, RUN: make writeOrder() write `products.position` for both
     * types and read it back on the brand page (the code before Lane SO) ->
     * the brand page follows Super Sale's order, red on the first line.
     */
    $admin = scoqAdmin();
    $medicube = Brand::create(['name' => 'Medicube SO', 'slug' => 'so-medicube']);
    $sale = Category::create(['name' => 'Super Sale', 'slug' => 'super-sale', 'path' => 'super-sale']);

    $m = [];
    foreach (['mc-a', 'mc-b', 'mc-c', 'mc-d'] as $slug) {
        $m[$slug] = scoqProduct($slug, $medicube);
    }
    $other = scoqProduct('ss-other');
    foreach ([...array_values($m), $other] as $p) {
        $p->categories()->attach($sale->id);
    }

    // 1. Medicube: d, b, a, c.
    scoqSave($this, $admin, 'brand', $medicube->id, [$m['mc-d']->id, $m['mc-b']->id, $m['mc-a']->id, $m['mc-c']->id]);
    // 2. Super Sale: a totally different order, shared products included.
    scoqSave($this, $admin, 'category', $sale->id, [$other->id, $m['mc-c']->id, $m['mc-a']->id, $m['mc-b']->id, $m['mc-d']->id]);

    $brandPage = $this->get('/brands/so-medicube/')->assertOk()->getContent();
    expect(scoqOrder($brandPage, array_keys($m)))->toBe(['mc-d', 'mc-b', 'mc-a', 'mc-c']);

    $all = ['ss-other', 'mc-a', 'mc-b', 'mc-c', 'mc-d'];
    expect(scoqOrder($this->get('/super-sale/')->assertOk()->getContent(), $all))->toBe(['ss-other', 'mc-c', 'mc-a', 'mc-b', 'mc-d'])
        ->and(scoqOrder($this->get('/collections/super-sale/')->assertOk()->getContent(), $all))->toBe(['ss-other', 'mc-c', 'mc-a', 'mc-b', 'mc-d']);

    // And the other way round: re-saving the brand leaves Super Sale alone.
    scoqSave($this, $admin, 'brand', $medicube->id, [$m['mc-a']->id, $m['mc-b']->id, $m['mc-c']->id, $m['mc-d']->id]);
    expect(scoqOrder($this->get('/super-sale/')->getContent(), $all))->toBe(['ss-other', 'mc-c', 'mc-a', 'mc-b', 'mc-d'])
        ->and(scoqOrder($this->get('/brands/so-medicube/')->getContent(), array_keys($m)))->toBe(['mc-a', 'mc-b', 'mc-c', 'mc-d']);

    // The Reorder screen shows each scope in the order its page does.
    expect(scoqScreen($this, $admin, 'category', $sale->id))->toBe([$other->id, $m['mc-c']->id, $m['mc-a']->id, $m['mc-b']->id, $m['mc-d']->id]);

    // The shared column is not written at all any more: /shop/ keeps its order.
    expect(Product::whereIn('id', array_map(fn ($p) => $p->id, $m))->pluck('position')->unique()->values()->all())->toBe([0]);
});

it('keeps two categories that share products independent of each other', function () {
    /* MUTATION, RUN: drop `where('category_id', $id)` from writeOrder() -> Toners follows Serums, red. */
    $admin = scoqAdmin();
    $serums = Category::create(['name' => 'SO Serums', 'slug' => 'so-serums']);
    $toners = Category::create(['name' => 'SO Toners', 'slug' => 'so-toners']);
    $p = [];
    foreach (['p-one', 'p-two', 'p-three'] as $slug) {
        $p[$slug] = scoqProduct($slug);
        $p[$slug]->categories()->attach([$serums->id, $toners->id]);
    }
    $ids = fn (array $slugs) => array_map(fn ($s) => $p[$s]->id, $slugs);

    scoqSave($this, $admin, 'category', $toners->id, $ids(['p-two', 'p-three', 'p-one']));
    scoqSave($this, $admin, 'category', $serums->id, $ids(['p-three', 'p-one', 'p-two']));

    expect(scoqOrder($this->get('/collections/so-toners/')->getContent(), array_keys($p)))->toBe(['p-two', 'p-three', 'p-one'])
        ->and(scoqOrder($this->get('/collections/so-serums/')->getContent(), array_keys($p)))->toBe(['p-three', 'p-one', 'p-two']);
});

it('puts a product never ordered in a scope after the ordered ones, featured then name', function () {
    /*
     * A product added to a category after its order was saved has no number
     * there (NULL). It goes to the end, with the page's own tie-break among
     * such products -- not to the top, where a 0 would have put it.
     * MUTATION, RUN: drop the `kso_pos IS NULL` key from orderInCategory() ->
     * NULL sorts first on SQLite and MySQL, the newcomers lead, red.
     */
    $admin = scoqAdmin();
    $cat = Category::create(['name' => 'SO Masks', 'slug' => 'so-masks']);
    $a = scoqProduct('mk-a');
    $b = scoqProduct('mk-b');
    $a->categories()->attach($cat->id);
    $b->categories()->attach($cat->id);
    scoqSave($this, $admin, 'category', $cat->id, [$b->id, $a->id]);

    $late1 = scoqProduct('mk-late-zed');
    $late2 = scoqProduct('mk-late-alpha');
    $late3 = scoqProduct('mk-late-featured', null, ['featured' => true]);
    foreach ([$late1, $late2, $late3] as $l) {
        $l->categories()->attach($cat->id);
    }

    $all = ['mk-a', 'mk-b', 'mk-late-zed', 'mk-late-alpha', 'mk-late-featured'];
    expect(scoqOrder($this->get('/collections/so-masks/')->getContent(), $all))
        ->toBe(['mk-b', 'mk-a', 'mk-late-featured', 'mk-late-alpha', 'mk-late-zed'])
        // The screen agrees, so a Save from it writes what the shop shows.
        ->and(scoqScreen($this, $admin, 'category', $cat->id))->toBe([$b->id, $a->id, $late3->id, $late2->id, $late1->id]);

    // A product new to a brand joins its end too.
    $brand = Brand::create(['name' => 'Bx', 'slug' => 'bx']);
    $x = scoqProduct('bx-x', $brand);
    $y = scoqProduct('bx-y', $brand);
    scoqSave($this, $admin, 'brand', $brand->id, [$y->id, $x->id]);
    scoqProduct('bx-a-new', $brand);
    expect(scoqOrder($this->get('/brands/bx/')->getContent(), ['bx-x', 'bx-y', 'bx-a-new']))->toBe(['bx-y', 'bx-x', 'bx-a-new']);
});

it('moves one product to a rank and auto-sorts within the scope only', function () {
    /* The number box (moveAbsolute) and "Start from" (autoSort) write only this scope's numbers. */
    $admin = scoqAdmin();
    $brand = Brand::create(['name' => 'Rk', 'slug' => 'rk']);
    $cat = Category::create(['name' => 'Rk Cat', 'slug' => 'rk-cat']);
    $p = [];
    foreach (['rk-c', 'rk-a', 'rk-b'] as $slug) {
        $p[$slug] = scoqProduct($slug, $brand);
        $p[$slug]->categories()->attach($cat->id);
    }
    scoqSave($this, $admin, 'category', $cat->id, [$p['rk-c']->id, $p['rk-a']->id, $p['rk-b']->id]);

    $this->actingAs($admin, 'admin')->postJson("/admin-api/catalog/reorder/brand/{$brand->id}/auto-sort", ['by' => 'name'])->assertOk();
    $this->actingAs($admin, 'admin')->postJson("/admin-api/catalog/reorder/brand/{$brand->id}/move", ['product_id' => $p['rk-c']->id, 'to' => 0])->assertOk();

    expect(scoqOrder($this->get('/brands/rk/')->getContent(), array_keys($p)))->toBe(['rk-c', 'rk-a', 'rk-b'])
        ->and(scoqOrder($this->get('/collections/rk-cat/')->getContent(), array_keys($p)))->toBe(['rk-c', 'rk-a', 'rk-b']);

    $this->actingAs($admin, 'admin')->postJson("/admin-api/catalog/reorder/category/{$cat->id}/auto-sort", ['by' => 'name'])->assertOk();
    expect(scoqOrder($this->get('/collections/rk-cat/')->getContent(), array_keys($p)))->toBe(['rk-a', 'rk-b', 'rk-c'])
        ->and(scoqOrder($this->get('/brands/rk/')->getContent(), array_keys($p)))->toBe(['rk-c', 'rk-a', 'rk-b']);
});

it('forgets a product\'s place when it leaves the category or the brand', function () {
    /*
     * MUTATION, RUN: delete Product's `saving` hook -> the moved product
     * carries brand A's number 0 into brand B and leads it, red.
     */
    $admin = scoqAdmin();
    $cat = Category::create(['name' => 'Lv', 'slug' => 'lv']);
    $a = scoqProduct('lv-a');
    $b = scoqProduct('lv-b');
    $a->categories()->attach($cat->id);
    $b->categories()->attach($cat->id);
    scoqSave($this, $admin, 'category', $cat->id, [$b->id, $a->id]);

    $b->categories()->detach($cat->id);
    expect(DB::table('category_product')->where('product_id', $b->id)->count())->toBe(0);
    $b->categories()->attach($cat->id);
    expect(scoqOrder($this->get('/collections/lv/')->getContent(), ['lv-a', 'lv-b']))->toBe(['lv-a', 'lv-b']);

    $one = Brand::create(['name' => 'One', 'slug' => 'one']);
    $two = Brand::create(['name' => 'Two', 'slug' => 'two']);
    $mover = scoqProduct('zz-mover', $one);
    $stay = scoqProduct('aa-stay', $two);
    scoqSave($this, $admin, 'brand', $one->id, [$mover->id]);
    scoqSave($this, $admin, 'brand', $two->id, [$stay->id]);
    DB::table('products')->where('id', $stay->id)->update(['brand_position' => 5]);

    $mover->fresh()->update(['brand_id' => $two->id]);
    expect($mover->fresh()->brand_position)->toBeNull()
        ->and(scoqOrder($this->get('/brands/two/')->getContent(), ['zz-mover', 'aa-stay']))->toBe(['aa-stay', 'zz-mover']);
});

it('keeps a product\'s place in a category a bulk "Replace categories" keeps it in', function () {
    /* MUTATION, RUN: put back the delete-everything line in bulkCategory()'s replace -> lands at the end, red. */
    $admin = scoqAdmin();
    $keep = Category::create(['name' => 'Keep', 'slug' => 'keep']);
    $drop = Category::create(['name' => 'Drop', 'slug' => 'drop']);
    $a = scoqProduct('kp-a');
    $b = scoqProduct('kp-b');
    $a->categories()->attach([$keep->id, $drop->id]);
    $b->categories()->attach($keep->id);
    scoqSave($this, $admin, 'category', $keep->id, [$b->id, $a->id]);
    scoqSave($this, $admin, 'category', $keep->id, [$a->id, $b->id]);

    $this->actingAs($admin, 'admin')->postJson('/admin-api/catalog-products-bulk-category', [
        'ids' => [$a->id], 'category_ids' => [$keep->id], 'mode' => 'replace', 'confirm' => true,
    ])->assertOk();

    expect(DB::table('category_product')->where('product_id', $a->id)->pluck('category_id')->all())->toBe([$keep->id])
        ->and(scoqOrder($this->get('/collections/keep/')->getContent(), ['kp-a', 'kp-b']))->toBe(['kp-a', 'kp-b']);
});

it('refuses an unknown scope, a role without the catalogue, and a payload from another page', function () {
    $owner = scoqAdmin();
    $this->actingAs($owner, 'admin')->getJson('/admin-api/catalog/reorder/category/999999/products')->assertNotFound();
    $this->actingAs($owner, 'admin')->postJson('/admin-api/catalog/reorder/brand/999999/auto-sort', ['by' => 'name'])->assertNotFound();

    $brand = Brand::create(['name' => 'G', 'slug' => 'g']);
    $p = scoqProduct('g-p', $brand);
    $q = scoqProduct('g-q');   // not in the brand

    $this->actingAs(scoqAdmin('support'), 'admin')
        ->postJson("/admin-api/catalog/reorder/brand/{$brand->id}/save-page", ['page' => 1, 'per_page' => 50, 'product_ids' => [$p->id]])
        ->assertForbidden();

    $this->actingAs($owner, 'admin')
        ->postJson("/admin-api/catalog/reorder/brand/{$brand->id}/save-page", ['page' => 1, 'per_page' => 50, 'product_ids' => [$q->id]])
        ->assertStatus(409);
    expect($q->fresh()->brand_position)->toBeNull();
});

it('seeds every category and brand from the order its page shows today, so applying it moves nothing', function () {
    /*
     * The migration's promise, measured: roll its columns back, build a shop
     * whose order lives only in products.position (ties, featured products,
     * a product in two categories), compute every page's order with the
     * ORDER BY each page used before Lane SO, apply the migration, and render.
     *
     * MUTATION, RUN: drop the UPDATE that copies `position` into
     * `category_position` -> every product ties at NULL, featured-then-name
     * decides the category pages, red.
     */
    $migration = require database_path('migrations/2027_08_28_100000_independent_order_per_category_and_brand.php');
    $migration->down();

    $brands = [Brand::create(['name' => 'Seed A', 'slug' => 'seed-a']), Brand::create(['name' => 'Seed B', 'slug' => 'seed-b'])];
    $cats = [Category::create(['name' => 'Seed One', 'slug' => 'seed-one']), Category::create(['name' => 'Seed Two', 'slug' => 'seed-two'])];
    $positions = [3, 0, 0, 7, 3, 1, 0, 7, 2];
    foreach ($positions as $i => $pos) {
        $p = Product::query()->forceCreate([
            'slug' => 'seed-'.$i, 'name' => 'Seed '.chr(90 - $i), 'type' => 'simple', 'status' => 'publish', 'is_visible' => true,
            'price' => 1000, 'stock_status' => 'instock', 'brand_id' => $brands[$i % 2]->id, 'position' => $pos, 'featured' => $i % 3 === 0,
        ]);
        DB::table('category_product')->insert(['category_id' => $cats[$i % 2]->id, 'product_id' => $p->id]);
        if ($i % 3 === 0) {
            DB::table('category_product')->insert(['category_id' => $cats[($i + 1) % 2]->id, 'product_id' => $p->id]);
        }
    }

    $before = [];
    foreach ($cats as $c) {
        // ShopController::applyDefaultSort before Lane SO.
        $before['/collections/'.$c->slug.'/'] = DB::table('products')->join('category_product', 'category_product.product_id', '=', 'products.id')
            ->where('category_product.category_id', $c->id)
            ->orderBy('products.position')->orderByDesc('products.featured')->orderBy('products.name')->orderBy('products.id')
            ->pluck('products.slug')->all();
    }
    foreach ($brands as $b) {
        // BrandController::show before Lane SO.
        $before['/brands/'.$b->slug.'/'] = DB::table('products')->where('brand_id', $b->id)
            ->orderBy('position')->orderBy('name')->orderBy('id')->pluck('slug')->all();
    }

    $migration->up();

    $slugs = array_map(fn ($i) => 'seed-'.$i, array_keys($positions));
    foreach ($before as $url => $want) {
        expect(scoqOrder($this->get($url)->assertOk()->getContent(), $slugs))->toBe($want, $url);
    }
});

it('turns the phone Filters button off where a shop had it on', function () {
    $migration = require database_path('migrations/2027_08_28_100000_independent_order_per_category_and_brand.php');
    app(SettingsService::class)->set('layout_filters_m', '1');
    $migration->up();
    SettingsService::forgetMemo();

    expect(DB::table('settings')->where('key', 'layout_filters_m')->value('value'))->toBe('0')
        ->and(app(\App\Services\SiteLayout::class)->get('filters_m'))->toBeFalse();
});

it('sends no filter rail and no link off a category page, until a switch says so', function () {
    /*
     * The owner: "remove the filter at all ... keep turned off completely on
     * all pages by default" and "the app should not display any external link
     * or shop filter". Off means NOT IN THE HTML -- not a rail hidden by CSS.
     * MUTATION, RUN: drop the @if round the <aside> in shop.blade.php -> the
     * rail and its /shop/?cat= links come back, red.
     */
    $brand = Brand::create(['name' => 'Fl', 'slug' => 'fl']);
    $cat = Category::create(['name' => 'Fl Cat', 'slug' => 'fl-cat']);
    $other = Category::create(['name' => 'Elsewhere', 'slug' => 'elsewhere']);
    foreach (['fl-1', 'fl-2'] as $slug) {
        $p = scoqProduct($slug, $brand, ['sale_price' => 500]);
        $p->categories()->attach([$cat->id, $other->id]);
    }

    $html = scoqContent($this->get('/collections/fl-cat/')->assertOk()->getContent());
    expect($html)->not->toContain('class="filtercol"')
        ->and($html)->not->toContain('id="showFilters"')
        ->and($html)->not->toContain('class="mobi-filter"')
        ->and($html)->not->toContain('class="fscrim"')
        ->and($html)->not->toMatch('#href="[^"]*[?&](cat|brand|filter_brands|price|sale|instock)=#')
        ->and($html)->not->toMatch('#href="[^"]*/shop/?"#')
        ->and($this->get('/collections/fl-cat/')->getContent())->toContain('filters-hidden');

    app(SettingsService::class)->set('layout_filters_d', '1');
    SettingsService::forgetMemo();
    $on = $this->get('/collections/fl-cat/')->assertOk()->getContent();
    expect($on)->toContain('class="filtercol"')->and($on)->toContain('id="showFilters"')
        ->and($on)->not->toContain('class="mobi-filter"');
});

it('sends no link to the shop from a brand page, in every header style, with and without a banner', function () {
    /* brand_cta and brand_popular ship off (2.60.402); this pins all three hero styles and the banner. */
    $brand = Brand::create(['name' => 'Hb', 'slug' => 'hb', 'description' => 'About Hb.']);
    scoqProduct('hb-1', $brand);
    $withBanner = Brand::create(['name' => 'Hbb', 'slug' => 'hbb', 'banner' => ['enabled' => true, 'image' => '/uploads/brands/own.jpg', 'heading' => 'Own']]);
    scoqProduct('hbb-1', $withBanner);

    foreach (['panel', 'compact', 'classic'] as $hero) {
        app(SettingsService::class)->set('layout_brand_hero', $hero);
        SettingsService::forgetMemo();
        foreach (['/brands/hb/', '/brands/hbb/'] as $url) {
            $html = scoqContent($this->get($url)->assertOk()->getContent());
            expect($html)->not->toMatch('#href="[^"]*/shop/[^"]*"#', "{$hero} {$url}")
                ->and($html)->not->toContain('brw-cta')
                ->and($html)->not->toMatch('#href="[^"]*[?&](cat|brand|filter_brands|price)=#');
        }
    }
});

it('sends no "All products" link from /super-sale/ or a concern page, and brings it back with its switch', function () {
    /* MUTATION, RUN: make 'shopLinks' true in CollectionController::show() -> /super-sale/ links to /shop/, red. */
    $sale = Category::create(['name' => 'Super Sale', 'slug' => 'super-sale', 'path' => 'super-sale']);
    for ($i = 1; $i <= 3; $i++) {
        $p = scoqProduct('cc-'.$i, null, ['routine_concerns' => json_encode(['acne'])]);
        $p->categories()->attach($sale->id);
    }

    foreach (['/super-sale/', '/concern/acne/'] as $url) {
        expect(scoqContent($this->get($url)->assertOk()->getContent()))->not->toMatch('#href="[^"]*/shop/"#', $url);
    }
    // A curated listing that is no category keeps its link, as it was.
    expect(scoqContent($this->get('/new-in/')->assertOk()->getContent()))->toMatch('#href="[^"]*/shop/"#');

    app(SettingsService::class)->set('layout_shop_links', '1');
    SettingsService::forgetMemo();
    expect(scoqContent($this->get('/super-sale/')->getContent()))->toMatch('#href="[^"]*/shop/"#');
});

it('costs the same queries for a category and a brand of 3 products as of 40', function () {
    /* "a page's cost stays FLAT as the catalogue grows" -- the join is one, whatever the scope holds. */
    $count = function (string $url): int {
        SettingsService::forgetMemo();
        $this->get($url)->assertOk();   // warm caches
        SettingsService::forgetMemo();
        $n = 0;
        DB::listen(function () use (&$n) {
            $n++;
        });
        $this->get($url)->assertOk();

        return $n;
    };

    $small = Brand::create(['name' => 'Sm', 'slug' => 'sm']);
    $big = Brand::create(['name' => 'Bg', 'slug' => 'bg']);
    $cs = Category::create(['name' => 'Cs', 'slug' => 'cs']);
    $cb = Category::create(['name' => 'Cb', 'slug' => 'cb']);
    for ($i = 0; $i < 40; $i++) {
        $p = scoqProduct('q-big-'.$i, $big);
        $p->categories()->attach($cb->id);
        if ($i < 3) {
            $s = scoqProduct('q-small-'.$i, $small);
            $s->categories()->attach($cs->id);
        }
    }
    $admin = scoqAdmin();
    scoqSave($this, $admin, 'category', $cb->id, Product::where('brand_id', $big->id)->orderByDesc('id')->limit(40)->pluck('id')->all());

    expect($count('/collections/cb/'))->toBe($count('/collections/cs/'))
        ->and($count('/brands/bg/'))->toBe($count('/brands/sm/'));
});
