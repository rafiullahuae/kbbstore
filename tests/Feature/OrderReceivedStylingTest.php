<?php

/*
 * The order-received page first shipped using `t`, `s` and `go` as class names.
 * The global stylesheet already defines all three for other components, with
 * selectors specific enough to win, so the action cards rendered with their
 * text wrapping one word per line and a stray pink block across them. The page
 * looked broken while every test passed, because no test looks at pixels.
 *
 * Single-token generic class names are the hazard on a page that loads the
 * whole storefront stylesheet. This pins the fix rather than the symptom.
 */

it('does not reuse generic class names the global stylesheet already claims', function () {
    $files = array_merge(
        [resource_path('views/store/checkout-success.blade.php')],
        glob(resource_path('views/partials/checkout/received-*.blade.php')) ?: [],
    );

    $claimed = ['t', 's', 'go', 'n', 'b', 'i'];
    $offenders = [];

    foreach ($files as $file) {
        preg_match_all('/class="([^"]*)"/', (string) file_get_contents($file), $m);

        foreach ($m[1] as $attr) {
            foreach (preg_split('/\s+/', trim($attr)) ?: [] as $class) {
                if ($class !== '' && in_array($class, $claimed, true)) {
                    $offenders[] = basename($file).': "'.$class.'"';
                }
            }
        }
    }

    // `n` is deliberately excluded from the check below: the section headings
    // reuse the checkout's own `<span class="n">` badge on purpose, inside the
    // same .kbb-checkout scope it was written for.
    $offenders = array_values(array_filter($offenders, fn (string $o): bool => ! str_contains($o, '"n"')));

    expect($offenders)->toBe([], 'Generic class names collide with the global stylesheet: '.implode(', ', $offenders));
});
