<?php

declare(strict_types=1);

/**
 * Appearance → Homepage content → All sections, Edit content, and the product
 * source picker (Lane HC).
 *
 * The owner: "on homepage content. i want a proper detailed popup with all
 * controls, including data queries to choose brand, category or mixed
 * categories, or manual section with search function properly. please bring
 * every section edit on hompage content ... i need it super fast please
 * without any bugs etc, and optimized and super light."
 *
 * Every case names the defect it would have caught and the mutation that turns
 * it red.
 */

use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Category;
use App\Models\GridSection;
use App\Models\Product;
use App\Services\GridSections;
use App\Services\HomepageContent;
use App\Services\HomepageSections;
use App\Services\ModuleSchema;
use App\Services\SettingsService;
use App\Support\AdminCapabilities;
use App\Support\HomeSources;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\HomepageContentAdminRoutes;
use Tests\Support\HomepageHubRoutes;

beforeEach(function () {
    app(SettingsService::class)->set('demo_content', false);
});

function hcAdmin(string $role = 'owner'): AdminUser
{
    return AdminUser::create(['name' => 'HC', 'email' => 'hc-'.uniqid().'@example.test', 'password' => 'secret-secret', 'role' => $role]);
}

function hcWire(): void
{
    HomepageContentAdminRoutes::wire(app());
    HomepageHubRoutes::wire(app());
}

function hcProduct(string $name, array $extra = []): Product
{
    static $n = 0;
    $n++;

    return Product::create(array_merge([
        'slug' => 'hc-'.$n.'-'.uniqid(), 'name' => $name, 'type' => 'simple', 'status' => 'publish',
        'is_visible' => true, 'stock_status' => 'instock', 'price' => 5000 + $n,
        'total_sales' => 900000 - $n, 'sku' => 'HC-SKU-'.$n, 'wc_id' => 70000 + $n,
    ], $extra));
}

function hcWrite(array $values): void
{
    ModuleSchema::write(app(SettingsService::class), 'homepage_content', HomepageContent::SCHEMA, $values);
    \App\Models\Setting::flushMap();
    SettingsService::forgetMemo();
}

function hcHome(): string
{
    SettingsService::forgetMemo();
    \App\Models\Setting::flushMap();
    Cache::flush();

    return test()->get('/')->assertOk()->getContent();
}

/** Switch every section off except the ones named (both devices on). */
function hcOnly(array $keys): void
{
    $rows = app(HomepageSections::class)->all();

    foreach ($rows as $k => $row) {
        $rows[$k]['desktop'] = $rows[$k]['mobile'] = in_array($k, $keys, true);
    }

    app(HomepageSections::class)->save($rows);
    SettingsService::forgetMemo();
}

/** The Best Sellers rail's own <section>, or ''. */
function hcRail(string $html, string $key = 'bestselling'): string
{
    return preg_match('#<section class="sec hs hs-rail hs-'.$key.'\b.*?</section>#s', $html, $m) === 1 ? $m[0] : '';
}

/* ═══ 1. ONE PAGE FOR THE WHOLE HOMEPAGE ════════════════════════════════════ */

