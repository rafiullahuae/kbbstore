<?php

declare(strict_types=1);

/**
 * Shop by skin concern — design E, the concern chips, under Trending. (Lane CX)
 *
 * The owner, 14 October 2026: "E · Concern chips is fine. make the section,
 * and give full control on backend in homepage sections page, under Trending
 * K-Beauty This Week section, with beautiful light background. also link the
 * collections pages to whole each capsule."
 *
 * Each case names the defect it catches on the shop and the mutation that
 * turns it red.
 */

use App\Models\Category;
use App\Models\Product;
use App\Services\HomepageContent;
use App\Services\HomepageSections;
use App\Services\ModuleSchema;
use App\Services\SettingsService;
use App\Support\HomeConcerns;
use App\Support\HomeSections;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\ArabicShop;
use Tests\Support\HomepageContentAdminRoutes;

beforeEach(function () {
    app(SettingsService::class)->set('demo_content', false);
});

function ccWrite(array $values): void
{
    ModuleSchema::write(app(SettingsService::class), 'homepage_content', HomepageContent::SCHEMA, $values);
    \App\Models\Setting::flushMap();
    SettingsService::forgetMemo();
}

function ccHome(string $path = '/'): string
{
    \App\Models\Setting::flushMap();
    SettingsService::forgetMemo();
    Cache::flush();

    return test()->get($path)->assertOk()->getContent();
}

/** A top-level category, as Catalog → Categories → Add makes one. */
function ccCat(string $name, string $slug): Category
{
    return Category::create(['name' => $name, 'slug' => $slug, 'path' => $slug]);
}

/** Enough products for Trending to draw, so "after Trending" means something. */
function ccCatalogue(): void
{
    for ($i = 1; $i <= 8; $i++) {
        Product::create(['slug' => 'cc-p-'.$i, 'name' => 'Concern product '.$i, 'type' => 'simple', 'status' => 'publish',
            'is_visible' => true, 'stock_status' => 'instock', 'price' => 5000 + $i, 'total_sales' => 90000 - $i]);
    }
}

function ccSection(string $html): string
{
    return preg_match('#<section class="sec hs hs-concerns\b.*?</div></section>#s', $html, $m) === 1 ? $m[0] : '';
}

it('draws the chips directly after Trending, each whole capsule a link to its collection', function () {
    /*
     * THE DEFECT: the section somewhere other than under Trending, a chip
     * whose link is only its label (the owner: "link the collections pages to
     * whole each capsule"), or a hard-coded address that breaks the day the
     * scheme or the host changes.
     * MUTATION: move the include above Trending in store/home.blade.php → red
     * on the order. Wrap only .hs-cc-t in the <a> → red on the capsule regex.
     */
    ccCatalogue();
    $pore = ccCat('Pore Care', 'pore-care');
    $spots = ccCat('Pigmentation & Dark Spots', 'pigmentation');
    $oily = ccCat('Oily Skin', 'oily-skin');

    $html = ccHome();
    preg_match_all('#<section class="sec ([^"]*)"#', $html, $m);
    $keys = array_values(array_filter(array_map(fn ($c) => preg_match('/\bhs-(trending|concerns|blog)\b/', $c, $k) ? $k[1] : null, $m[1])));

    expect(array_slice($keys, 0, 2))->toBe(['trending', 'concerns']);

    $sec = ccSection($html);
    preg_match_all('#<a class="hs-cc-i" href="([^"]+)" style="[^"]*"><span class="hs-cc-ic"><svg [^>]*width="32" height="32"[^>]*>.*?</svg></span><span class="hs-cc-t">([^<]+)</span></a>#s', $sec, $chips, PREG_SET_ORDER);

    expect(array_map(fn ($c) => [$c[1], html_entity_decode($c[2])], $chips))->toBe([
        [$pore->url(), 'Pore Care'],
        [$spots->url(), 'Pigmentation & Dark Spots'],
        [$oily->url(), 'Oily Skin'],
    ])->and($pore->url())->toEndWith('/collections/pore-care/')
        ->and($sec)->toContain('<h2 id="hs-concerns-h">Shop by skin concern</h2>')
        ->and($sec)->toContain('hs-cc-bg-gradient')
        ->and($sec)->toContain('--cc-b1:#FFF6F0;--cc-b2:#FCEAF2');
});

