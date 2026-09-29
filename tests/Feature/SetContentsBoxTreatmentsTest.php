<?php

declare(strict_types=1);

use Tests\Support\CssDirection;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 *  THE "WHAT IS IN THIS SET" BOX — three proposed treatments.  (Lane SPL)
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * The owner, 29 September 2026:
 *
 *   "i want to redesign the products list box on the set product page. want
 *    nice light background box type and inside a squeezed products list."
 *
 * Three treatments of that box are in tools/spl-box/. Each one is the block of
 * rules to append to the end of the @once <style> in
 * resources/views/partials/set-contents-panel.blade.php once he picks one —
 * nothing is wired, the shipped partial is byte-identical on this branch, and
 * `StorefrontEnglishUnchangedTest` is the pin that says so.
 *
 * ── WHY A TEST AT ALL FOR SOMETHING THAT IS NOT WIRED ──────────────────────
 *
 * Because the thing that breaks when it IS wired is invisible in an English
 * screenshot, and this repository has already paid for it twice: a physical
 * `margin-left` where a `margin-inline-start` belongs looks perfect in every
 * shot the lane took and puts the element on the wrong side of an Arabic page.
 * Treatment 3 hangs its photographs 30px off the panel's inline-start edge —
 * which is the RIGHT-hand edge on /ar — so it is exactly the shape of rule that
 * has to be read declaration by declaration rather than eyeballed.
 *
 * Tests\Support\CssDirection is the reader Lane G built for the RTL audit, and
 * it is a real CSS declaration reader rather than a grep: `margin-left` occurs
 * both as a declaration and inside comments explaining why a rule keeps one,
 * and a grep cannot tell those apart.
 *
 * MUTATION, and it is a one-character edit: change `margin-inline-start` to
 * `margin-left` on `.ksl-ph` in tools/spl-box/t3-hanging-photos.css and the
 * first case below goes red naming the file, the selector and the property.
 */
function splTreatmentFiles(): array
{
    return [
        't1' => 'tools/spl-box/t1-filled-tint.css',
        't2' => 'tools/spl-box/t2-white-card.css',
        't3' => 'tools/spl-box/t3-hanging-photos.css',
    ];
}

it('has all three treatments on disk with the README that says how to ship one', function () {
    foreach (splTreatmentFiles() as $key => $relative) {
        expect(file_exists(base_path($relative)))->toBeTrue("{$key} is missing: {$relative}");
    }

    $readme = (string) file_get_contents(base_path('tools/spl-box/README.md'));

    expect($readme)->toContain('set-contents-panel.blade.php');
});

it('states no physical direction anywhere in any treatment', function () {
    /*
     * The row is three grid tracks on the INLINE axis and the panel's asymmetric
     * padding, the photographs' overhang and the footing's pull-back are all
     * logical. One `left`, one `right`, one `margin-left` and the box is drawn
     * correctly in English and wrongly in Arabic — which is a defect nobody
     * reviewing an English screenshot can see.
     */
    $physical = [];

    foreach (splTreatmentFiles() as $relative) {
        $physical += CssDirection::physicalIn(base_path(), $relative);
    }

    expect(array_keys($physical))->toBe([], "Physical direction declarations in the box treatments:\n  "
        .implode("\n  ", array_keys($physical)));
});

it('never selects on [dir] in any treatment', function () {
    /*
     * The same rule from the other end. A `[dir="rtl"]` override is how a
     * physical property gets kept alive — it works, and it doubles every rule
     * it touches, and the second copy is the one that stops being maintained.
     * partials/set-contents-panel.blade.php has no [dir] selector in it today
     * and none of these may introduce one.
     */
    foreach (splTreatmentFiles() as $key => $relative) {
        expect(str_contains((string) file_get_contents(base_path($relative)), '[dir'))
            ->toBeFalse("{$key} introduces a [dir] selector");
    }
});

it('makes each treatment a real box: a fill, a radius and inner padding', function () {
    /*
     * "want nice light background box type". A treatment that forgets one of
     * the three is not a box, it is a restyled list — which is the note the
     * owner has already sent back twice about proposals that were too alike.
     *
     * MUTATION: delete the `background:` line from any one treatment's `.ksl`
     * rule and that treatment fails here by name.
     */
    foreach (splTreatmentFiles() as $key => $relative) {
        $declarations = CssDirection::declarationsInFile(base_path($relative));

        $onPanel = array_values(array_filter(
            $declarations,
            fn (array $d): bool => $d['selector'] === '.ksl'
        ));

        $properties = array_column($onPanel, 'property');

        /* in_array() and not ->toContain(): Pest's toContain() takes a LIST of
           needles, so a message passed as its second argument is asserted as a
           second needle and the case fails on its own explanation. Found by
           running it. */
        expect(in_array('background', $properties, true))->toBeTrue("{$key}: the panel has no fill");
        expect(in_array('border-radius', $properties, true))->toBeTrue("{$key}: the panel has no radius");
        expect(in_array('padding', $properties, true))->toBeTrue("{$key}: the panel has no inner padding");
    }
});

