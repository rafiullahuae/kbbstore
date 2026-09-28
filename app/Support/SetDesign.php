<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Which drawing of a set row is live. (Lane SET)
 *
 * ── WHY THIS CLASS EXISTS AT ALL ───────────────────────────────────────────
 *
 * The owner has been shown four designs for the set row and had not picked one
 * when the module shipped. Everything else about a Set is independent of that
 * choice and is finished; the drawing is not, so it is the one thing that is
 * named in ONE place rather than assumed in seven.
 *
 * resources/views/partials/set-row.blade.php switches on what this returns.
 * Swapping in the chosen design is: add a `@case` to that switch, and change
 * the constant below to name it. Two edits, in two files, and nothing else in
 * this repository moves.
 *
 * ── WHY A CONSTANT AND NOT A SETTING ───────────────────────────────────────
 *
 * A setting would be a fifth thing the owner has to find and set, on a screen
 * that would then have to exist, to choose between four options only one of
 * which will ever be wanted once he has chosen. CLAUDE.md's rule is that any
 * NEW setting ships at the value the page already has so applying the package
 * moves nothing — and the value this "setting" would ship at is the only value
 * it would ever hold. A constant says what is true and costs no screen, no
 * migration, no default to get wrong and no way for a stray row in `settings`
 * to make one shop draw a set differently from another.
 *
 * ── AND WHY IT IS NOT JUST HARD-CODED IN THE PARTIAL ───────────────────────
 *
 * Because a test has to be able to read it. SetRowSurfacesTest asserts the set
 * partial is included exactly once by each surface and says nothing about what
 * it draws, precisely so the design can be swapped without a test going red for
 * the wrong reason — but a NAMED design is something a later test about the
 * chosen design can assert against without reading Blade source.
 */
final class SetDesign
{
    /**
     * "Option A, the fanned stack" — THE ONE THE OWNER CHOSE, 28 September 2026:
     *
     *   "A Fanned Stack is fine, for cart panel, cart page and checkout page
     *    please apply this design everywhere. and the thumnail we don't need any
     *    label on thubmail etc. and What's inside i need with a small on screen
     *    popup with products names list. a tiny popup box."
     *
     * A row of overlapping circular member thumbnails, picture only and no
     * label of any kind, and one button that opens a tiny popup listing the
     * member names and quantities.
     */
    public const FAN = 'fan';

    /** The live design. One line to change if he ever picks another. */
    private const CURRENT = self::FAN;

    public static function current(): string
    {
        return self::CURRENT;
    }
}