it('lists every section the homepage renders, in render order, each with an editor', function () {
    /*
     * THE DEFECT: a section the shop draws that the one-page editor does not
     * list is a section the owner has to go hunting for — the thing he asked
     * this page to end. And a list in another order than the shop's is a list
     * that lies about the page.
     *
     * MUTATION: drop a row from HomepageHub::payload()'s loop (or build it from
     * HomepageSections::REGISTRY instead of all()) → red on the key list.
     */
    hcWire();
    $grid = GridSection::create(['name' => 'HC grid', 'slug' => 'hc-grid', 'status' => 'publish', 'position' => 1,
        'show_heading' => true, 'heading' => 'Grid', 'subheading' => '', 'source' => 'bestsellers', 'include_children' => false,
        'count' => 4, 'mobile_count' => 4, 'desktop_layout' => 'grid', 'desktop_cols' => 4, 'mobile_layout' => 'grid',
        'mobile_cols' => 2, 'skin' => '', 'card_label' => '', 'show_rank' => false, 'show_view_all' => false,
        'view_all_label' => '', 'view_all_url' => '']);
    GridSections::flush();

    $j = test()->actingAs(hcAdmin(), 'admin')->getJson('/admin-api/homepage-hub')->assertOk()->json();

    expect(array_column($j['sections'], 'key'))->toBe(array_keys(app(HomepageSections::class)->all()))
        ->and(array_column($j['sections'], 'key'))->toContain('grid_'.$grid->id, 'topstrip', 'countries');

    foreach ($j['sections'] as $s) {
        expect($s['editor']['kind'])->toBeIn(['content', 'remote', 'grid', 'hero', 'none'], $s['key'])
            ->and($s['summary'])->toBeString()->not->toBe('', $s['key'].' has no one-line summary')
            ->and($s['section_tabs'])->not->toBeEmpty();

        if ($s['editor']['kind'] === 'content') {
            expect(HomepageContent::TABS)->toHaveKey($s['editor']['tab']);
            expect($s['fields'])->not->toBeEmpty($s['key'].' opens an empty editor');
        }
    }

    // Every product section carries a source picker.
    $withSource = array_column(array_filter($j['sections'], fn ($s) => isset($s['source'])), 'key');
    expect($withSource)->toEqualCanonicalizing(['bundles', 'bestselling', 'recommended', 'trending', 'bestsellers', 'flash', 'under54']);
});

it('draws an Edit content button on each card, from the screen partial', function () {
    /*
     * THE DEFECT: a payload with no button to open it. MUTATION: rename the
     * data-hph-edit hook in the list markup → red.
     */
    $src = (string) file_get_contents(resource_path('views/admin/partials/homepage-hub.blade.php'));

    expect(substr_count($src, '>Edit content</button>'))->toBe(1)
        ->and($src)->toContain('data-hph-edit="')
        ->and($src)->toContain("req('GET', 'homepage-hub')")
        // Nothing measured, nothing polled.
        ->and($src)->not->toContain('getBoundingClientRect')
        ->and($src)->not->toContain('offsetHeight')
        ->and($src)->not->toContain('setInterval');
});

it('is wired exactly once — the route file and the screen partial (finished state)', function () {
    /*
     * PINS THE FINISHED STATE. Zero is "built, never wired" — red in this lane's
     * worktree until the integrator adds the two lines in tools/hc-wire.php;
     * two is a duplicated route group or a sidebar entry wrapped twice.
     */
    $web = (string) file_get_contents(base_path('routes/web.php'));
    $app = (string) file_get_contents(resource_path('views/admin/app.blade.php'));

    expect(substr_count($web, "require __DIR__.'/homepage-hub-admin.php';"))->toBe(1)
        ->and(substr_count($app, "@include('admin.partials.homepage-hub')"))->toBe(1);
});

/* ═══ 2. THE SOURCE PICKER — AND THE SHOP SHOWS EXACTLY THAT ════════════════ */

it('saves mixed categories through the existing endpoint and the rail shows only those', function () {
    /*
     * THE DEFECT: "Category" meant ONE category; a rail of toners AND serums
     * could not be built. MUTATION: drop the `whereHas('categories', …)` arm of
     * GridSections::applyQuery() → the off-category product appears, red.
     */
    hcWire();
    $toner = Category::create(['name' => 'HC Toners', 'slug' => 'hc-toners']);
    $serum = Category::create(['name' => 'HC Serums', 'slug' => 'hc-serums']);
    $cream = Category::create(['name' => 'HC Creams', 'slug' => 'hc-creams']);
    $a = hcProduct('Toner Alpha');
    $b = hcProduct('Serum Beta');
    $c = hcProduct('Cream Gamma');
    $a->categories()->attach($toner->id);
    $b->categories()->attach($serum->id);
    $c->categories()->attach($cream->id);

    test()->actingAs(hcAdmin(), 'admin')->postJson('/admin-api/homepage/content', ['copy' => [
        'home_bs_source' => 'query', 'home_bs_cats' => $toner->id.','.$serum->id, 'home_bs_sort' => 'bestselling',
    ]])->assertOk()->assertJsonPath('rejected', []);

    $rail = hcRail(hcHome());

    expect($rail)->toContain('Toner Alpha')->toContain('Serum Beta')->not->toContain('Cream Gamma');

    // The hub summarises it in one line.
    $j = test()->getJson('/admin-api/homepage-hub')->assertOk()->json();
    $row = collect($j['sections'])->firstWhere('key', 'bestselling');
    expect($row['summary'])->toContain('Categories: HC Toners + HC Serums');
});

