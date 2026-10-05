<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Page;
use App\Models\Product;
use App\Services\PageBanners;
use App\Services\PageHeaders;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;
use Tests\Support\PageBannersRoutes;
use Tests\Support\PageHeaderRoutes;
use Tests\Support\StorefrontAdminRoutes;

/**
 * The top of a custom page: the strip off by default, switched on from the
 * "Edit header" panel, ordered against the header area by drag and drop, with
 * the gap under the site header gone and three spacings to set. (Lane SP3)
 *
 * The owner, with his phone screenshot of /super-sale/
 * (docs/sp-owner/super-sale-phone.png): "turned off the strip by default on
 * all pages, and allow to turn ON on any page from the edit panel on the
 * front-end, also give facilty to sort header area and strip. by drag n drop
 * up down. also remove any space between header area and main site header.
 * and give option to control to spacings."
 */
function sp3Admin(string $role = 'owner', array $revokes = []): AdminUser
{
    $admin = AdminUser::create([
        'name' => 'PT '.$role, 'email' => 'pt-'.$role.'-'.uniqid().'@example.test',
        'password' => 'secret-secret', 'role' => $role,
    ]);
    if ($revokes !== []) {
        $admin->forceFill(['revokes' => $revokes])->save();
    }

    return $admin;
}

function sp3Store(string $key, array $value): void
{
    app(SettingsService::class)->set($key, $value);
    SettingsService::forgetMemo();
    app(PageBanners::class)->forget();
    app(PageHeaders::class)->forget();
}

function sp3Products(int $n, string $tag = 'x'): void
{
    for ($i = 1; $i <= $n; $i++) {
        Product::create(['slug' => "pt-{$tag}-{$i}", 'name' => "PT {$i}", 'type' => 'simple', 'status' => 'publish',
            'is_visible' => true, 'price' => 2000, 'sale_price' => 1000, 'stock_status' => 'instock']);
    }
}

/** The top area a page printed, or ''. */
function sp3Block(string $html): string
{
    return preg_match('#<style id="kbb-pt-css">.*?\n</div>\n(?=<section class="sec">)#s', $html, $m) ? $m[0] : '';
}

/** The strip switched on for one page, with the shipped banner. */
function sp3StripOn(string $page = 'collection:super-sale'): void
{
    sp3Store(PageBanners::KEY, ['banners' => PageBanners::defaults()['banners'], 'assign' => [$page => 'super-sale']]);
}

beforeEach(function () {
    PageHeaderRoutes::wire($this->app);
    PageBannersRoutes::wire($this->app);
    SettingsService::forgetMemo();
    app(PageBanners::class)->forget();
    app(PageHeaders::class)->forget();
});

/* ═════════════════════════════════════════ the strip, off by default ═══ */

it('ships the strip off on every custom page and keeps the banner to switch back on', function () {
    /*
     * DEFECT THIS CATCHES: the pink "100% Authentic Products" strip still
     * across /super-sale/ after the package — the first thing his screenshot
     * crossed out.
     * MUTATION: put 'collection:super-sale' => 'super-sale' back in
     * PageBanners::defaults()['assign'] -> red on the first line.
     */
    sp3Products(2);
    Page::firstOrCreate(['slug' => 'about'], ['title' => 'About us', 'content' => '<p>About.</p>', 'status' => 'published']);

    foreach (['/super-sale', '/new-in', '/best-sellers', '/everything-under-54-aed', '/about'] as $path) {
        expect(str_contains($this->get($path)->assertOk()->getContent(), 'class="kbb-pb'))->toBeFalse($path);
    }

    expect(PageBanners::defaults()['assign'])->toBe([])
        ->and(PageBanners::defaults()['banners'][0]['items'][0]['en'])->toBe('100% Authentic Products');
});

