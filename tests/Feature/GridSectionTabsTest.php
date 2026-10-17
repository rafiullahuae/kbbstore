<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\GridSection;
use App\Models\Product;
use App\Services\GridSections;
use App\Support\GridTabs;
use Illuminate\Support\Facades\DB;
use Tests\Support\ArabicShop;

/**
 * "Product tabs" — a Grid section shown as tabs.                   (Lane HTB)
 *
 * The owner: "i need Tabs section on homepage like different tabs in one
 * section, like Best Sellers, Trending K-Beauty, New Arrivals etc. We need to
 * be able to add new tabs with title and products selection query via
 * categories, brands etc and also manual section, and also the sorting inside
 * that section. also i need option as carousel or grid." — "Options A and B
 * are final. give us also the align on left or center." — "need also for
 * arabic too with proper translation option available while creating." —
 * "give me options to create a full new section. i don't want to disturb the
 * homepage at the moment."
 *
 * What a defect here looks like on the shop: a tab row whose style or
 * alignment is whatever a hand-rolled POST typed; a `javascript:` "View all";
 * a blank tab on /ar because the Arabic title was empty; every tab's pictures
 * downloading on load because the closed panels were not `hidden`; a cold
 * homepage that costs one query per product; and — the one the owner named —
 * a homepage that changed the day the package applied.
 */
function htbAdmin(string $role = 'owner'): AdminUser
{
    return AdminUser::create([
        'name' => 'HTB '.$role, 'email' => 'htb-'.$role.'-'.uniqid().'@example.test',
        'password' => 'secret-secret', 'role' => $role,
    ]);
}

function htbProducts(int $n): void
{
    $brand = Brand::firstOrCreate(['slug' => 'htb-brand'], ['name' => 'HTB Brand']);
    $have = Product::query()->where('slug', 'like', 'htb-p%')->count();

    foreach ($have >= $n ? [] : range($have + 1, $n) as $i) {
        Product::create([
            'slug' => 'htb-p'.$i, 'name' => 'HTB Product '.$i, 'status' => 'publish', 'is_visible' => true,
            'brand_id' => $brand->id, 'price' => 4000 + $i, 'stock_status' => 'instock', 'type' => 'simple',
            'rating' => 0.0, 'review_count' => 0, 'total_sales' => 900000 - $i,
        ]);
    }
}

function htbTabs(array $over = []): array
{
    return array_replace([
        'eyebrow' => 'Shop the edit', 'eyebrow_ar' => '', 'heading_ar' => 'الأكثر حبًا', 'sub_ar' => '', 'view_all_ar' => '',
        'align' => 'center',
        'list' => [
            ['title' => 'Best Sellers', 'title_ar' => 'الأكثر مبيعًا', 'source' => 'bestsellers', 'url' => '/best-sellers'],
            ['title' => 'Trending K-Beauty', 'title_ar' => '', 'source' => 'trending', 'url' => ''],
            ['title' => 'New Arrivals', 'title_ar' => 'وصل حديثًا', 'source' => 'newest', 'url' => '/new-in'],
        ],
    ], $over);
}

function htbSection(array $extra = []): GridSection
{
    $n = GridSection::query()->count() + 1;

    return GridSection::create(array_merge([
        'name' => 'HTB '.$n, 'slug' => 'htb-'.$n.'-'.uniqid(), 'status' => 'publish', 'position' => $n,
        'show_heading' => true, 'heading' => 'Loved most', 'subheading' => '',
        'source' => 'bestsellers', 'include_children' => false, 'count' => 4, 'mobile_count' => 4,
        'desktop_layout' => 'grid', 'desktop_cols' => 4, 'mobile_layout' => 'carousel', 'mobile_cols' => 2,
        'skin' => '', 'card_label' => '', 'show_rank' => false,
        'show_view_all' => false, 'view_all_label' => '', 'view_all_url' => '',
    ], $extra));
}

function htbHome(string $path = '/'): string
{
    GridSections::flush();

    return test()->get($path)->assertOk()->getContent();
}