it('mixes brands with an order, and in-stock only drops what is sold out', function () {
    /*
     * MUTATION: drop `$base->inStock()` from applyQuery() → the sold-out one
     * is back; swap the price_asc arm for best selling → the order flips.
     */
    hcWire();
    $x = Brand::create(['name' => 'HC Brand X', 'slug' => 'hc-x']);
    $y = Brand::create(['name' => 'HC Brand Y', 'slug' => 'hc-y']);
    $z = Brand::create(['name' => 'HC Brand Z', 'slug' => 'hc-z']);
    hcProduct('Dear One', ['brand_id' => $x->id, 'price' => 9000]);
    hcProduct('Cheap Two', ['brand_id' => $y->id, 'price' => 1000]);
    hcProduct('Gone Three', ['brand_id' => $y->id, 'price' => 2000, 'stock_status' => 'outofstock']);
    hcProduct('Other Four', ['brand_id' => $z->id, 'price' => 500]);

    test()->actingAs(hcAdmin(), 'admin')->postJson('/admin-api/homepage/content', ['copy' => [
        'home_bs_source' => 'query', 'home_bs_brands' => $x->id.','.$y->id, 'home_bs_sort' => 'price_asc', 'home_bs_stock' => true,
    ]])->assertOk();

    $rail = hcRail(hcHome());

    expect($rail)->toContain('Dear One')->toContain('Cheap Two')
        ->not->toContain('Gone Three')->not->toContain('Other Four')
        ->and(strpos($rail, 'Cheap Two'))->toBeLessThan(strpos($rail, 'Dear One'));
});

it('keeps a manual list in the owner\'s order, on a rail and on an older row', function () {
    /*
     * THE DEFECT: "drag to reorder" that the shop ignores — a whereIn() answers
     * in id order. MUTATION: return `$rows` instead of mapping $ids over it in
     * the manual arm of fetchPool() → the order is the database's, red.
     */
    hcWire();
    $p1 = hcProduct('Manual First');
    $p2 = hcProduct('Manual Second');
    $p3 = hcProduct('Manual Third');
    $order = [$p3->id, $p1->id, $p2->id];

    test()->actingAs(hcAdmin(), 'admin')->postJson('/admin-api/homepage/content', ['copy' => [
        'home_bs_source' => 'manual', 'home_bs_picks' => implode(',', $order),
        'home_fl_src' => 'manual', 'home_fl_picks' => implode(',', $order),
    ]])->assertOk();

    $rail = hcRail(hcHome());
    expect(strpos($rail, 'Manual Third'))->toBeLessThan(strpos($rail, 'Manual First'))
        ->and(strpos($rail, 'Manual First'))->toBeLessThan(strpos($rail, 'Manual Second'));

    // The older Flash sale row, alone on the page.
    hcOnly(['flash']);
    $html = hcHome();
    $body = substr($html, strpos($html, '<div class="kbb-home">'));

    expect(strpos($body, 'Manual Third'))->toBeLessThan(strpos($body, 'Manual First'))
        ->and(strpos($body, 'Manual First'))->toBeLessThan(strpos($body, 'Manual Second'));
    expect(HomeSources::chosen(\App\Support\HomeSections::settings(), 'flash')->pluck('id')->all())->toBe($order);
});