it('turns a saved strip off on every page and keeps every banner, once', function () {
    /*
     * A shop that SAVED Pages → Page banners carries its own assignment, which
     * the new empty default cannot reach — the live shop is one. Without the
     * migration the package changes nothing there.
     * MUTATION: return 0 from stripOff() without writing -> red.
     */
    $banners = [PageBanners::blank('super-sale', 'Super Sale'), PageBanners::blank('promo', 'Promo')];
    $banners[1]['bg'] = '#112233';
    sp3Store(PageBanners::KEY, ['banners' => $banners, 'assign' => ['collection:super-sale' => 'super-sale', 'collection:new-in' => 'promo']]);

    $migration = require database_path('migrations/2027_08_21_100000_clear_caches_page_top_strip_off.php');
    ob_start();
    $migration->up();
    $migration->up();   // twice: nothing more to do
    ob_end_clean();

    SettingsService::forgetMemo();
    $stored = app(SettingsService::class)->get(PageBanners::KEY);

    expect($stored['assign'])->toBe([])
        ->and(array_column($stored['banners'], 'id'))->toBe(['super-sale', 'promo'])
        ->and($stored['banners'][1]['bg'])->toBe('#112233');
});

/* ═════════════════════════════════════ no space under the site header ═══ */

it('puts the first block straight under the site header, and zeroes what made the gap', function () {
    /*
     * DEFECT THIS CATCHES: the gap the owner arrowed. Measured in Chromium on
     * /super-sale/ before this: 26px at 390 and 51.3px at 1280 between the
     * strip and the header picture, made of .kbb-pb's 4px margin, the
     * listing section's padding (6px / 14px) and its .wrap's padding-top
     * (16px / 33.28px); with the strip off the same 22px / 47.3px sat under
     * the site header. After: 0 and 0, and the products the 12px / 22px below
     * the picture they always were (docs/sp-shots).
     * MUTATION: drop `.kbb-home .sec.kbb-pt-s>.wrap{padding-top:0;…}` or
     * `.kbb-home .kbb-pt+.sec{padding-top:0}` from TOP_CSS -> red; print the
     * header back inside the listing's section -> sp3Block() is '' and red.
     */
    sp3Products(2);
    $html = $this->get('/super-sale')->assertOk()->getContent();
    $top = sp3Block($html);

    expect($top)->not->toBe('')
        ->and($top)->toContain('<div class="kbb-pt" style="--pt-td:0px;--pt-gd:0px;--pt-bd:22px;--pt-tm:0px;--pt-gm:0px;--pt-bm:12px" data-kbb-pt="collection:super-sale">')
        ->and($top)->toContain('<div class="sec kbb-pt-s"><div class="wrap">')
        ->and($top)->toContain('data-kbb-ph="collection:super-sale"')
        ->and(substr_count($html, 'data-kbb-ph='))->toBe(1)
        ->and(strpos($html, '<div class="kbb-pt"'))->toBeLessThan(strpos($html, '<section class="sec">'));

    foreach ([
        '.kbb-home .kbb-pt{display:flow-root;padding:var(--pt-tm) 0 var(--pt-bm)}',
        '.kbb-home .kbb-pt>.kbb-pb,.kbb-home .kbb-pt>.kbb-pt-s{margin:0}',
        '.kbb-home .sec.kbb-pt-s{padding:0}.kbb-home .sec.kbb-pt-s>.wrap{padding-top:0;padding-bottom:0}',
        '.kbb-home .kbb-pt div.kbb-ph{margin:0}',
        '.kbb-home .kbb-pt+.sec{padding-top:0}.kbb-home .kbb-pt+.sec>.wrap{padding-top:0}',
        '@media (min-width:901px){.kbb-home .kbb-pt{padding:var(--pt-td) 0 var(--pt-bd)}',
    ] as $rule) {
        expect(PageHeaders::TOP_CSS)->toContain($rule);
    }
    expect(PageHeaders::TOP_CSS)->not->toContain('<');
});

it('leaves a page that draws neither a configured header nor a strip on its original markup', function () {
    /*
     * Rule 1: the top area is for pages that have one. /new-in/ with nothing
     * set keeps its breadcrumb and title block inside its section, byte for
     * byte. MUTATION: make top() never return null -> red.
     */
    sp3Products(2);
    $html = $this->get('/new-in')->assertOk()->getContent();

    expect($html)->not->toContain('kbb-pt')
        ->and($html)->toContain("<section class=\"sec\"><div class=\"wrap\">\n    <nav class=\"crumb\">")
        ->and(app(PageHeaders::class)->top('collection:new-in', 'collection'))->toBeNull();
});

