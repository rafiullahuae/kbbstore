<?php

declare(strict_types=1);

/**
 * A measurement may not collapse "there was nothing to measure" into a number
 * that means "it was fine".                                         (Lane BG)
 *
 * ── THE SHAPE, AND THE ONE SITE THAT HAD IT ────────────────────────────────
 *
 * tools/ig-shots.cjs reported the admin content column's overflow as
 *
 *     contentOverflow: (document.querySelector('#content')?.scrollWidth ?? 0)
 *                    - (document.querySelector('#content')?.clientWidth ?? 0),
 *
 * Both halves fall back to 0 when `#content` is not there, so the subtraction
 * is 0 - 0. MEASURED in Chromium on four pages:
 *
 *     a screen that fits                     0
 *     a screen that OVERFLOWS              600
 *     a screen that rendered nothing         0   <- identical to "it fits"
 *     a screen whose selector was renamed    0   <- and this one really did
 *                                                   overflow, by 600
 *
 * So the instrument's failure mode was to report the ALL-CLEAR on the exact
 * defect it exists to catch, and renaming the selector would have turned every
 * future run green without anybody touching the screen. Its neighbours in the
 * same object -- box(), px(), trackClass, columns, gap, radius -- already
 * returned null for an absent element. That line was the only one that did not.
 *
 * It now reports `contentPresent` and a null overflow when there is nothing to
 * measure: null is not a width, so it cannot be read as a good one.
 *
 * ── WHY THIS IS A GUARD AND NOT JUST A FIX ─────────────────────────────────
 *
 * This is the fourth time this lane has found a probe that returns the same
 * answer whether or not the thing under test is true -- after a ruler that
 * measured the system font, document.fonts.check() answering true for fonts
 * that do not exist, and scrollWidth on a block element reporting the
 * container's width as the text's. The shape generalises; the guard names the
 * one mechanical form of it that a regex can catch honestly.
 *
 * ── WHAT IT DELIBERATELY DOES NOT BAN ──────────────────────────────────────
 *
 * `?? 0` and `|| 0` are fine, and common, where zero is the true answer for
 * absence: `washBytes: document.getElementById('kbb-page-wash')?.textContent
 * .length ?? 0` is correct, because the wash emits no element precisely when it
 * has no bytes. What is banned is the pair -- two optional reads of the SAME
 * kind of quantity, both defaulted to 0, SUBTRACTED -- because that is the one
 * form where absence produces a number that reads as health.
 *
 * ── MUTATION NOTES, both run in this lane's worktree ───────────────────────
 *
 *   - put the `(…?.scrollWidth ?? 0) - (…?.clientWidth ?? 0)` line back in
 *     tools/ig-shots.cjs
 *       → 'ig-shots.cjs subtracts two measurements that both default to 0'
 *   - delete `contentPresent` from tools/ig-shots.cjs
 *       → 'ig-shots.cjs no longer says whether #content was there to measure'
 */

/** Source with comments stripped, so prose cannot exempt a file. */
function mzCode(string $file): string
{
    $src = (string) file_get_contents($file);
    $src = (string) preg_replace('#/\*.*?\*/#s', '', $src);

    return (string) preg_replace('#^\s*//.*$#m', '', $src);
}

/** Every browser-side script that could be measuring something. */
function mzScripts(): array
{
    $out = [];

    foreach ([base_path('tools'), base_path('tests/browser')] as $dir) {
        foreach ((array) glob($dir.'/*.{cjs,mjs,js}', GLOB_BRACE) as $file) {
            $out[] = (string) $file;
        }
    }

    sort($out);

    return $out;
}

it('lets no script subtract two measurements that both default to zero when absent', function () {
    $scripts = mzScripts();

    // Blind if the directories stop being walked. There were 250 when written.
    expect(count($scripts))->toBeGreaterThan(100,
        'the measurement sweep found almost no scripts, so it proved nothing');

    /*
     * Two optional reads defaulted to 0 with a minus between them, in either
     * order and across a line break -- which is how the real one was written.
     */
    $pattern = '/\?\?\s*0\s*\)\s*\n?\s*-\s*\(?\s*document\./s';

    $wrong = [];

    foreach ($scripts as $file) {
        if (preg_match($pattern, mzCode($file))) {
            $wrong[] = '  '.basename($file).' subtracts two measurements that both default to 0';
        }
    }

    expect($wrong)->toBe([], "a measurement reports absence as a number that means health:\n"
        .implode("\n", $wrong)
        ."\n\nWhen the element is missing both halves are 0, so the difference is 0 -- the same"
        ." answer as \"it fits\". Measured on the one site that had this: a screen that rendered\n"
        .'nothing and a screen that fitted perfectly both reported 0, and a screen that really'
        .' overflowed by 600 reported 0 as soon as its selector was renamed. Return null when'
        .' there is nothing to measure; null cannot be read as a good width.');
});

it('makes the screen probe say whether there was anything to measure', function () {
    /*
     * The positive half. Banning the bad shape is not the same as keeping the
     * good one: a lane could delete the overflow line altogether and this file
     * would go quiet. `contentPresent` is the thing that makes a null overflow
     * readable rather than mysterious.
     */
    $code = mzCode(base_path('tools/ig-shots.cjs'));

    expect(str_contains($code, 'contentPresent'))->toBeTrue(
        'ig-shots.cjs no longer says whether #content was there to measure, so a null overflow'
        .' cannot be told from a missing one');

    expect(str_contains($code, 'c ? c.scrollWidth - c.clientWidth : null'))->toBeTrue(
        'ig-shots.cjs no longer returns null for an absent #content, so "nothing rendered" is'
        .' back to reading as "nothing overflowed"');
});
