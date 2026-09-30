<?php

declare(strict_types=1);

/**
 * An instrument that measures a font may not be unable to fail.
 *                                                                    (Lane BG)
 *
 * ── WHAT WAS WRONG, TWICE, IN TWO DIFFERENT WAYS ───────────────────────────
 *
 * Two scripts in this repository reported a font as present on pages that did
 * not have it, and both printed the answer as a plain fact with no hedge.
 *
 * 1. tools/bg-weight500.cjs set its ruler in
 *
 *        font-family: Poppins, system-ui, sans-serif
 *
 *    and measured its width. On a page WITHOUT Poppins that silently measures
 *    system-ui — and because it is the same system font on every such page, it
 *    reports the SAME plausible numbers across all of them. It printed 543.28px
 *    at weight 400 and 556.77px at 600 for all eight storefront pages, four of
 *    which had no Poppins at all. Three numbers derived from that reached a
 *    docblock and a commit message as measured fact before anyone read the
 *    columns instead of the conclusion.
 *
 * 2. tools/perf-fontcheck.cjs — a script whose entire subject is "does the page
 *    actually get its fonts?" — answered with
 *
 *        check: document.fonts.check('13px Poppins')
 *
 *    which reads as "is Poppins available?" and is not that question. An
 *    unknown family needs nothing loaded, so the answer is true. Measured in
 *    Chromium on a page declaring ZERO faces (no stylesheet, no @font-face,
 *    `document.fonts.size` === 0):
 *
 *        check('13px Poppins')               true
 *        check('13px Fraunces')              true
 *        check('13px KbbNoSuchFamily12345')  true
 *
 *    On the exact failure that script was written to catch — the faces erroring
 *    and the page falling back to the system font — it still printed
 *    `check: true`. It could only ever print true.
 *
 * ── SO THE MECHANICS LIVE IN ONE FILE AND THIS GUARDS THAT FILE ────────────
 *
 * tools/font-probe.cjs holds the two rulers, the explicit per-weight load, and
 * the note on all three traps. A lane writing the next instrument gets them by
 * requiring a module rather than by remembering a story.
 *
 * ── MUTATION NOTES, all four run in this lane's worktree ───────────────────
 *
 *   - put `document.fonts.check(` back in tools/perf-fontcheck.cjs
 *       → 'perf-fontcheck.cjs asks document.fonts.check()'
 *   - delete the control-family ruler from tools/font-probe.cjs
 *       → 'tools/font-probe.cjs no longer measures a control family'
 *   - delete the `document.fonts.load(` loop from tools/font-probe.cjs
 *       → 'tools/font-probe.cjs no longer loads each weight before measuring'
 *   - point tools/bg-weight500.cjs back at an inline probe (drop the require)
 *       → 'bg-weight500.cjs measures a font family without using
 *          tools/font-probe.cjs'
 */

/** Every script that could be measuring a font. */
function fpScripts(): array
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

/** The source with block and line comments removed, so prose cannot exempt a file. */
function fpCode(string $file): string
{
    $src = (string) file_get_contents($file);
    $src = (string) preg_replace('#/\*.*?\*/#s', '', $src);

    return (string) preg_replace('#^\s*//.*$#m', '', $src);
}

it('lets no instrument ask document.fonts.check(), which cannot answer false', function () {
    /*
     * The guard is blind if the directories stop being walked, so the sweep is
     * required to find a plausible number of scripts before it believes a
     * clean result. There were 249 files in tools/ when this was written.
     */
    $scripts = fpScripts();

    expect(count($scripts))->toBeGreaterThan(100,
        'the font-instrument sweep found almost no scripts, so it proved nothing');

    $wrong = [];

    foreach ($scripts as $file) {
        /*
         * COMMENTS STRIPPED FIRST, and it is not a nicety: tools/font-probe.cjs
         * and tools/perf-fontcheck.cjs both QUOTE this call at length in the
         * note explaining why it is wrong. A naive scan reads the explanation
         * as the thing it explains and this guard fails on the two files that
         * are doing it right. Lane MY's SQL-dialect guard was bitten by exactly
         * this and its docs/SUITE-DIALECT-NEEDLES.md says so.
         */
        if (str_contains(fpCode($file), 'document.fonts.check(')) {
            $wrong[] = '  '.basename($file).' asks document.fonts.check()';
        }
    }

    expect($wrong)->toBe([], "an instrument asks a question that cannot answer false:\n"
        .implode("\n", $wrong)
        ."\n\ndocument.fonts.check('13px X') returns TRUE for a family that does not exist"
        ." anywhere — measured on a page declaring zero faces. It cannot distinguish a font\n"
        .'that loaded from one that was never declared. Use probeFamily() from'
        .' tools/font-probe.cjs, which measures a second ruler in a family that cannot exist.');
});

