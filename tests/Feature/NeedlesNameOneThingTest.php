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

    /*
     * EVERY WAY THIS SUITE ASSERTS CONTAINMENT, not just Pest's.
     *
     * The run-time instrument hooks Pest expectations, so it sees toContain and
     * toMatch and CANNOT see assertSee: that is a method on
     * Illuminate\Testing\TestResponse which calls PHPUnit's static
     * assertStringContainsString directly, and there is no seam in front of it
     * short of patching vendor or overriding createTestResponse() on the
     * suite's shared TestCase. Measured anyway, statically: 94 distinct
     * assertSee literals in tests/Feature, 57 of them prose a shopper reads,
     * and 144 distinct toMatch patterns. The same mistake is available in all
     * three, so the sweep reads all three.
     *
     * assertSee ESCAPES its needle by default, which does not help here: the
     * strings below contain nothing that escapes, so a bare assertSee of one is
     * exactly as unable to name its element as a bare toContain.
     */
    preg_match_all('/(?:toContain|toMatch|assertSee)\(\s*(\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)")\s*[,)]/', $clean, $matches, PREG_SET_ORDER);

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

/**
 * Sites repaired in round 4, by the element their needle now names.
 *
 * Each was measured as ambiguous at run time, confirmed by blanking the thing
 * under test, and repaired by naming markup. This keeps the repair: a lane
 * shortening one of these back to a bare string loses the only assertion that
 * can see its element.
 *
 * @var array<string, array{0: string, 1: int}>  file => [needle fragment, how many]
 */
const KBB_ELEMENT_ANCHORED = [
    'tests/Feature/BuildMyRoutineTest.php' => ['<span class="kbb-card-nm">', 10],
    'tests/Feature/GridSectionTest.php' => ['<span class="kbb-card-nm">', 1],
    'tests/Feature/CheckoutBrowsedAddTest.php' => ['<div class="n">', 2],
    'tests/Feature/CheckoutLineUpdateTest.php' => ['<div class="n">', 2],
    /*
     * ▲ THIS ROW WAS `['<div class="woocommerce-info" role="status">', 3]` AND
     * LANE SEC REPLACED IT WITH A STRONGER REPAIR OF THE SAME THREE SITES,
     * reached in the same round from the other side.
     *
     * Anchoring the SENTENCE to the element catches a deleted notice. It does
     * not catch a notice that is present and names the wrong product, because
     * the two NAME needles were still running against the whole page — and the
     * cart page carries the product name three more times over, in the line
     * item and in the drawer.
     *
     * slsPageNotice() makes the haystack the element instead, so all three
     * needles are anchored rather than one, and the deleted-notice case is
     * subsumed: the helper returns '' when the div is absent, so every
     * assertion in the case fails. Measured both ways in
     * tests/Feature/SetAndLooseLineStockTest.php's own notes — dropping the
     * @include from store/cart.blade.php takes TWO cases red, and from
     * store/checkout.blade.php one.
     *
     * The fragment is the call with a variable argument, so it counts the three
     * SITES and not the helper's own declaration.
     */
    'tests/Feature/SetAndLooseLineStockTest.php' => ['slsPageNotice($', 3],
    'tests/Feature/AdminProductWritePathTest.php' => ['<meta name="description" content="', 1],
    'tests/Feature/ProductEditorTest.php' => ['<meta name="description" content="', 1],
    'tests/Feature/SeoCrawlSurfaceTest.php' => ['<meta name="description" content="', 1],
    'tests/Feature/YoastImportReachesTheHeadTest.php' => ['<meta name="description" content="', 1],
];

it('keeps the repaired assertions naming the element they are about', function () {
    /*
     * ▲ THE ROUND-4 SWEEP, PINNED WHERE IT LANDED.
     *
     * 140 assertion sites whose needle reads like a sentence a shopper is shown
     * and occurs more than once on the page it ran against. 114 of them have
     * their copies in DIFFERENT elements, which is the condition that makes an
     * assertion unable to see its own subject: blank the element under test and
     * the other copy keeps it green.
     *
     * That is not a proxy anybody has to take on trust. Measured twice, end to
     * end:
     *
     *   · blank `<span class="kbb-card-nm">` in the product card — a shop whose
     *     every product card has no name — and BuildMyRoutineTest was 21 passed.
     *     With the repaired needles it is 5 red.
     *   · stop emitting `<meta name="description">` altogether, so the shop
     *     ships no description on any page, and AdminProductWritePathTest was
     *     28 passed. With the repaired needle it is 1 red.
     *
     * The copies were in the visible element and in an attribute of the same
     * component (`data-name`, `aria-label`) or in a parallel SEO surface
     * (JSON-LD, `<title>`), which is why nothing was noticing.
     *
     * MUTATION, run: shorten any needle below back to its bare string → the
     * count for that file drops and this goes red naming it.
     *
     * ▲ AND ONE ROW IS NOT A MARKUP FRAGMENT. SetAndLooseLineStockTest's three
     * sites are anchored by extracting the element into the haystack rather
     * than by naming it in the needle, which is stronger for the reason set out
     * beside that row. The property this case guards is unchanged: those sites
     * cannot be answered by a copy of the sentence somewhere else on the page.
     */
    $missing = [];

    foreach (KBB_ELEMENT_ANCHORED as $file => [$fragment, $least]) {
        $path = base_path($file);

        expect(file_exists($path))->toBeTrue($file.' has moved; this pin needs its new home');

        $found = substr_count((string) file_get_contents($path), $fragment);

        if ($found < $least) {
            $missing[] = $file.' names '.var_export($fragment, true).' '.$found
                .' times, was repaired to '.$least
                .'. A bare needle there cannot tell its element from the attribute beside it.';
        }
    }

    expect($missing)->toBe([]);
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
