<?php

declare(strict_types=1);

/**
 * =============================================================================
 * AN ASSERTION WHOSE TRUTH DEPENDS ON A DISTANCE IS TRUE FOR THE WRONG REASON
 * =============================================================================
 *
 * This is the third face of one defect. Lane PLC found it in NEEDLES — a string
 * that occurs more than once cannot tell its subject from its neighbour. Lane
 * BG found it in MEASUREMENT PROBES — an instrument that could only ever return
 * one answer. This file is the POSITIONAL face: an assertion that runs against
 * `substr($haystack, $offset, <a number>)` is true or false because of where
 * the number falls, and a change with nothing to do with the subject moves it.
 *
 * ── IT FAILS IN BOTH DIRECTIONS, AND ONLY ONE OF THEM IS LOUD ──────────────
 *
 * TOO SHORT, in front of a POSITIVE assertion, is a false RED: the subject is
 * pushed out of the window and the case fails at a line that has not moved.
 * That is annoying and it announces itself. `InstagramPreviewAndPopupTest`'s
 * timer case read `substr($screen, $watch, 900)` and went red because a COMMENT
 * was added to the function it was about.
 *
 * TOO SHORT, in front of a NEGATIVE assertion, IS A FALSE GREEN, and it is
 * silent. `->not->toContain(...)` over a window that stops early does not fail
 * — it stops looking. Measured on this tree:
 *
 *   case                                    region    window    unchecked
 *   CartPageScreenTest                      60,018    24,000    36,018  (60%)
 *   CheckoutPageScreenTest                  47,140    20,000    27,140  (58%)
 *   SlimFooterTest                          20,709    18,000     2,709  (13%)
 *   ProductGalleryImagesTest       the gallery el.     4,000    whatever is past it
 *
 * Those four assert that no un-rendered Blade survived into a screen partial,
 * and that no gallery photograph is painted as a CSS background. Both are
 * shapes that have taken this console and this shop's SEO down before.
 *
 * PROVED, BOTH OF THEM, END TO END:
 *
 *   · a raw `@json([1,2])` planted in cart-page-screen.blade.php past the
 *     24,000-byte cut — old window GREEN, region RED.
 *   · a `background:#fff url('/pad.jpg')` planted in the gallery past the
 *     4,000-byte cut — old window GREEN, element RED.
 *
 * CartPageScreenTest's own mutation note said that plant would be red "and
 * nothing else in the suite notices, which is the whole point of this test
 * existing". It was true only for the first 24,000 bytes.
 *
 * ── THE CURE IS A RELATIONSHIP, NOT A BIGGER NUMBER ────────────────────────
 *
 * A screen's region runs from its own marker to the NEXT screen's
 * (Tests\Support\ConsoleScreen). An element's region is the element
 * (DOMDocument::saveHTML). A function's region is its balanced braces
 * (igpFunctionBody). None of those can be outgrown and none needs a number.
 *
 * ── WHAT THIS CASE PINS, AND WHAT IT DELIBERATELY DOES NOT ─────────────────
 *
 * Not every fixed window is wrong. Thirty-odd sites in this suite slice a few
 * hundred bytes out of a JS file to check a handful of POSITIVE needles, where
 * an over-long window is the risk and an over-short one is a loud failure.
 * Those are left alone, and the census below is the record of that decision.
 *
 * What is refused is the combination that is SILENT: a fixed-width window with
 * a negative assertion inside it.
 */