it('gives a page whose strip is switched on the top area, with the header it already looked like', function () {
    /*
     * /new-in/ has no header of its own; switched on, its strip and its title
     * block become the two blocks, in the shipped order (strip first).
     * MUTATION: drop the `$banner !== null` force in top() -> no header block,
     * the title disappears from /new-in/, red.
     */
    sp3Products(2);
    sp3StripOn('collection:new-in');
    $top = sp3Block($this->get('/new-in')->assertOk()->getContent());

    expect($top)->toContain('<ul class="kbb-pb-strip">')
        ->and($top)->toContain('<span>100% Authentic Products</span>')
        ->and($top)->toContain('data-kbb-ph="collection:new-in"')
        ->and($top)->toContain('<h1 class="kbb-ph-t">New In ')
        ->and(strpos($top, 'class="kbb-pb"'))->toBeLessThan(strpos($top, 'class="sec kbb-pt-s"'));
});

/* ════════════════════════════════════════════════════════ the order ═══ */

it('draws the two blocks in the order saved, per page or globally', function () {
    /*
     * "give facilty to sort header area and strip. by drag n drop up down."
     * MUTATION: use ['strip', 'header'] in top() whatever the bag says -> red.
     */
    sp3Products(2);
    sp3StripOn();
    $sale = PageHeaders::defaults()['pages']['collection:super-sale'];
    $sale['blocks'] = ['header', 'strip'];
    sp3Store(PageHeaders::KEY, ['global' => PageHeaders::blank(), 'pages' => ['collection:super-sale' => $sale]]);

    $top = sp3Block($this->get('/super-sale')->getContent());
    expect(strpos($top, 'class="sec kbb-pt-s"'))->toBeLessThan(strpos($top, 'class="kbb-pb"'));

    // The global order, for a page with no look of its own.
    $global = PageHeaders::blank();
    $global['blocks'] = ['header', 'strip'];
    sp3Store(PageHeaders::KEY, ['global' => $global, 'pages' => []]);
    $top = sp3Block($this->get('/super-sale')->getContent());
    expect(strpos($top, 'class="sec kbb-pt-s"'))->toBeLessThan(strpos($top, 'class="kbb-pb"'));
});

it('puts the space between the blocks in either order, past the strip\'s own stylesheet', function () {
    /*
     * The strip's partial prints its <style> just before it, so in the
     * header-first order that element stands between the two blocks and an
     * adjacent-sibling `+` never matches: the "between" slider moved and the
     * page did not. MUTATION: write the between rule with `+` -> red.
     */
    expect(PageHeaders::TOP_CSS)->toContain('.kbb-home .kbb-pt>.kbb-pb~.kbb-pt-s,.kbb-home .kbb-pt>.kbb-pt-s~.kbb-pb{margin-top:var(--pt-gm)}')
        ->and(PageHeaders::TOP_CSS)->toContain('.kbb-home .kbb-pt>.kbb-pb~.kbb-pt-s,.kbb-home .kbb-pt>.kbb-pt-s~.kbb-pb{margin-top:var(--pt-gd)}');
});

/* ══════════════════════════════════════════ spacing, clamped, stored ═══ */

it('stores the spacing per device as clamped integers and refuses the rest, writing nothing', function () {
    /*
     * MUTATION: drop 'top' or 'mid' from NUMBERS -> the value is not stored
     * and not printed, red; widen a range -> the out-of-range case saves, red.
     */
    sp3Products(1);
    $this->actingAs(sp3Admin('editor'), 'admin');
    $bag = PageHeaders::defaults()['pages']['collection:super-sale'];
    $bag['d']['top'] = 24;
    $bag['m']['mid'] = 16;
    $bag['m']['space'] = 0;

    $this->postJson('/admin-api/page-header/apply', ['key' => 'collection:super-sale', 'scope' => 'page', 'bag' => $bag])->assertOk();
    SettingsService::forgetMemo();
    expect(sp3Block($this->get('/super-sale')->getContent()))->toContain('style="--pt-td:24px;--pt-gd:0px;--pt-bd:22px;--pt-tm:0px;--pt-gm:16px;--pt-bm:0px"');

    $before = DB::table('settings')->where('key', PageHeaders::KEY)->value('value');
    foreach ([
        'above too big' => ['d', 'top', 81],
        'between negative' => ['m', 'mid', -1],
        'not a number' => ['d', 'mid', '12px;color:red'],
    ] as $name => [$dev, $k, $v]) {
        $bad = $bag;
        $bad[$dev][$k] = $v;
        $this->postJson('/admin-api/page-header/apply', ['key' => 'collection:super-sale', 'scope' => 'page', 'bag' => $bad])->assertStatus(422);
    }
    foreach ([['strip', 'strip'], ['header'], ['header', 'strip', 'strip'], ['header', '<b>'], 'header,strip'] as $blocks) {
        $bad = $bag;
        $bad['blocks'] = $blocks;
        $this->postJson('/admin-api/page-header/apply', ['key' => 'collection:super-sale', 'scope' => 'page', 'bag' => $bad])->assertStatus(422);
    }
    expect(DB::table('settings')->where('key', PageHeaders::KEY)->value('value'))->toBe($before);

    // "Space above the header" beside "Space below the header", desktop and
    // phone apart, 0 by default, as he asked.
    expect(PageHeaders::NUMBERS['top'])->toBe([0, 80, 0, 0, 'Space above the header (px)'])
        ->and(PageHeaders::NUMBERS['mid'])->toBe([0, 80, 0, 0, 'Space between the header and the strip (px)'])
        ->and(PageHeaders::NUMBERS['space'])->toBe([0, 80, 22, 12, 'Space below the header (px)'])
        ->and(PageHeaders::SPACING)->toBe(['top', 'space', 'mid']);
});

