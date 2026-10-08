<?php

declare(strict_types=1);

/*
 * "You may also like" as a carousel, mixed from the same brand and the same
 * category, with the owner's controls and per-product picks.     (Lane PS)
 *
 * The owner, 2 October: "You may also like should be a slider on each product
 * page, I need it carousel by suggesting products from the same brand and
 * category mixed. Give us control to choose the products query what to show
 * etc, or manual selection also."
 *
 * WHAT THE SHOP DID BEFORE, which every case below would have caught: FOUR
 * cards, from the product's categories only (no brand anywhere in the choice),
 * sold-out products included, in a grid that wrapped — and no control of any
 * kind. Store\ProductController::related() hard-coded all of it.
 *
 * MUTATIONS, each RUN against this file and each red:
 *
 *   M1–M3 went with App\Services\AlsoLikeRail (Lane RP2); RecsBlocksTest
 *       carries their successors against App\Services\ProductRecs.
 *   M4  Product::booted() — remove the ProductRecs::forget() listener →
 *       "it forgets a product's lists when the product is saved" red.
 *   M5  AlsoLikePicks::fromRequest() — remove the visible() check → "it
 *       refuses a pick the shop cannot show" red (200 instead of 422).
 *   M6  ProductPageApiController::save() — move the unknown-key check below
 *       the sections write → "refuses an unknown carousel key and writes
 *       nothing" red: the switch was written before the 422.
 *   M7  kbb-product.css — `grid-auto-flow:column` deleted → the CSS case red;
 *       on the page that is the four-across wrapping grid again.
 */

use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Services\AlsoLikeSettings;
use App\Services\ProductSections;
use App\Services\SettingsService;
use App\Support\AlsoLikePicks;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

function ymalAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'YMAL Owner',
        'email' => 'ymal-'.Str::random(6).'@example.test',
        'password' => Hash::make('secret-secret'),
        'role' => 'owner',
    ]);
}

function ymalProduct(string $name, ?Brand $brand, array $categories, int $sales, array $extra = []): Product
{
    $p = Product::create(array_merge([
        'slug' => Str::slug($name).'-'.Str::random(5),
        'name' => $name,
        'type' => 'simple',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 5000,
        'stock_status' => 'instock',
        'brand_id' => $brand?->id,
        'total_sales' => $sales,
    ], $extra));

    if ($categories !== []) {
        $p->categories()->sync(array_map(fn ($c) => $c->id, $categories));
    }

    return $p;
}

/**
 * A small shop: the product under test, three of its brand (other shelves),
 * three of its category (other brands), two in a sibling shelf under the same
 * parent, and three unrelated best sellers.
 *
 * @return array<string, mixed>
 */
