<?php

declare(strict_types=1);

/**
 * The desktop menu bar never makes the page scroll sideways.     (2.60.369)
 *
 * THE DEFECT ON THE LIVE SHOP: "in DESKTOP above 1000px width, there's right
 * side horizontal scroll space coming." Read off his page in Chrome's console:
 * page 1425 / window 1414, the one element ending at 1425 being DIV.drop.mega —
 * the closed two-column dropdown of the last menu item (Skincare), 440px wide,
 * opening rightward from the word. A closed dropdown is opacity 0 / hidden, so
 * its box still widened the page. Reproduced on the preview with a menu of his
 * shape: 1100 → page 1347, 1280 → 1402, 1414 → 1444, 1440 → 1452; after the
 * fix the page is the window's width at 1000–1920 (docs/int-369 shots).
 *
 * MUTATION NOTES, RUN:
 *   · delete `.mbar{overflow-x:clip}` → RED (first case).
 *   · delete the max-width:1599.98px block → RED (second case).
 */

function mbnsRules(): string
{
    return (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(resource_path('css/kbb/kbb.css')));
}

it('clips the menu bar sideways, never vertically, so a dropdown cannot widen the page', function () {
    $css = mbnsRules();

    expect($css)->toContain('.mbar{overflow-x:clip}')
        // `clip`, not `hidden`: hidden would turn overflow-y into auto and cut
        // off every dropdown hanging below the bar.
        ->and($css)->not->toMatch('/\.mbar\{[^}]*overflow(-x)?:hidden/')
        ->and($css)->not->toMatch('/\.mbar\{[^}]*overflow-y:/');
});

it('opens the last two menus leftward on windows up to 1599px, and leaves wider screens as they were', function () {
    $css = mbnsRules();

    expect($css)->toContain("@media (max-width:1599.98px){\n    .mbar .navitem:nth-last-child(-n+2) > .drop{inset-inline-start:auto;inset-inline-end:0}\n  }");

    // The ordinary rule every other dropdown follows is unchanged.
    expect($css)->toContain('.drop{position:absolute;top:calc(100% - 1px);inset-inline-start:0;');

    // The items are the bar's only children, so "the last two" are the last two menu items.
    $bar = (string) file_get_contents(resource_path('views/partials/nav-bar.blade.php'));
    /*
     * ▲ Lane NV: a fitted bar (nine or more items) prints `nav-fill` and a
     * style on `.mbar` itself; the wrap is still its only child and the items
     * still the wrap's only children, which is what "the last two" relies on.
     */
    expect($bar)->toContain('<div class="mbar{{ $kbbNavFit !== null ? \' nav-fill\' : \'\' }}"@if ($kbbNavFit !== null) style="{{ $kbbNavFit }}"@endif><div class="wrap">')
        ->and($bar)->toMatch('#@endforeach\s*</div></div>\s*$#');
});
