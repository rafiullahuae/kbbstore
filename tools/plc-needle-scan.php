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

        /*
         * The instrument's own frames are not the caller. NeedleScan and
         * RecordingTestResponse live under tests/Support, so without this every
         * assertSee row was filed against NeedleScan.php:75 — a survey that
         * blames itself for every hit it finds.
         */
        if (str_contains($file, '/tests/bootstrap.php')
            || str_contains($file, '/tests/Pest.php')
            || str_contains($file, '/tests/Support/')) {
            continue;
        }

        return [$file, (int) ($frame['line'] ?? 0)];
    }

    return ['', 0];
};

/**
 * The innermost open tag before each occurrence, as a short fingerprint.
 *
 * Deliberately crude: the last `<tag ... class="...">` before the match, cut to
 * its tag name and class list. That is enough to say "these two copies are in
 * the same kind of box" or "one is in a .co-note and the other in a .kc-nm",
 * which is the only question being asked of it. A copy that is inside no tag at
 * all — prose in a comment, or a value in a script's string table — comes back
 * as the text that precedes it, which is just as telling.
 */
$kbbNeedleContexts = static function (string $haystack, string $needle): array {
    $seen = [];
    $offset = 0;

    while (($at = strpos($haystack, $needle, $offset)) !== false) {
        $before = substr($haystack, max(0, $at - 400), min($at, 400));

        /*
         * ▲ IS THE COPY INSIDE AN ATTRIBUTE? Decided first, because getting it
         * wrong is what made the first fingerprints unreadable.
         *
         * If there is a `<` after the last `>`, the tag this copy sits in has
         * not closed yet — so the copy is in an ATTRIBUTE VALUE (alt=, title=,
         * data-name=, content=) and the "last complete tag before it" is some
         * unrelated element from further up the page. That reported a product
         * name in an `alt` as living in the previous card's price span, which
         * is nonsense and made every such site look like a disagreement for the
         * wrong reason.
         *
         * It IS still a disagreement — a name in an alt and the same name in
         * the visible title are two different things, and blanking the visible
         * one leaves the assertion green — but it has to be labelled for what
         * it is or it cannot be judged.
         */
        $lastOpen = strrpos($before, '<');
        $lastClose = strrpos($before, '>');

        if ($lastOpen !== false && ($lastClose === false || $lastOpen > $lastClose)) {
            $open = substr($before, $lastOpen);
            $tagName = preg_match('/^<([a-zA-Z][a-zA-Z0-9-]*)/', $open, $t) === 1 ? $t[1] : '?';
            $attr = preg_match('/([a-zA-Z-]+)="[^"]*$/', $open, $a) === 1 ? $a[1] : 'attr';

            $seen[] = '@'.$tagName.'['.$attr.']';
            $offset = $at + 1;

            continue;
        }

        $tag = '';

        if (preg_match_all('/<([a-zA-Z][a-zA-Z0-9-]*)([^>]*)>/', $before, $m, PREG_SET_ORDER)) {
            $last = end($m);
            $class = '';

            if (preg_match('/class="([^"]{0,70})"/', $last[2], $c)) {
                $class = '.'.str_replace(' ', '.', trim($c[1]));
            }

            $tag = $last[1].$class;
        }

        $seen[] = $tag !== '' ? $tag : ('«'.trim(substr($before, -40)).'»');
        $offset = $at + 1;
    }

    return $seen;
};

