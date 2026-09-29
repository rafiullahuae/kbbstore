<?php

declare(strict_types=1);

/**
 * THE PREVIEW HARNESSES SEED WITHOUT BLOCKING ON A REPL.
 *
 * ── THE DEFECT, IN THE SHAPE IT ACTUALLY ARRIVED IN ─────────────────────────
 *
 * `php artisan tinker <file>` runs the file AND THEN DROPS INTO ITS REPL, which
 * reads stdin until it is closed. Run from anything that leaves a terminal
 * attached — which is how a lane runs its own preview — the seed completes, the
 * log's last line says the rows were written, and `php -S` is never reached. The
 * script simply sits there.
 *
 * That failure reads as a broken seed and is not one: the evidence a person
 * looks at first (`migrate.log`) says the work succeeded, so the time goes into
 * the seed file. Lane UG3 paid for it and fixed `tools/ug3-preview.sh`. ELEVEN
 * OTHER SCRIPTS carried the identical two lines, copied from the same skeleton,
 * and each was one lane away from paying for it again.
 *
 * ── WHY A TEST AND NOT A NOTE ───────────────────────────────────────────────
 *
 * These scripts are written by copying the last one. A note in CLAUDE.md is read
 * by whoever is looking for it; a copied block is copied by everybody. This
 * scans for the SHAPE rather than the script, so a thirteenth harness written
 * next round from the same skeleton is caught on the run that introduces it.
 *
 * The `--execute="require ..."` fallback arm does not enter the REPL and cannot
 * hang, but it carries the redirect too: the two lines are a pair, and a pair
 * that disagrees is the thing the next copy gets wrong.
 *
 * MUTATION NOTE — RAN. `sed -i 's| </dev/null \\| \\|' tools/cp-preview.sh`
 * (removing the redirect from both arms of one script) turns this red naming
 * tools/cp-preview.sh line 39. Restoring it is green.
 */
it('gives every preview seeder a closed stdin, so none can sit in the tinker REPL', function () {
    $scripts = glob(base_path('tools/*.sh')) ?: [];

    expect(count($scripts))->toBeGreaterThan(10,
        'the sweep found almost no shell scripts in tools/, so it is not sweeping anything');

    $offences = [];
    $checked = 0;

    foreach ($scripts as $path) {
        $relative = 'tools/'.basename($path);
        $lines = explode("\n", (string) file_get_contents($path));

        foreach ($lines as $number => $line) {
            /*
             * Prose is not a matcher. This repository has been bitten by a
             * source scan reading its own explanation more than once, and every
             * one of the twelve scripts now carries a comment spelling out both
             * `artisan tinker <file>` and `</dev/null`, so a scan that read
             * comments would report each script as both offending and cured.
             */
            if (preg_match('/^\s*#/', $line) === 1) {
                continue;
            }

            /*
             * THE BARE-FILE FORM ONLY, and the exclusion is the whole precision
             * of this guard. `tinker --execute="..."` evaluates its argument and
             * EXITS — it cannot reach the REPL, so requiring a redirect there
             * would be a rule with no defect behind it, and ten scripts in this
             * directory legitimately use it alone. `tinker <file>` is the form
             * that runs the file and then waits.
             */
            if (preg_match('/artisan"?\s+tinker\s/', $line) !== 1
                || str_contains($line, '--execute')) {
                continue;
            }

            $checked++;

            if (! str_contains($line, '</dev/null')) {
                $offences[] = $relative.' line '.($number + 1);
            }
        }
    }

    expect($checked)->toBeGreaterThan(10,
        'no bare-file `artisan tinker` invocation was found in tools/, so this guard is matching nothing');

    expect($offences)->toBe([],
        'these `artisan tinker` lines can block on the REPL after seeding, which looks '
        .'exactly like a broken seed: '.implode(', ', $offences)
        .'. Append `</dev/null` to the line.');
});