it('does not move a page into the top area for a spacing number alone', function () {
    /*
     * The space above and between belong to a page that HAS a first block. A
     * global "space above" of 30 must not pull every untouched custom page
     * out of its original markup. MUTATION: stop unsetting top/mid in
     * project() -> /new-in/ gains a top area, red.
     */
    sp3Products(1);
    $global = PageHeaders::blank();
    $global['d']['top'] = 30;
    $global['m']['mid'] = 10;
    sp3Store(PageHeaders::KEY, ['global' => $global, 'pages' => []]);

    expect($this->get('/new-in')->getContent())->not->toContain('kbb-pt');
});

it('builds the spacing exactly as the PHP does in the browser panel', function () {
    /*
     * The panel previews with page-header-compile.js topStyle(); the shop
     * prints PageHeaders::topStyle(). MUTATION: swap two properties in either
     * -> red.
     */
    $node = trim((string) shell_exec('command -v node 2>/dev/null')) ?: (is_file('/opt/node22/bin/node') ? '/opt/node22/bin/node' : '');
    if ($node === '') {
        $this->markTestSkipped('node is not installed');
    }
    $a = PageHeaders::blank();
    $b = PageHeaders::blank();
    $b['d'] = array_replace($b['d'], ['top' => 80, 'mid' => 3, 'space' => 0]);
    $b['m'] = array_replace($b['m'], ['top' => 7, 'mid' => 80, 'space' => 41]);

    $module = 'file://'.resource_path('js/kbb/admin/page-header-compile.js');
    $script = "import {topStyle} from '{$module}'; const c=JSON.parse(process.argv[1]);"
        .'process.stdout.write(JSON.stringify(c.map(topStyle)));';
    $out = json_decode((string) shell_exec(escapeshellarg($node).' --input-type=module -e '.escapeshellarg($script).' '.escapeshellarg(json_encode([$a, $b]))), true);

    expect($out)->toBe([PageHeaders::topStyle($a), PageHeaders::topStyle($b)])
        ->and($out[1])->toBe('--pt-td:80px;--pt-gd:3px;--pt-bd:0px;--pt-tm:7px;--pt-gm:80px;--pt-bm:41px');
});

/* ═════════════════════════════════════ the strip switch in the panel ═══ */