/** The tabs section's own markup, cut out of a page. */
function htbCut(string $html): string
{
    $at = strpos($html, '<section class="sec kbb-gsec gs-tabs');
    expect($at)->not->toBeFalse('no tabs section on the page');

    return substr($html, $at, strpos($html, '</div></section>', $at) - $at);
}

beforeEach(function () {
    GridSections::flush();
    htbProducts(12);
});

it('stores only its own options: style, alignment, source and sort fall back, a seventh tab is dropped, a bad link stores nothing', function () {
    /*
     * Rule 5: "A select stores one of its own options or the default."
     * MUTATION: in GridTabs::clean() return $raw['align'] as typed and the
     * alignment assertion is red; drop the `count($list) >= self::MAX` break
     * and the six-tab assertion is red; replace SafeUrl::href($url, '') with
     * $url in cleanTab() and the javascript: assertion is red. Each run, red,
     * put back.
     */
    $section = htbSection(['status' => 'draft']);
    $list = [];

    foreach (range(1, 8) as $i) {
        $list[] = ['title' => "<b>Tab {$i}</b>", 'source' => 'spiral', 'sort' => 'by-magic', 'url' => 'javascript:alert(1)'];
    }

    $list[1] = ['title' => 'Brand', 'source' => 'brand', 'brand_id' => 999999, 'sort' => 'price_desc', 'url' => '//evil.example/x'];
    $list[2] = ['title' => 'Mine', 'source' => 'manual', 'manual_ids' => [Product::query()->value('id'), 777777], 'sort' => 'nope', 'url' => '/new-in'];
    $list[3] = ['title' => '', 'title_ar' => 'بلا عنوان', 'source' => 'newest'];

    test()->actingAs(htbAdmin(), 'admin')
        ->putJson('/admin-api/grid-sections/'.$section->id, [
            'product_tabs' => ['align' => 'diagonal', 'eyebrow' => '<script>x</script>Hi', 'list' => $list],
            'tab_style' => 'c',
        ])->assertOk();

    $fresh = $section->fresh();
    $t = $fresh->tabs;

    expect($fresh->tab_style)->toBe('a')
        ->and($t['align'])->toBe('center')
        ->and($t['eyebrow'])->toBe('xHi')
        // 8 posted, one has no title: 7 real, 6 kept.
        ->and($t['list'])->toHaveCount(GridTabs::MAX)
        ->and($t['list'][0]['title'])->toBe('Tab 1')
        ->and($t['list'][0]['source'])->toBe('bestsellers')
        ->and($t['list'][0]['sort'])->toBe('')
        ->and($t['list'][0]['url'])->toBe('')
        ->and($t['list'][1]['brand_id'])->toBe(0)
        ->and($t['list'][1]['sort'])->toBe('price_desc')
        ->and($t['list'][1]['url'])->toBe('')
        ->and($t['list'][2]['manual_ids'])->toBe([(int) Product::query()->value('id')])
        ->and($t['list'][2]['sort'])->toBe('manual')
        ->and($t['list'][2]['url'])->toBe('/new-in');

    foreach ($t['list'] as $tab) {
        expect(GridTabs::SOURCES)->toHaveKey($tab['source'])
            ->and(mb_strlen($tab['title']))->toBeLessThanOrEqual(GridTabs::TITLE_MAX);
    }

    // Switching tabs off turns it back into the ordinary grid.
    test()->actingAs(htbAdmin(), 'admin')
        ->putJson('/admin-api/grid-sections/'.$section->id, ['product_tabs' => null])->assertOk();

    expect($section->fresh()->tabs)->toBeNull();
});

