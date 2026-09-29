<?php

declare(strict_types=1);

use Tests\Support\CssDirection;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 *  THE "WHAT IS IN THIS SET" BOX — the shipped drawing.  (Lane SPL, shipped)
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * The owner, 29 September 2026:
 *
 *   "i want to redesign the products list box on the set product page. want
 *    nice light background box type and inside a squeezed products list."
 *
 *   "ok the hanging photos style is fine. please proceed with that"
 *
 * Three treatments were drawn in tools/spl-box/ and he chose the third, so the
 * rules were appended to the @once <style> in
 * resources/views/partials/set-contents-panel.blade.php and the three proposal
 * files were DELETED. This file used to assert about those three files; it now
 * asserts about the one box that ships, which is both the thing that can
 * actually regress and the thing a shopper sees.
 *
 * ── WHY THESE CASES AND NOT A SCREENSHOT ──────────────────────────────────
 *
 * Because what breaks here is invisible in an English screenshot, and this
 * repository has already paid for it twice: a physical `margin-left` where a
 * `margin-inline-start` belongs looks perfect in every shot and puts the
 * element on the wrong side of an Arabic page. This box hangs its photographs
 * 30px off the panel's INLINE-START edge — the right-hand edge on /ar — so it
 * is exactly the shape of rule that has to be read declaration by declaration.
 *
 * Tests\Support\CssDirection is the reader Lane G built for the RTL audit, and
 * it is a real CSS declaration reader rather than a grep: `margin-left` occurs
 * both as a declaration and inside comments explaining why a rule keeps one,
 * and a grep cannot tell those apart.
 *
 * MUTATION, a one-character edit: change `margin-inline-start` to `margin-left`
 * on `.ksl-ph` in the partial and the first case goes red naming the selector
 * and the property. RUN IT.
 */
function splBoxCss(): string
{
    $partial = (string) file_get_contents(
        base_path('resources/views/partials/set-contents-panel.blade.php')
    );

    /* The partial is Blade, not CSS, so the declaration reader is handed the
       <style> block alone rather than the file. One block, by construction —
       it is @once and there is exactly one <style> in the file. */
    expect(substr_count($partial, '<style>'))->toBe(1, 'The panel must carry exactly one style block.');

    $open = strpos($partial, '<style>');
    $close = strpos($partial, '</style>');

    expect($open === false || $close === false)->toBeFalse('The panel has no closed style block.');

    return substr($partial, $open + 7, $close - $open - 7);
}

it('states no physical direction anywhere in the shipped box', function () {
    /*
     * The row is three grid tracks on the INLINE axis, and the panel's
     * asymmetric padding, the photographs' overhang and the footing's pull-back
     * are all logical. One `left`, one `right`, one `margin-left` and the box is
     * drawn correctly in English and wrongly in Arabic.
     */
    $physical = [];

    foreach (CssDirection::declarations(splBoxCss()) as $d) {
        if (CssDirection::isPhysical($d['property'], $d['value'])) {
            $physical[] = $d['selector'].' | '.$d['property'].': '.$d['value'];
        }
    }

    expect($physical)->toBe([], "Physical direction declarations in the shipped set box:\n  "
        .implode("\n  ", $physical));
});

it('never selects on [dir] in the shipped box', function () {
    /*
     * The same rule from the other end. A `[dir="rtl"]` override is how a
     * physical property gets kept alive — it works, it doubles every rule it
     * touches, and the second copy is the one that stops being maintained.
     */
    expect(str_contains(splBoxCss(), '[dir'))->toBeFalse(
        'The set box introduced a [dir] selector; it had none and needs none.'
    );
});

it('is a real box: a fill, a radius and inner padding on the panel', function () {
    /*
     * "want nice light background box type". A drawing that loses one of the
     * three is not a box, it is a restyled list — which is the note the owner
     * sent back twice about proposals that were too alike.
     *
     * MUTATION: delete the `background:` line from the `.ksl` rule and this
     * goes red.
     */
    $properties = [];

    foreach (CssDirection::declarations(splBoxCss()) as $d) {
        if ($d['selector'] === '.ksl') {
            $properties[] = $d['property'];
        }
    }

    /* in_array() and not ->toContain(): Pest's toContain() takes a LIST of
       needles, so a message passed as its second argument is asserted as a
       second needle and the case fails on its own explanation. */
    expect(in_array('background', $properties, true))->toBeTrue('The panel has no fill.');
    expect(in_array('border-radius', $properties, true))->toBeTrue('The panel has no radius.');
    expect(in_array('padding', $properties, true))->toBeTrue('The panel has no inner padding.');
});