function ymalShop(): array
{
    $anua = Brand::create(['slug' => 'anua-'.Str::random(4), 'name' => 'Anua']);
    $other = Brand::create(['slug' => 'other-'.Str::random(4), 'name' => 'Other']);
    $far = Brand::create(['slug' => 'far-'.Str::random(4), 'name' => 'Far']);

    $skin = Category::create(['slug' => 'skin-'.Str::random(4), 'name' => 'Skincare']);
    $toners = Category::create(['slug' => 'toners-'.Str::random(4), 'name' => 'Toners', 'parent_id' => $skin->id]);
    $serums = Category::create(['slug' => 'serums-'.Str::random(4), 'name' => 'Serums', 'parent_id' => $skin->id]);
    $hair = Category::create(['slug' => 'hair-'.Str::random(4), 'name' => 'Hair']);
    $masks = Category::create(['slug' => 'masks-'.Str::random(4), 'name' => 'Masks']);

    $self = ymalProduct('Heartleaf Toner', $anua, [$toners], 10);

    return [
        'self' => $self,
        'b1' => ymalProduct('Anua One', $anua, [$masks], 300),
        'b2' => ymalProduct('Anua Two', $anua, [$masks], 200),
        'b3' => ymalProduct('Anua Three', $anua, [$hair], 100),
        'c1' => ymalProduct('Toner One', $other, [$toners], 290),
        'c2' => ymalProduct('Toner Two', $other, [$toners], 190),
        'c3' => ymalProduct('Toner Three', $other, [$toners], 90),
        's1' => ymalProduct('Serum One', $far, [$serums], 50),
        's2' => ymalProduct('Serum Two', $far, [$serums], 40),
        // The whole shop's best sellers — above anything the demo catalogue
        // the test database carries has sold, so they are the top-up.
        'x1' => ymalProduct('Hair Best', $far, [$hair], 90000),
        'x2' => ymalProduct('Hair Next', $far, [$hair], 80000),
        'x3' => ymalProduct('Hair Third', $far, [$hair], 70000),
        // Never shown: sold out, hidden, a draft — of the same brand AND
        // category, and the best sellers in the shop, so a missing filter puts
        // them first rather than somewhere a test might not look.
        'oos' => ymalProduct('Anua Sold Out', $anua, [$toners], 500000, ['stock_status' => 'outofstock']),
        'hidden' => ymalProduct('Anua Hidden', $anua, [$toners], 600000, ['is_visible' => false]),
        'draft' => ymalProduct('Anua Draft', $anua, [$toners], 700000, ['status' => 'draft']),
        'brand' => $anua,
        'toners' => $toners,
    ];
}

/**
 * Read the settings snapshot and the interface strings once, the way the
 * product page has by the time it reaches the rail, so a query count measures
 * the rail and nothing else.
 */
function ymalWarm(): void
{
    app(SettingsService::class)->get('ymal_enabled');
    AlsoLikeSettings::wording(app(AlsoLikeSettings::class)->all());
}

/*
 * (Lane RP2) The rail's own cases — the brand/category mix, the ratio, the
 * seven rules, the tree top-up — went with App\Services\AlsoLikeRail when the
 * owner made the three blocks brand / category / best sellers. What they also
 * guarded lives on in tests/Feature/RecsBlocksTest.php against
 * App\Services\ProductRecs: hidden, sold-out and the product itself left out;
 * a product's own picks first or alone; two queries cold and warm; a product
 * hidden after the lists were cached gone on the next view; nothing asked of
 * the database with the foot off; and a saved product forgets its lists (below).
 */

it('forgets a product\'s lists when the product is saved', function () {
    $s = ymalShop();
    $this->get('/product/'.$s['self']->slug.'/')->assertOk();

    expect(Cache::has(\App\Services\ProductRecs::CACHE_PREFIX.$s['self']->id))->toBeTrue();

    $s['self']->forceFill(['name' => 'Heartleaf Toner 2'])->save();

    expect(Cache::has(\App\Services\ProductRecs::CACHE_PREFIX.$s['self']->id))->toBeFalse();
});

/* ═══════════════════════════════ the page ════════════════════════════════ */

it('draws the shop\'s own cards in one scrolling row, with arrows and a reserved size', function () {
    $s = ymalShop();
    // (Lane RP2) Block 3 alone: the carousel this file has always been about.
    app(AlsoLikeSettings::class)->save(['brand_on' => false, 'cat_on' => false]);

    $html = $this->get('/product/'.$s['self']->slug.'/')->assertOk()->getContent();

    expect(substr_count($html, 'data-ymal '))->toBe(1)
        ->and($html)->toContain('class="rel kbb-pgrid ymal-track" data-skin="')
        ->and($html)->toContain('id="related" data-ymal-track tabindex="0" role="region"')
        ->and($html)->toContain('style="--ymal-d:5;--ymal-m:2.3"') // Lane PX: 2.3 on a phone, as the owner asked
        ->and($html)->toContain('aria-label="Previous products" disabled')
        ->and($html)->toContain('aria-label="More products"')
        ->and($html)->toContain('<h2 id="ymal-h">You may also like</h2>');

    preg_match('#id="related".*?</section>#s', $html, $m);
    // Twelve, the shipped count, from the shop's best sellers.
    expect(substr_count($m[0] ?? '', 'class="kbb-card kbb-tile'))->toBe(12);
    // The cards' pictures are lazy: the rail is at the foot of the page.
    expect(substr_count($m[0] ?? '', 'loading="eager"'))->toBe(0);
});

