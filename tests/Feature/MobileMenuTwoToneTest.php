<?php

declare(strict_types=1);

/**
 * =============================================================================
 * THE PHONE MENU IN V4 "TWO-TONE", WITH EVERY SIZE, ITS QUICK LINKS AND ITS
 * OPENING ANIMATION UNDER THE OWNER'S CONTROL — AND CLASSIC UNDOES ALL OF IT
 * =============================================================================
 *
 * The owner, 6 October, after docs/mv-preview: "V4 is fine. please proceed, but
 * give full control of font sizes, row height, paddings, upper custom links,
 * panel size, on click sub menu opening animation etc. please must be super
 * light weight, fully optimized, and must be adjust auto as per the user mobile
 * screen size."
 *
 * Appearance → Mobile menu:
 *   Style              → Menu style (V4 two-tone | Classic glass 2.60.413),
 *                        Quick links row, Super Sale highlight
 *   Quick links        → up to 8: label, Arabic label, link, colour, on/off, order
 *   Sizes              → panel width (%, at least, at most), band padding,
 *                        search, quick links, rows, sub-items, gap, headings, arrow
 *   Sub-menu animation → Slide down / Fade / Expand / None, duration, easing
 *
 * What a red case here looked like on the shop is said in each case.
 */

use App\Models\AdminUser;
use App\Services\MobileMenu;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

function m4Save(array $values): void
{
    app(MobileMenu::class)->save($values);
    Cache::flush();
    SettingsService::forgetMemo();
}

function m4Home(string $path = '/'): string
{
    $html = test()->get($path)->assertOk()->getContent();

    return (string) preg_replace('/(name="_token" value="|name="csrf-token" content=")[^"]+/', '$1X', $html);
}

/** The whole <nav class="mmenu">…</nav> element. */
function m4Nav(string $html): string
{
    expect(preg_match('#<nav class="mmenu.*?</nav>#s', $html, $m))->toBe(1, 'the phone menu is not on the page');

    return $m[0];
}

function m4Css(): string
{
    $css = (string) file_get_contents(resource_path('css/kbb/kbb.css'));
    $start = strpos($css, 'MOBILE MENU · V4 TWO-TONE (Lane M4');
    expect($start)->not->toBeFalse('the V4 block is gone from kbb.css');
    $start = strrpos(substr($css, 0, $start), '/*');   // from its own comment, so comments strip cleanly
    $end = strrpos(substr($css, 0, strpos($css, 'HEADER — driven by Appearance → Header', $start)), '/*');

    return (string) preg_replace('#/\*.*?\*/#s', '', substr($css, $start, $end - $start));
}

function m4Owner(): void
{
    $owner = AdminUser::create([
        'name' => 'Menu Owner', 'email' => 'menu-owner@example.com',
        'password' => Hash::make('secret-secret'), 'role' => 'owner',
    ]);
    test()->actingAs($owner, 'admin');
}

/** The 25 keys Lane M4 added, in SCHEMA order. */
function m4Keys(): array
{
    $keys = array_keys(MobileMenu::SCHEMA);

    return array_slice($keys, array_search('menu_style', $keys, true));
}

/* ───────────────────────────── V4 is the default ───────────────────────────── */

