<?php

declare(strict_types=1);

/**
 * The phone menu icon's blue ring (17 October 2026).
 *
 * What it looked like on the shop: tap ☰, tap ×, and the icon kept "one
 * weird outer blue border" (the owner, on his phone). The menu script moved
 * focus by itself after the TAP -- into the panel on open, back to ☰ on
 * close -- and a phone's Chrome drew its focus ring for that. Measured in
 * Chromium with touch taps (tools/mmf-check.cjs): after the fix nothing is
 * focused once the menu is shut by a tap, while Enter / Escape still move
 * focus in and back with the ring, as a keyboard user needs. The header's
 * CSS is untouched (HeaderLayoutLockTest).
 *
 * MUTATION: drop `byKeys(event)` from either focus call -- red.
 */
it('moves focus into the menu and back to ☰ only for the keyboard', function () {
    $js = (string) file_get_contents(resource_path('js/kbb/home.js'));

    expect($js)->toContain("const byKeys = (event) => !!event && (event.type === 'keydown' || (event.type === 'click' && event.detail === 0));")
        ->toContain("if (side && byKeys(event)) (document.getElementById('mmx') || menu).focus({ preventScroll: true });")
        ->toContain('if (side && was && byKeys(event)) burger?.focus({ preventScroll: true });')
        ->toContain('else if (was && menu.contains(document.activeElement)) document.activeElement.blur();')
        // Every way of shutting hands its event on, so a swipe or a tap never
        // counts as the keyboard.
        ->not->toContain('shut();');
});
