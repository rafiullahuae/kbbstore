<?php

declare(strict_types=1);

use Tests\Support\CssDirection;

/**
 * =============================================================================
 * THE DESKTOP NAV BAR DRAGGED THE WHOLE SHOP INTO HORIZONTAL SCROLL
 * =============================================================================
 *
 * THE DEFECT, AS IT LOOKED ON THE SHOP. `.mbar .wrap` was a `flex-wrap: nowrap`
 * row with `overflow-x: visible`. Flex items shrink only to their MIN-CONTENT
 * width, and `.navlink` is `white-space: nowrap`, so past that floor the bar
 * stopped shrinking and pushed the PAGE wider instead -- nothing clipped it,
 * scrolled it or wrapped it. The mobile nav does not take over until 1000px, so
 * every desktop width was exposed.
 *
 * Measured in Chromium at 1280x900 on a sixteen-entry menu, on `/`, `/shop/`,
 * `/new-in/`, `/best-sellers/` and `/super-sale/` alike:
 *
 *              documentElement   clientWidth   the shop
 *   1024            1459            1024       scrolls sideways by 435px
 *   1080            1459            1080       scrolls sideways by 379px
 *   1120            1459            1120       scrolls sideways by 339px
 *   1280            1459            1280       scrolls sideways by 179px
 *
 * The last entry was sliced mid-word at the right edge and the product grid ran
 * off the page with it. Lane S measured the same thing on the owner's real menu
 * at `scrollWidth` 1347 against 1280.
 *
 * ── WHY `flex-wrap: wrap` AND NOT `overflow-x: auto` ────────────────────────
 *
 * Both contain the page. Both were built and measured on this shop, and only
 * one of them survives contact with it.
 *
 * `overflow-x: auto` makes the bar a scroll container, and a scroll container
 * cannot have `overflow-y: visible` -- the other axis computes to `auto` with
 * it. The mega panels are `position: absolute` children INSIDE `.mbar .wrap`,
 * 319.2px tall, hanging below a 45.5px bar. Measured with it applied: the page
 * was contained at every width, and **0.3% of the mega panel was visible** --
 * 1px of 319.2. The navigation was destroyed to save the scrollbar. That is the
 * shop-specific reason, and it is why the answer differs from what the answer
 * would be on a bar with no dropdowns: `.catbar .wrap` one rule above DOES use
 * `overflow-x: auto`, correctly, because its children are plain links.
 *
 * `flex-wrap: wrap` leaves `overflow` alone, so the panels are untouched.
 *
 * ── WHAT HAD TO GO WITH IT, AND WHY IT IS NOT OPTIONAL ──────────────────────
 *
 * nav-fit.js shrinks the bar so the items fit one row. It asked
 * `wrap.scrollWidth > wrap.clientWidth`, and BOTH halves of that were wrong in
 * ways that cancelled out for as long as nothing could wrap:
 *
 *   - `scrollWidth` is CLAMPED to `clientWidth`. Measured on the twelve-entry
 *     menu this shop ships, at 1280 with wrapping suspended: `scrollWidth` 1280
 *     against `clientWidth` 1280 -- "it fits" -- while the items and their gaps
 *     came to 1242.3 against a content box of 1236. Six pixels over, invisible,
 *     because the row bled into the 22px padding gutter.
 *   - `clientWidth` includes that padding; flex wrapping happens against the
 *     content box, which is 44px narrower.
 *
 * Neither mattered under `nowrap`. Under `wrap` a six-pixel overrun costs a
 * whole second row: with the declaration alone, the shipped twelve-entry menu
 * rendered on THREE rows, 93px tall against 45.5px, at every desktop width from
 * 1024 to 1920 -- on a menu that has never overflowed. So the measurement had
 * to become the one the browser is actually making: the items plus their gaps,
 * against the content box, with wrapping suspended for the reading.
 *
 * ── WHAT THIS COSTS THE SHIPPED SHOP, SAID OUT LOUD ─────────────────────────
 *
 * Bar height is IDENTICAL at every width, 45.5px, so nothing moves vertically
 * and there is no layout shift. One row before, one row after. The only change
 * is `--nav-scale`, because the bar is now honest about its own box:
 *
 *              before   after
 *   1024        0.736   0.729
 *   1120        0.819   0.800
 *   1280+       0.936   0.919      (13px x 0.936 = 12.17px -> 11.95px)
 *
 * A fifth of a pixel of font size, and it is the correct size -- the old one
 * overran its content box. On the owner's real menu, which is already past the
 * floor, this replaces a shop that scrolls sideways with a nav bar on two rows.
 */

/** Every declaration of one property on one selector in a stylesheet. */
function navBarDeclarations(string $relative, string $selector, string $property): array
{
    static $cache = [];

    $rows = $cache[$relative] ??= CssDirection::declarationsInFile(base_path($relative));

    $out = [];

    foreach ($rows as $d) {
        if ($d['selector'] === $selector && $d['property'] === $property) {
            $out[] = $d['value'];
        }
    }

    return $out;
}

