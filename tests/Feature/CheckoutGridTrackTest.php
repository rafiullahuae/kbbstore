<?php

declare(strict_types=1);

/**
 * The checkout grid, and why its tracks are not written `1fr`.
 *
 * `1fr` is shorthand for `minmax(auto, 1fr)`, and that `auto` minimum resolves
 * to the track's min-content size. A track is therefore allowed to grow WIDER
 * than the grid box that holds it, and when it does, every block inside it is
 * stretched past the viewport with it.
 *
 * That is what the address box did. `.cka-ad i` is the one-line address, set
 * `white-space:nowrap` with `text-overflow:ellipsis` so a long address reads
 * as a single truncated line. `min-width:0` on the flex item stops the item
 * from refusing to shrink, but it does not reduce the item's min-content
 * CONTRIBUTION — the nowrap line still reports its full natural width to
 * whatever sizes the track above it.
 *
 * Measured in Chromium at a 390px viewport, with the address
 * "Al-Thumama - area 46, street 912, house 80 Doha - Doha - Fujairah - United
 * Arab Emirates" chosen:
 *
 *      document.scrollWidth  671   (client 390)
 *      .co-grid track        651px  on a 390px grid box
 *      aside.summary         w=651   .sec w=649   .kbb-mobile-order w=390
 *
 * which is exactly the report: some sections wide, some not, and the page
 * scrolling sideways. With `minmax(0, 1fr)` the same render measures
 * scrollWidth 390, every `.sec` 348, and the address line ellipsises.
 *
 * The desktop track is spelled the same way on purpose. It has never
 * overflowed, because 594px of column happens to exceed the address line's
 * min-content — "happens to" is the whole problem, and a longer address or a
 * narrower window is all it would take.
 *
 * These assertions are structural because Pest has no layout engine; the
 * measurement lives in this comment and in the commit. What they pin is the
 * one property that makes it hold.
 *
 * MUTATION: change either track back to `1fr`. Red.
 */
it('gives the checkout grid tracks a zero minimum, not a content minimum', function () {
    $css = (string) file_get_contents(resource_path('css/kbb/kbb-checkout.css'));

    preg_match_all('/\.kbb-checkout \.co-grid\{([^}]*)\}/', $css, $m);

    expect($m[1])->not->toBeEmpty('the .co-grid rules moved — re-read this test');

    foreach ($m[1] as $body) {
        if (! str_contains($body, 'grid-template-columns')) {
            continue;
        }

        expect($body)->toMatch('/grid-template-columns:\s*minmax\(0,\s*1fr\)/')
            ->and($body)->not->toMatch('/grid-template-columns:\s*1fr/');
    }
});

it('serves that minimum from the built bundle, not only the source', function () {
    /*
     * The source is not what the browser loads. @vite resolves the manifest
     * and serves a hashed file, so an unbuilt fix is a fix that does not
     * exist on the site — see BuiltCssIsCurrentTest for the full account.
     */
    $manifest = json_decode(
        (string) file_get_contents(public_path('build/manifest.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    $built = $manifest['resources/css/kbb/kbb-checkout.css']['file'] ?? null;

    expect($built)->not->toBeNull('kbb-checkout.css is missing from the manifest');

    $bundle = (string) file_get_contents(public_path('build/'.$built));

    // Minifiers may drop the space after the comma; they may not drop the
    // minmax() itself, because it changes what the track resolves to.
    expect($bundle)->toMatch('/\.co-grid\{[^}]*grid-template-columns:minmax\(0,\s*1fr\) 380px/')
        ->and($bundle)->toMatch('/\.co-grid\{grid-template-columns:minmax\(0,\s*1fr\);/');
});