it('switches one page\'s strip on and off from the panel, and touches no other page or banner', function () {
    /*
     * "allow to turn ON on any page from the edit panel on the front-end".
     * MUTATION: ignore `strip` in PageHeaderApiController::apply() -> the
     * page stays without its strip, red.
     */
    sp3Products(1);
    sp3Store(PageBanners::KEY, ['banners' => [PageBanners::blank('super-sale', 'Super Sale'), PageBanners::blank('promo', 'Promo')], 'assign' => ['collection:best-sellers' => 'promo']]);
    $this->actingAs(sp3Admin('editor'), 'admin');

    $this->postJson('/admin-api/page-header/apply', ['key' => 'collection:new-in', 'scope' => 'page', 'bag' => PageHeaders::blank(), 'strip' => 'super-sale'])
        ->assertOk()->assertJsonPath('strip', 'super-sale');
    SettingsService::forgetMemo();
    app(PageBanners::class)->forget();
    expect(sp3Block($this->get('/new-in')->getContent()))->toContain('<ul class="kbb-pb-strip">')
        ->and(app(PageBanners::class)->all()['assign'])->toBe(['collection:best-sellers' => 'promo', 'collection:new-in' => 'super-sale']);

    $this->postJson('/admin-api/page-header/apply', ['key' => 'collection:new-in', 'scope' => 'page', 'bag' => PageHeaders::blank(), 'strip' => ''])
        ->assertOk()->assertJsonPath('strip', '');
    SettingsService::forgetMemo();
    app(PageBanners::class)->forget();
    expect(app(PageBanners::class)->all()['assign'])->toBe(['collection:best-sellers' => 'promo'])
        ->and(array_column(app(PageBanners::class)->all()['banners'], 'id'))->toBe(['super-sale', 'promo']);

    // Absent: the strip is not touched at all.
    $this->postJson('/admin-api/page-header/apply', ['key' => 'collection:best-sellers', 'scope' => 'page', 'bag' => PageHeaders::blank()])
        ->assertOk()->assertJsonPath('strip', 'promo');
});

it('refuses the strip to a role without the banners\' capability, and saves nothing, header included', function () {
    /*
     * The panel's route is pageheader.manage; the strip writes Pages → Page
     * banners, which is pagebanners.manage. An editor with that one revoked
     * must not switch it — and the header in the same Save must not land
     * half-way. MUTATION: delete the AdminRoles::can() check -> 200, red.
     */
    sp3Products(1);
    $this->actingAs(sp3Admin('editor', ['pagebanners.manage']), 'admin');
    $bag = PageHeaders::blank();
    $bag['d']['top'] = 40;

    $this->postJson('/admin-api/page-header/apply', ['key' => 'collection:new-in', 'scope' => 'page', 'bag' => $bag, 'strip' => 'super-sale'])
        ->assertStatus(403);
    $this->postJson('/admin-api/page-header/apply', ['key' => 'collection:new-in', 'scope' => 'page', 'bag' => $bag, 'strip' => ''])
        ->assertStatus(403);

    expect(DB::table('settings')->whereIn('key', [PageHeaders::KEY, PageBanners::KEY])->count())->toBe(0);

    // Without the strip in the request, the same account saves the header.
    $this->postJson('/admin-api/page-header/apply', ['key' => 'collection:new-in', 'scope' => 'page', 'bag' => $bag])->assertOk();
});

it('refuses a strip that is not in the library, before writing the header', function () {
    /* MUTATION: check the banner id after apply() -> the header is written, red. */
    $this->actingAs(sp3Admin('owner'), 'admin');
    $bag = PageHeaders::blank();
    $bag['d']['top'] = 40;

    $this->postJson('/admin-api/page-header/apply', ['key' => 'collection:new-in', 'scope' => 'page', 'bag' => $bag, 'strip' => 'nope'])
        ->assertStatus(422);
    $this->postJson('/admin-api/page-header/apply', ['key' => 'collection:new-in', 'scope' => 'page', 'bag' => $bag, 'strip' => '"><script>'])
        ->assertStatus(422);

    expect(DB::table('settings')->whereIn('key', [PageHeaders::KEY, PageBanners::KEY])->count())->toBe(0);
});

it('hands the panel the strip it can preview with no request, for a role that holds it', function () {
    /*
     * MUTATION: drop 'strip' from editorContext() -> the panel has no Strip
     * section, red; set 'can' true regardless -> the revoked editor case is red.
     */
    StorefrontAdminRoutes::wire($this->app);
    $this->actingAs(sp3Admin('editor'), 'admin');
    $strip = $this->getJson('/admin-api/storefront/context?path='.rawurlencode('/super-sale/'))->assertOk()->json('pageheader.strip');

    expect($strip['can'])->toBeTrue()
        ->and($strip['on'])->toBe('')
        ->and($strip['banners'][0]['id'])->toBe('super-sale')
        ->and($strip['banners'][0]['view']['items'])->toBe(['100% Authentic Products', 'Express Delivery all over UAE', 'Free skincare consultation'])
        ->and($strip['css'])->toBe(PageBanners::CSS);

    $this->actingAs(sp3Admin('editor', ['pagebanners.manage']), 'admin');
    expect($this->getJson('/admin-api/storefront/context?path=/super-sale/')->json('pageheader.strip.can'))->toBeFalse();
});

