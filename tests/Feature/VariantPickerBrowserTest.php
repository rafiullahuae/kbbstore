<?php

declare(strict_types=1);

/*
 * Lane VPA: the picker in a REAL Chromium, against the real application.
 *
 * tools/vpa-preview.sh boots the shop on its own database with Missha (3
 * shades), TIRTIR (9 shades, four sold out, one on sale) and Anua (two ml
 * sizes); tools/vpa-shots.cjs then, at 390 and 1280:
 *   - taps a dot on the CARD and checks the card's price and picture move to
 *     that shade's own, that the tap does not navigate, and that the dot and
 *     the name link are what is under the finger;
 *   - taps a shade on the PAGE and checks the price, the struck price, the
 *     picture, the SKU and the stock line move;
 *   - taps a sold-out shade and checks the page says so and Add to cart is
 *     off -- and that /api/cart/add refuses it anyway (422);
 *   - adds 21N Ivory and checks the basket holds "Shade: 21N Ivory";
 *   - checks a card link is still prefetched and the prefetch is used;
 *   - and that no page has a console error or a sideways scroll.
 *
 * Gated like the other browser tests (CheckoutHiddenRowsTest): it needs
 * KBB_BROWSER_TESTS=1, node, playwright and Chromium, and it takes a couple of
 * minutes because it migrates a fresh database. The rest of the picker is
 * pinned without a browser in VariantPickerTest and VariantEndToEndTest.
 *
 * MUTATION: in resources/js/kbb/variant-pick.js make apply() skip setPrice()
 * and "page 390: price moved to the shade's sale price" fails -- after
 * `npx vite build`, which is what the shop loads.
 */

it('taps a swatch on the card and the page, adds the right shade, and refuses a sold-out one', function () {
    $chrome = env('KBB_BROWSER_CHROME', '/opt/pw-browsers/chromium-1194/chrome-linux/chrome');

    if (! env('KBB_BROWSER_TESTS') || ! is_file($chrome) || trim((string) shell_exec('command -v node 2>/dev/null')) === '') {
        $this->markTestSkipped('needs KBB_BROWSER_TESTS=1, node and Chromium');
    }

    $dir = storage_path('framework/testing/vpa-browser-'.getmypid());
    $boot = (string) shell_exec('VPA_PREVIEW_DIR='.escapeshellarg($dir).' sh '.escapeshellarg(base_path('tools/vpa-preview.sh')).' 10900 2>&1');

    expect($boot)->toMatch('#preview on (http://127\.0\.0\.1:\d+)#');
    preg_match('#preview on (http://127\.0\.0\.1:\d+)\s+pid (\d+)#', $boot, $m);

    try {
        exec('node '.escapeshellarg(base_path('tools/vpa-shots.cjs')).' '.escapeshellarg($m[1]).' '.escapeshellarg($dir.'/shots').' 2>&1', $out, $code);
        expect($code)->toBe(0, implode("\n", array_filter($out, fn ($l) => str_starts_with($l, 'FAIL'))));
    } finally {
        // The PID this test started, never a pattern (CLAUDE.md).
        exec('kill '.(int) $m[2].' 2>/dev/null');
        exec('rm -rf '.escapeshellarg($dir));
    }
});