expect()->pipe('toContain', function (Closure $next, mixed ...$needles) use ($kbbNeedleHandle, $kbbNeedleOrigin, $kbbNeedleContexts): void {
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
                'count' => $occurrences = substr_count($haystack, $needle),
                /*
                 * WHERE EACH COPY SITS, which is what turns the count from a
                 * screen into evidence.
                 *
                 * A needle that occurs twice inside the same kind of element is
                 * usually a list with two rows in it, and harmless. A needle
                 * whose copies sit in DIFFERENT elements — the band and the
                 * drawer, the visible title and the screen-reader line, the
                 * shopper's sentence and a CSS comment — is one an assertion
                 * cannot pin to the thing it names. So the innermost open tag
                 * before each copy is recorded, and the sites worth a human are
                 * the ones whose contexts disagree.
                 *
                 * Only for the band where it is affordable and meaningful: a
                 * needle that occurs forty times is a table, not a defect.
                 */
                'contexts' => ($occurrences >= 2 && $occurrences <= 12)
                    ? $kbbNeedleContexts($haystack, $needle)
                    : [],
                'haystack' => strlen($haystack),
                /* How many needles this single call passed. More than one is
                   the variadic shape ExpectationsThatCannotFailTest sweeps for
                   in the negative; recorded here so the two surveys can be
                   compared rather than guessed at. */
                'needles' => count($needles),
                'kind' => 'toContain',
            ], JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE)."\n");
        }
    }

    $next();
});

/*
 * ── AND toMatch, WHICH IS THE SAME MISTAKE WITH A REGEX IN IT ──────────────
 *
 * The suite uses toMatch 158 times, more often than this lane expected, and a
 * pattern is if anything EASIER to satisfy for the wrong reason than a literal:
 * `/class="co-note[^"]*err/` is happy with any of them. The count recorded is
 * preg_match_all's, so the reading is identical — 2+ means the pattern cannot
 * pin the thing it names.
 *
 * A pattern that does not compile is recorded with a count of -1 rather than
 * dropped, because an invalid pattern is its own kind of assertion that cannot
 * do what it claims.
 */
expect()->pipe('toMatch', function (Closure $next, mixed ...$arguments) use ($kbbNeedleHandle, $kbbNeedleOrigin, $kbbNeedleContexts): void {
    $haystack = $this->value;
    $pattern = $arguments[0] ?? null;

    if (is_string($haystack) && is_string($pattern)) {
        [$file, $line] = $kbbNeedleOrigin();

        $count = @preg_match_all($pattern, $haystack);

        fwrite($kbbNeedleHandle, json_encode([
            'file' => $file,
            'line' => $line,
            'needle' => $pattern,
            'count' => $count === false ? -1 : $count,
            'haystack' => strlen($haystack),
            'needles' => 1,
            'kind' => 'toMatch',
            'contexts' => [],
        ], JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE)."\n");
    }

    $next();
});

/*
 * ── AND assertSee, THROUGH THE SUITE'S OWN TestCase ────────────────────────
 *
 * 94 distinct assertSee literals in tests/Feature, 57 of them reading as a
 * sentence a shopper is shown — the same mistake is as available here as in
 * toContain, and until now it was swept statically because assertSee could not
 * be hooked. It can: MakesHttpRequests::createTestResponse() is the documented
 * override point, Tests\TestCase uses it, and Tests\Support\NeedleScan is the
 * switch. Off, it is one null check per response and the framework's own
 * TestResponse comes back.
 *
 * THE NEEDLE IS RECORDED RAW AND COUNTED ESCAPED. assertSee escapes its
 * argument by default and matches the escaped form, so counting the raw string
 * would under-report any needle containing `&`, `<`, `>` or a quote — it would
 * read 0 occurrences on a page that plainly shows it, and the survey would call
 * a perfectly good assertion invisible.
 */
Tests\Support\NeedleScan::recordWith(static function (string $value, string $haystack, bool $escaped) use ($kbbNeedleHandle, $kbbNeedleOrigin, $kbbNeedleContexts): void {
    [$file, $line] = $kbbNeedleOrigin();

    $matched = $escaped ? e($value) : $value;
    $count = substr_count($haystack, $matched);

    fwrite($kbbNeedleHandle, json_encode([
        'file' => $file,
        'line' => $line,
        'needle' => $value,
        'count' => $count,
        'haystack' => strlen($haystack),
        'needles' => 1,
        'kind' => 'assertSee',
        'escaped' => $escaped,
        'contexts' => ($count >= 2 && $count <= 12) ? $kbbNeedleContexts($haystack, $matched) : [],
    ], JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE)."\n");
});

register_shutdown_function(static function () use ($kbbNeedleHandle): void {
    fclose($kbbNeedleHandle);
});
