<?php

declare(strict_types=1);

/**
 * TABLETS: NO BUTTON OVER A HEADING, AND THE CHECKOUT BUTTON KEEPS ITS WORDS.
 *
 * What it looked like (owner's tablet screenshots, 23 October 2026):
 *  - "See every #KBeautyBliss look" (307px) lay over "#KBEAUTYBLISS Spotted"
 *    and its subtitle, because beside a centred heading the button only has
 *    220px reserved. Measured on the live homepage: overlapping at 901-1024px
 *    (the Spotted grid, and "K-Beauty Under AED 54" at 901px).
 *  - In the bag's two-column layout the black button read "SECURE CHECK…"
 *    with the owner's larger text sizes (measured 1024-1440px at 150% / 18px).
 *
 * Mutation notes: delete the 901-1199px block in kbb.css and the first case is
 * red; delete the tablet query in cart-squeeze.blade.php, or wrap the bar
 * again (2.60.580's mistake), and the second is.
 */
function tabletBlock(): string
{
    $css = (string) file_get_contents(resource_path('css/kbb/kbb.css'));
    preg_match('/@media \(min-width:901px\) and \(max-width:1199px\)\{(.*?)\n\}/s', $css, $m);

    return $m[1] ?? '';
}

it('moves every beside-the-heading button under its section on tablets', function () {
    $block = tabletBlock();
    expect($block)->not->toBe('');

    // Each top button is hidden, and its under-the-section twin is shown.
    foreach ([
        '.kbb-home .bndl.bndl-btn-d-top .bndl-all-top{display:none}',
        '.kbb-home .bndl.bndl-btn-d-top .bndl-foot{display:flex',
        '.kbb-home .hs-btn-top{display:none}',
        '.kbb-home .hs-head.hs-split~.hs-foot{display:flex}',
        '.kbb-home .spt-sg.spt-btn-d-top .spt-hbtn{display:none}',
        '.kbb-home .spt-sg.spt-btn-d-top .spt-sg-foot{display:flex}',
    ] as $rule) {
        expect(str_contains($block, $rule))->toBeTrue('missing tablet rule: '.$rule);
    }

    // And the room reserved for the button goes back to the heading.
    foreach (['.bndl-head{padding-inline:0}', '.hs-split-c{padding-inline:0}', '.spt-head{padding-inline:0}'] as $rule) {
        expect(str_contains($block, $rule))->toBeTrue('reserved room not released: '.$rule);
    }

    // The block must come AFTER the rules it overrides, or it loses on order.
    $css = (string) file_get_contents(resource_path('css/kbb/kbb.css'));
    $at = strpos($css, '@media (min-width:901px) and (max-width:1199px){');
    expect($at)->toBeGreaterThan(strpos($css, '.kbb-home .spt-sg.spt-btn-d-top .spt-hbtn{display:block'));
    expect($at)->toBeGreaterThan(strpos($css, '.kbb-home .hs-head.hs-split~.hs-foot{display:none}'));
    expect($at)->toBeGreaterThan(strpos($css, '.kbb-home .bndl.bndl-center.bndl-btn-d-top .bndl-head{padding-inline:190px}'));
});

it('keeps the bag total and a slightly smaller checkout button side by side in a slightly wider column, on tablets only', function () {
    $view = (string) file_get_contents(resource_path('views/store/cart-squeeze.blade.php'));
    $desktop = substr($view, (int) strpos($view, "@media (min-width: {{ (int) \$kbbCartPage->get('d_min') }}px){\n\n  /* The page stops growing"));
    preg_match('/@media \(max-width:1199px\)\{(.*?)\n  \}/s', $desktop, $m);
    $tablet = $m[1] ?? '';

    // A little wider column, a little smaller button (owner, 23 October).
    expect(str_contains($tablet, 'calc(var(--cpg-d-aside,380px) + 50px)'))->toBeTrue();
    expect(str_contains($tablet, '.cpg-d .cpg-cobar .cobtn{font-size:calc(15px * var(--cpg-bar-f) * .85)'))->toBeTrue();
    expect(str_contains($tablet, '.cobtn.cpg-cob2{font-size:calc(var(--cpg-cob-f,14px) * var(--cpg-bar-f) * .85)}'))->toBeTrue();

    // Side by side everywhere: no wrap, on tablets or anywhere else. 2.60.580
    // wrapped the bar at every desktop width; the owner: "for desktop etc it
    // was absolutely fine side by side ... i want the value and button side by
    // side".
    expect(str_contains($view, '.cpg-cobar{flex-wrap:wrap'))->toBeFalse();
});