it('ships V4 two-tone with its five quick links pointing at routes the shop really has', function () {
    // Mutation: default 'classic' for menu_style -> red: the shop keeps the old
    // panel and no quick links at all.
    $nav = m4Nav(m4Home());

    expect(MobileMenu::SCHEMA['menu_style'][2])->toBe('v4')
        // ▲ Lane QK12: `mm-nohl mm-flash` follow -- Super Sale highlight off and
        // its flash icon on, as the owner asked on 9 October.
        ->and($nav)->toContain('class="mmenu mm-card-cream mm-rule-children mm-left mm-v4 mm-an-sl mm-nohl mm-flash"');

    preg_match_all('#<a class="mm-chip( a-[a-z]+)?" href="([^"]+)">([^<]+)</a>#', $nav, $m);
    expect($m[3])->toBe(['Super Sale', 'New In', 'Best Sellers', 'Under 54 AED', 'Brands A–Z'])
        ->and($m[2])->toBe(['/super-sale/', '/new-in/', '/best-sellers/', '/everything-under-54-aed/', '/brands/'])
        ->and(trim($m[1][0]))->toBe('a-sale');

    // Every default answers on the shop, not a 404 (Lane MV had guessed
    // /shop/?orderby=…). Mutation: point New In at /new-arrivals/ -> red.
    foreach ($m[2] as $path) {
        test()->get($path)->assertOk();
    }

    // Under the search field and above the list, once.
    expect(substr_count($nav, '<div class="mm-chips">'))->toBe(1)
        ->and(strpos($nav, 'mm-chips'))->toBeGreaterThan(strpos($nav, 'id="mmFilter"'))
        ->and(strpos($nav, 'mm-chips'))->toBeLessThan(strpos($nav, 'id="mmBody"'));
});

it('prints no size variable at the defaults, so the panel attribute is what it was', function () {
    // Mutation: print every --m-* variable whatever its value -> red; each page
    // would carry ~400 bytes of numbers the stylesheet already holds.
    $style = app(MobileMenu::class)->cssVariables();

    expect($style)->toBe('--mm-top:20%;--mm-radius:20px;--mm-speed:380ms;--mm-scrim:rgba(42,34,40,0.5);--mm-rule:#E0567B;--mm-rule-w:2px;--mm-card:#FFF8F5;--mm-parent-bg:#FFF3F6;--mm-parent-fg:#C13E63;--mm-pad:8px;--mm-size:13px;--mm-cols:1fr 1fr')
        ->and($style)->not->toContain('--m-');
});

it('prints only the sizes moved off their defaults, one custom property each', function () {
    m4Save(['row_h' => 52, 'chip_fs' => 14, 'sub_ms' => 300, 'sub_ease' => 'spring', 'chip_radius' => 10]);

    $vars = explode(';', app(MobileMenu::class)->cssVariables());
    $mine = array_values(array_filter($vars, fn ($v) => str_starts_with($v, '--m-')));

    // Mutation: compare against the wrong default in twoToneVariables() -> red.
    expect($mine)->toBe(['--m-cf:14', '--m-rh:52', '--m-cr:10px', '--m-sd:300ms', '--m-se:cubic-bezier(.34,1.56,.64,1)']);

    // No inline style on any row: the sizes reach the rows through the panel.
    $nav = m4Nav(m4Home());
    expect(preg_match('#class="mm-(it|si|chip)[^"]*"[^>]*style="[^"]*--#', $nav))->toBe(0);
});

it('writes the panel width as one clamp, and never lets "at least" pass "at most"', function () {
    m4Save(['panel_w_pct' => 92]);
    expect(app(MobileMenu::class)->cssVariables())->toContain('--m-w:min(100vw,clamp(240px,92vw,420px))');

    // Mutation: drop the min() in twoToneVariables() -> red: at least 360 over
    // at most 300 would make clamp() pick 360 and the panel ignore its cap.
    m4Save(['panel_w_min' => 360, 'panel_w_max' => 300]);
    expect(app(MobileMenu::class)->cssVariables())->toContain('--m-w:min(100vw,clamp(300px,88vw,300px))');
});

/* ─────────────────────────── Classic is 2.60.413 ──────────────────────────── */

