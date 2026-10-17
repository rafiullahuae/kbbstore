<?php

declare(strict_types=1);

/**
 * The wishlist heart turns at the tap, and the cart panel's heart tab is grey
 * until something is saved, then red. (17 October 2026)
 *
 * What it looked like on the shop: tapping a heart did nothing visible until
 * the server answered -- and with the cart panel's heart tab, until the server
 * had redrawn that tab's list too ("wishlist function gives delay, it's not
 * adding instantly"). And the tab's heart took the tab's colour, not the
 * wishlist's state ("should be empty, not colored ... then it should become
 * red, in both languages").
 *
 * Measured in Chromium with the toggle request held for 1 s
 * (tools/wli-check.cjs): heart on in the same frame as the tap; a refused
 * request puts it back.
 *
 * MUTATION: move paint(id, !was) below the fetch -- the first assertion is red;
 * drop the paint(id, was) in the catch -- the second; drop the .kc-wh.on rule
 * -- the third.
 */
it('paints the heart before the request and puts it back when the request fails', function () {
    $js = (string) file_get_contents(resource_path('js/kbb/wishlist.js'));

    $paint = strpos($js, 'paint(id, !was);');
    $fetch = strpos($js, 'await fetch(window.KBB.routes.wishlistToggle');

    expect($paint)->not->toBeFalse()->and($fetch)->not->toBeFalse()
        ->and($paint)->toBeLessThan($fetch);

    // A refusal and a network failure both restore the heart as it was.
    expect(substr_count($js, 'paint(id, was);'))->toBe(2)
        // and a second tap on the same heart waits for the first answer.
        ->and($js)->toContain('if (inFlight.has(id)) return;');
});

it('keeps the heart tab grey while the wishlist is empty and red once it is not', function () {
    $css = (string) file_get_contents(resource_path('css/kbb/kbb.css'));

    expect($css)->toContain('.kc-tab.kc-wt .kc-wh{color:var(--muted)}')
        ->toContain('.kc-tab.kc-wt .kc-wh.on{color:var(--pink)}');
});
