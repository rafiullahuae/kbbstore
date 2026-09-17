<?php

/**
 * The Mega Menu switch, pinned end to end — Lane EM.
 *
 * ── WHAT THIS IS FOR ────────────────────────────────────────────────────────
 *
 * `mega_menu` was marked `live` in ModuleRegistry — which that file defines as
 * "something on the storefront reads moduleEnabled() for this key" — while the
 * only code anywhere that read it was `'module_on' => ...moduleEnabled(
 * 'mega_menu', false)` in MegaMenuApiController, a flag the admin screen prints
 * about itself. The header rendered its dropdown panels and the phone menu its
 * expandable sections whatever the switch said. Same defect `seo_engine` and
 * `product_sorting` both carried, found by ModuleFrameworkGuardTest.
 *
 * That guard proves a storefront file MENTIONS the key. It cannot prove the
 * gate WORKS, and a gate that reads the switch and then renders the panel
 * anyway would satisfy it. So this asks the pages.
 *
 * ── ASSERTED ON ELEMENTS, NOT ON SUBSTRINGS ─────────────────────────────────
 *
 * Every assertion below counts MATCHED ELEMENTS with preg_match_all rather than
 * str_contains()ing a class name. The storefront ships its CSS inline, so
 * `.drop` and `.mm-node` appear in a stylesheet block on every page whether or
 * not a single one is rendered, and a substring search would pass with the menu
 * entirely gone. Counting `<div class="drop...` finds the markup only.
 *
 * ── WHAT "OFF" MEANS HERE, AND THE ONE DECISION NOT TAKEN ───────────────────
 *
 * Off removes the dropdown panels and the phone menu's expandable sections. It
 * does NOT remove the top-level links on either surface, and it does not remove
 * the phone sheet itself: `.mbar` is display:none under 1000px, so that sheet
 * is the only navigation a phone has, and hiding it would leave a #burger that
 * opens nothing. The alternative readings — "off falls back to plain simple
 * dropdowns", and "off removes the phone sheet outright" — are the owner's to
 * choose and are recorded in the hand-back, not decided here.
 */

use App\Services\SettingsService;

function mmsGate(bool $on): void
{
    app(SettingsService::class)->setModule('mega_menu', $on);
}

/** Count of real elements carrying this class, ignoring any inlined CSS. */
function mmsElements(string $html, string $class): int
{
    return preg_match_all('/<[a-z][a-z0-9]*\s[^>]*class="[^"]*\b' . preg_quote($class, '/') . '\b/i', $html);
}

function mmsHome(): string
{
    return (string) test()->get('/')->assertOk()->getContent();
}

/**
 * The store's own primary menu is what these assertions run against.
 *
 * NavigationService::menu() falls back to the published kbeautybliss.com menu
 * when no Menu row is marked primary, and that fallback is the real thing:
 * category parents with children, which is exactly the shape the gate acts on.
 * Building a synthetic menu here would prove the gate works on a fixture and
 * leave the shipped menu untested, so only the five-minute nav cache is
 * cleared.
 */
beforeEach(function () {
    \Illuminate\Support\Facades\Cache::forget('kbb.nav.primary');
    \Illuminate\Support\Facades\Cache::forget('kbb.nav.mobile');
    \Illuminate\Support\Facades\Cache::forget('kbb.modules');
});

/*
|------------------------------------------------------------------------------
| The desktop bar
|------------------------------------------------------------------------------
*/

it('renders the dropdown panels and their carets when the module is on', function () {
    mmsGate(true);

    $html = mmsHome();

    expect(mmsElements($html, 'drop'))->toBeGreaterThan(0);
    expect(mmsElements($html, 'mega'))->toBeGreaterThan(0);
    expect(mmsElements($html, 'ind'))->toBeGreaterThan(0);
});

it('removes the dropdown panels AND the carets when the module is off', function () {
    /*
     * BOTH, and this is the assertion the fix exists for. The `@if` on the
     * caret and the `@if` on the panel are separate statements around separate
     * markup: gating only the panel leaves a "▾" pointing at nothing, gating
     * only the caret leaves a panel that still drops on hover. A test that
     * checked one would pass with the other still broken.
     */
    mmsGate(false);

    $html = mmsHome();

    expect(mmsElements($html, 'drop'))->toBe(0);
    expect(mmsElements($html, 'mega'))->toBe(0);
    expect(mmsElements($html, 'ind'))->toBe(0);
});