it('Classic puts back the 2.60.413 panel byte for byte, whatever the V4 controls say', function () {
    $v4 = m4Nav(m4Home());

    // Moved V4 controls must not leak into Classic.
    m4Save(['menu_style' => 'classic', 'row_h' => 60, 'sub_anim' => 'fade', 'sale_fill' => false, 'chip_fs' => 15]);
    $classic = m4Nav(m4Home());

    // Mutation: drop the `'v4' !==` guard in twoToneVariables() or
    // chipsForRender() -> red.
    expect($classic)->toContain('<nav class="mmenu mm-card-cream mm-rule-children mm-left" id="mmenu" style="--mm-top:20%;--mm-radius:20px;--mm-speed:380ms;--mm-scrim:rgba(42,34,40,0.5);--mm-rule:#E0567B;--mm-rule-w:2px;--mm-card:#FFF8F5;--mm-parent-bg:#FFF3F6;--mm-parent-fg:#C13E63;--mm-pad:8px;--mm-size:13px;--mm-cols:1fr 1fr"')
        ->and($classic)->not->toContain('mm-chip')
        ->and($classic)->not->toContain('mm-v4')
        ->and($classic)->not->toContain('--m-');

    // And the rest of the menu is exactly V4's: the only differences are the
    // two classes and the quick-link row.
    // ▲ Lane QK12: V4 also carries `mm-nohl mm-flash` and the Super Sale row's
    // flash icon, which Classic does not print (Qk12HeaderAndMenuTest).
    $v4 = str_replace('<svg class="mm-fl" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M13.2 2 4.6 13.4h6.1L9.8 22l8.6-11.6h-6.1z"/></svg>', '', $v4);
    $stripped = (string) preg_replace('#    <div class="mm-chips">.*?</div></div>\n#', '', str_replace(' mm-v4 mm-an-sl mm-nohl mm-flash"', '"', $v4));
    expect($stripped)->toBe($classic);
});

/* ─────────────────────────────── Quick links ──────────────────────────────── */

it('renders the saved quick links in order, the Arabic label on an Arabic page', function () {
    m4Save(['chips' => [
        ['label' => 'Sunscreens', 'label_ar' => 'واقيات الشمس', 'url' => '/collections/sunscreens/', 'accent' => 'gold', 'on' => true],
        ['label' => 'Hidden', 'label_ar' => '', 'url' => '/new-in/', 'accent' => '', 'on' => false],
        ['label' => 'Gift <b>cards</b>', 'label_ar' => '', 'url' => 'https://example.com/gift', 'accent' => 'nope', 'on' => true],
    ]]);

    $nav = m4Nav(m4Home());
    expect($nav)->toContain('<a class="mm-chip a-gold" href="/collections/sunscreens/">Sunscreens</a><a class="mm-chip" href="https://example.com/gift">Gift cards</a>')
        ->and($nav)->not->toContain('Hidden');

    // Mutation: always print `label` in chipsForRender() -> red here.
    \App\Models\Setting::query()->updateOrCreate(['key' => \App\Support\Locale::SETTING_ENABLED], ['value' => '1']);
    SettingsService::forgetMemo();
    Cache::flush();
    $ar = m4Nav(m4Home('/ar/'));
    expect($ar)->toContain('href="/ar/collections/sunscreens/">واقيات الشمس</a>')
        // a blank Arabic label falls back to the English one rather than vanishing
        ->and($ar)->toContain('>Gift cards</a>');
});

it('keeps the stored quick links when a save does not carry them', function () {
    m4Save(['chips' => [['label' => 'Only', 'url' => '/new-in/']]]);
    m4Save(['row_h' => 50]);

    // Mutation: drop the elseif in save() -> red: moving a slider wiped the links.
    expect(array_column(app(MobileMenu::class)->chips(), 'label'))->toBe(['Only']);
});