/* ══════════════════════════════════════════ the panel's drag and drop ═══ */

it('orders the two blocks by drag and drop with a keyboard and touch fallback, measuring nothing', function () {
    /*
     * The mouse uses the browser's drag and drop; a finger uses pointer
     * events from the grip; the keyboard uses ↑ ↓. A drag swaps rows when it
     * ENTERS the other one, so nothing is measured. Two defects caught while
     * building it, both pinned here:
     *  - Chromium fires pointercancel the moment a native drag starts; ending
     *    the drag on any pointercancel threw the mouse drag away before it
     *    reached the other row (the rows never moved). MUTATION: end on
     *    pointercancel whatever the mode -> the `end('touch')` pin is red.
     *  - a touch is captured to the grip AFTER pointerdown runs, so releasing
     *    it inside pointerdown did nothing and the other row never heard
     *    pointerenter. MUTATION: drop the gotpointercapture release -> red.
     */
    $js = (string) file_get_contents(resource_path('js/kbb/admin/page-header-editor.js'));

    foreach (["'dragstart'", "'dragenter'", "'dragover'", "'dragend'", "draggable: 'true'", "'pointerenter'", "'gotpointercapture'",
        "list.addEventListener('pointercancel', () => end('touch'))", "li.addEventListener('dragend', () => end('mouse'))",
        "'aria-label': `Move \${label(k)} up`", 'kbb-phe-blocks', 'kbb-phe-space', 'kbb-phe-strip', 'topStyle(bag)'] as $needle) {
        expect(str_contains($js, $needle))->toBeTrue($needle);
    }

    foreach ([resource_path('js/kbb/admin/page-header-editor.js'), resource_path('js/kbb/admin/page-header-compile.js'), resource_path('views/partials/page-top.blade.php')] as $file) {
        $src = (string) file_get_contents($file);
        foreach (['getBoundingClientRect', 'offsetWidth', 'offsetHeight', 'offsetTop', 'clientWidth', 'clientHeight', 'scrollWidth', 'elementFromPoint', 'ResizeObserver', 'setInterval', 'innerHTML ='] as $api) {
            expect(str_contains($src, $api))->toBeFalse(basename($file).' uses '.$api);
        }
    }
});

/* ═══════════════════════════════════════════════════════ rule 4 ═══ */

it('costs the page no query at all, strip on and header first, with 3 products or 40', function () {
    /*
     * Both settings are read from the map the page already loaded. MUTATION:
     * look the banner or the header up with a query of its own in top() ->
     * on is dearer than off, red.
     */
    $measure = function (int $n, bool $on): int {
        Product::query()->delete();
        sp3Products($n, "q{$n}".($on ? 'on' : 'off'));
        if ($on) {
            sp3StripOn();
            $sale = PageHeaders::defaults()['pages']['collection:super-sale'];
            $sale['blocks'] = ['header', 'strip'];
            sp3Store(PageHeaders::KEY, ['global' => PageHeaders::blank(), 'pages' => ['collection:super-sale' => $sale]]);
        } else {
            sp3Store(PageBanners::KEY, ['banners' => [], 'assign' => []]);
            sp3Store(PageHeaders::KEY, ['global' => PageHeaders::blank(), 'pages' => []]);
        }
        $this->get('/super-sale');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $html = $this->get('/super-sale')->getContent();
        $q = count(DB::getQueryLog());
        DB::disableQueryLog();

        expect(str_contains($html, 'class="kbb-pb-strip"'))->toBe($on);

        return $q;
    };

    $off3 = $measure(3, false);
    $on3 = $measure(3, true);
    $on40 = $measure(40, true);

    expect($on3)->toBe($off3)->and($on40)->toBe($on3);
});

it('escapes the top area\'s attributes and prints only the constant raw', function () {
    /* MUTATION: print the style with {!! !!} in page-top.blade.php -> red. */
    $blade = (string) file_get_contents(resource_path('views/partials/page-top.blade.php'));

    expect(preg_match_all('/\{!!\s*(.+?)\s*!!\}/', $blade, $m))->toBe(1)
        ->and($m[1][0])->toBe('\App\Services\PageHeaders::TOP_CSS')
        ->and($blade)->toContain('style="{{ $pageTop[\'style\'] }}"')
        ->and($blade)->toContain('data-kbb-pt="{{ $pageTop[\'key\'] }}"');
});