it('keeps every top-level link in the bar when the module is off', function () {
    /*
     * Off costs the shopper the dropdowns and NOTHING ELSE. The bar itself, and
     * a link to every parent's own URL, survive — including the parents whose
     * children have just been hidden, which is the only way those sections stay
     * reachable at all.
     */
    mmsGate(true);
    $on = mmsHome();

    mmsGate(false);
    $off = mmsHome();

    expect(mmsElements($off, 'mbar'))->toBeGreaterThan(0);

    // The same number of top-level rows either way: the gate takes panels, not
    // menu entries.
    expect(mmsElements($off, 'navitem'))->toBe(mmsElements($on, 'navitem'));
    expect(mmsElements($off, 'navitem'))->toBeGreaterThan(0);
    expect(mmsElements($off, 'navlink'))->toBe(mmsElements($on, 'navlink'));
});

/*
|------------------------------------------------------------------------------
| The phone menu
|------------------------------------------------------------------------------
*/

it('renders the phone menu’s expandable sections when the module is on', function () {
    mmsGate(true);

    $html = mmsHome();

    expect(mmsElements($html, 'mm-node'))->toBeGreaterThan(0);
    expect(mmsElements($html, 'mm-par'))->toBeGreaterThan(0);
});

it('flattens the phone menu’s expandable sections when the module is off', function () {
    mmsGate(false);

    $html = mmsHome();

    expect(mmsElements($html, 'mm-node'))->toBe(0);
    expect(mmsElements($html, 'mm-par'))->toBe(0);
    expect(mmsElements($html, 'mm-kid'))->toBe(0);
});

it('keeps the phone sheet itself, and its rows, when the module is off', function () {
    /*
     * THE LINE THIS LANE DECLINED TO CROSS.
     *
     * `.mbar` is display:none under 1000px (resources/css/kbb/kbb.css), so the
     * #mmenu sheet is a phone's ONLY navigation, and it also carries the menu
     * search and the account links, which are configured on a different screen
     * entirely (Appearance → Mobile menu). Hiding the sheet with this switch
     * would leave a phone with no navigation and a #burger that opens an empty
     * panel.
     *
     * So every parent becomes a plain link — reachable, just not expandable —
     * and the sheet, its search and its account block are untouched. If the
     * owner decides "off" should take the whole sheet, this test is the one
     * that has to change, deliberately.
     */
    mmsGate(false);

    $html = mmsHome();

    expect(mmsElements($html, 'mmenu'))->toBeGreaterThan(0);
    expect(mmsElements($html, 'mm-body'))->toBeGreaterThan(0);
    // The rows are all still there, as leaves rather than expanders.
    expect(mmsElements($html, 'mm-it'))->toBeGreaterThan(0);
});

/*
|------------------------------------------------------------------------------
| The default, and the migration that makes it safe to apply
|------------------------------------------------------------------------------
*/

it('defaults to on, because the panels rendered before the switch was real', function () {
    /*
     * With no stored row at all the registry default decides, and it has to be
     * `true`: until 2.60.199 nothing read this key and the panels rendered
     * regardless, so `false` would not be a default, it would be an outage the
     * moment the package applied. Same reasoning `brands` and `seo_engine`
     * used, and 2026_11_10_000000 carries the matching alignment.
     */
    \App\Models\ModuleToggle::query()->where('module', 'mega_menu')->delete();
    \Illuminate\Support\Facades\Cache::forget('kbb.modules');

    expect(app(SettingsService::class)->moduleEnabled('mega_menu', true))->toBeTrue();
    expect(\App\Services\ModuleRegistry::REGISTRY['mega_menu'][3])->toBeTrue();

    expect(mmsElements(mmsHome(), 'drop'))->toBeGreaterThan(0);
});

it('aligns a store that was seeded off while nothing read the switch', function () {
    \App\Models\ModuleToggle::updateOrCreate(['module' => 'mega_menu'], ['enabled' => false]);
    \Illuminate\Support\Facades\Cache::forget('kbb.modules');

    require_once base_path('database/migrations/2026_11_10_000000_align_mega_menu_module_toggle.php');

    $migration = require base_path('database/migrations/2026_11_10_000000_align_mega_menu_module_toggle.php');
    $migration->up();

    expect(app(SettingsService::class)->moduleEnabled('mega_menu', false))->toBeTrue();
});