it('refuses every link that is not a path on this shop or an https address', function (string $bad) {
    m4Owner();

    $chips = MobileMenu::CHIPS_DEFAULT;
    $chips[1]['url'] = $bad;

    // Mutation: return true from the url check in cleanChips() -> red: the
    // shop would print `javascript:` into an href in the phone menu.
    test()->postJson('/admin-api/mobile-menu', ['settings' => ['chips' => $chips]])
        ->assertStatus(422)
        ->assertJsonPath('ok', false)
        ->assertJson(fn ($j) => $j->where('error', fn ($e) => str_contains($e, 'Quick link 2 (New In)'))->etc());

    // And a bad one that reached storage some other way is dropped on read.
    expect(array_column(MobileMenu::cleanChips($chips), 'label'))->not->toContain('New In');
})->with([
    'javascript' => 'javascript:alert(1)',
    'data' => 'data:text/html,<script>alert(1)</script>',
    'plain http' => 'http://example.com/',
    'another host' => '//evil.example/',
    'backslash' => '/\\evil.example',
    'entity disguise' => 'java&#x09;script:alert(1)',
    'empty' => '',
]);

it('accepts a path or an https link, and refuses a ninth link and an empty label', function () {
    m4Owner();

    $ok = [['label' => 'A', 'url' => '/brands/'], ['label' => 'B', 'url' => 'https://example.com/x']];
    test()->postJson('/admin-api/mobile-menu', ['settings' => ['chips' => $ok]])->assertOk()
        ->assertJsonPath('chips.1.url', 'https://example.com/x');

    $nine = array_fill(0, 9, ['label' => 'X', 'url' => '/']);
    test()->postJson('/admin-api/mobile-menu', ['settings' => ['chips' => $nine]])->assertStatus(422);
    expect(MobileMenu::cleanChips($nine))->toHaveCount(MobileMenu::CHIP_MAX);

    test()->postJson('/admin-api/mobile-menu', ['settings' => ['chips' => [['label' => '  ', 'url' => '/']]]])
        ->assertStatus(422);

    // The screen gets the list, its defaults and its palette beside `fields`.
    test()->getJson('/admin-api/mobile-menu')->assertOk()
        ->assertJsonPath('chips.max', 8)
        ->assertJsonPath('chips.default.0.url', '/super-sale/')
        ->assertJsonPath('chips.accents.sale', 'Sale red');
});

it('escapes a quick link label and stores only its plain text', function () {
    m4Save(['chips' => [['label' => '"><img src=x onerror=alert(1)>Sale', 'url' => '/super-sale/']]]);

    $nav = m4Nav(m4Home());
    expect($nav)->not->toContain('<img')
        ->and(app(MobileMenu::class)->chips()[0]['label'])->toBe('">Sale');
});

/* ───────────────────────── Every control validates ────────────────────────── */

it('clamps every range and keeps every select to its own options', function () {
    $mm = app(MobileMenu::class);

    // Mutation: drop 'clamp' => true from MobileMenu::POLICY -> red on 99999.
    foreach (m4Keys() as $key) {
        $def = MobileMenu::SCHEMA[$key];

        if ($def[0] === 'range') {
            expect($mm->cast($key, 99999))->toBe($def[4]['max'], "{$key} over its max")
                ->and($mm->cast($key, -10))->toBe($def[4]['min'], "{$key} under its min");
        }

        if ($def[0] === 'select') {
            expect($mm->cast($key, 'nope'))->toBe($def[2], "{$key} stored a value it does not offer");

            foreach (array_keys($def[4]) as $option) {
                expect($mm->cast($key, $option))->toBe($option);
            }
        }
    }

    // Tap targets: the row and search sliders cannot go under 44px.
    expect(MobileMenu::SCHEMA['row_h'][4]['min'])->toBe(44)
        ->and(MobileMenu::SCHEMA['search_h'][4]['min'])->toBe(44);
});

/* ───────────────────── Auto-adjust, tap floor, animation ──────────────────── */