it('draws style A and B, the alignment, one open panel and hidden others, with tab roles and no script', function () {
    /*
     * MUTATION: drop `@if ($gtI > 0) hidden @endif` from grid-tabs.blade.php
     * and "hidden panels" is red — on the shop every tab's pictures would
     * download on load. Change `gs-al-{{ $gtCfg['align'] }}` to a constant and
     * the left case is red.
     */
    htbSection(['tabs' => htbTabs(), 'tab_style' => 'a']);

    $page = htbHome();
    $a = htbCut($page);

    expect($a)->toContain('gs-tabs gs-tabs-a gs-al-center')
        ->and($a)->toContain('data-rp-tabs')
        ->and(substr_count($a, 'role="tab"'))->toBe(3)
        ->and(substr_count($a, 'role="tabpanel"'))->toBe(3)
        ->and(substr_count($a, 'aria-selected="true"'))->toBe(1)
        ->and(preg_match_all('/role="tabpanel"[^>]* hidden[ >]/', $a))->toBe(2)
        ->and(preg_match('/id="gs-\d+-p0"[^>]* hidden[ >]/', $a))->toBe(0)
        ->and(preg_match_all('/role="tab"[^>]*tabindex="-1"/', $a))->toBe(2)
        ->and($a)->toContain('role="tablist"')
        ->and($a)->toContain('<span class="hs-eyebrow">Shop the edit</span>')
        ->and($a)->toContain('>Loved most</h2>')
        ->and($a)->not->toContain('<script')
        // Every card is the shop's own card.
        ->and(substr_count($a, 'class="gs-cell'))->toBe(12)
        // The tab row's CSS is on the page exactly once.
        ->and(substr_count($page, '.kbb-gsec.gs-tabs .gs-tl{display:flex'))->toBe(1);

    // Panel ids are unique and each tab controls its own.
    preg_match_all('/id="(gs-\d+-[tp]\d+)"/', $a, $ids);
    expect(count($ids[1]))->toBe(count(array_unique($ids[1])));

    // The "View all" goes where the tab says, and only for tabs that have one.
    expect($a)->toContain('href="/best-sellers"')->and($a)->toContain('href="/new-in"')
        ->and(substr_count($a, 'class="gs-all"'))->toBe(2);

    GridSection::query()->update(['tab_style' => 'b', 'tabs' => json_encode(htbTabs(['align' => 'left']))]);

    $b = htbCut(htbHome());

    expect($b)->toContain('gs-tabs gs-tabs-b gs-al-left');
});

it('shows the Arabic title on /ar and the English where the Arabic is empty, never a blank tab', function () {
    /*
     * MUTATION: make GridTabs::pick() return $arabic unconditionally and the
     * Trending tab is an empty button on /ar — red. Make it return $english
     * and the Arabic titles are red.
     */
    htbSection(['tabs' => htbTabs()]);

    $en = htbCut(htbHome());

    expect($en)->toContain('data-rp-tab>Best Sellers</button>')
        ->and($en)->not->toContain('الأكثر مبيعًا');

    ArabicShop::on();

    $ar = htbCut(htbHome('/ar/'));

    expect($ar)->toContain('data-rp-tab>الأكثر مبيعًا</button>')
        ->and($ar)->toContain('data-rp-tab>Trending K-Beauty</button>')
        ->and($ar)->toContain('data-rp-tab>وصل حديثًا</button>')
        ->and($ar)->toContain('>الأكثر حبًا</h2>')
        // Eyebrow has no Arabic: the English, not nothing.
        ->and($ar)->toContain('<span class="hs-eyebrow">Shop the edit</span>')
        // The tab's own link is on the Arabic shop.
        ->and($ar)->toContain('href="/ar/best-sellers"');
});

