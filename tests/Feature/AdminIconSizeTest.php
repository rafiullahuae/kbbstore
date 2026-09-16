<?php

declare(strict_types=1);

/**
 * Admin icons must carry an intrinsic size.
 *
 * ic() built `<svg viewBox="0 0 24 24">` with no width or height. An SVG with a
 * viewBox and no dimensions has no intrinsic size, so in a flex row it takes
 * whatever space is going: the "this order is no longer editable" note on a
 * completed order drew an info icon 468px across, which is what the owner
 * reported. Measured in Chromium against the real markup — 468x468 before,
 * 16x16 after.
 *
 * The admin stylesheet sizes svg only inside particular containers
 * (.nav-item svg, .btn svg, .iconbtn svg and a dozen more), so every one of the
 * 100-odd ic() calls placed outside one of those was unsized. This one was
 * simply the most visible.
 *
 * width/height are presentation attributes rather than a style attribute on
 * purpose: they sit below author CSS in the cascade, so every existing rule
 * still wins and nothing that was already sized moved.
 */
it('gives every admin icon a width and a height', function () {
    $source = file_get_contents(base_path('resources/views/admin/app.blade.php'));

    expect(preg_match('/const ic\s*=\s*\(p\)\s*=>\s*`(<svg[^>]*>)/', $source, $m))
        ->toBe(1, 'the ic() icon helper is gone or no longer recognisable — this check is blind');

    $tag = $m[1];

    expect(str_contains($tag, 'width='))
        ->toBeTrue("ic() emits an svg with no width, so it stretches wherever no CSS sizes it: {$tag}");
    expect(str_contains($tag, 'height='))
        ->toBeTrue("ic() emits an svg with no height, so it stretches wherever no CSS sizes it: {$tag}");

    // A style attribute would beat the container rules that size icons in the
    // nav, buttons and KPI tiles, silently shrinking all of them to 16px.
    expect(str_contains($tag, 'style='))
        ->toBeFalse("ic() sizes its svg with an inline style, which overrides the admin's own icon sizing: {$tag}");
});