it('scales every size with the screen in CSS and never drops a row or a quick link under 44px', function () {
    $css = m4Css();

    // 0.92 at 320, 1 at 390, 1.08 from 430 up — the larger of two lines, capped.
    // Mutation: a fixed `--u:1px` -> red, and the menu stops following the phone.
    expect($css)->toContain('--u:clamp(.92px,max(calc(.5543px + .1143vw),calc(.22px + .2vw)),1.08px)')
        ->and($css)->toContain('.mm-v4 .mm-it{min-height:max(44px,calc(var(--m-rh) * var(--u)))')
        ->and($css)->toContain('font-size:calc(var(--m-rf) * var(--u))')
        ->and($css)->toContain('.mm-chip::after{content:"";position:absolute;inset:min(0px,calc((100% - 44px) / 2)) 0}')
        ->and($css)->toContain('padding:max(2px,calc((44px - var(--ch)) / 2)) 12px')
        ->and($css)->toContain('height:max(44px,calc(var(--m-sh) * var(--u)))');

    // Every size the panel can print has a default here, so the defaults print none.
    foreach (['--m-bp', '--m-sh', '--m-sf', '--m-cf', '--m-ch', '--m-cg', '--m-cr', '--m-rh', '--m-rf', '--m-ry', '--m-rx', '--m-qf', '--m-qh', '--m-cc', '--m-gf', '--m-ic', '--m-sd', '--m-se'] as $var) {
        expect($css)->toContain($var.':');
    }

    // Every rule is V4's own (or a quick-link class only V4 prints), so Classic
    // matches none of them.
    preg_match_all('/([^{}@]+)\{[^{}]*\}/', $css, $m);
    foreach (array_map('trim', $m[1]) as $sel) {
        if ($sel === 'from' || str_starts_with($sel, '--')) {
            continue;
        }
        foreach (explode(',', $sel) as $one) {
            expect(str_contains($one, 'mm-v4') || str_contains($one, 'mm-chip'))->toBeTrue("\"{$one}\" would also style Classic");
        }
    }
});

it('gives each opening animation its own class and CSS, None none, and reduced motion none', function () {
    $classes = [];

    foreach (['slide' => 'mm-an-sl', 'fade' => 'mm-an-fd', 'expand' => 'mm-an-ex', 'none' => null] as $anim => $class) {
        m4Save(['sub_anim' => $anim]);
        $body = app(MobileMenu::class)->bodyClass();
        preg_match_all('/mm-an-\w+/', $body, $m);
        $classes[$anim] = $m[0];

        expect($m[0])->toBe($class === null ? [] : [$class]);
    }

    $css = m4Css();

    // Mutation: delete the fade keyframe rule -> red; Fade would snap like None.
    expect($css)->toContain('.mm-v4.mm-an-sl .mm-node > .mm-kid,.mm-v4.mm-an-ex .mm-node > .mm-kid{display:grid;grid-template-rows:0fr;visibility:hidden;')
        ->and($css)->toContain('.mm-v4.mm-an-sl .mm-node.on > .mm-kid,.mm-v4.mm-an-ex .mm-node.on > .mm-kid{grid-template-rows:1fr;visibility:visible;')
        ->and($css)->toContain('.mm-v4.mm-an-sl .mm-kid > .mm-c2{opacity:0;transform:translateY(-8px);')
        ->and($css)->toContain('.mm-v4.mm-an-fd .mm-node.on > .mm-kid{animation:mm-fd var(--m-sd) var(--m-se)}')
        ->and($css)->toContain('@keyframes mm-fd{from{opacity:0}}')
        ->and($css)->toMatch('/@media \(prefers-reduced-motion:reduce\)\{\s*\.mm-v4 \.mm-kid,\.mm-v4 \.mm-kid > \.mm-c2\{transition:none !important;animation:none !important\}/');
});

it('darkens the Super Sale row for AA, and its switch takes the fill away', function () {
    $css = m4Css();

    // #E23A4E with 12% black is #C73345: 5.27:1 with white (4.24:1 before).
    expect($css)->toContain('.mm-v4 .mm-it[style*="background:"],.mm-v4 .mm-si[style*="background:"]{background-image:linear-gradient(rgba(0,0,0,.12),rgba(0,0,0,.12)) !important}')
        ->and($css)->toContain('.mm-v4.mm-nohl .mm-it[style*="background:"]');

    // ▲ Lane QK12: the fill now ships OFF (the owner: "remove the red color of
    // super sale menu"); on puts the darkened fill back.
    expect(app(MobileMenu::class)->bodyClass())->toContain('mm-nohl');
    m4Save(['sale_fill' => true]);
    expect(app(MobileMenu::class)->bodyClass())->not->toContain('mm-nohl');
});