it('translates only through the shop’s existing per-field endpoint, POST only, failing closed', function () {
    /*
     * The Translate button beside every Arabic box posts to
     * /admin-api/translations/machine/field — the endpoint and capability the
     * Translation screens already use (content.manage), the provider set under
     * Translation → Language settings. No new endpoint, so no new surface.
     *
     * MUTATION: map that route to a capability `support` holds in
     * AdminCapabilities::RULES and the 403 is red; register it with
     * Route::any and the GET case is red.
     */
    test()->postJson('/admin-api/translations/machine/field', ['text' => 'Best Sellers', 'locale' => 'ar'])
        ->assertStatus(401);

    test()->actingAs(htbAdmin('support'), 'admin')
        ->postJson('/admin-api/translations/machine/field', ['text' => 'Best Sellers', 'locale' => 'ar'])
        ->assertForbidden();

    $owner = htbAdmin();

    expect(test()->actingAs($owner, 'admin')->getJson('/admin-api/translations/machine/field')->status())
        ->toBeIn([404, 405]);

    // No provider configured in the suite: it says so, and stores nothing.
    $res = test()->actingAs($owner, 'admin')
        ->postJson('/admin-api/translations/machine/field', ['text' => 'Best Sellers', 'locale' => 'ar']);

    $res->assertStatus(409);
    expect((string) $res->json('message'))->toContain('No translation service is connected');

    // And the editor is told whether it is connected before anybody presses.
    $s = htbSection(['tabs' => htbTabs(), 'status' => 'draft']);
    test()->actingAs($owner, 'admin')->getJson('/admin-api/grid-sections/'.$s->id)
        ->assertOk()->assertJsonPath('machine_translate', false)
        ->assertJsonPath('tab_style', 'a')
        ->assertJsonPath('product_tabs.list.0.title_ar', 'الأكثر مبيعًا');
});

it('costs nothing warm, and the same cold over 3 products and over 40', function () {
    /*
     * Each tab is one more spec in GridSections::forHome()'s de-duplicated
     * pool, inside the existing cache entry.
     * MUTATION: drop `->with('brand:id,name,slug')` from fetchPool()'s base
     * and the 40-product count is higher than the 3-product one — red.
     * Remove the Cache::remember around forHome()'s build and warm is red.
     */
    DB::table('products')->where('slug', 'like', 'htb-p%')->delete();
    htbProducts(3);
    test()->get('/');

    $warmNone = htbWarm();

    htbSection(['tabs' => htbTabs(['list' => [
        ['title' => 'Best', 'source' => 'bestsellers'],
        ['title' => 'New', 'source' => 'newest'],
        ['title' => 'Brand', 'source' => 'brand', 'brand_id' => Brand::query()->where('slug', 'htb-brand')->value('id'), 'sort' => 'price_asc'],
    ]])]);

    $small = htbCold();
    $warm = htbWarm();

    htbProducts(40);

    $large = htbCold();

    expect($warm)->toBe($warmNone, "warm with a tabs section {$warm}, without {$warmNone}")
        ->and($large)->toBe($small, "3 products cost {$small}, 40 cost {$large}");
});

it('shares a pool between a tab and a grid asking for the same products', function () {
    /*
     * MUTATION: build the tab's spec as anything but GridSections::pool()'s
     * format (e.g. prefix it) and the second section costs two more queries.
     */
    htbSection(['source' => 'bestsellers']);
    $one = htbCold();

    htbSection(['tabs' => htbTabs(['list' => [['title' => 'Best', 'source' => 'bestsellers']]])]);
    $two = htbCold();

    expect($two)->toBe($one);
});

it('leaves the homepage byte-identical while no tabs section is published', function () {
    /*
     * "give me options to create a full new section. i don't want to disturb
     * the homepage at the moment." The package ships no section; the preset
     * creates a DRAFT. An ordinary grid prints exactly what it printed.
     * MUTATION: push GridSections::tabsCss() from store/home.blade.php
     * unconditionally and the first comparison is red.
     */
    $before = htbHome();

    $draft = htbSection(['status' => 'draft', 'tabs' => htbTabs()]);
    expect(htbHome())->toBe($before);

    // An ordinary published grid, with and without an EMPTY tabs value, is the
    // same bytes — and carries no byte of this feature.
    $draft->delete();
    $grid = htbSection();
    $plain = htbHome();

    $grid->forceFill(['tabs' => ['list' => []], 'tab_style' => 'b'])->save();

    expect(htbHome())->toBe($plain)
        ->and($plain)->not->toContain('gs-tabs')
        ->and($plain)->not->toContain('.gs-tl');
});

