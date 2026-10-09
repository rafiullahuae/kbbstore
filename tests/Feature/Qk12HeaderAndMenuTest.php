<?php

declare(strict_types=1);

/**
 * Lane QK12, chunk A -- the owner's three asks about the header and the menus,
 * 9 October, each with a phone screenshot:
 *
 *   1. "remove the tick arrow that comes with cart panel when adding product
 *      to cart" -- a round green badge with a white check popped up over the
 *      logo beside the opening panel. Appearance -> Cart panel -> Behaviour ->
 *      "When something is added" now ships at None; the panel still opens.
 *   2. "in mobile menu, remove the red color of super sale menu, add a flash
 *      icon with super sale" -- the row was a full-width crimson fill with
 *      white bold text. The red is the row's own highlight colour (Store &
 *      content -> Mega Menu, #E23A4E), which the DESKTOP bar also paints as its
 *      pill, so the colour stays stored and only the phone sheet stops filling
 *      with it: Appearance -> Mobile menu -> Style -> "Super Sale highlight"
 *      off, and the new "Flash icon beside Super Sale" on.
 *   3. "the mobile menu icon i need simple three lines but beautiful. also
 *      give option on backend to change back anytime." -- Appearance -> Header
 *      -> Menu icon -> Icon: "Three lines" (new, default); "Four squares
 *      (previous)" renders the old icon byte for byte.
 */

use App\Services\CartPanel;
use App\Services\HeaderSettings;
use App\Services\MobileMenu;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Cache;

function qk12aFresh(): void
{
    Cache::flush();
    SettingsService::forgetMemo();
}

function qk12aHome(): string
{
    qk12aFresh();

    return (string) test()->get('/')->assertOk()->getContent();
}

function qk12aMigrate(): void
{
    $migration = require database_path('migrations/2027_10_19_100000_qk12a_header_and_menu.php');
    $migration->up();
    qk12aFresh();
}

/* ─────────────────────────── 1. the green tick ─────────────────────────── */

it('ships the add-to-cart feedback at None, so no green tick appears beside the panel', function () {
    // THE DEFECT: add_feedback shipped at `tick`, and cart.js drew the badge
    // (#kbbAddMark.on, green with a white check) over the logo on every add.
    // Mutation: the default back to 'tick' -> red here.
    expect(CartPanel::SCHEMA['add_feedback'][2])->toBe('none')
        ->and(app(CartPanel::class)->get('add_feedback'))->toBe('none')
        ->and(app(CartPanel::class)->jsConfig()['feedback'])->toBe('none')
        ->and(app(CartPanel::class)->jsConfig()['openOnAdd'])->toBeTrue('the panel must still open on add');

    // The way back is still on the screen.
    expect(CartPanel::SCHEMA['add_feedback'][4])->toHaveKey('tick');

    // cart.js draws nothing at all for `none` -- the tick only for `tick`.
    $js = (string) file_get_contents(resource_path('js/kbb/cart.js'));
    expect($js)->toContain("if (mode === 'tick' && cp.openOnAdd !== false) tick(data.toast);")
        ->and($js)->toContain("else if (mode !== 'none') toast(data.toast);");
});

it('moves a shop that saved Animated tick to None when the package is applied', function () {
    // A moved default alone does not reach a shop that saved the screen.
    // Mutation: drop the cartpanel_add_feedback write from the migration -> red.
    app(CartPanel::class)->save(['add_feedback' => 'tick']);
    qk12aFresh();
    expect(app(CartPanel::class)->get('add_feedback'))->toBe('tick');

    qk12aMigrate();

    expect(app(CartPanel::class)->get('add_feedback'))->toBe('none');
});

/* ─────────────────────── 2. the Super Sale row ─────────────────────────── */

it('draws the phone Super Sale row plain, with a flash icon, and leaves the other rows alone', function () {
    $html = qk12aHome();
    expect(preg_match('#<nav class="mmenu([^"]*)".*?</nav>#s', $html, $m))->toBe(1);
    [$nav, $classes] = [$m[0], $m[1]];

    // THE DEFECT: the sheet carried no mm-nohl, so the row's inline
    // background:#E23A4E;color:#fff painted a full-width crimson bar.
    // Mutation: sale_fill's default back to true -> red on mm-nohl.
    expect($classes)->toContain('mm-nohl')->and($classes)->toContain('mm-flash');

    // The bolt is on the highlighted row and on no other row.
    expect(preg_match('#<a class="mm-it hot" href="[^"]*super-sale/?"\s+style="background:\#E23A4E;color:\#fff"\s*><svg class="mm-fl" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="[^"]+"/></svg>Super Sale</a>#', $nav))
        ->toBe(1, 'the Super Sale row has no flash icon in front of its name');
    expect(substr_count($nav, 'class="mm-fl"'))->toBe(1);

    // The highlight colour is still STORED and still printed: the desktop bar
    // paints its pill from it, and the owner did not ask about desktop.
    expect($html)->toContain('background:#E23A4E;color:#fff;border-radius:8px');

    // The quick-link chip row ("Super Sale" pill) is untouched.
    expect($nav)->toContain('class="mm-chip a-sale"');
});

