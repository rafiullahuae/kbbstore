<?php

declare(strict_types=1);

use Tests\Support\CssDirection;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 *  THE MEMBER-NAME UNDERLINE, AND THE TWO CONTROLS THAT DRIVE IT.  (Lane FIN2)
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * On the set contents list — `/product/{a set}/`, the buy column — each member
 * name is a link, drawn with an underline. THREE rules in
 * resources/views/partials/set-contents-panel.blade.php co-operate to draw it,
 * and all three name the same CSS property today:
 *
 *     a.ksl-nm          { border-bottom: 1px solid var(--ksl-linec, …) }   draw
 *     a.ksl-nm:hover    { border-bottom-color: currentColor }              hover
 *     .ksl-noul a.ksl-nm{ border-bottom: 0 }                               off
 *
 * The third is not decoration — it is the storefront half of
 * **Appearance → Set → Desktop / Mobile · "Underline the linked names"**
 * (`SetAppearance::SCHEMA['p_underline_on']`), which emits the class `ksl-noul`
 * on `.ksl` when the owner switches it off. The colour in the first is the
 * storefront half of that screen's line-colour control, through `--ksl-linec`.
 *
 * ── WHY THIS FILE EXISTS, WHICH IS A FIX THAT HAS NOT BEEN MADE YET ────────
 *
 * There is a real, pre-existing defect in the first of those three rules, and it
 * is NOT the one docs/SET-BOX-SQUEEZE.md used to describe. `.ksl-w` is
 * `display:flex;flex-direction:column`, so `a.ksl-nm` is a flex child and is
 * BLOCKIFIED: one block box, one `border-bottom`, painted once at the bottom of
 * the whole name at the width of its LONGEST line. On a name that wraps — seven
 * of eight in the set fixture at 390px — that hairline sits under the second
 * line and runs well past the end of it, which reads as a stray row rule in a
 * box whose whole idea is that it has none. Measured with
 * Range.getClientRects(), one rect per line box: first line bottom 849.3, last
 * line bottom 865.4, painted border 870.5. That document records it under
 * "Found and not fixed" and used to offer a one-line repair:
 *
 *     a.ksl-nm{border-bottom:0; text-decoration:underline; …}
 *
 * That snippet was written before Appearance → Set shipped, and applied AS
 * WRITTEN it silently kills both controls above: `.ksl-noul a.ksl-nm
 * {border-bottom:0}` no longer removes anything, so the owner's "Underline the
 * linked names" switch stops working, and `:hover` recolours a border that is
 * no longer drawn. Two live controls dead, on a shop, with no error anywhere —
 * the exact shape CLAUDE.md rule 1 exists to catch.
 *
 * ── SO THE ASSERTION IS MECHANISM-AGNOSTIC, ON PURPOSE ────────────────────
 *
 * This file does NOT pin `border-bottom`. It reads which property actually
 * draws the underline and then demands that the OFF switch and the HOVER rule
 * name that same property. That is green today, green the moment the defect is
 * repaired properly, and red the moment somebody converts one of the three
 * rules and not the other two.
 *
 * Pinning the finished state rather than the current spelling is the rule
 * CLAUDE.md sets out for exactly this situation: an assertion that goes red
 * when the repair lands is an assertion that can only be satisfied by not
 * repairing it.
 *
 * MUTATION, one line: delete `.ksl-noul a.ksl-nm{border-bottom:0}` from the
 * partial and the second case is red naming the switch. Change only the base
 * rule to `text-decoration:underline` — i.e. paste the doc's snippet — and
 * cases two and three are both red. RUN IT.
 *
 * SEE ALSO SetContentsBoxTreatmentsTest, which pins the box's drawing, and
 * SetAppearanceTest, which pins the control that emits `ksl-noul`.
 */

/**
 * Every declaration in the set panel's one `@once` <style> block.
 *
 * Tests\Support\CssDirection is a real declaration reader rather than a grep,
 * and here that matters as much as it does for direction: this partial's own
 * comments contain the words `border-bottom` and `text-decoration` while
 * explaining the rules, and a grep cannot tell a comment from a declaration.
 *
 * @return list<array{selector: string, property: string, value: string, line: int}>
 */
function fin2PanelDeclarations(): array
{
    $partial = (string) file_get_contents(
        base_path('resources/views/partials/set-contents-panel.blade.php')
    );

    expect(substr_count($partial, '<style>'))->toBe(1, 'The panel must carry exactly one style block.');

    $open = strpos($partial, '<style>');
    $close = strpos($partial, '</style>');

    expect($open === false || $close === false)->toBeFalse('The panel has no closed style block.');

    return CssDirection::declarations(substr($partial, $open + 7, $close - $open - 7));
}

/**
 * The declarations of every rule whose selector list contains $selector
 * exactly, at any nesting (a media query prefixes the stack, so the compare is
 * per comma-separated part rather than on the whole string).
 *
 * @return list<array{selector: string, property: string, value: string, line: int}>
 */
function fin2RuleFor(string $selector): array
{
    $out = [];

    foreach (fin2PanelDeclarations() as $d) {
        foreach (explode(',', $d['selector']) as $part) {
            // Drop any @media / @supports prefix the stack carries.
            $part = trim((string) preg_replace('/^@[^{]*\s/', '', trim($part)));

            if ($part === $selector) {
                $out[] = $d;
            }
        }
    }

    return $out;
}