it('gives a Grid section the mixed query through its own endpoint', function () {
    /*
     * MUTATION: drop applyQuery() from GridSectionApiController::update() →
     * the query is not stored and the grid keeps drawing best sellers, red.
     */
    $cat = Category::create(['name' => 'HC Masks', 'slug' => 'hc-masks']);
    $in = hcProduct('Mask In');
    hcProduct('Not A Mask');
    $in->categories()->attach($cat->id);
    $grid = GridSection::create(['name' => 'HC grid', 'slug' => 'hc-grid-q', 'status' => 'publish', 'position' => 1,
        'show_heading' => true, 'heading' => 'Masks', 'subheading' => '', 'source' => 'bestsellers', 'include_children' => false,
        'count' => 4, 'mobile_count' => 4, 'desktop_layout' => 'grid', 'desktop_cols' => 4, 'mobile_layout' => 'grid',
        'mobile_cols' => 2, 'skin' => '', 'card_label' => '', 'show_rank' => false, 'show_view_all' => false,
        'view_all_label' => '', 'view_all_url' => '']);

    test()->actingAs(hcAdmin(), 'admin')->putJson('/admin-api/grid-sections/'.$grid->id, [
        'values' => ['source' => 'query'],
        'source_query' => ['brands' => [], 'cats' => [$cat->id, 999999], 'sort' => 'newest', 'stock' => false],
    ])->assertOk()->assertJsonPath('source_query.cats', [$cat->id]);

    GridSections::flush();
    $row = collect(app(GridSections::class)->forHome())->first(fn ($r) => $r['section']->id === $grid->id);

    expect($row['items']->pluck('name')->all())->toBe(['Mask In']);
});

/* ═══ 3. REFUSED ════════════════════════════════════════════════════════════ */

it('refuses ids that name no row, and limits that are not offered', function () {
    /*
     * THE DEFECT: the ids cast kept any positive integer, so a hand-rolled POST
     * stored ids that drew nothing and that no screen could name; a count of
     * 999 would be a query the page never budgeted for.
     * MUTATION: drop the existing() filter from HomepageContent::saveCopy() →
     * 999999 is stored, red.
     */
    hcWire();
    $real = hcProduct('Real One');

    $j = test()->actingAs(hcAdmin(), 'admin')->postJson('/admin-api/homepage/content', ['copy' => [
        'home_bs_picks' => '999999,'.$real->id, 'home_bs_brands' => '888888',
        'home_bs_count_d' => '999', 'home_rc_limit' => '500', 'home_bs_sort' => 'DROP TABLE',
    ]])->assertOk()->json();

    $c = \App\Support\HomeSections::settings();

    expect($c['home_bs_picks'])->toBe((string) $real->id)
        ->and($c['home_bs_brands'])->toBe('')
        ->and($c['home_bs_count_d'])->toBe('8')
        ->and($c['home_rc_limit'])->toBe('5')
        ->and($c['home_bs_sort'])->toBe('bestselling')
        ->and(implode(' ', array_keys($j['rejected'])))->toContain('#999999')->toContain('#888888');
});

/* ═══ 4. THE TYPEAHEAD AND THE PREVIEW ══════════════════════════════════════ */

it('searches by name, brand or SKU — capped, visible only, allowlisted', function () {
    /*
     * THE DEFECT CLASS: /admin-api answers a model and `products` carries
     * wc_id, sku and total_sales. MUTATION: return `$rows` instead of
     * HomepageHub::card() → the key set grows, red; drop the limit → 25, red.
     */
    hcWire();
    $brand = Brand::create(['name' => 'Glowmaker', 'slug' => 'glowmaker']);

    for ($i = 1; $i <= 25; $i++) {
        hcProduct('Snail Essence '.$i);
    }

    hcProduct('Hidden Snail', ['is_visible' => false]);
    $byBrand = hcProduct('Plain Cream', ['brand_id' => $brand->id]);
    $bySku = hcProduct('Odd Name', ['sku' => 'ZZ-UNIQUE-9']);
    $admin = hcAdmin();

    $j = test()->actingAs($admin, 'admin')->getJson('/admin-api/homepage-hub/products?q=snail')->assertOk()->json();

    expect($j['products'])->toHaveCount(20)
        ->and(array_keys($j['products'][0]))->toBe(['id', 'name', 'brand', 'image', 'price'])
        ->and(array_column($j['products'], 'name'))->not->toContain('Hidden Snail')
        ->and(json_encode($j))->not->toContain('wc_id')->not->toContain('total_sales')->not->toContain('HC-SKU');

    expect(test()->getJson('/admin-api/homepage-hub/products?q=s')->json('products'))->toBe([])
        ->and(test()->getJson('/admin-api/homepage-hub/products?q=glowmaker')->json('products.0.id'))->toBe($byBrand->id)
        ->and(test()->getJson('/admin-api/homepage-hub/products?q=ZZ-UNIQUE-9')->json('products.0.id'))->toBe($bySku->id);
});