it('does not draw a chip whose category is missing, and draws nothing when none exists', function () {
    /*
     * THE DEFECT: a chip pointing at a deleted collection (a 404 on the
     * homepage), or an empty band with a heading and no chips on a shop that
     * has none of the seven.
     * MUTATION: in HomeConcerns::section() drop the `$cat === null` skip → a
     * fatal on url(); return the array when $chips is empty → red on ''.
     */
    expect(ccSection(ccHome()))->toBe('')
        ->and(ccHome())->not->toContain('hs-concerns');

    $pore = ccCat('Pore Care', 'pore-care');
    ccWrite(['home_cc_items' => json_encode([['c' => $pore->id, 'i' => 'pores'], ['c' => 999999, 'i' => 'acne'], ['s' => ['no-such-slug'], 'i' => 'oily']])]);

    $sec = ccSection(ccHome());
    expect(substr_count($sec, 'class="hs-cc-i"'))->toBe(1)
        ->and($sec)->toContain('href="'.$pore->url().'"');

    // Every chip removed in the admin: nothing at all, not the seven defaults.
    ccWrite(['home_cc_items' => '[]']);
    expect(ccHome())->not->toContain('hs-concerns');
});

it('draws nothing when its Laptop and Phone switches are off', function () {
    /*
     * MUTATION: drop the @unless ($sections->hidden('concerns')) → red.
     */
    ccCat('Pore Care', 'pore-care');
    expect(ccHome())->toContain('hs-concerns');

    $rows = [];
    foreach (app(HomepageSections::class)->all() as $key => $row) {
        $rows[$key] = ['desktop' => $key === 'concerns' ? false : $row['desktop'], 'mobile' => $key === 'concerns' ? false : $row['mobile'], 'skin' => $row['skin'], 'order' => $row['order']];
    }
    app(SettingsService::class)->set('homepage_sections', $rows);

    expect(ccHome())->not->toContain('hs-concerns');
});

it('keeps the section under Trending on a shop whose saved order never mentioned it', function () {
    /*
     * THE DEFECT: the live shop has a saved order; a key it never saved falls
     * back to its registry index, which on a reordered shop is anywhere.
     * MUTATION: return $rows unchanged from HomepageSections::follow() → red.
     */
    $saved = [];
    $keys = array_keys(HomepageSections::REGISTRY);
    $keys = array_values(array_diff($keys, ['concerns', 'trending']));
    array_unshift($keys, 'trending'); // the owner moved Trending to the top

    foreach ($keys as $i => $k) {
        $saved[$k] = ['desktop' => true, 'mobile' => true, 'order' => $i];
    }
    app(SettingsService::class)->set('homepage_sections', $saved);
    SettingsService::forgetMemo();

    $order = array_keys(app(HomepageSections::class)->all());
    expect(array_search('concerns', $order, true))->toBe(array_search('trending', $order, true) + 1);
});

it('finds every chip\'s category in one query, however many chips there are', function () {
    /*
     * THE DEFECT: a query per chip — the N+1 the speed rules forbid.
     * MUTATION: look each row up with Category::find() inside section() → the
     * seven-chip count is seven, not one.
     */
    $n = 0;
    DB::listen(function ($q) use (&$n) { if (str_contains($q->sql, '"categories"') || str_contains($q->sql, '`categories`')) { $n++; } });
    $count = function () use (&$n): int {
        $c = HomeSections::settings();
        $n = 0;
        $rows = HomeConcerns::fetch($c);

        return $n * 100 + $rows->count();
    };

    ccCat('Pore Care', 'pore-care');
    ccCat('Oily Skin', 'oily-skin');
    $two = $count();

    foreach ([['Anti-Aging', 'anti-aging'], ['Hydration & Glow', 'hydration-glow'], ['Acne & Blemish Care', 'acne-blemish-care'], ['Redness & Sensitive Skin', 'redness-sensitive-skin'], ['Pigmentation', 'pigmentation']] as [$name, $slug]) {
        ccCat($name, $slug);
    }

    // One query and two rows; one query and seven rows.
    expect($two)->toBe(102)->and($count())->toBe(107);

    // And on the page it is part of the homepage's one cached entry: a warm
    // render does not read categories for the chips at all.
    ccHome();
    $hits = 0;
    DB::listen(function ($q) use (&$hits) { if (in_array('pore-care', $q->bindings, true)) { $hits++; } });
    SettingsService::forgetMemo();
    test()->get('/')->assertOk();
    expect($hits)->toBe(0);
});