it('keeps the squeeze above the legible and tappable floor in every treatment', function () {
    /*
     * "squeezed" has a floor and the floor is the reason the last squeeze
     * stopped where it did: 12.5px is the smallest a member name may be set,
     * 30px the smallest the photograph may be, and `a.ksl-nm` must keep the
     * padding-block / negative margin-block trick that buys 6px of hit area for
     * zero row height. A treatment may not quietly take any of those back.
     *
     * Read off the DECLARATIONS rather than off a rendered page, because a
     * rendered page needs a browser and this has to fail in CI. The rendered
     * figures are measured too — tools/spl-box-shots.cjs refuses to take the
     * screenshot at all if the computed name size is under 12.5px or the
     * computed photograph under 30px, on either width, in either language.
     *
     * MUTATION: set `.ksl-nm{font-size:11px}` in any treatment and it fails.
     */
    foreach (splTreatmentFiles() as $key => $relative) {
        foreach (CssDirection::declarationsInFile(base_path($relative)) as $d) {
            if ($d['property'] === 'font-size' && str_contains($d['selector'], '.ksl-nm')) {
                expect((float) $d['value'])->toBeGreaterThanOrEqual(
                    12.5,
                    "{$key}: {$d['selector']} sets the member name to {$d['value']}"
                );
            }

            if (in_array($d['property'], ['width', 'height'], true) && str_contains($d['selector'], '.ksl-ph')) {
                expect((float) $d['value'])->toBeGreaterThanOrEqual(
                    30.0,
                    "{$key}: {$d['selector']} sets the photograph to {$d['value']}"
                );
            }

            // A treatment must not zero the hit-area padding on the link.
            if ($d['property'] === 'padding-block' && str_contains($d['selector'], 'a.ksl-nm')) {
                expect((float) $d['value'])->toBeGreaterThan(0.0, "{$key}: the link's hit area was squeezed away");
            }
        }
    }
});

it('puts no script and no remote fetch in any treatment', function () {
    /*
     * The block these land in has no JavaScript at all: the fold is HTML's own
     * <details> and the sizing is calc() and one media query. `@import` and a
     * remote url() are the two ways a stylesheet fetches something, and a box
     * in a buy column has no business doing either.
     */
    foreach (splTreatmentFiles() as $key => $relative) {
        $css = (string) file_get_contents(base_path($relative));

        expect(str_contains($css, '@import'))->toBeFalse("{$key} imports another stylesheet");
        expect(str_contains($css, 'javascript:'))->toBeFalse("{$key} carries a javascript: URL");
        expect(str_contains($css, 'expression('))->toBeFalse("{$key} carries an IE expression");
        expect(preg_match('#url\(\s*[\'"]?https?:#i', $css))->toBe(0, "{$key} fetches a remote asset");
    }
});

it('leaves the shipped box exactly as it is until the owner picks one', function () {
    /*
     * CLAUDE.md rule 1. This lane proposes; it does not ship. The three
     * treatments are files under tools/ that the application never loads, and
     * the assertion that matters is that the partial still emits the markup
     * they are written against — the class names are the contract between the
     * proposal and the page.
     *
     * ▲ THIS IS NOT A PIN ON THE UNWIRED STATE. It does not assert that the
     *   panel has no background; that assertion would be correct today and go
     *   red the moment the integrator pastes the chosen treatment in, which is
     *   the trap CLAUDE.md names as having cost this repository three round
     *   trips. It asserts the four hooks every treatment writes against, which
     *   are true now AND after the paste — and which, if a later lane renamed
     *   one of them, would leave the chosen treatment silently styling nothing.
     */
    $panel = (string) file_get_contents(resource_path('views/partials/set-contents-panel.blade.php'));
    $row = (string) file_get_contents(resource_path('views/partials/set-contents-row.blade.php'));

    expect($panel)->toContain('<div class="ksl">')
        ->and($panel)->toContain('class="ksl-rows"')
        ->and($panel)->toContain('class="ksl-more"')
        ->and($panel)->toContain('class="ksl-foot"')
        ->and($row)->toContain('class="ksl-r"')
        ->and($row)->toContain('class="ksl-ph');

    // And the fold is still HTML's own disclosure, with no script behind it.
    expect($panel)->toContain('<details class="ksl-more">')
        ->and($panel)->not->toContain('<script');
});