it('prints his heading in English and his Arabic heading only on the Arabic page', function () {
    $c = AlsoLikeSettings::defaults();

    expect(AlsoLikeSettings::wording($c)['title'])->toBe('You may also like');

    $c['title'] = 'Pairs well with';
    $c['title_ar'] = 'يناسبه أيضاً';
    expect(AlsoLikeSettings::wording($c)['title'])->toBe('Pairs well with');

    app()->setLocale('ar');
    expect(AlsoLikeSettings::wording($c)['title'])->toBe('يناسبه أيضاً');

    // An empty Arabic box is the Arabic interface string — never his English.
    $c['title_ar'] = '';
    expect(AlsoLikeSettings::wording($c)['title'])->not->toBe('Pairs well with');
    app()->setLocale('en');
});

it('sizes the cards in CSS and reads no geometry to do it', function () {
    $css = (string) file_get_contents(resource_path('css/kbb/kbb-product.css'));
    $flat = preg_replace('/\s+/', '', $css);

    expect($flat)->toContain('.rel.ymal-track{grid-template-columns:none;grid-auto-flow:column;grid-auto-columns:calc((100%-(var(--ymal-m,2)-1)*var(--kbb-gap))/var(--ymal-m,2));')
        ->and($flat)->toContain('grid-auto-columns:calc((100%-(var(--ymal-d,5)-1)*var(--kbb-gap))/var(--ymal-d,5));')
        ->and($flat)->toContain('scroll-snap-type:xmandatory;')
        ->and($flat)->toContain('[dir="rtl"].ymal-btnsvg{transform:scaleX(-1)}');

    $js = (string) file_get_contents(resource_path('js/kbb/ymal.js'));

    foreach (['getBoundingClientRect', 'offsetWidth', 'offsetHeight', 'offsetLeft', 'scrollWidth', 'ResizeObserver', 'style.width', 'style.height'] as $api) {
        expect(str_contains($js, $api))->toBeFalse("ymal.js reaches for {$api}");
    }

    // Wired once into the storefront bundle.
    $app = (string) file_get_contents(resource_path('js/kbb/app.js'));
    expect(substr_count($app, "import { initAlsoLike } from './ymal.js';"))->toBe(1)
        ->and(substr_count($app, '    initAlsoLike,'))->toBe(1);

    // And the partial is on the product page exactly once — through the
    // three-block foot (Lane RP), which the page includes exactly once.
    $page = (string) file_get_contents(resource_path('views/store/product.blade.php'));
    $foot = (string) file_get_contents(resource_path('views/partials/product/recs.blade.php'));
    expect(substr_count($page, "@include('partials.product.recs')"))->toBe(1)
        ->and(substr_count($page, "@include('partials.you-may-also-like')"))->toBe(0)
        ->and(substr_count($foot, "@include('partials.you-may-also-like')"))->toBe(1);
});

/* ═════════════════════ Appearance → Product page ═════════════════════════ */