it('keeps both mechanics inside the shared probe', function () {
    /*
     * The module is the fix. If either half of it is removed the fix is gone
     * everywhere at once and silently, which is the whole reason it was worth
     * moving out of one script.
     */
    $probe = base_path('tools/font-probe.cjs');

    expect(is_file($probe))->toBeTrue('tools/font-probe.cjs is gone; every font instrument'
        .' that requires it is now broken');

    $code = fpCode($probe);

    // 1. THE CONTROL RULER — without it a probe cannot tell a rendered family
    //    from the fallback that stood in for it.
    /*
     * str_contains() INSIDE the expectation, not expect($code)->toContain($x, $msg).
     * Pest's toContain takes a LIST OF NEEDLES, so a message passed as the second
     * argument becomes a second string the haystack must contain -- and every one of
     * these cases then fails demanding its own failure message be present in the file
     * it is checking. Written the obvious way, this whole test is red for a reason
     * that has nothing to do with the code it guards. (Caught here; the same shape
     * cost this lane a cycle in round 3.)
     */
    /*
     * THE DECLARATION, not the identifier. `CONTROL_FAMILY` occurs three times
     * in this module -- the const, the export and the use -- so naming it bare
     * is the same shape this round narrowed in PageWashScreenTest: it would
     * survive somebody replacing the declaration's VALUE with a family that
     * does exist, which is the failure that matters. The declaration carries
     * both halves of the claim: the name, and that the name is unclaimable.
     */
    expect((bool) preg_match("/const\s+CONTROL_FAMILY\s*=\s*'KbbNoSuchFamily/", $code))->toBeTrue(
        'tools/font-probe.cjs no longer declares a control family that cannot exist, so it'
        .' cannot tell a font that rendered from a fallback that stood in for it');

    // 2. THE EXPLICIT LOAD — display:swap means a face nothing on the page uses
    //    has not been fetched when document.fonts.ready resolves.
    expect(str_contains($code, 'document.fonts.load('))->toBeTrue(
        'tools/font-probe.cjs no longer loads each weight before measuring, so a weight'
        .' nothing on the page uses will measure the fallback and read as missing');

    // 3. AND THE VERDICT IS PER WEIGHT, not one boolean for the family.
    expect(str_contains($code, 'rendered[w] = a !== b'))->toBeTrue(
        'the probe no longer decides "rendered" by comparing the ruler with the control');
});

it('keeps the teeth on the one instrument that reports a verdict', function () {
    /*
     * tools/perf-fontcheck.cjs is the only font instrument that says pass or
     * fail rather than printing numbers, and round 4 gave it the ability to say
     * fail at all. This is the pin that keeps it.
     *
     * ── IT HAS NO CALLERS, WHICH IS WHY THIS MATTERS RATHER THAN WHY IT DOES
     *    NOT ───────────────────────────────────────────────────────────────
     *
     * Swept in round 5: nothing in .github/workflows, no shell script, no npm
     * script and no other tool runs it — CI runs composer, `php -l` and pest
     * and no node tools at all. So today the exit code reaches a human and
     * nothing else. The first caller somebody writes will be a one-liner, and a
     * one-liner is where an answer gets lost: a guard that fails into a pipe
     * nobody reads is the same defect one level up.
     *
     * Two things have to survive a refactor for that caller to be able to
     * notice anything:
     *
     *   1. A NON-ZERO EXIT CODE. Without it the script is back to being unable
     *      to fail, which is the defect round 4 removed.
     *   2. THE VERDICT ON stderr. It used to print beside the JSON on stdout,
     *      so `… > /dev/null` threw it away. Measured after the change: with
     *      stdout discarded the FAIL line still arrives and the exit is 1.
     *
     * MUTATIONS, both run:
     *   - delete `process.exitCode = 1` → 'no longer sets a non-zero exit code'
     *   - change console.error back to console.log for the verdict
     *       → 'no longer writes its verdict to stderr'
     */
    $code = fpCode(base_path('tools/perf-fontcheck.cjs'));

    expect(str_contains($code, 'process.exitCode = 1'))->toBeTrue(
        'tools/perf-fontcheck.cjs no longer sets a non-zero exit code, so a caller cannot tell'
        .' a page that got its fonts from one that did not — which is the state round 4 found it in.');

    /*
     * str_contains WITH A LITERAL, not preg_match. Written as a regex this read
     *
     *     preg_match('/console\.error\(`\\nFAIL:/', $code)
     *
     * and PHP single quotes collapse that `\\n` to `\n`, which preg then reads as
     * the NEWLINE metacharacter rather than as backslash-n — so it hunted for a
     * real line break where the JavaScript has the two characters of an escape
     * inside a template literal, and reported the verdict missing from a file
     * that has it. The plain literal cannot be misread by a second engine.
     */
    expect(str_contains($code, 'console.error(`\nFAIL:'))->toBeTrue(
        'tools/perf-fontcheck.cjs no longer writes its verdict to stderr, so any caller that'
        .' redirects stdout loses the one line that says what went wrong.');
});

it('makes the font-measuring instruments use the shared probe rather than their own', function () {
    /*
     * Named rather than swept, because "does this script measure a font?" is not
     * something a regex can answer without either missing one or accusing every
     * screenshot tool that happens to set a font-family in a contact sheet.
     *
     * A lane adding a third font instrument adds it here. That is a deliberate
     * cost of one line, paid once, against a class of error that has now cost
     * this repository two rounds.
     */
    $mustUse = [
        'tools/bg-weight500.cjs',
        'tools/perf-fontcheck.cjs',
        'tools/bg-sorina-options.cjs',
    ];

    foreach ($mustUse as $rel) {
        $file = base_path($rel);

        expect(is_file($file))->toBeTrue($rel.' is gone; if it was renamed, move it in this list');

        expect(str_contains(fpCode($file), "require('./font-probe.cjs')"))->toBeTrue(
            $rel.' measures a font family without using tools/font-probe.cjs. Both of this'
            .' repository\'s font instruments have reported a font as present on a page that'
            .' did not have it; the mechanics that stop that are in that module.');
    }
});
