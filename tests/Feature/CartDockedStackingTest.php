<?php

declare(strict_types=1);

/**
 * =============================================================================
 * NOTHING AT THE BOTTOM OF THE PAGE MAY COVER THE CHECKOUT ROW
 * =============================================================================
 *
 * The owner, three times, with screenshots: a "weird white bar" appears over
 * the docked rows while scrolling back up and settles when the scroll stops.
 *
 * ── THE ROOT CAUSE, MEASURED ────────────────────────────────────────────────
 *
 *   .tabbar       kbb.css:888   position:fixed; bottom:0; z-index:95
 *                               background:rgba(255,255,255,.97)
 *                               backdrop-filter:blur(14px)
 *   .cpg-docked                 position:fixed; bottom:0; z-index:40
 *
 * A near-opaque white bar outranking the checkout row by fifty-five. It was not
 * appearing and disappearing: it was there the whole time, and only its PAINT
 * came and went, because Chrome re-rasterises a backdrop-filter during scroll.
 *
 * That it is not our JavaScript was established rather than assumed — the only
 * `addEventListener('scroll')` calls in this codebase are in `home.js` and
 * `pdp.js`, and neither is loaded on the cart page.
 *
 * ── WHY THE display:none RULE WAS NOT ENOUGH ────────────────────────────────
 *
 * The squeeze stylesheet already hides `.tabbar`. That is the right first line
 * of defence and it failed in practice for the dullest reasons: a package not
 * yet applied, a stale compiled view, a module switched back on. A z-index that
 * loses by fifty-five turns every one of those into a shopper who cannot see
 * the Checkout button.
 *
 * So the ordering is the second line, and this test is what keeps it: the
 * docked rows must outrank EVERY fixed bottom bar in the storefront stylesheet,
 * whoever adds one next.
 *
 * MUTATION: put .cpg-docked back to z-index:40. Red.
 */
it('ranks the docked rows above every fixed bottom bar in the storefront', function () {
    $squeeze = (string) file_get_contents(base_path('resources/views/store/cart-squeeze.blade.php'));
    $shop = (string) file_get_contents(base_path('resources/css/kbb/kbb.css'));

    preg_match('/\.cpg-docked\{[^}]*z-index:(\d+)/', $squeeze, $m);

    expect($m)->not->toBe([], 'the docked rows have no z-index at all');

    $docked = (int) $m[1];

    /*
     * Every rule in the shop stylesheet that is fixed AND anchored to the
     * bottom. Read out of the file rather than listed here, so a bar added
     * tomorrow is included without anyone remembering to add it.
     */
    preg_match_all('/\.([a-z-]+)\{([^}]*position:fixed[^}]*)\}/', $shop, $rules, PREG_SET_ORDER);

    $offenders = [];

    foreach ($rules as [$whole, $selector, $body]) {
        if (! preg_match('/(?<![-\w])bottom:\s*0/', $body)) {
            continue;
        }

        /*
         * A bottom BAR, not a panel that happens to reach the bottom.
         *
         * `.msub` is `top:0;bottom:0` and `.mmenu` is `top:var(--mm-top);
         * bottom:0` — the mobile menu drawer and its submenu, which are
         * SUPPOSED to cover these rows. Outranking them would trap a shopper
         * behind their own basket with the menu open.
         *
         * So the test is "sets a bottom and no top". Anything with a top is
         * spanning a range and is somebody's overlay; anything without one is
         * a bar sitting on the bottom edge, which is where the checkout row
         * lives and where it must win. Matched on any `top:` value, not on
         * `top:0`, because --mm-top is exactly the case that slipped through.
         */
        if (preg_match('/(?<![-\w])top:/', $body) || preg_match('/inset:\s*0/', $body)) {
            continue;
        }
        if (! preg_match('/z-index:(\d+)/', $body, $z)) {
            continue;
        }
        if ((int) $z[1] >= $docked) {
            $offenders[] = ".{$selector} (z-index {$z[1]})";
        }
    }

    expect($offenders)->toBe(
        [],
        'these bottom-anchored bars can paint over the checkout row: '.implode(', ', $offenders)
        .'. The docked rows are at '.$docked.'.'
    );
});

it('keeps the address sheet and its close button above the rows they open from', function () {
    /*
     * Raising the rows without raising the sheet would put the sheet BEHIND the
     * bar that opens it — which is the same bug, one layer up, and would have
     * been the obvious way to get this wrong.
     */
    $css = (string) file_get_contents(base_path('resources/views/store/cart-squeeze.blade.php'));

    $z = function (string $selector) use ($css): int {
        preg_match('/'.preg_quote($selector, '/').'\{[^}]*z-index:(\d+)/', $css, $m);

        return (int) ($m[1] ?? 0);
    };

    $docked = $z('.cpg-docked');

    expect($z('.cpg-scrim'))->toBeGreaterThan($docked, 'the scrim sits behind the rows it dims');
    expect($z('.cpg-sheet'))->toBeGreaterThan($z('.cpg-scrim'), 'the sheet sits behind its own scrim');
    expect($z('.cpg-x'))->toBeGreaterThan($z('.cpg-sheet'), 'the close button sits behind the sheet');
});

it('still hides the tab bar outright, because ordering is the second line and not the first', function () {
    /*
     * The z-index makes the bar harmless; this keeps it absent. Both, because a
     * translucent bar stacked under the checkout row would still be a second
     * floating bar at the bottom of a phone, which is what the owner asked to
     * be rid of in the first place.
     */
    $css = (string) file_get_contents(base_path('resources/views/store/cart-squeeze.blade.php'));

    expect(preg_match('/\.tabbar\{display:none\}/', $css))->toBe(
        1,
        'the cart page no longer hides the floating tab bar'
    );
});