it('hangs the photographs outside the panel rather than inside it', function () {
    /*
     * THIS IS THE TREATMENT'S WHOLE IDEA, and it is one subtraction away from
     * being a plain tinted list. The chip's negative inline-start margin must be
     * LARGER than the panel's inline-start padding — equal is a gutter, smaller
     * is an indent, and only larger is an overhang.
     *
     * The numbers ship at 20px of panel padding against a 30px pull (16 against
     * 26 on the phone), so each chip stands 10px outside the panel on both.
     *
     * MUTATION: change the pull to -20px and this goes red saying the chips sit
     * flush rather than hanging.
     */
    $pull = null;
    $pad = null;

    foreach (CssDirection::declarations(splBoxCss()) as $d) {
        if ($d['selector'] === '.ksl-ph' && $d['property'] === 'margin-inline-start') {
            $pull ??= (int) preg_replace('/[^0-9-]/', '', $d['value']);
        }
        if ($d['selector'] === '.ksl' && $d['property'] === 'padding') {
            /* `padding: 12px 14px 11px 20px` — the fourth value is the
               inline-start inset in a physical shorthand that CSS has no
               logical spelling of at this level. */
            $parts = preg_split('/\s+/', trim($d['value'])) ?: [];
            $pad ??= (int) preg_replace('/[^0-9]/', '', (string) ($parts[3] ?? $parts[1] ?? '0'));
        }
    }

    expect($pull)->not->toBeNull('The photographs carry no inline-start pull at all.');
    expect($pad)->not->toBeNull('The panel declares no padding to hang off.');
    expect(abs((int) $pull))->toBeGreaterThan(
        (int) $pad,
        'The chips do not hang: the pull must exceed the panel padding, or they sit flush inside it.'
    );
});

it('keeps the squeeze above the legible and tappable floor', function () {
    /*
     * "squeezed" has a floor, and it is the reason the previous squeeze stopped
     * where it did: 12.5px is the smallest a member name may be set and 30px the
     * smallest the photograph may be drawn. Both are read off the shipped rules
     * rather than trusted, because a squeeze is precisely the change that walks
     * past a floor one pixel at a time.
     */
    /*
     * ▲ THE VALUE MAY BE A var() AND THE FALLBACK IS THE FLOOR THAT MATTERS.
     *   `.ksl-ph{width:var(--ksl-ph,40px)}` — Appearance → Set drives these
     *   through custom properties, so a naive (float) cast reads 0 and this case
     *   fails on a box that is drawn at 40px. Found by running it: the first
     *   version reported "0.0 is not greater than 30.0" against a 40px square.
     *   The fallback is what the shop renders until somebody moves a slider, so
     *   the fallback is what is pinned here; the slider's own floor is
     *   SetAppearance's to enforce, and it does.
     */
    $px = static function (string $value): ?float {
        if (preg_match('/^var\(\s*--[A-Za-z0-9_-]+\s*,\s*([0-9.]+)px\s*\)$/', trim($value), $m) === 1) {
            return (float) $m[1];
        }

        return preg_match('/^([0-9.]+)px$/', trim($value), $m) === 1 ? (float) $m[1] : null;
    };

    foreach (CssDirection::declarations(splBoxCss()) as $d) {
        if ($d['selector'] === '.ksl-nm' && $d['property'] === 'font-size') {
            $size = $px($d['value']);

            if ($size !== null) {
                expect($size)->toBeGreaterThanOrEqual(12.5, 'A member name is set below the legible floor.');
            }
        }

        if ($d['selector'] === '.ksl-ph' && in_array($d['property'], ['width', 'height'], true)) {
            $size = $px($d['value']);

            if ($size !== null) {
                expect($size)->toBeGreaterThanOrEqual(30.0, 'The photograph is drawn below the tappable floor.');
            }
        }
    }
});

it('puts no script and no remote fetch in the box', function () {
    /*
     * The fold is a <details> and stays script-free, and a @import or a remote
     * url() in a style block the storefront inlines is a third-party request on
     * every set page.
     */
    $css = splBoxCss();

    expect(str_contains($css, '@import'))->toBeFalse('The box @imports something.');
    expect((bool) preg_match('#url\(\s*[\'"]?https?:#i', $css))->toBeFalse('The box fetches a remote url().');
    expect(str_contains($css, '<script'))->toBeFalse('The box carries a script.');
});

it('leaves the three proposals deleted', function () {
    /*
     * He chose one. Keeping the two it beat is two more drawings to maintain and
     * a second answer to "what does a set look like" — the thing
     * partials/set-row.blade.php's header exists to prevent. Pinning the
     * FINISHED state rather than an absence-of-work: this is green the day it
     * ships and stays green.
     */
    expect(is_dir(base_path('tools/spl-box')))->toBeFalse(
        'The proposal directory is back; the owner has already chosen.'
    );
});
