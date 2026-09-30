<?php

declare(strict_types=1);

/**
 * THE NEEDLE THAT IS PRESENT FOR THE WRONG REASON — a survey instrument. (Lane PLC)
 *
 * ── WHAT IT IS FOR, AND WHY ExpectationsThatCannotFailTest CANNOT DO IT ─────
 *
 * That file sweeps for assertions that are MALFORMED: `->not->toContain($x,
 * $msg)`, where a message becomes a second needle. The shape here is different
 * and it is invisible to any reader of the source, because the assertion is
 * perfectly well formed:
 *
 *     expect($html)->toContain('Your bag is empty.');
 *
 * It was written to prove a notice band had drawn that sentence. The cart
 * DRAWER renders the same sentence, period and all, in `.empty-d` on every page
 * of this shop — so the assertion was green whether or not the band existed,
 * and it survived its own mutation. Measured: 2 occurrences in that page.
 *
 * Nothing static can see that. The needle is fine, the haystack is fine, and
 * the defect only exists in the relationship between them AT RUN TIME. So this
 * measures it at run time.
 *
 * ── HOW ──────────────────────────────────────────────────────────────────────
 *
 * Pest's expectations are pipeable, and a pipe is bound to the Expectation, so
 * `$this->value` is the haystack the assertion is about to run against. Every
 * toContain over a string haystack is recorded with the number of times its
 * needle occurs in it. An assertion whose needle occurs MORE THAN ONCE cannot
 * distinguish the thing it names from the thing it does not.
 *
 * It only ever records. It never fails an assertion and never changes one: the
 * survey has to run over a green suite to be worth anything, and an instrument
 * that changes the thing it measures is not one.
 *
 * ── RUN IT ───────────────────────────────────────────────────────────────────
 *
 *     ./tools/plc-needle-scan.sh          # whole suite, writes the JSONL
 *     php tools/plc-needle-report.php     # reads it, prints the count
 *
 * NOT LOADED BY THE ORDINARY SUITE. phpunit.xml names tests/bootstrap.php and
 * is untouched; the scan generates a config of its own that names this file
 * instead, and this file requires that same bootstrap before adding anything.
 * So nothing here can reach a package or another lane's run.
 */

require __DIR__.'/../tests/bootstrap.php';

/**
 * Where the rows go.
 *
 * INSIDE THIS WORKTREE and named for this lane, because the session scratchpad
 * is shared and a generic filename in it is how one lane comes to report
 * another lane's numbers (CLAUDE.md). One file per process id as well: the
 * suite may be run with more than one worker, and two workers appending to one
 * file interleave partial lines.
 */
$kbbNeedleOut = getenv('KBB_NEEDLE_OUT') ?: __DIR__.'/../storage/plc-logs/needles';

@mkdir($kbbNeedleOut, 0777, true);

$kbbNeedleHandle = fopen($kbbNeedleOut.'/needles-'.getmypid().'.jsonl', 'ab');

if ($kbbNeedleHandle === false) {
    fwrite(STDERR, "plc-needle-scan: could not open the output file\n");

    return;
}

/**
 * The test that is making the assertion.
 *
 * The first frame under tests/ that is not this file. Pest's own machinery sits
 * between the pipe and the test, so the immediate caller is never the answer.
 */
$kbbNeedleOrigin = static function (): array {
    foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 40) as $frame) {
        $file = $frame['file'] ?? '';

        if ($file === '' || ! str_contains($file, '/tests/')) {
            continue;
        }

        if (str_contains($file, '/tests/bootstrap.php') || str_contains($file, '/tests/Pest.php')) {
            continue;
        }

        return [$file, (int) ($frame['line'] ?? 0)];
    }

    return ['', 0];
};

expect()->pipe('toContain', function (Closure $next, mixed ...$needles) use ($kbbNeedleHandle, $kbbNeedleOrigin): void {
    $haystack = $this->value;

    if (is_string($haystack)) {
        [$file, $line] = $kbbNeedleOrigin();

        foreach ($needles as $needle) {
            if (! is_string($needle) || $needle === '') {
                continue;
            }

            fwrite($kbbNeedleHandle, json_encode([
                'file' => $file,
                'line' => $line,
                'needle' => $needle,
                /* THE NUMBER THE WHOLE SURVEY IS ABOUT. */
                'count' => substr_count($haystack, $needle),
                'haystack' => strlen($haystack),
                /* How many needles this single call passed. More than one is
                   the variadic shape ExpectationsThatCannotFailTest sweeps for
                   in the negative; recorded here so the two surveys can be
                   compared rather than guessed at. */
                'needles' => count($needles),
            ], JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE)."\n");
        }
    }

    $next();
});

register_shutdown_function(static function () use ($kbbNeedleHandle): void {
    fclose($kbbNeedleHandle);
});