it('styles the bolt from a constant and takes the red off the row only with the switch on', function () {
    $css = (string) file_get_contents(resource_path('css/kbb/kbb.css'));

    // Mutation: drop the color/!important rule -> the row stays red text.
    expect($css)->toContain('.mm-v4.mm-flash .mm-fl{display:block;flex:none;width:1.15em;height:1.15em;fill:var(--pink-deep)}')
        ->and($css)->toContain('.mm-v4.mm-nohl.mm-flash .mm-it[style*="background:"]{color:var(--ink) !important;font-weight:400}');

    // Flash off -> no .mm-flash and no bolt printed at all; fill back on ->
    // the old crimson row. Mutation: drop the sale_flash check from
    // partials/mobile-menu-item -> the bolt is still printed, red here.
    app(MobileMenu::class)->save(['sale_flash' => false, 'sale_fill' => true]);
    qk12aFresh();
    $classes = explode(' ', app(MobileMenu::class)->bodyClass());
    expect($classes)->not->toContain('mm-flash')->and($classes)->not->toContain('mm-nohl')
        ->and(qk12aHome())->not->toContain('class="mm-fl"');

    // Classic draws the 2.60.413 sheet: no bolt either.
    app(MobileMenu::class)->save(['menu_style' => 'classic']);
    expect(qk12aHome())->not->toContain('class="mm-fl"');
});

it('turns the stored fill off without touching anything else the owner saved', function () {
    // MobileMenu::save() replaces the whole array, so a migration that used it
    // for one key would wipe the quick links. Mutation: use save() -> red.
    app(SettingsService::class)->set('mobile_menu', [
        'sale_fill' => true, 'row_h' => 52,
        'chips' => [['label' => 'Gifts', 'url' => '/gifts/', 'accent' => 'gold']],
    ]);
    qk12aFresh();

    qk12aMigrate();

    $saved = app(SettingsService::class)->get('mobile_menu');
    expect($saved['sale_fill'])->toBeFalse()
        ->and($saved['row_h'])->toBe(52)
        ->and($saved['chips'])->toBe([['label' => 'Gifts', 'url' => '/gifts/', 'accent' => 'gold']]);
});

/* ─────────────────────────── 3. the menu icon ──────────────────────────── */

it('draws the phone menu button as three lines, and the four squares again on request', function () {
    // THE ASK: four rounded squares (2x2) -> "simple three lines". Mutation:
    // the default back to 'tiles' -> red.
    expect(HeaderSettings::SCHEMA['menu_icon'][2])->toBe('lines')
        ->and(HeaderSettings::SCHEMA['menu_icon'][4])->toHaveKey('tiles');

    $html = qk12aHome();
    expect($html)->toContain('<button class="kbbmi kbbmi-lines" id="burger" type="button"')
        ->and($html)->toContain('<svg class="ln" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3.5 6.5h17M3.5 12h11M3.5 17.5h17"/></svg>');

    // "change back anytime": the previous icon, byte for byte as it rendered.
    app(HeaderSettings::class)->save(['menu_icon' => 'tiles']);
    $html = qk12aHome();
    expect($html)->toContain(
        "<button class=\"kbbmi kbbmi-tiles\" id=\"burger\" type=\"button\"\n"
        . "        aria-label=\"Menu\" aria-controls=\"mmenu\" aria-expanded=\"false\">\n"
        . "                <span class=\"s\"></span><span class=\"s\"></span><span class=\"s\"></span><span class=\"s\"></span>\n"
        . "            </button>"
    )->and($html)->not->toContain('class="ln"');

    $css = (string) file_get_contents(resource_path('css/kbb/kbb.css'));
    expect($css)->toContain('.kbbmi .ln{display:block;width:24px;height:24px;fill:none;stroke:#2A2228;stroke-width:2;stroke-linecap:round}');
});

it('moves a shop that saved the four squares to three lines, keeping its other header values', function () {
    app(HeaderSettings::class)->save(['menu_icon' => 'tiles', 'menu_icon_size' => 40]);
    qk12aFresh();

    qk12aMigrate();

    expect(app(HeaderSettings::class)->get('menu_icon'))->toBe('lines')
        ->and(app(HeaderSettings::class)->get('menu_icon_size'))->toBe(40);
});