it('serves and saves the carousel tab as its own half', function () {
    $this->actingAs(ymalAdmin(), 'admin');

    $body = $this->getJson('/admin-api/product-page')->assertOk()->json();
    expect($body['also'])->toHaveCount(1)
        ->and($body['also'][0]['label'])->toBe('You may also like')
        ->and(array_column($body['also'][0]['fields'], 'key'))->toBe(AlsoLikeSettings::TABS['ymal'][2]);

    app(ProductSections::class)->save(['tabs' => ['desktop' => false, 'mobile' => false]]);

    $this->postJson('/admin-api/product-page', ['also' => ['g_layout_d' => 'grid', 'brand_count_m' => '8', 'per_desktop' => '4', 'autoplay' => true]])
        ->assertOk()->assertJson(['ok' => true, 'saved' => 4]);

    $c = app(AlsoLikeSettings::class)->all();
    expect([$c['g_layout_d'], (string) $c['brand_count_m'], $c['per_desktop'], $c['autoplay']])->toBe(['grid', '8', '4', true]);
    // The switch the carousel post never mentioned stayed where it was.
    expect(app(ProductSections::class)->all()['tabs']['desktop'])->toBeFalse();

    // A select stores one of its own options or the default.
    $this->postJson('/admin-api/product-page', ['also' => ['g_layout_m' => 'everything', 'per_phone' => '7', 'also_count_d' => '99']])->assertOk();
    $c = app(AlsoLikeSettings::class)->all();
    expect([$c['g_layout_m'], $c['per_phone'], (string) $c['also_count_d']])->toBe(['std', '2.3', 'global']); // Lane PX: the default is 2.3

    // Markup in a heading is wording, not markup.
    $this->postJson('/admin-api/product-page', ['also' => ['title' => '<script>x</script>Pairs well']])->assertOk();
    expect(app(AlsoLikeSettings::class)->all()['title'])->toBe('xPairs well');
});

it('refuses an unknown carousel key and writes nothing at all', function () {
    $this->actingAs(ymalAdmin(), 'admin');

    $this->postJson('/admin-api/product-page', [
        'sections' => [['key' => 'tabs', 'desktop' => false, 'mobile' => false]],
        'also' => ['not_a_field' => 1],
    ])->assertStatus(422)->assertJson(['ok' => false]);

    expect(app(ProductSections::class)->all()['tabs']['desktop'])->toBeTrue();
    expect(app(AlsoLikeSettings::class)->all())->toBe(AlsoLikeSettings::defaults());
});

it('ships the carousel on, twelve, five and 2.3, and autoplay off', function () {
    expect(AlsoLikeSettings::defaults())->toMatchArray([
        'enabled' => true, 'count' => 12,
        // Lane PX: per_phone 2 -> 2.3 and arrows_m off, both the owner's ask.
        'hide_oos' => true, 'per_desktop' => '5', 'per_phone' => '2.3', 'arrows_m' => false, 'autoplay' => false,
        'title' => '', 'title_ar' => '',
    ]);
});

/* ═══════════════════ Catalog → Products → (edit) ═════════════════════════ */

it('saves a product\'s picks with the product, checked', function () {
    $s = ymalShop();
    $this->actingAs(ymalAdmin(), 'admin');
    $url = '/admin-api/product-editor-save/'.$s['self']->id;

    $this->postJson($url, ['also_like' => ['mode' => 'first', 'ids' => [$s['x2']->id, $s['c3']->id]]])->assertOk();
    expect(AlsoLikePicks::read($s['self']->fresh()))->toBe(['mode' => 'first', 'ids' => [$s['x2']->id, $s['c3']->id]]);

    // The editor's own payload carries them back, in order, as rows.
    $load = $this->getJson('/admin-api/product-editor-load/'.$s['self']->id)->assertOk()->json('product.also_like');
    expect($load['mode'])->toBe('first')
        ->and(array_column($load['items'], 'name'))->toBe(['Hair Next', 'Toner Three'])
        ->and($load['items'][0]['on_shop'])->toBeTrue();

    // A save that does not mention them leaves them alone.
    $this->postJson($url, ['name' => 'Heartleaf Toner'])->assertOk();
    expect(AlsoLikePicks::read($s['self']->fresh())['ids'])->toBe([$s['x2']->id, $s['c3']->id]);

    // Back to the rule with nothing picked: the column is empty again.
    $this->postJson($url, ['also_like' => ['mode' => 'rule', 'ids' => []]])->assertOk();
    expect($s['self']->fresh()->also_like)->toBeNull();
});