it('previews an unsaved source with the shop\'s own query, writing nothing', function () {
    /*
     * MUTATION: build the preview from its own query instead of
     * GridSections::pool() and it drifts from the page — this pins that the
     * manual order and the allowlist are the pool's.
     */
    hcWire();
    $p1 = hcProduct('Prev One');
    $p2 = hcProduct('Prev Two');
    $before = DB::table('settings')->count();

    $j = test()->actingAs(hcAdmin(), 'admin')->postJson('/admin-api/homepage-hub/preview', [
        'source' => 'manual', 'picks' => [$p2->id, $p1->id], 'limit' => 8,
    ])->assertOk()->json();

    expect(array_column($j['products'], 'id'))->toBe([$p2->id, $p1->id])
        ->and(array_keys($j['products'][0]))->toBe(['id', 'name', 'brand', 'image', 'price'])
        ->and(DB::table('settings')->count())->toBe($before);

    test()->postJson('/admin-api/homepage-hub/preview', ['source' => 'query', 'limit' => 500])->assertStatus(422);
});

it('guards all three endpoints with their own capabilities, failing closed', function () {
    /*
     * THE DEFECT: a new prefix with no line in AdminCapabilities::RULES is
     * owner-only, and one that matched content.manage would have handed the
     * catalogue to anyone with that grant. MUTATION: delete the three RULES
     * lines → forPath() answers null, red; give `support` homepagehub.search →
     * its 403 becomes 200, red.
     */
    expect(AdminCapabilities::forPath('GET', 'admin-api/homepage-hub'))->toBe('homepagehub.view')
        ->and(AdminCapabilities::forPath('GET', 'admin-api/homepage-hub/products'))->toBe('homepagehub.search')
        ->and(AdminCapabilities::forPath('POST', 'admin-api/homepage-hub/preview'))->toBe('homepagehub.search')
        ->and(AdminCapabilities::forPath('POST', 'admin-api/homepage-hub/anything-new'))->toBeNull();

    $sections = array_merge(...array_map(fn ($s) => array_keys($s[2]), \App\Support\AdminRoles::SECTIONS));
    expect($sections)->toContain('homepagehub.view', 'homepagehub.search');

    hcWire();
    test()->getJson('/admin-api/homepage-hub/products?q=ab')->assertStatus(401);
    test()->actingAs(hcAdmin('support'), 'admin')->getJson('/admin-api/homepage-hub/products?q=ab')->assertStatus(403);
    test()->actingAs(hcAdmin('support'), 'admin')->getJson('/admin-api/homepage-hub')->assertStatus(403);
    test()->actingAs(hcAdmin('editor'), 'admin')->getJson('/admin-api/homepage-hub')->assertOk();
});

/* ═══ 5. FAST: FLAT AS THE CATALOGUE GROWS ══════════════════════════════════ */

