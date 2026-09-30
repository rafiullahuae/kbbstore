<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * One CSS rule, from its selector to its own closing brace.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * ▲ WRITTEN BECAUSE TWO CASES SLICED A RULE WITH A BYTE COUNT AND THE TWO
 * COUNTS WERE WRONG IN OPPOSITE DIRECTIONS.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Measured on this tree:
 *
 *   CheckoutPageSpacingTest   `.kbb-checkout .qty .co-q{`
 *       rule 374 bytes, window 320 — 54 BYTES OF THE RULE UNCHECKED. The
 *       assertion there is `->not->toMatch('/width:23px/')`, so the short
 *       window did not fail, it stopped looking: a `width:23px` in the last
 *       fifty-four bytes of that rule shipped in silence, and the case's own
 *       mutation note ("put 23px back in that block. RED") was true only for
 *       the first 320.
 *
 *   CartPageSqueezeTest       `.cpg-squeeze .cpg-addrbar .who b,`
 *       rule 135 bytes, window 190 — the window OVERSHOOTS 55 bytes INTO THE
 *       NEXT RULE. Its `->not->toContain('mask-image')` would fail for a mask
 *       on a rule it is not about, and its positive needles could be answered
 *       by one.
 *
 * Same defect, both directions: a distance standing in for a relationship. A
 * rule's extent is its braces, which cannot be outgrown and needs no number.
 *
 * See `Tests\Support\ConsoleScreen` for the same correction on a screen's
 * region, and `AssertionsDoNotDependOnADistanceTest` for the class.
 */
final class CssRule
{
    /**
     * The rule that starts at `$selector`, up to and including its `}`.
     *
     * Returns '' when the selector is not there, so an assertion against it
     * fails rather than passing vacuously.
     *
     * NESTING IS NOT HANDLED and does not need to be: this is for the flat
     * declaration blocks these two cases read. A rule inside an `@media` block
     * is found by its selector exactly as before; what it will not do is walk
     * out of an enclosing at-rule, because it stops at the first closing brace
     * that balances its own opening one.
     */
    public static function at(string $css, string $selector): string
    {
        $start = strpos($css, $selector);

        if ($start === false) {
            return '';
        }

        $open = strpos($css, '{', $start);

        if ($open === false) {
            return '';
        }

        $depth = 0;

        for ($i = $open, $n = strlen($css); $i < $n; $i++) {
            if ($css[$i] === '{') {
                $depth++;
            } elseif ($css[$i] === '}') {
                $depth--;

                if ($depth === 0) {
                    return substr($css, $start, $i - $start + 1);
                }
            }
        }

        return substr($css, $start);
    }
}
