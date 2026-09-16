<?php

declare(strict_types=1);

/**
 * The category and brand banners are thin, and stay thin.
 *
 * The owner asked for half the height, on desktop and on a phone. Measured in
 * Chromium against the real component rendered through Blade, with the real
 * stylesheet:
 *
 *              1280        390
 *   full     512 -> 256   293 -> 146
 *   split    492 -> 246   321 -> 180
 *   tint     512 -> 256   132 ->  83
 *
 * Horizontal overflow 0 at both widths, before and after.
 *
 * A ratio is the only thing that can be asserted without a layout engine, so
 * that is what this pins. The numbers above live in the commit. Three of the
 * four levers matter and each is here: the media ratios, the tint box (the one
 * style with no image, where the copy would otherwise set the height), and the
 * type scale — at the old 60px the tint heading wrapped to two lines and made
 * the band tall on its own, which is why halving the ratio alone was not
 * enough.
 */
function bannerCss(): string
{
    return (string) file_get_contents(base_path('resources/css/kbb/kbb-banner.css'));
}

it('keeps the banner media ratios wide enough to be thin', function () {
    $css = bannerCss();

    // width / height — a larger first term is a shorter band.
    foreach ([
        'full, desktop' => ['/\.kbb-banner--full \.kbb-banner__media\{--kbb-banner-ratio:(\d+) \/ (\d+)\}/', 5.0],
        'split, desktop' => ['/\.kbb-banner--split \.kbb-banner__media\{--kbb-banner-ratio:(\d+) \/ (\d+)\}/', 2.6],
    ] as $label => [$pattern, $minimum]) {
        expect(preg_match($pattern, $css, $m))
            ->toBe(1, "the {$label} banner ratio is gone, so this check is blind");

        $ratio = (int) $m[1] / (int) $m[2];

        expect($ratio)->toBeGreaterThanOrEqual(
            $minimum,
            "the {$label} banner got taller: {$m[1]}:{$m[2]} is {$ratio}, below {$minimum}"
        );
    }
});

it('keeps the tint banner short, since nothing else sets its height', function () {
    $css = bannerCss();

    expect(preg_match('/\.kbb-banner--tint \.kbb-banner__inner\{[^}]*min-height:(\d+)px/', $css, $m))
        ->toBe(1, 'the tint banner no longer declares a min-height');

    expect((int) $m[1])->toBeLessThanOrEqual(
        120,
        "the tint banner's min-height is back up to {$m[1]}px"
    );

    // The heading is the other half: it wrapped to two lines at 60px and set
    // the band's height on its own.
    expect(preg_match('/\.kbb-banner--tint \.kbb-banner__heading\{font-size:clamp\([^,]+,[^,]+,\s*(\d+)px\)/', $css, $h))
        ->toBe(1, 'the tint heading size is gone');

    expect((int) $h[1])->toBeLessThanOrEqual(
        40,
        "the tint heading is back to {$h[1]}px, which wraps and makes the band tall again"
    );
});

it('makes the phone banner shorter too, not just the desktop one', function () {
    $css = bannerCss();

    /*
     * The mobile block deliberately uses a TALLER crop than desktop — a 5:1
     * strip at 360px is 72px and a heading does not sit in it — so this asserts
     * it came down, not that it matches desktop.
     */
    expect(preg_match('/@media \(max-width:640px\)\{(.+?)\n\}/s', $css, $m))
        ->toBe(1, 'the 640px breakpoint block is gone');

    $mobile = $m[1];

    expect(preg_match('/\.kbb-banner--full \.kbb-banner__media\{--kbb-banner-ratio:(\d+) \/ (\d+)\}/', $mobile, $f))
        ->toBe(1, 'the phone override for the full-bleed banner is gone');

    $ratio = (int) $f[1] / (int) $f[2];

    expect($ratio)->toBeGreaterThanOrEqual(
        2.0,
        "the phone full-bleed banner is back to {$f[1]}:{$f[2]}, which is taller than the owner asked for"
    );

    expect(preg_match('/\.kbb-banner--tint \.kbb-banner__inner\{[^}]*padding-block:(\d+)px/', $mobile, $t))
        ->toBe(1, 'the phone tint padding override is gone');

    expect((int) $t[1])->toBeLessThanOrEqual(24, "the phone tint banner's padding is back to {$t[1]}px");
});
