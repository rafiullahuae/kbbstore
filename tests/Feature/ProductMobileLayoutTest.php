<?php

/*
 * The product page at phone width: the details accordion's +/- control, and
 * the gutters and gaps under it.
 *
 * Four things were reported from a 390px screen. Three were real, and each is
 * pinned here from BOTH sides -- the stylesheet source AND the compiled bundle
 * the server actually serves -- because this repo's signature failure is a fix
 * that is real in one half and absent in the other. public/build has shipped
 * stale more than once, and a migration in this suite deletes public/build as a
 * side effect, so these read git's committed content rather than the working
 * tree, the same way BuildAssetsTest and CartPageLayoutTest do.
 *
 *   1. The open accordion row drew a short stroke leaning at 45 degrees where a
 *      minus belongs. Nothing was broken in isolation: product-tabs.blade.php
 *      prints "+" on a closed row and "-" (U+2212) on the open one, tabs.js
 *      swaps them on tap, and the stylesheet rotated the open glyph 45deg.
 *      That rotation is correct for a control that stays a plus -- a plus
 *      turned 45deg is a clean multiply sign -- and wrong for every glyph that
 *      is not one. Rotating a MINUS 45deg is the leaning stroke. Measured at
 *      390px before the fix, the open .pm computed
 *      transform: matrix(0.707107, 0.707107, -0.707107, 0.707107, 0, 0).
 *      The icon is now two bars on ::before/::after with the glyph hidden, so
 *      what the script writes can no longer decide what the icon looks like.
 *
 *   2. Customer Reviews sat 36px in from each edge while the buy box, the
 *      details block and the related grid all sat at 20px. .sr carries
 *      `padding:0 16px` from sorina-reviews.css, which ProductController
 *      inlines; inside .wrap, which already pads 20px, that is a second gutter.
 *
 *   3. The related grid's 18px gap is the four-across desktop figure; between
 *      two 170px phone cards it reads as a hole, above all down the rows.
 *
 * The fourth report -- that the related block is inset like the reviews block
 * -- did not reproduce. Measured at 320/360/390/414/600/768/880, #related sits
 * at exactly the same 20px as #details at every one of them. Nothing was
 * changed for it, and the last test below pins that it stays that way.
 *
 * Note on style: assertions that carry an explanation use toBeTrue/toBeFalse,
 * because Pest's toContain() is variadic -- a second string argument there is
 * read as another needle, not as a message.
 */

/** Committed content for a path, as git has it. What ships is what is committed. */
function pdpMobileTracked(string $path): ?string
{
    $out = shell_exec('git -C '.escapeshellarg(base_path()).' show HEAD:'.escapeshellarg($path).' 2>/dev/null');

    return ($out === null || $out === '') ? null : $out;
}

/** The built stylesheet the product page loads, resolved through the Vite manifest. */
function pdpMobileBuiltCss(): string
{
    $manifest = json_decode((string) pdpMobileTracked('public/build/manifest.json'), true);
    $entry = 'resources/css/kbb/kbb-product.css';

    expect($manifest)->toBeArray()->toHaveKey($entry);

    $file = $manifest[$entry]['file'] ?? null;
    expect($file)->not->toBeNull();

    $css = pdpMobileTracked('public/build/'.$file);
    expect($css)->not->toBeNull("public/build/{$file} is referenced by the manifest but is not committed.");

    return (string) $css;
}

/**
 * Whitespace collapsed away and ::before/::after lowered to the single-colon
 * form, so one needle matches both the readable source and esbuild's output.
 */
function pdpMobileFlat(string $css): string
{
    return str_replace('::', ':', (string) preg_replace('/\s+/', '', $css));
}

/** Source and built bundle, keyed by the name to use when one of them fails. */
function pdpMobileBothHalves(): array
{
    return [
        'source' => pdpMobileFlat((string) pdpMobileTracked('resources/css/kbb/kbb-product.css')),
        'built bundle' => pdpMobileFlat(pdpMobileBuiltCss()),
    ];
}

it('draws the accordion icon from bars instead of rotating the glyph', function () {
    foreach (pdpMobileBothHalves() as $where => $flat) {
        expect(str_contains($flat, '.macc-i.open.macc-h.pm{transform:rotate(45deg)}'))->toBeFalse(
            "The {$where} still rotates the open row's glyph 45deg. The glyph is a MINUS there, ".
            'not a plus, so that rotation draws a stroke leaning at 45 degrees rather than a minus.'
        );

        // Two bars, the vertical one standing up when closed and lying down
        // into the horizontal one when open: an exact plus and an exact minus.
        expect(str_contains($flat, '.macc-h.pm:before,.macc-h.pm:after{'))->toBeTrue(
            "The {$where} no longer draws the accordion icon from two bars.");
        expect(str_contains($flat, '.macc-h.pm:after{transform:rotate(90deg)}'))->toBeTrue(
            "The {$where} no longer stands the second bar up, so the closed rows lost their plus.");
        expect(str_contains($flat, '.macc-i.open.macc-h.pm:after{transform:rotate(0'))->toBeTrue(
            "The {$where} no longer lays the second bar down, so the open row lost its minus.");

        // The bars only read as the icon while the glyph itself is invisible.
        expect(str_contains($flat, 'font-size:0;'))->toBeTrue(
            "The {$where} no longer hides the glyph the script writes into .pm.");
    }

    /*
     * Pinned from the other side too. tabs.js belongs to another lane and still
     * writes "+" and "-" into .pm; the markup still renders them server-side.
     * If either ever stops, this CSS is drawing an icon over nothing and the
     * comment above stops describing the page.
     */
    $blade = (string) pdpMobileTracked('resources/views/partials/product-tabs.blade.php');
    $js = (string) pdpMobileTracked('resources/js/kbb/tabs.js');

    expect($blade)->toContain('<span class="pm">')
        ->and($js)->toContain(".querySelector('.pm')");
});