it('refuses a pick the shop cannot show, the product itself, a 25th, and a mode that is not one', function () {
    $s = ymalShop();
    $this->actingAs(ymalAdmin(), 'admin');
    $url = '/admin-api/product-editor-save/'.$s['self']->id;

    $this->postJson($url, ['also_like' => ['mode' => 'first', 'ids' => [$s['hidden']->id]]])->assertStatus(422);
    $this->postJson($url, ['also_like' => ['mode' => 'first', 'ids' => [$s['draft']->id]]])->assertStatus(422);
    $this->postJson($url, ['also_like' => ['mode' => 'first', 'ids' => [$s['self']->id]]])->assertStatus(422);
    $this->postJson($url, ['also_like' => ['mode' => 'always', 'ids' => []]])->assertStatus(422);
    $this->postJson($url, ['also_like' => ['mode' => 'first', 'ids' => ['1; drop table']]])->assertStatus(422);

    $many = [];
    for ($i = 0; $i < 25; $i++) {
        $many[] = ymalProduct('Many '.$i, null, [], 0)->id;
    }
    $this->postJson($url, ['also_like' => ['mode' => 'only', 'ids' => $many]])->assertStatus(422);

    // And none of the refusals wrote anything — not the picks, not the name.
    $this->postJson($url, ['name' => 'Renamed', 'also_like' => ['mode' => 'first', 'ids' => [$s['hidden']->id]]])->assertStatus(422);
    expect($s['self']->fresh()->also_like)->toBeNull()
        ->and($s['self']->fresh()->name)->toBe('Heartleaf Toner');
});

it('searches only what the shop can show, never the product itself, and publishes an allowlist', function () {
    $s = ymalShop();
    $this->actingAs(ymalAdmin(), 'admin');

    $rows = $this->getJson('/admin-api/product-editor-also-like?q=anua&exclude='.$s['self']->id)->assertOk()->json('products');

    expect(array_column($rows, 'name'))->toBe(['Anua Sold Out', 'Anua One', 'Anua Two', 'Anua Three'])
        ->and(array_keys($rows[0]))->toBe(['id', 'name', 'brand', 'image', 'sold_out'])
        ->and($rows[0]['sold_out'])->toBeTrue();

    // A LIKE wildcard is a character, not a pattern: `_` matches no name here
    // (as a pattern it would match every one), and `%` only the names that
    // really carry a percent sign.
    expect($this->getJson('/admin-api/product-editor-also-like?q=_')->json('products'))->toBe([]);
    foreach ($this->getJson('/admin-api/product-editor-also-like?q=%25')->json('products') as $row) {
        expect($row['name'])->toContain('%');
    }
});

it('keeps the picker behind the product editor\'s door', function () {
    $this->getJson('/admin-api/product-editor-also-like?q=a')->assertStatus(401);

    $routes = (string) file_get_contents(base_path('routes/product-editor-admin.php'));
    expect(substr_count($routes, "Route::get('/product-editor-also-like', [\\App\\Http\\Controllers\\Admin\\AlsoLikeApiController::class, 'search']);"))->toBe(1);

    expect(\App\Support\AdminCapabilities::forPath('GET', 'admin-api/product-editor-also-like'))->toBe('catalog.view');
});

it('draws the picker panel and the settings tab in the console exactly once', function () {
    $editor = (string) file_get_contents(resource_path('views/admin/partials/product-editor-screen.blade.php'));

    expect(substr_count($editor, "{ key: 'alsolike',   label: 'You may also like', col: 'main', view: function(){ return ymalView(); } },"))->toBe(1)
        ->and(substr_count($editor, 'function ymalView('))->toBe(1)
        ->and(substr_count($editor, '    bindYmal();'))->toBe(1);

    $console = (string) file_get_contents(resource_path('views/admin/app.blade.php'));
    expect(substr_count($console, 'function pyaBody('))->toBe(1)
        ->and(substr_count($console, 'data-pptab="ymal"'))->toBe(1)
        ->and(substr_count($console, "if(PPDIRTY.also){ payload.also={};"))->toBe(1);
});