it('uses the Arabic heading and labels on /ar, and the category\'s Arabic name for a chip with none', function () {
    /*
     * MUTATION: ignore `$arabic` in HomeConcerns::section() → red on /ar.
     */
    ArabicShop::on();
    $pore = ccCat('Pore Care', 'pore-care');
    $own = ccCat('Glass Skin', 'glass-skin');
    DB::table('translations')->insert(['locale' => 'ar', 'group' => 'categories', 'item_id' => $own->id, 'field' => 'name', 'value' => 'بشرة زجاجية', 'status' => 'published']);
    ccWrite(['home_cc_items' => json_encode([['c' => $pore->id, 'i' => 'pores', 'la' => 'العناية بالمسام'], ['c' => $own->id, 'i' => 'hydra']])]);

    $ar = ccSection(ccHome('/ar/'));
    expect($ar)->toContain('تسوّقي حسب مشكلة البشرة')
        ->and($ar)->toContain('العناية بالمسام')
        ->and($ar)->toContain('بشرة زجاجية')
        ->and($ar)->toContain('href="/ar/collections/pore-care/"');

    $en = ccSection(ccHome());
    expect($en)->toContain('>Pore Care<')->and($en)->toContain('>Glass Skin<');
});

it('cleans every setting: selects keep their own options, colours are hex, labels are text', function () {
    /*
     * THE DEFECT: a hand-made POST or edited row putting markup in a label,
     * an unknown icon in the page, or a non-colour into the style attribute.
     * MUTATION: in HomeConcerns::items() drop strip_tags → red on <script>;
     * drop the ICONS check → red on the icon; ModuleSchema's colour cast is
     * the colour half.
     */
    HomepageContentAdminRoutes::wire(app());
    test()->actingAs(\App\Models\AdminUser::create(['name' => 'O', 'email' => 'o-'.uniqid().'@example.test', 'password' => 'secret-secret', 'role' => 'owner']), 'admin');
    $pore = ccCat('Pore Care', 'pore-care');

    $json = $this->getJson('/admin-api/homepage/content')->assertOk()->json();
    $tabs = array_column($json['tabs'], 'key');
    expect(array_slice($tabs, array_search('trending', $tabs, true), 2))->toBe(['trending', 'concerns'])
        ->and(collect($json['picks']['categories'])->firstWhere('slug', 'pore-care')['id'])->toBe($pore->id);

    $res = $this->postJson('/admin-api/homepage/content', ['slides' => [], 'copy' => [
        'home_cc_bg_from' => 'red;}body{display:none', 'home_cc_align' => 'evil', 'home_cc_ic_d' => '999',
        'home_cc_items' => json_encode([['c' => $pore->id, 'l' => '<script>x</script>Pores', 'i' => 'nope', 't' => 'neon'], ['c' => 'abc']]),
    ]])->assertOk()->json();

    $c = HomeSections::settings();
    expect($c['home_cc_bg_from'])->toBe('#FFF6F0')
        ->and(HomeSections::pick($c, 'home_cc_align'))->toBe('left')
        ->and(HomeSections::pick($c, 'home_cc_ic_d'))->toBe('60')
        ->and(json_decode($c['home_cc_items'], true))->toBe([['c' => $pore->id, 's' => [], 'l' => 'xPores', 'la' => '', 'i' => 'pores', 't' => '']]);

    $sec = ccSection(ccHome());
    expect($sec)->not->toContain('<script>')
        ->and($sec)->toContain('--cc-ic-d:60px')
        ->and($sec)->toContain('--cc-b1:#FFF6F0');

    // A good save sticks.
    ccWrite(['home_cc_bg' => 'solid', 'home_cc_bg_from' => '#eef6ff', 'home_cc_layout_m' => 'wrap', 'home_cc_align' => 'center']);
    $sec = ccSection(ccHome());
    expect($sec)->toContain('hs-cc-bg-solid')->and($sec)->toContain('--cc-b1:#EEF6FF;--cc-b2:#EEF6FF')
        ->and($sec)->toContain('hs-cc-m-wrap')->and($sec)->toContain('hs-cc-ac');

    // And background "none" prints no colour at all.
    ccWrite(['home_cc_bg' => 'none']);
    expect(ccSection(ccHome()))->not->toContain('--cc-b1');
});