it('costs the homepage the same queries with 3 products as with 40, on a mixed query', function () {
    /*
     * Rule 4: a page's cost stays FLAT as the catalogue grows. A source that
     * fetched per product, or hydrated brands one by one, grows with the rows.
     * MUTATION: drop `->with('brand:id,name,slug')` from fetchPool()'s base →
     * one query per card, red.
     */
    $cat = Category::create(['name' => 'HC Flat', 'slug' => 'hc-flat']);
    $brand = Brand::create(['name' => 'HC Flat Brand', 'slug' => 'hc-flat-brand']);
    hcWrite(['home_bs_source' => 'query', 'home_bs_cats' => (string) $cat->id, 'home_bs_brands' => (string) $brand->id, 'home_bs_sort' => 'random',
        'home_rc_src' => 'query', 'home_rc_cats' => (string) $cat->id]);
    hcOnly(['bestselling', 'recommended']);

    $count = function (): int {
        Cache::flush();
        SettingsService::forgetMemo();
        \App\Models\Setting::flushMap();
        DB::flushQueryLog();
        DB::enableQueryLog();
        test()->get('/')->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    $make = function (int $n) use ($cat, $brand) {
        for ($i = 0; $i < $n; $i++) {
            $p = hcProduct('Flat '.uniqid(), ['brand_id' => $brand->id]);
            $p->categories()->attach($cat->id);
        }
    };

    $make(3);
    $count(); // warm the process-level memos (translations, grid registry) once
    $three = $count();
    $make(37);
    $forty = $count();

    expect($forty)->toBe($three);
});

it('builds the hub with the same queries for 3 picked products as for 24', function () {
    /*
     * The admin side is held to the same rule: the payload reads product cards
     * only for the ids a manual list holds, in one whereIn. MUTATION: replace
     * HomepageHub::cards() with a per-id find() → grows with the list, red.
     */
    hcWire();
    $admin = hcAdmin();
    $ids = [];

    for ($i = 0; $i < 24; $i++) {
        $ids[] = hcProduct('Pick '.$i)->id;
    }

    $count = function (array $picks) use ($admin): int {
        hcWrite(['home_bs_source' => 'manual', 'home_bs_picks' => implode(',', $picks)]);
        Cache::flush();
        DB::flushQueryLog();
        DB::enableQueryLog();
        test()->actingAs($admin, 'admin')->getJson('/admin-api/homepage-hub')->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    $count($ids); // warm the process-level memos once
    expect($count($ids))->toBe($count(array_slice($ids, 0, 3)));
});

/* ═══ 6. RULE 1: NOTHING MOVES UNTIL HE MOVES IT ════════════════════════════ */

it('renders the homepage byte-identical with every new setting stored at its default', function () {
    /*
     * THE DEFECT THIS WOULD CATCH: a "default" that is not the shipped page —
     * a limit of 6 where the row took 5, a query source standing in for the
     * hard-coded one. Rendering with the new keys absent and then stored at
     * their defaults must give the same document.
     * MUTATION: ship `home_rc_limit` at '6' → one more card, red.
     */
    hcOnly(['bundles', 'recommended', 'bestsellers', 'flash', 'bestselling', 'trending', 'under54']);

    for ($i = 0; $i < 12; $i++) {
        hcProduct('Same '.$i, ['featured' => true, 'sale_price' => 3000]);
    }

    $before = \Tests\Support\EnglishRenderWalk::mask(hcHome());

    $defaults = [];

    foreach (array_merge(HomeSources::SCHEMA_BUNDLES, HomeSources::SCHEMA) as $k => $f) {
        $defaults[$k] = $f['default'];
    }

    foreach (['bs', 'tr', 'u54'] as $p) {
        foreach (['brands', 'cats', 'sort', 'stock'] as $s) {
            $defaults["home_{$p}_{$s}"] = HomepageContent::SCHEMA["home_{$p}_{$s}"]['default'];
        }
    }

    hcWrite($defaults);

    expect(\Tests\Support\EnglishRenderWalk::mask(hcHome()))->toBe($before);
});

/* ═══ 7. THE TWO STRIPS (phones only by default) ═══════════════════════════ */

it('ships the Top strip and the Countries strip on phones and hidden on laptops, by CSS alone', function () {
    /*
     * The owner: "ONLY FOR MOBILE: turn this off in laptop by default". ONE
     * document for both widths — `d-off` hides each from 901px — so a laptop
     * is not sent different HTML.
     * MUTATION: drop 'topstrip' from MOBILE_ONLY_BY_DEFAULT → no d-off on it,
     * red; drop the countries @unless → it draws with the section off, red.
     */
    $html = hcHome();
    $body = substr($html, strpos($html, '<div class="kbb-home">'));

    expect(substr_count($body, '<div class="kts d-off"'))->toBe(1)
        ->and($body)->toContain('1-3 Days Delivery all over UAE')
        ->and(substr_count($body, '<style id="kbb-kts">'))->toBe(1)
        ->and(preg_match('#<div class="kfb kfb-m kfb-pill kfb-notx d-off"#', $body))->toBe(1)
        ->and($body)->toContain('UAE&#039;s Authentic K-Beauty Store')
        // The strip is first inside the homepage, under the header.
        ->and(strpos($body, 'class="kts'))->toBeLessThan(strpos($body, 'class="kfb'));

    // Laptops on, from the same row control the hub draws.
    $rows = app(HomepageSections::class)->all();
    $rows['topstrip']['desktop'] = true;
    $rows['countries']['desktop'] = true;
    app(HomepageSections::class)->save($rows);

    $on = hcHome();
    expect($on)->toContain('<div class="kts"')->toMatch('#<div class="kfb kfb-m kfb-d kfb-pill kfb-notx"#');

    // Off on both: no element at all.
    $rows['topstrip']['desktop'] = $rows['topstrip']['mobile'] = false;
    $rows['countries']['desktop'] = $rows['countries']['mobile'] = false;
    app(HomepageSections::class)->save($rows);

    $off = hcHome();
    expect($off)->not->toContain('class="kts')->not->toContain('kbb-kts')
        ->and(substr($off, strpos($off, '<div class="kbb-home">')))->not->toContain('class="kfb ');
});

it('escapes the owner\'s strip wording and refuses a link that is not http(s) or a path', function () {
    /*
     * Rule 5. MUTATION: print `$ts['text']` with {!! !!} instead of e() → the
     * <script> survives, red; drop HomeSections::url() → javascript: is an href.
     */
    hcWrite(['home_ts_text' => '<script>alert(1)</script> Eid sale', 'home_ts_url' => 'javascript:alert(1)', 'home_ts_bg' => '#123456', 'home_ts_size' => '99']);

    $html = hcHome();

    expect($html)->not->toContain('<script>alert(1)</script>')
        ->and($html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt; Eid sale')
        ->and($html)->not->toContain('javascript:alert')
        ->and($html)->toContain('--kts-bg:#123456;--kts-ink:#FFFFFF;--kts-s:13px');

    hcWrite(['home_ts_url' => '/collections/sale/']);
    expect(hcHome())->toMatch('#<a class="kts d-off" style="[^"]*" href="[^"]*/collections/sale/">#');
});

it('puts both strips on Homepage content with full edits, and the countries strip writes the Flag bar', function () {
    /*
     * One copy of every value: the countries strip's words, colours and sizes
     * ARE Appearance → Header → Flag bar's, saved through /admin-api/header.
     * MUTATION: point the `countries` editor at a new settings key → the
     * header tab it names is missing, red.
     */
    hcWire();
    $j = test()->actingAs(hcAdmin(), 'admin')->getJson('/admin-api/homepage-hub')->assertOk()->json();
    $by = collect($j['sections'])->keyBy('key');

    expect($by['topstrip']['editor'])->toBe(['kind' => 'content', 'tab' => 'topstrip'])
        ->and(array_column($by['topstrip']['fields'], 'key'))->toBe(array_keys(\App\Support\HomeStrips::SCHEMA))
        ->and($by['topstrip']['desktop'])->toBeFalse()->and($by['topstrip']['mobile'])->toBeTrue()
        ->and($by['countries']['editor']['get'])->toBe('header')
        ->and($by['countries']['desktop'])->toBeFalse()->and($by['countries']['mobile'])->toBeTrue();

    $header = test()->getJson('/admin-api/header')->assertOk()->json();
    expect(array_column($header['tabs'], 'key'))->toContain(...$by['countries']['editor']['tabs']);
});

it('draws the shipped line whole — the threshold from Delivery & Shipping, with its space', function () {
    /*
     * THE DEFECT, caught in the 390px shot: "Free Delivery overAED 199". The
     * strip is a flex row, and a flex container turns a text run and the price
     * <span> into two items, dropping the space between them. The line is one
     * <span> now. MUTATION: take the <span> out of top-strip.blade.php → red.
     * And the figure is the shop's, not a literal: drop the free-shipping
     * method and the second half goes, the first stays.
     */
    $uae = \App\Models\ShippingZone::create(['name' => 'All UAE', 'position' => 0]);
    \App\Models\ShippingZoneLocation::create(['shipping_zone_id' => $uae->id, 'type' => 'country', 'code' => 'AE']);
    $free = \App\Models\ShippingMethod::create(['shipping_zone_id' => $uae->id, 'type' => 'free_shipping', 'title' => 'Free', 'cost' => 0, 'min_amount' => 19900, 'enabled' => true, 'position' => 0]);

    expect(hcHome())->toMatch('#<div class="kts d-off" style="[^"]*"><span>1-3 Days Delivery all over UAE – Free Delivery over <span class="woocommerce-Price-amount[^>]*><span[^>]*>AED</span> 199</span></span></div>#');

    $free->delete();

    expect(hcHome())->toMatch('#<div class="kts d-off" style="[^"]*"><span>1-3 Days Delivery all over UAE</span></div>#');
});