it('lets no negative assertion sit inside a fixed-width window', function () {
    /*
     * ▲ THE GUARD. `->not->toContain()` over `substr($x, $y, 4000)` cannot fail
     * for anything past byte 4000, so it reports "clean" about material it
     * never read. Four of these existed; all four were repaired, and two were
     * demonstrated to be hiding a real defect first.
     *
     * MUTATION NOTE. Put `substr($html, (int) $from, 24000)` back in
     * CartPageScreenTest in place of ConsoleScreen::region() and this is red
     * naming that file and line. RUN.
     */
    $offenders = [];

    $walk = static function (string $dir) use (&$walk): array {
        $out = [];

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir.'/'.$entry;
            $out = array_merge($out, is_dir($path) ? $walk($path) : (str_ends_with($entry, '.php') ? [$path] : []));
        }

        return $out;
    };

    foreach ($walk(base_path('tests')) as $file) {
        /*
         * ▲ COMMENTS STRIPPED FIRST, AND THE LINE NUMBERS KEPT.
         *
         * The first draft said this was needed because the repaired cases quote
         * the byte counts they replaced. MEASURED, THAT TURNED OUT TO BE FALSE:
         * once the pattern was tightened to require the ASSIGNMENT as well, no
         * comment in the suite matched, and removing the strip changed nothing.
         *
         * It is kept because the shape it guards is one line away. A comment
         * that quotes the whole defect — the assignment AND the negative on it,
         * which is exactly how these repairs are best explained — trips it.
         * RUN, both ways: adding such a comment to SlimFooterTest is red at
         * `SlimFooterTest.php:278` without the strip and green with it. Without
         * this, the cure for that red would be to delete the explanation, which
         * is the trap NeedlesNameOneThingTest records for needles.
         *
         * token_get_all rather than a regex, for the reason that file gives:
         * a comment here routinely holds an apostrophe, a quoted assertion and
         * an unbalanced bracket. Each comment is replaced by its own newlines
         * so that every line number below is still the line in the real file.
         */
        $source = '';

        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $source .= str_repeat("\n", substr_count($token[1], "\n"));

                continue;
            }

            $source .= is_array($token) ? $token[1] : $token;
        }

        $lines = explode("\n", $source);

        /*
         * A window anchored on a VARIABLE offset and closed with a literal
         * length. `substr($s, 0, 200)` for a truncated failure message is not
         * this shape and is not matched: the offset has to be computed.
         */
        /*
         * ▲ THE ASSERTION HAS TO BE ON THE WINDOWED VARIABLE, and the first
         * draft of this guard did not check that — it looked for
         * `->not->toContain` anywhere in the next eighteen lines and reported
         * two sites in CartPageSqueezeTest where the negative assertion is on
         * `app(CartPage::class)->cssVariables()`, a different haystack
         * entirely. A guard for assertions that are true for the wrong reason,
         * itself true for the wrong reason.
         *
         * So the variable is captured and the negative has to be chained on it.
         */
        if (! preg_match_all(
            '/\$([A-Za-z_]\w*)\s*=\s*substr\(\s*\$[A-Za-z_]\w*\s*,\s*(?:\(int\)\s*)?\$[A-Za-z_]\w*\s*,\s*(\d+)\s*\)/',
            $source,
            $matches,
            PREG_OFFSET_CAPTURE
        )) {
            continue;
        }

        foreach ($matches[0] as $i => [$text, $at]) {
            $line = substr_count(substr($source, 0, $at), "\n");
            $after = implode("\n", array_slice($lines, $line, 18));
            $var = preg_quote($matches[1][$i][0], '/');

            if (preg_match('/(?:expect|->and)\(\s*\$'.$var.'\s*\)\s*->not->to(?:Contain|Match)/', $after)) {
                $offenders[] = str_replace(base_path().'/', '', $file).':'.($line + 1)
                    .'  a '.$matches[2][$i][0].'-byte window in front of a negative assertion on $'.$matches[1][$i][0];
            }
        }
    }

    sort($offenders);

    expect($offenders)->toBe([], "a negative assertion cannot see past its own window:\n".implode("\n", $offenders));
});

it('keeps the six repairs bound to a relationship rather than a number', function () {
    /*
     * The finished state of the six, so a later lane cannot quietly put a byte
     * count back in a way the guard above would not catch — for instance by
     * moving the substr further from the assertion than eighteen lines.
     *
     * MUTATION NOTE. Change any one of these six back and this is red naming
     * it. RUN.
     */
    $bound = [
        'tests/Feature/CartPageScreenTest.php' => "ConsoleScreen::region(\$html, 'cartpage')",
        'tests/Feature/CheckoutPageScreenTest.php' => "ConsoleScreen::region(\$html, 'checkoutpage')",
        'tests/Feature/SlimFooterTest.php' => "ConsoleScreen::region(\$html, 'slimfooter')",
        'tests/Feature/ProductGalleryImagesTest.php' => 'saveHTML($node)',
        'tests/Feature/CheckoutPageSpacingTest.php' => "CssRule::at(\$css, '.kbb-checkout .qty .co-q{')",
        'tests/Feature/CartPageSqueezeTest.php' => "CssRule::at(\$css, '.cpg-squeeze .cpg-addrbar .who b,')",
    ];

    /*
     * ▲ str_contains() AND NOT ->toContain($fragment, $message). Pest's
     * toContain is VARIADIC: a second argument is another NEEDLE, not a
     * failure message, so the message text becomes a string the file must also
     * contain and the case fails for a reason that has nothing to do with its
     * subject. Written that way first, and it failed on a file that was
     * correct — which is the very defect this file is about, arriving through
     * the assertion API rather than through a window.
     */
    foreach ($bound as $file => $fragment) {
        expect(str_contains((string) file_get_contents(base_path($file)), $fragment))
            ->toBeTrue($file.' went back to a byte count');
    }
});