it('makes the desktop nav bar wrap rather than push the page sideways', function () {
    /*
     * MUTATION: delete `flex-wrap:wrap` from `.mbar .wrap` in kbb.css. Red, and
     * the shop goes back to documentElement.scrollWidth 1459 against a 1280
     * viewport on five pages. Run and confirmed.
     */
    $file = 'resources/css/kbb/kbb.css';
    $wrong = [];

    $flexWrap = navBarDeclarations($file, '.mbar .wrap', 'flex-wrap');

    if (! in_array('wrap', $flexWrap, true)) {
        $wrong[] = '.mbar .wrap must declare flex-wrap:wrap. Without it the bar is a nowrap flex row with visible overflow: past the min-content floor it stops shrinking and pushes the PAGE wider instead, measured at documentElement.scrollWidth 1459 against a 1280 viewport (and 1024, 1080, 1120) on /, /shop/, /new-in/, /best-sellers/ and /super-sale/.';
    }

    /*
     * And NOT the other one, on this element. `.catbar .wrap` uses
     * `overflow-x:auto` correctly one rule above, because its children are
     * plain links; this bar's children hold the mega panels.
     */
    $overflowX = navBarDeclarations($file, '.mbar .wrap', 'overflow-x');

    if (in_array('auto', $overflowX, true) || in_array('scroll', $overflowX, true)) {
        $wrong[] = '.mbar .wrap must not be a scroll container. A scroll container cannot keep overflow-y:visible -- the other axis computes to auto with it -- and the mega panels are position:absolute children INSIDE this element, 319.2px tall under a 45.5px bar. Measured with overflow-x:auto applied: the page was contained and 0.3% of the mega panel was visible, 1px of 319.2.';
    }

    expect($wrong)->toBe([], implode("\n", $wrong));
});

it('keeps nav-fit measuring the row the browser is actually laying out', function () {
    /*
     * THE PREMISE OF THE RULE ABOVE. `flex-wrap:wrap` is only correct while
     * nav-fit.js still shrinks a bar that could fit one row; a bar that wraps
     * instead of shrinking is a regression on every shop whose menu fits today.
     *
     * With the declaration alone, the twelve-entry menu this shop ships
     * rendered on three rows, 93px against 45.5px, at every desktop width from
     * 1024 to 1920 -- because `scrollWidth` is clamped to `clientWidth` and so
     * reports that a wrapped bar fits.
     *
     * MUTATION 1: put `let needed = wrap.scrollWidth;` back in nav-fit.js. Red
     * on the first assertion, and the shipped menu goes to three rows.
     * MUTATION 2: delete the `wrap.style.flexWrap = 'nowrap'` line from
     * rowNeeded(). Red on the second — the measurement then reads the width of
     * the widest wrapped row rather than of the one row it is deciding about.
     * MUTATION 3: make rowSpace() return `wrap.clientWidth` unchanged. Red on
     * the third; the six-pixel overrun comes back and with it the second row.
     * Run and confirmed.
     */
    $js = (string) file_get_contents(base_path('resources/js/kbb/nav-fit.js'));

    // Comments explain the clamping at length and name the property while doing
    // it, so the assertions read the CODE. docs/rtl-audit.md Sec. 1 records the
    // same trap for CSS: a declaration reader, never a substring search.
    $code = preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', $js);

    $wrong = [];

    if (preg_match('/\bneeded\s*=\s*wrap\.scrollWidth\b/', $code)) {
        $wrong[] = 'nav-fit.js is measuring wrap.scrollWidth again. It is CLAMPED to clientWidth, so a bar that has already wrapped reports that it fits and nothing is ever shrunk: measured on the shipped twelve-entry menu, scrollWidth 1280 against clientWidth 1280 while the items needed 1242.3 in a content box of 1236.';
    }

    if (! preg_match("/flexWrap\s*=\s*'nowrap'/", $code)) {
        $wrong[] = "nav-fit.js no longer suspends wrapping while it measures. With flex-wrap:wrap live, the measurement reads the widest WRAPPED row instead of the single row this script is deciding about, so it can never tell that the bar needs shrinking.";
    }

    if (! preg_match('/paddingLeft/', $code) || ! preg_match('/paddingRight/', $code)) {
        $wrong[] = 'nav-fit.js is comparing against clientWidth including the bar\'s own padding. Flex wrapping happens against the CONTENT box, 44px narrower at 1280, so the row is allowed to overrun by up to the padding — six pixels on the shipped menu, which under flex-wrap:wrap costs a whole second row.';
    }

    expect($wrong)->toBe([], implode("\n", $wrong));
});

it('leaves the mobile breakpoint and the category bar exactly as they were', function () {
    /*
     * The blast radius, pinned. This lane was given the nav bar's containment
     * and nothing else: not the mobile breakpoint, not the category bar, not
     * the menu's content.
     *
     * MUTATION: change `.mbar{display:none}` to any other width, or drop
     * overflow-x:auto from .catbar .wrap. Red. Run and confirmed.
     */
    $css = (string) file_get_contents(base_path('resources/css/kbb/kbb.css'));
    $file = 'resources/css/kbb/kbb.css';

    $wrong = [];

    // .mbar is hidden under 1000px and the mobile nav takes over there.
    if (! str_contains($css, '@media(max-width:1000px)')) {
        $wrong[] = 'The 1000px breakpoint that hands the desktop bar over to the mobile nav has moved. This lane must not change it — the containment fix is for the band above it.';
    }

    // The category bar is the OTHER horizontal bar and it scrolls, correctly,
    // because its children are plain links with no panels hanging off them.
    if (! in_array('auto', navBarDeclarations($file, '.catbar .wrap', 'overflow-x'), true)) {
        $wrong[] = '.catbar .wrap has lost its overflow-x:auto. That bar is not this lane\'s change and scrolling is right for it: its children are plain links, so nothing is clipped by making it a scroll container.';
    }

    expect($wrong)->toBe([], implode("\n", $wrong));
});
