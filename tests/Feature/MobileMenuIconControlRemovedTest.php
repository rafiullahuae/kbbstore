<?php

declare(strict_types=1);

use App\Services\HeaderSettings;
use App\Services\MobileMenu;

/**
 * =============================================================================
 * APPEARANCE → MOBILE MENU NO LONGER OFFERS A MENU ICON             Lane AD
 * =============================================================================
 *
 * ── THE CONTROL, AND WHAT IT DID ────────────────────────────────────────────
 *
 * 'icon_style' was a select of seven — Cross, Spin cross, Down arrow, Collapse,
 * Slow pinch, Cross in pink, Tapered — and its values are CSS class names:
 * `ico-spin`, `ico-arrow`, `ico-collapse`, `ico-pinch`, `ico-pink`,
 * `ico-taper`. The rules those names select are REAL and are still in kbb.css
 * (`.burger.ico-spin span{transition-duration:.42s}` and the rest).
 *
 * What never existed is anything that put one of those classes on an element.
 * MobileMenu::bodyClass() emits `mm-card-*`, `mm-rule-*` and `mm-nocounts`, and
 * has never emitted this one. So an owner could pick any of the seven and the
 * icon did exactly what it did before — the same shape as the twenty controls
 * on Appearance → Product styles, found in the same sweep.
 *
 * ── REMOVED RATHER THAN WIRED, AND THE ARGUMENT ─────────────────────────────
 *
 * partials/menu-icon.blade.php states its own reason for not using `.burger` at
 * all: "Deliberately not .burger: that class carries the old bar styling, and
 * an element wearing both ends up with bar rules fighting tile rules." It
 * renders `kbbmi kbbmi-{menu_icon}` from HeaderSettings instead.
 *
 * So the question this control asked already has a better answer on another
 * screen — Appearance → Header → Icon — and that answer is richer in every
 * direction: a family (tiles, three dots, nine dots, bars), an effect speed, a
 * size and three colours, all of which reach the element. Wiring the old one
 * back would put a second, poorer answer to one question on a second screen,
 * pointed at the markup the icon partial was written to stop using.
 *
 * NOTHING MOVES ON THE SHOP. A control that emitted nothing cannot change
 * anything by being removed, and no stored value needs clearing for the same
 * reason — there is no code path that would begin reading it.
 *
 * MUTATION (run): put 'icon_style' back in MobileMenu::SCHEMA and TABS. RED on
 * the first assertion here, and on ModuleScreenPayloadTest besides.
 */
it('has stopped offering seven menu-icon animations that did nothing', function () {
    expect(array_key_exists('icon_style', MobileMenu::SCHEMA))->toBeFalse(
        'Appearance → Mobile menu offers "Menu icon" again — its seven options are classes nothing emits'
    );

    foreach (MobileMenu::TABS as $tab => $spec) {
        expect(in_array('icon_style', $spec[2], true))->toBeFalse(
            "the {$tab} tab still lists icon_style, which the schema does not define"
        );
    }
});

it('still emits the three classes it always emitted, and no more', function () {
    /*
     * THE OTHER HALF. Removing a key from a schema is only safe if the emitter
     * was really not using it, and "I read bodyClass() and it looked fine" is
     * how this kind of thing gets missed. At the shipped defaults bodyClass()
     * is a known string, so a fourth class appearing or one of these three
     * going missing is red here.
     */
    $classes = explode(' ', app(MobileMenu::class)->bodyClass());

    sort($classes);

    // ▲ Lane MN (6 October): `mm-left` joined them deliberately — Appearance →
    // Mobile menu → Panel → "Menu opens from" ships at Left, the side panel the
    // owner asked for. It is the class the stylesheet and initMobileChrome()
    // key the side panel off; MobileMenuOpensFromTest pins it. Still no icon
    // class: the icon stays the Header screen's.
    expect($classes)->toBe(['mm-card-cream', 'mm-left', 'mm-rule-children']);
});

it('leaves the menu icon answered by the screen that reaches the element', function () {
    /*
     * The replacement has to actually be there, or this is a removal and not a
     * consolidation. HeaderSettings::menu_icon is what
     * partials/menu-icon.blade.php renders into `kbbmi-{style}`.
     */
    expect(array_key_exists('menu_icon', HeaderSettings::SCHEMA))->toBeTrue(
        'Appearance → Header no longer offers the menu icon, so removing the Mobile menu one leaves no answer at all'
    );

    $partial = (string) file_get_contents(base_path('resources/views/partials/menu-icon.blade.php'));

    expect(str_contains($partial, 'kbbmi-{{ $style }}'))->toBeTrue(
        'the menu icon partial no longer renders the Header screen\'s choice'
    );
});