/**
 * Which property family actually paints the underline on a member-name link.
 *
 * `border` is the first-fragment-only spelling that ships today; `decoration`
 * is the one that paints every line of a wrapped name. Anything else is a link
 * with no underline at all, which is a third answer and its own failure.
 */
function fin2UnderlineMechanism(): string
{
    $found = [];

    foreach (fin2RuleFor('a.ksl-nm') as $d) {
        if (str_starts_with($d['property'], 'border-bottom')
            && ! preg_match('/^(0\w*|none)$/', $d['value'])) {
            $found['border'] = true;
        }

        if (($d['property'] === 'text-decoration' || $d['property'] === 'text-decoration-line')
            && str_contains($d['value'], 'underline')) {
            $found['decoration'] = true;
        }
    }

    expect(array_keys($found))->toHaveCount(
        1,
        'the member name is underlined by '.count($found).' mechanisms at once ('
        .implode(', ', array_keys($found)).'). One rule draws the underline, and the off '
        .'switch and the hover rule have to be able to name it.'
    );

    return (string) array_key_first($found);
}

it('draws the member-name underline with exactly one property family', function () {
    /*
     * Zero mechanisms is a link with nothing under it, which is a visible
     * change to a shipped page. Two is a double rule under every name, and the
     * off switch can only cancel one of them.
     */
    expect(fin2UnderlineMechanism())->toBeIn(['border', 'decoration']);
});

it('lets Appearance → Set → "Underline the linked names" actually turn it off', function () {
    /*
     * THE DEFECT THIS WOULD HAVE CAUGHT, on the shop: the owner switches
     * "Underline the linked names" off on Appearance → Set, the class `ksl-noul`
     * is emitted on `.ksl` exactly as designed, the page is saved, and every
     * member name is still underlined — because the rule that answers that
     * class cancels a property the base rule no longer sets.
     *
     * There is no error, no console warning and nothing in the log. A switch
     * that reports success and moves nothing is indistinguishable from a
     * setting that did not save.
     */
    $mechanism = fin2UnderlineMechanism();
    $off = fin2RuleFor('.ksl-noul a.ksl-nm');

    expect($off)->not->toBeEmpty(
        'nothing answers the `ksl-noul` class that App\Services\SetAppearance::panelClass() '
        .'emits when p_underline_on is off, so that control does nothing on the shop'
    );

    $cancels = false;

    foreach ($off as $d) {
        if ($mechanism === 'border'
            && str_starts_with($d['property'], 'border-bottom')
            && preg_match('/^(0\w*|none|0 none.*)$/', $d['value'])) {
            $cancels = true;
        }

        if ($mechanism === 'decoration'
            && str_starts_with($d['property'], 'text-decoration')
            && str_contains($d['value'], 'none')) {
            $cancels = true;
        }
    }

    expect($cancels)->toBeTrue(
        'the underline is drawn with `'.$mechanism.'` but `.ksl-noul a.ksl-nm` cancels something '
        .'else, so Appearance → Set → "Underline the linked names" is a switch that moves nothing: '
        .json_encode(array_map(static fn (array $d): string => $d['property'].': '.$d['value'], $off))
    );
});

it('recolours the same underline it drew when the name is hovered', function () {
    /*
     * The resting underline is `--ksl-linec` — Appearance → Set's own line
     * colour — and the hover state is meant to take it to the text colour, so
     * the link says it is a link under a pointer. A hover rule that names the
     * other mechanism is a link that does not respond at all.
     */
    $mechanism = fin2UnderlineMechanism();
    $hover = fin2RuleFor('a.ksl-nm:hover');

    expect($hover)->not->toBeEmpty('the member-name link has no hover state at all');

    $prefix = $mechanism === 'border' ? 'border-bottom' : 'text-decoration';

    $recolours = array_filter(
        $hover,
        static fn (array $d): bool => str_starts_with($d['property'], $prefix)
    );

    expect($recolours)->not->toBeEmpty(
        'the underline is drawn with `'.$mechanism.'` and the hover rule recolours something else: '
        .json_encode(array_map(static fn (array $d): string => $d['property'].': '.$d['value'], $hover))
    );
});

it('takes the resting underline colour from the Appearance → Set control', function () {
    /*
     * `--ksl-linec` is written by App\Services\SetAppearance::storefrontCss()
     * from `p_line_c`. A repair that hard-codes the colour — which the snippet
     * in docs/SET-BOX-SQUEEZE.md does, reaching past `--ksl-linec` to the
     * site-wide `--line` — takes the box's own line-colour control off the
     * shop while leaving its slider on the screen.
     */
    $mechanism = fin2UnderlineMechanism();
    $prefix = $mechanism === 'border' ? 'border-bottom' : 'text-decoration';

    $carriers = array_filter(
        fin2RuleFor('a.ksl-nm'),
        static fn (array $d): bool => str_starts_with($d['property'], $prefix)
            && str_contains($d['value'], '--ksl-linec')
    );

    expect($carriers)->not->toBeEmpty(
        'no `'.$prefix.'` declaration on `a.ksl-nm` reads var(--ksl-linec), so Appearance → Set\'s '
        .'line colour no longer reaches the underline it is supposed to drive'
    );
});