it('offers the section on the All sections list, under Trending, with its own editor', function () {
    /*
     * MUTATION: drop 'concerns' from HomepageHub::EDITORS → the row has no
     * content editor, red.
     */
    expect(HomepageSections::REGISTRY)->toHaveKey('concerns')
        ->and(array_search('concerns', array_keys(HomepageSections::REGISTRY), true))
        ->toBe(array_search('trending', array_keys(HomepageSections::REGISTRY), true) + 1)
        ->and(\App\Services\HomepageHub::EDITORS['concerns'])->toBe(['kind' => 'content', 'tab' => 'concerns']);
});

it('ships every icon with its own width and height, and none outside the set', function () {
    /*
     * The header lock's rule, applied here: an inline <svg> with no size is
     * the lotus logo filling the screen on an old browser.
     */
    foreach (array_keys(HomeConcerns::ICONS) as $k) {
        expect(HomeConcerns::svg($k))->toStartWith('<svg viewBox="0 0 48 48" width="32" height="32"')
            ->and(HomeConcerns::ICON_TONE)->toHaveKey($k);
    }
    expect(HomeConcerns::svg('<x>'))->toBe(HomeConcerns::svg('pores'));
});

it('is one row with arrows on a laptop by default, and drops the arrows when the row wraps', function () {
    // 2.60.509, the owner: "this will be carousel, one row." Before it the
    // laptop wrapped onto two rows with no arrows. The arrows are the product
    // carousels' own (data-ymal, driven by ymal.js already on the page).
    // MUTATION: put home_cc_layout_d's default back to 'wrap' -- the first
    // half is red.
    ccCatalogue();
    ccCat('Pore Care', 'pore-care');
    ccCat('Oily Skin', 'oily-skin');

    $sec = ccSection(ccHome());
    expect($sec)->toContain('hs-cc-d-scroll')
        ->and($sec)->toContain(' data-ymal>')
        ->and(substr_count($sec, 'data-ymal-prev'))->toBe(1)
        ->and(substr_count($sec, 'data-ymal-next'))->toBe(1)
        ->and($sec)->toContain('<ul class="hs-cc-list" data-ymal-track data-ymal-drag>')
        // 2.60.510: the row stops where the shopper lets go -- no snapping by default.
        ->and($sec)->toContain('hs-cc-snap-free')
        // Phone arrows are off by default: the phone swipes.
        ->and($sec)->toContain('hs-cc-noarr-m');

    ccWrite(['home_cc_layout_d' => 'wrap']);
    $wrap = ccSection(ccHome());
    expect($wrap)->toContain('hs-cc-d-wrap')
        ->and($wrap)->not->toContain('data-ymal')
        ->and($wrap)->toContain('<ul class="hs-cc-list">');
});

it('scrolls freely by default and snaps only when asked, with a mouse drag that measures nothing', function () {
    // 2.60.510, the owner: "freely move as per the user scroll ... not limits
    // ... smooth, super calm." The row had scroll-snap (mandatory on a phone,
    // proximity on a laptop), so it jumped to the nearest chip on release.
    // MUTATION: put `scroll-snap-type:x mandatory` back on the phone row's
    // base rule -- the first expectation is red.
    $css = (string) file_get_contents(resource_path('css/kbb/kbb.css'));
    preg_match_all('#\.kbb-home \.hs-cc-[md]-scroll \.hs-cc-list\{[^}]*\}#', $css, $base);

    expect(implode('', $base[0]))->not->toContain('scroll-snap-type')
        ->and($css)->toContain('.kbb-home .hs-cc-snap-chip.hs-cc-m-scroll .hs-cc-list{scroll-snap-type:x mandatory}');

    $js = (string) file_get_contents(resource_path('js/kbb/ymal.js'));
    expect($js)->toContain("if (track.hasAttribute('data-ymal-drag')) dragToScroll(track, reduce, () => stop());")
        ->and(substr($js, (int) strpos($js, 'function dragToScroll')))->not->toMatch('/getBoundingClientRect|offset(Width|Height|Left)|scrollWidth|clientWidth|ResizeObserver/');

    ccCatalogue();
    ccCat('Pore Care', 'pore-care');
    ccWrite(['home_cc_snap' => 'chip']);
    expect(ccSection(ccHome()))->toContain('hs-cc-snap-chip');
});