it('lets Customer Reviews sit on the page gutter instead of a second one', function () {
    foreach (pdpMobileBothHalves() as $where => $flat) {
        /*
         * Either spelling of the same rule. T6 rewrote the storefront's
         * physical direction properties as logical ones, and
         * `padding-inline-start`/`-end` resolve to exactly these values in a
         * left-to-right document. The committed bundle still carries the
         * physical spelling because asset builds here are manual, so the two
         * halves differ in the property name and in nothing else. That BOTH
         * halves carry the override is what this pins, and it still does.
         */
        expect(
            str_contains($flat, '.wrap>.sr{padding-left:0;padding-right:0}')
            || str_contains($flat, '.wrap>.sr{padding-inline-start:0;padding-inline-end:0}')
        )->toBeTrue(
            "The {$where} does not drop .sr's own horizontal padding, so the reviews block ".
            'keeps sitting one gutter further in than the rest of the page.'
        );

        // Phone and tablet only. Above 940px (.sr's 900px column plus .wrap's
        // two 20px pads) the column is centred with room to spare and its own
        // padding costs nothing, so desktop is deliberately left alone.
        expect(str_contains($flat, '@media(max-width:940px){.wrap>.sr{'))->toBeTrue(
            "The {$where} no longer scopes the override to the widths where the column is constrained.");
    }

    /*
     * The other half of the fix: the padding being overridden is real, and it
     * arrives on the page after this stylesheet does. ProductController inlines
     * sorina-reviews.css in a <style> below the built bundle, so a bare `.sr`
     * rule in kbb-product.css would lose the tie on source order -- the child
     * combinator is what wins it. If the plugin stylesheet ever drops that
     * padding, this override is dead weight and should go with it.
     */
    $plugin = pdpMobileFlat((string) pdpMobileTracked('resources/css/kbb/sorina-reviews.css'));

    expect(str_contains($plugin, 'padding:016px'))->toBeTrue(
        'sorina-reviews.css no longer pads .sr, so the override in kbb-product.css is overriding nothing.');
});

it('tightens the related grid only where the cards are narrow', function () {
    foreach (pdpMobileBothHalves() as $where => $flat) {
        /*
         * 18px stays the desktop figure; phones get 10px. BOTH NUMBERS ARE
         * UNCHANGED — measured 18px at 900, 1280 and 1680 and 10px below 600,
         * before and after Lane W1 — but they are spelled as `--kbb-gap` now
         * rather than as `gap`, and the fixed `repeat(4,1fr)` is gone.
         *
         * WHY THE VARIABLE AND NOT `gap`. The auto-fill track in kbb.css divides
         * the row by `var(--kbb-gap)` to guarantee the column floor. Declare 18px
         * as a plain `gap` here and the guarantee is computed with 16 while the
         * browser lays out with 18: on a 320px screen that is two 138px tracks
         * plus an 18px gap in a 292px row, which is 2px of horizontal page scroll
         * on the narrowest phone. One number, used by both.
         *
         * The count is derived from the row now — `.rel` showed the same four
         * cards on a 1180px product page as on a 2560px one — so there is no
         * `repeat(4,1fr)` left to pin. SiteWidthSystemTest pins the shared rule
         * and docs/W1-SITE-WIDTH.md has the rendered count at seventeen widths.
         */
        expect(str_contains($flat, '.rel{--kbb-gap:18px}'))->toBeTrue(
            "The {$where} no longer declares the related grid's desktop gap of 18px.");
        expect(str_contains($flat, '@media(max-width:600px){.rel{--kbb-gap:10px}}'))->toBeTrue(
            "The {$where} no longer narrows the related grid's gap at phone width.");
    }
});

it('leaves the related block on the same gutter as the rest of the page', function () {
    /*
     * The report said the related block was inset like the reviews block. It is
     * not, at any phone width: #related is a plain child of .wrap inside .sec,
     * and neither adds horizontal padding, so it inherits the page's 20px and
     * nothing else. This is here so that stays true -- and so nobody "fixes" a
     * gutter that was never wrong.
     */
    $css = pdpMobileFlat((string) pdpMobileTracked('resources/css/kbb/kbb-product.css'));

    /*
     * MOVED BY LANE W1: the fifth of six page-container widths. What this case
     * pins is that #related inherits the page's gutter and adds none of its own,
     * and that is unchanged — the gutter is `--site-gutter` (22px) instead of a
     * literal 20px, and `.rel` still declares no padding at all.
     */
    expect($css)->toContain('.wrap{max-width:var(--site-max);margin-inline:auto;padding-inline:var(--site-gutter)}')
        ->and($css)->toContain('.sec{padding:34px0;')
        ->and($css)->not->toContain('.rel{padding')
        ->and($css)->not->toContain('.sec{padding:34px20px');
});
