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
     * MUTATION: delete the `background:` line from the `.ksl-panel` rule and
     * this goes red.
     *
     * ── TWO PINS ADVANCED DELIBERATELY, 29 September ───────────────────────
     *
     * The selector is `.ksl-panel` and not `.ksl`: the panel is a SETTING now
     * — Appearance → Set → Desktop · Set list — what is drawn → "Draw the list
     * inside a panel" — and SetAppearance::panelClass() puts that class on the
     * element when it is on. Scoping the treatment under it is what lets the
     * switch turn the box back into the bare list it was layered over without
     * nine declarations having to be undone.
     *
     * And the padding is four LOGICAL longhands rather than one physical
     * shorthand. That is the defect this release fixes and the reason the case
     * below changed with it: `padding: a b c d` puts its fourth value on the
     * LEFT in every language, while the chips' pull and the footing's pull-back
     * are both inline-start, so on /ar the chips hung 16px past a panel whose
     * leading padding they thought was 20 and the footing's rule pushed 6px out
     * through the panel's own edge. Measured in Chromium, both widths, both
     * languages — the numbers are in the partial's own comment.
     */
    $properties = [];

    foreach (CssDirection::declarations(splBoxCss()) as $d) {
        if ($d['selector'] === '.ksl-panel') {
            $properties[] = $d['property'];
        }
    }

    /* in_array() and not ->toContain(): Pest's toContain() takes a LIST of
       needles, so a message passed as its second argument is asserted as a
       second needle and the case fails on its own explanation. */
    expect(in_array('background', $properties, true))->toBeTrue('The panel has no fill.');
    expect(in_array('border-radius', $properties, true))->toBeTrue('The panel has no radius.');

    foreach (['padding-block-start', 'padding-block-end', 'padding-inline-start', 'padding-inline-end'] as $side) {
        expect(in_array($side, $properties, true))->toBeTrue(
            "The panel has no {$side}; a `padding` shorthand here is physical and mirrors wrongly on /ar."
        );
    }

    expect(in_array('padding', $properties, true))->toBeFalse(
        'The panel is back on a `padding` shorthand, which is physical: its fourth value is the LEFT '
        .'edge in every language, while the chips and the footing are pulled off the INLINE-START one.'
    );
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
        if ($d['selector'] === '.ksl-panel .ksl-ph' && $d['property'] === 'margin-inline-start') {
            $pull ??= trim($d['value']);
        }
        if ($d['selector'] === '.ksl-panel' && $d['property'] === 'padding-inline-start') {
            $pad ??= trim($d['value']);
        }
    }

    expect($pull)->not->toBeNull('The photographs carry no inline-start pull at all.');
    expect($pad)->not->toBeNull('The panel declares no inline-start padding to hang off.');

    /*
     * ── AND IT IS NOW PINNED AS ARITHMETIC RATHER THAN AS TWO NUMBERS ──────
     *
     * Both are settings from this release, so comparing the two LITERALS would
     * pin only the shipped pair and say nothing about the pair the owner can
     * drag them to. The rule states the pull as `padding + overhang`, and that
     * expression is larger than the padding for every positive overhang — which
     * is the guarantee, and the schema's floor of 1px on `p_over` is what makes
     * it total. So what is pinned here is the SHAPE: the pull must be the
     * panel's own inline-start padding plus something, never a number of its
     * own.
     *
     * MUTATION: write `margin-inline-start:calc(-1 * var(--ksl-over,10px))` —
     * dropping the padding out of the sum, which is exactly the mistake that
     * makes a 20px-padded panel swallow a 10px overhang — and this goes red.
     * SetAppearanceTest's own case walks both sliders' ranges and asserts the
     * rendered geometry, which is the other half of the same proof.
     */
    /* str_contains() and not ->toContain(): Pest's toContain() takes a LIST of
       needles, so a message passed as its second argument is asserted as a
       second needle and the case fails on its own explanation — the note on the
       case above is this file paying for that once already. */
    expect(str_contains((string) $pull, 'var(--ksl-pps,'))->toBeTrue(
        'The chips are not pulled off the PANEL\'s own padding, so the two can be set to values '
        .'that make the overhang vanish.'
    );
    expect(str_contains((string) $pull, 'var(--ksl-over,'))->toBeTrue('The chips carry no overhang term at all.');
    expect($pad)->toBe('var(--ksl-pps,20px)');

    /* The shipped pair, read out of the two fallbacks, so the numbers the shop
       actually renders are still checked and not merely their arrangement. */
    preg_match('/var\(--ksl-pps,\s*([0-9.]+)px\)/', (string) $pull, $a);
    preg_match('/var\(--ksl-over,\s*([0-9.]+)px\)/', (string) $pull, $b);
    expect((float) ($a[1] ?? 0) + (float) ($b[1] ?? 0))->toBeGreaterThan(
        (float) ($a[1] ?? 0),
        'The chips do not hang: the pull must exceed the panel padding, or they sit flush inside it.'
    );
    expect((float) ($b[1] ?? 0))->toBe(10.0, 'The shipped overhang is 10px.');
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
        if (in_array($d['selector'], ['.ksl-nm', '.ksl-panel .ksl-nm'], true) && $d['property'] === 'font-size') {
            $size = $px($d['value']);

            if ($size !== null) {
                expect($size)->toBeGreaterThanOrEqual(12.5, 'A member name is set below the legible floor.');
            }
        }

        if (in_array($d['selector'], ['.ksl-ph', '.ksl-panel .ksl-ph'], true)
            && in_array($d['property'], ['width', 'height'], true)) {
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
