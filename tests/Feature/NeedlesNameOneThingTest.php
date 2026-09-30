<?php

declare(strict_types=1);

/**
 * A NEEDLE THAT IS PRESENT FOR THE WRONG REASON. (Lane PLC, round 3)
 *
 * ── THE DEFECT, AND WHY THE OTHER GUARD CANNOT SEE IT ──────────────────────
 *
 * ExpectationsThatCannotFailTest sweeps for assertions that are MALFORMED —
 * `->not->toContain($needle, $message)`, where the message silently becomes a
 * second needle. This is the positive twin, and it is invisible to any reader
 * of the source, because the assertion is perfectly well formed:
 *
 *     expect($html)->toContain('Your bag is empty.');
 *
 * It was written to prove a notice band had drawn that sentence. The cart
 * DRAWER renders the same sentence, period and all, in its `.empty-d` line on
 * every page of this shop — so the assertion was green whether or not the band
 * existed. It survived its own mutation, which is how it was found.
 *
 * Nothing static can see that in general: the needle is fine, the haystack is
 * fine, and the defect lives only in the relationship between them at run time.
 * So it was MEASURED at run time — tools/plc-needle-scan.php records, for every
 * toContain over a string haystack in the whole suite, how many times its
 * needle occurs in the haystack it ran against.
 *
 * WHAT THAT SURVEY FOUND, on 8,064 passing tests:
 *
 *     toContain assertions recorded over a string haystack : 6,958
 *     assertion SITES whose needle is prose and occurs 2+  :   500
 *       of which read like a sentence a shopper is shown   :   157
 *       of which are bare tokens, ids and code fragments   :   343
 *     files involved                                       :   151
 *
 * Most of the 500 are harmless: a test that wants to know a sentinel reached
 * the page does not care that it reached it nine times. The count is a SCREEN,
 * not a verdict, and each site needs a mutation to settle it. That is why this
 * guard does not fail on the count.
 *
 * ── WHAT IT DOES PIN ────────────────────────────────────────────────────────
 *
 * The strings below were each carried through that mutation and each proved
 * vacuous in a real assertion in this repository. They are strings THIS SHOP
 * RENDERS IN MORE THAN ONE PLACE, so no bare assertion on one of them can name
 * the place it means — whatever test, whatever page, whoever writes it next.
 *
 * The repair in every case was to name markup: the band and the sentence
 * together, `<p class="kbb-placing-title">Order placed</p>` rather than
 * `Order placed`. That is what this requires.
 *
 * ── AND COMMENTS ARE STRIPPED BEFORE IT LOOKS ───────────────────────────────
 *
 * Not tidiness — the first run of this guard flagged the comment in
 * CheckoutRestoreBasketTest that EXPLAINS the defect, which quotes the bad
 * assertion verbatim. A guard that cannot be written about is a guard people
 * delete the explanation of, so the sweep runs over a comment-free copy of each
 * file, via token_get_all() rather than a regex that would have to guess where
 * a comment ends.
 */
use Tests\Support\SqlShape;

/**
 * Strings measured as rendered more than once on the page they are asserted
 * against, with where the copies live.
 *
 * Adding one is how a lane records a defect of this shape it has just paid for.
 * Removing one needs a measurement showing the shop now draws it once.
 */
const KBB_MULTI_RENDERED = [
    'Your bag is empty.' => 'the cart drawer draws it in `.empty-d` on every page of the shop, beside any band that says it',
    'Placing your order' => 'the overlay dialog carries it as aria-label as well as in the script TEXT table',
    'Order placed' => 'the tick draws it in `.kbb-placing-title` and again in the `.kbb-placing-sr` live region',
    'Confirming your payment…' => 'the same pair — visible title and screen-reader live region',
    'still being confirmed' => 'the shopper sentence, and PROSE IN A CSS COMMENT that placing-style ships to the page',
];

/** Every toContain literal in a file, with comments already removed. */
function kbbNeedlesIn(string $file): array
{
    $source = (string) file_get_contents($file);

    /*
     * token_get_all rather than a regex. A comment in this suite routinely
     * contains an apostrophe, a quoted assertion and an unbalanced bracket,
     * and a regex that tries to skip comments gets all three wrong.
     */
    $clean = '';

    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $clean .= is_array($token) ? $token[1] : $token;
    }

    $found = [];

    // Both quote styles, and only a needle that is the WHOLE literal.
    preg_match_all('/toContain\(\s*(\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)")\s*[,)]/', $clean, $matches, PREG_SET_ORDER);

    foreach ($matches as $match) {
        $found[] = $match[2] !== '' ? $match[2] : ($match[3] ?? '');
    }

    return $found;
}

