<?php

declare(strict_types=1);

/**
 * The footer's big name on /ar, cut off at the bottom. (16 October 2026)
 *
 * What it looked like on the shop: "كي بيوتي بليس" across the foot of every
 * Arabic page with its bottom third missing -- the tails of ي and the bowl of
 * س sliced flat (the owner: "the logo is cutting or hiding from downside").
 * The name is painted with background-clip:text, which paints ONLY inside the
 * name's own box, and that box ended .06em under the line: enough for the
 * English "y", nowhere near the ~.35em Arabic letters reach. Measured in
 * Chromium with tools/kfn-clip.cjs: 13-14px cut at 320-390, 51-53px at
 * 1280-1600; 0px at every width with the rule below.
 *
 * MUTATION: delete the [dir="rtl"] rule, or set it under .41em -- red.
 * English is pinned too: its .06em is the look it has always had.
 */
it('gives the Arabic footer name room for letters that reach below the line, and leaves English alone', function () {
    $css = (string) file_get_contents(resource_path('css/kbb/kbb.css'));

    expect(preg_match('/\[dir="rtl"\] \.kft-name\{padding-bottom:([\d.]+)em\}/', $css, $m))->toBe(1);
    expect((float) $m[1])->toBeGreaterThanOrEqual(0.41);

    // The base (English) rule still ends .06em under the line.
    expect(preg_match('/\n\.kft-name\{[^}]*padding-bottom:\.06em;/', $css))->toBe(1);
});
