<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * One admin screen's own region of the rendered console.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * ▲ WRITTEN BECAUSE THREE TESTS EXPRESSED "THIS SCREEN'S REGION" AS A BYTE
 * COUNT, AND EVERY ONE OF THE THREE COUNTS WAS TOO SMALL.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * `CartPageScreenTest`, `CheckoutPageScreenTest` and `SlimFooterTest` each
 * assert that NO un-rendered Blade survived into their partial — `@json(`,
 * `@if (`, `@endif`, `@php` printed as text inside a script block, which is the
 * shape that has taken this console down. Each searched its own region, for the
 * right reason, stated in CartPageScreenTest's own words: "because the console
 * legitimately contains the characters elsewhere".
 *
 * Each expressed that region as `substr($html, $from, <a number>)`. Measured on
 * the rendered console (3,603,103 bytes, 33 screen markers):
 *
 *   screen         true extent   declared window   unchecked
 *   cartpage            60,018            24,000      36,018   (60%)
 *   checkoutpage        47,140            20,000      27,140   (58%)
 *   slimfooter          20,709            18,000       2,709   (13%)
 *
 * These are NEGATIVE assertions, so a window that is too short does not fail —
 * it stops looking. More than half of the cart and checkout partials was not
 * being checked at all, and a directive left raw beyond the cut would have
 * shipped in silence. That is the same defect as an ambiguous needle wearing
 * different clothes: the assertion is true for a reason unrelated to its
 * subject, and only a change that has nothing to do with it reveals that.
 *
 * ── THE REGION IS A RELATIONSHIP, NOT A DISTANCE ───────────────────────────
 *
 * A screen's region runs from its own `var SCREEN = '…';` marker to the NEXT
 * one, or to the end of the document for the last screen. That is what those
 * three tests meant, it cannot be outgrown, and it needs no number.
 *
 * The same correction, for the same reason, as the balanced-brace window in
 * `InstagramPreviewAndPopupTest`: a fixed `substr($screen, $watch, 900)` there
 * went red at a line that had not moved, because a COMMENT pushed it past
 * character 900.
 */
final class ConsoleScreen
{
    /** The marker every screen partial opens its script with. */
    public const MARKER = "var SCREEN = '";

    /**
     * Everything from this screen's marker up to the next screen's.
     *
     * Returns '' when the screen is not on the console at all, so an assertion
     * against it fails rather than passing vacuously — the failure mode a
     * `substr` of a `false` offset would otherwise hand back.
     */
    public static function region(string $console, string $screen): string
    {
        $start = strpos($console, self::MARKER.$screen."';");

        if ($start === false) {
            return '';
        }

        $next = strpos($console, self::MARKER, $start + strlen(self::MARKER));

        return $next === false
            ? substr($console, $start)
            : substr($console, $start, $next - $start);
    }
}