it('never asserts a bare string this shop draws in more than one place', function () {
    $files = glob(base_path('tests/Feature/*.php')) ?: [];

    expect($files)->not->toBe([]);

    $offences = [];
    $examined = 0;

    foreach ($files as $file) {
        if (basename($file) === basename(__FILE__)) {
            continue;
        }

        foreach (kbbNeedlesIn($file) as $needle) {
            $examined++;

            if (! array_key_exists($needle, KBB_MULTI_RENDERED)) {
                continue;
            }

            $offences[] = str_replace(base_path().'/', '', $file)
                .' asserts the bare string '.var_export($needle, true)
                .' — '.KBB_MULTI_RENDERED[$needle]
                .'. Name the markup instead, the way the repaired sites in '
                .'CheckoutPlacingOverlayTest and CheckoutRestoreBasketTest do.';
        }
    }

    /*
     * THE ANTI-VACUITY HALF, and this guard of all guards needs one. If the
     * pattern above stops matching — a Pest upgrade, a reformat that puts the
     * needle on its own line — the sweep silently examines nothing and reports
     * a clean suite for ever. The floor is well under the 6,958 the survey
     * recorded at run time, because this sees only literal needles.
     */
    expect($examined)->toBeGreaterThan(500,
        'the sweep found fewer than five hundred literal toContain needles in tests/Feature, '
        .'which means the pattern has stopped matching rather than that the suite is clean');

    expect($offences)->toBe([]);
});

it('reads a needle out of code and not out of the comment beside it', function () {
    /*
     * The guard above is only true because comments are stripped, and the file
     * that proves it is one this lane wrote: CheckoutRestoreBasketTest carries
     * a comment quoting `toContain('Your bag is empty.')` verbatim, because
     * that is the defect it is explaining. Without the strip, the guard would
     * flag its own documentation and the cure would be to delete the
     * explanation.
     *
     * MUTATION, run: return the raw file_get_contents() from kbbNeedlesIn()
     * instead of the token-filtered copy → the guard above goes red on
     * CheckoutRestoreBasketTest.php, for a string that is in a comment.
     */
    $file = base_path('tests/Feature/CheckoutRestoreBasketTest.php');
    $raw = (string) file_get_contents($file);

    // The comment really is there, or this case is not testing the strip.
    expect($raw)->toContain("A bare `toContain('Your bag is empty.')` PASSED THE MUTATION");

    expect(kbbNeedlesIn($file))->not->toContain('Your bag is empty.');
});

it('still sees a needle that is really asserted', function () {
    /*
     * The other direction, because a stripper that removed everything would
     * make the guard vacuous and green. This asserts the sweep finds a needle
     * this suite genuinely asserts, in the same file as the comment above.
     *
     * MUTATION, run: make kbbNeedlesIn() return [] → this goes red, and so
     * does the floor in the first case.
     */
    $needles = kbbNeedlesIn(base_path('tests/Feature/CheckoutRestoreBasketTest.php'));

    expect($needles)->toContain('Put my basket back')
        ->and($needles)->toContain('class="co-note err" role="alert">Your bag is empty.');
});

it('is written against SQL it would recognise on either engine', function () {
    /*
     * Not about needles in a page — about the one in this lane's own race case,
     * and it is here because the survey run is what surfaced it.
     *
     * CheckoutRestoreBasketTest reproduces a webhook landing mid-press by
     * hooking beforeExecuting and matching moveTo()'s locked read. Matched raw,
     * that needle names `from "orders"` — the SQLite spelling. Under
     * phpunit-mysql.xml the same statement is logged with backticks, the hook
     * never fires, the webhook write never lands, and the case passes while
     * proving nothing. SqlNeedleDialectGuardTest caught it before it was
     * committed; this pins the repair at the site.
     *
     * MUTATION, run: drop the SqlShape::portable() call from that hook → the
     * dialect guard goes red and names the line.
     */
    $source = (string) file_get_contents(base_path('tests/Feature/CheckoutRestoreBasketTest.php'));

    expect($source)->toContain('$sql = SqlShape::portable($query);')
        ->and(SqlShape::portable('select * from `orders` where `orders`.`id` = ?'))
        ->toBe('select * from "orders" where "orders"."id" = ?');
});