it('lets the quick-link row scroll sideways instead of starting a swipe-to-close', function () {
    $js = (string) file_get_contents(resource_path('js/kbb/home.js'));

    // Mutation: back to closest('input') -> red: dragging the row sideways
    // dragged the whole panel shut instead of scrolling the links.
    expect($js)->toContain("e.target.closest('input, .mm-chips')");
});

/* ──────────────────────────────── Light ───────────────────────────────────── */

it('costs no query and no settings read, with eight quick links as with none', function () {
    $count = function (): int {
        $n = 0;
        DB::listen(function () use (&$n) { $n++; });
        test()->get('/')->assertOk();

        return $n;
    };

    m4Save(['menu_style' => 'classic']);
    m4Home();
    $classic = $count();

    m4Save(['menu_style' => 'v4', 'chips' => array_fill(0, 8, ['label' => 'X', 'url' => '/new-in/'])]);
    m4Home();
    $v4 = $count();

    // Mutation: store the links under their own settings key -> red: a key the
    // map lacks costs a snapshot query on every page.
    expect($v4)->toBe($classic);
});

/* ────────────────────────────── Admin wiring ──────────────────────────────── */

function m4WiredConsole(): string
{
    $src = (string) file_get_contents(resource_path('views/admin/app.blade.php'));
    $edits = json_decode((string) file_get_contents(base_path('docs/m4-wiring.json')), true, 512, JSON_THROW_ON_ERROR);

    foreach ($edits as $e) {
        if (str_contains($src, $e['replacement'])) {
            continue;   // the integrator has applied it
        }
        expect(substr_count($src, $e['anchor']))->toBe($e['count'], "block {$e['n']}: anchor moved");
        $src = str_replace($e['anchor'], $e['replacement'], $src);
    }

    return $src;
}

it('is included in the console exactly once, after the script that defines paintMobileMenu', function () {
    // Mutation: duplicate the include line in docs/m4-wiring.json's replacement -> red (2).
    $console = m4WiredConsole();
    $line = "@include('admin.partials.mobile-menu-chips-screen')";

    expect(substr_count($console, $line))->toBe(1)
        ->and(strpos($console, $line))->toBeGreaterThan(strpos($console, 'function paintMobileMenu(){'))
        ->and(strpos($console, $line))->toBeGreaterThan(strpos($console, 'function mmPreview(){'));
});

it('wraps the screen once, rides its Save, and asks the server for nothing but the picker list', function () {
    $partial = (string) file_get_contents(resource_path('views/admin/partials/mobile-menu-chips-screen.blade.php'));

    expect($partial)->toContain('if (window.__kbbM4Chips) return;')
        ->and(substr_count($partial, 'fetch('))->toBe(1)
        ->and($partial)->toContain("mega-menu/sources")
        ->and($partial)->not->toContain('setInterval')
        ->and($partial)->not->toContain('getBoundingClientRect')
        ->and($partial)->not->toContain('offsetHeight')
        // the links are one more MM.fields entry, so Save posts them and Reset restores them
        ->and($partial)->toContain("key: 'chips'");
});

it('drops the Brands A–Z quick link while the Brands module is off', function () {
    // Mutation: drop the BrandUrls::matches() arm in chipsForRender() -> red:
    // the phone menu linked to a /brands/ page that answers 404 with the module
    // off (ModulePortsOnOffTest caught it on /shop first).
    app(SettingsService::class)->setModule('brands', false);
    Cache::flush();

    $nav = m4Nav(m4Home());
    expect($nav)->toContain('>Best Sellers</a>')
        ->and($nav)->not->toContain('href="/brands/"');
});
