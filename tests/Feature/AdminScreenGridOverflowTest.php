<?php

declare(strict_types=1);

/**
 * Screens that put a wide table inside a CSS grid.
 *
 * A grid item's default min-width is `auto` — "at least as wide as my
 * content". So a card holding a wide table refuses to shrink, its inner
 * `overflow-x: auto` never gets the chance to scroll, and the whole screen is
 * stretched to the table's natural width. Everything laid out beside it is
 * dragged out with it.
 *
 * That shipped on the Coupons screen: measured in Chromium at a 390px
 * viewport, the content box was 677px. The last three columns were off-screen
 * with no way to reach them, and the summary tiles had been pulled out of the
 * viewport too. The stylesheet's own comment claimed "no element carries a
 * min-width larger than the narrowest content box", which was true of every
 * rule written down and false of the default nobody had written down.
 *
 * These assertions are structural because Pest has no layout engine — the
 * measurement lives in the commit. What they pin is the pairing that makes the
 * measurement hold: a grid whose children can shrink, and a scroller that is
 * allowed to be narrower than the table inside it.
 */
function partialSource(string $name): string
{
    return (string) file_get_contents(resource_path('views/admin/partials/'.$name.'.blade.php'));
}

it('lets the coupons grid shrink below its table width', function () {
    $css = partialSource('coupon-usage-screen');

    // The grid itself, and — the half that actually bites — its children.
    expect($css)->toMatch('/\.cu-wrap\{[^}]*min-width:0/')
        ->and($css)->toMatch('/\.cu-wrap\s*>\s*\*\{[^}]*min-width:0/');
});

it('gives the coupons table a scroller that is allowed to be narrow', function () {
    $css = partialSource('coupon-usage-screen');

    // overflow-x:auto alone is not a scroller. Without a min-width it grows to
    // its content and scrolls nothing.
    expect($css)->toMatch('/\.cu-scroll\{[^}]*overflow-x:auto/')
        ->and($css)->toMatch('/\.cu-scroll\{[^}]*min-width:0/')
        ->and($css)->toMatch('/\.cu-scroll\{[^}]*max-width:100%/');
});

it('lets the coupon summary tiles wrap instead of forcing a row', function () {
    $css = partialSource('coupon-usage-screen');

    /*
     * repeat(auto-fit, minmax(150px, 1fr)) cannot go narrower than 150px per
     * track, so three tiles plus their gaps demand more than a 390px screen
     * has. minmax(min(150px,100%), 1fr) lets a track give way on a narrow
     * phone and keeps the 150px floor everywhere else.
     */
    expect($css)->toMatch('/\.cu-stats\{[^}]*minmax\(min\(150px,\s*100%\),\s*1fr\)/');
});

it('keeps the same protection on the new-order screen', function () {
    // Built by a different lane on the same layout, and measured clean at
    // 390px. Asserted here so the two screens cannot drift apart: whichever
    // one is edited next, this file is the reminder.
    $css = partialSource('manual-order-screen');

    expect($css)->toMatch('/min-width:\s*0/');
});