it('creates the Product tabs preset as a draft with Best Sellers, Trending K-Beauty and New Arrivals in both languages', function () {
    /*
     * MUTATION: drop the preset's 'product_tabs' handling from store() and the
     * row is an ordinary grid — red. Remove `status = 'draft'` and it is live.
     */
    test()->actingAs(htbAdmin(), 'admin')
        ->postJson('/admin-api/grid-sections', ['preset' => 'tabs'])->assertCreated();

    $row = GridSection::query()->sole();
    $tabs = GridTabs::of($row);

    expect($row->status)->toBe('draft')
        ->and($row->tab_style)->toBe('a')
        ->and(array_column($tabs['list'], 'title'))->toBe(['Best Sellers', 'Trending K-Beauty', 'New Arrivals'])
        ->and(array_column($tabs['list'], 'title_ar'))->toBe(['الأكثر مبيعًا', 'الأكثر رواجًا في الجمال الكوري', 'وصل حديثًا'])
        ->and(array_column($tabs['list'], 'source'))->toBe(['bestsellers', 'trending', 'newest']);

    // A draft draws nothing.
    expect(htbHome())->not->toContain('gs-tabs');
});

it('previews the unsaved tabs, in Arabic when asked, and writes nothing', function () {
    $s = htbSection(['status' => 'draft']);

    $res = test()->actingAs(htbAdmin(), 'admin')
        ->postJson('/admin-api/grid-sections/'.$s->id.'/preview', [
            'product_tabs' => htbTabs(), 'tab_style' => 'b', 'lang' => 'ar',
        ])->assertOk();

    expect((string) $res->json('html'))->toContain('gs-tabs-b')
        ->and((string) $res->json('html'))->toContain('الأكثر مبيعًا')
        ->and((string) $res->json('css'))->toContain('.kbb-gsec.gs-tabs-b .gs-t')
        ->and($s->fresh()->tabs)->toBeNull();
});

it('renders every tab cold without a lazy load, so the cost stays flat', function () {
    /*
     * ComponentLoadContractTest names this partial as a caller of the product
     * card and points here for its cold render.
     * MUTATION: drop `->with('brand:id,name,slug')` from fetchPool()'s base
     * and this throws a LazyLoadingViolationException — red.
     */
    htbSection(['tabs' => htbTabs()]);
    GridSections::flush();
    \Illuminate\Database\Eloquent\Model::preventLazyLoading();

    try {
        $html = test()->get('/')->assertOk()->getContent();
    } finally {
        \Illuminate\Database\Eloquent\Model::preventLazyLoading(false);
    }

    expect(substr_count(htbCut($html), 'class="gs-cell'))->toBe(12);
});

it('styles the tab row with constants, logical properties and no direction selector', function () {
    /*
     * Rule 5 (nothing typed inside a style element) and the RTL rule: on /ar
     * "left" must become the right-hand side with no second rule to keep in step.
     * MUTATION: write `margin-left:auto` for the centred phone row in
     * GridSections::tabsCss() and this is red.
     */
    $css = GridSections::tabsCss();

    expect($css)->not->toContain('[dir')
        ->and($css)->not->toMatch('/(?<![-\w])(left|right)\s*:/')
        ->and($css)->not->toMatch('/margin-(left|right)|padding-(left|right)/')
        ->and($css)->not->toContain('{{')
        ->and($css)->toContain('.kbb-gsec.gs-al-left .hs-head{text-align:start');
});

function htbWarm(): int
{
    test()->get('/');
    DB::flushQueryLog();
    DB::enableQueryLog();
    test()->get('/')->assertOk();
    $n = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $n;
}

function htbCold(): int
{
    test()->get('/');
    GridSections::flush();
    DB::flushQueryLog();
    DB::enableQueryLog();
    test()->get('/')->assertOk();
    $n = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $n;
}
