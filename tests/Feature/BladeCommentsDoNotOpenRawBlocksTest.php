<?php

declare(strict_types=1);

/**
 * A Blade comment may not leave a raw-php or verbatim block OPEN, because a
 * comment is not a comment yet when Blade decides where those blocks end.
 *
 * ── THE MECHANISM, READ OFF THE COMPILER RATHER THAN GUESSED ────────────────
 *
 * BladeCompiler::compileString() does these in this order:
 *
 *     1. storeUncompiledBlocks()   // @php…@endphp and @verbatim…@endverbatim
 *                                  // are lifted out and replaced by a placeholder
 *     2. compileComments()         // {{-- … --}} is stripped
 *     3. token_get_all() + the directive and echo passes
 *
 * Step 1 runs FIRST and it does not know what a comment is. So a `@php` written
 * inside a comment pairs with the next REAL `@endphp` further down the file —
 * which lifts out everything between them, INCLUDING the comment's own `--}}`
 * terminator and whatever markup lay in the way. Step 2 then strips from the
 * comment's `{{--` to the next `--}}` it can find, which is some later
 * comment's, deleting the `@if` and `@foreach` openers in between and leaving
 * their `@endif` and `@endforeach` behind.
 *
 * ── WHAT IT COSTS, WHICH IS WHY THIS IS A TEST AND NOT A NOTE ───────────────
 *
 * The template dies with
 *
 *     ParseError: syntax error, unexpected token "endif"
 *
 * pointing at a line that is perfectly correct and is nowhere near the comment.
 * It cost most of an afternoon on resources/views/partials/nav-bar.blade.php —
 * the comment explaining a security fix broke the page the fix was in, and the
 * error blamed the markup. Bisecting found the comment; nothing about the error
 * pointed at it.
 *
 * ── WHAT IS ACTUALLY FORBIDDEN, WHICH IS NARROWER THAN "DON'T SAY @php" ─────
 *
 * A BALANCED pair inside one comment is fine and three comments in
 * store/home.blade.php have carried one for months: step 1 lifts the pair out
 * from inside the comment, leaving the comment intact for step 2. What breaks
 * is an UNBALANCED one — an opener with no closer before the comment ends, or a
 * closer with no opener, which instead closes a real block opened above and
 * swallows the comment's `{{--`.
 *
 * So this simulates step 1 on each comment body and then looks for what is
 * left over. The rule it enforces is exactly the compiler's.
 */
function bladeTemplateFiles(): array
{
    $out = [];

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(resource_path('views'), FilesystemIterator::SKIP_DOTS)
    );

    foreach ($it as $file) {
        if (str_ends_with($file->getFilename(), '.blade.php')) {
            $out[] = $file->getPathname();
        }
    }

    sort($out);

    return $out;
}

it('never leaves a raw-php or verbatim block open inside a Blade comment', function () {
    $files = bladeTemplateFiles();

    expect(count($files))->toBeGreaterThan(
        50,
        'The scan found almost no templates, which means it is looking in the wrong place and would '
        .'pass whatever the views contain.'
    );

    $offenders = [];

    /*
     * THE CHECK IS "DOES A RAW BLOCK STRADDLE A COMMENT BOUNDARY", not "does a
     * comment contain the word @php", and the difference is not pedantry — the
     * looser rule reports two comments in arabic-face.blade.php that are
     * perfectly safe and have compiled for months.
     *
     * Safe, because storeVerbatimBlocks()/storePhpBlocks() are
     * `/(?<!@)@verbatim(\s*)(.*?)@endverbatim/s` and `/(?<!@)@php(.*?)@endphp/s`:
     * an opener with NO closer anywhere in the file matches nothing and lifts
     * nothing, and a closer that appears before its opener does the same. What
     * is fatal is a block that BEGINS inside a comment and ENDS outside it (or
     * the reverse), because that is the one shape that takes the comment's own
     * delimiter away with it.
     *
     * So both sets of ranges are computed on the real source with the real
     * patterns, and a block is an offender exactly when its two ends disagree
     * about which comment they are in.
     */
    foreach ($files as $file) {
        $src = (string) file_get_contents($file);

        preg_match_all('/\{\{--(.*?)--\}\}/s', $src, $cm, PREG_OFFSET_CAPTURE);

        $comments = array_map(
            static fn (array $c): array => [$c[1], $c[1] + strlen($c[0])],
            $cm[0]
        );

        // Which comment an offset falls in, or null. Comments cannot nest, so
        // a single scan answers it.
        $inComment = static function (int $at) use ($comments): ?int {
            foreach ($comments as $i => [$from, $to]) {
                if ($at >= $from && $at < $to) {
                    return $i;
                }
            }

            return null;
        };

        foreach (['/(?<!@)@verbatim(\s*)(.*?)@endverbatim/s', '/(?<!@)@php(.*?)@endphp/s'] as $pattern) {
            preg_match_all($pattern, $src, $bm, PREG_OFFSET_CAPTURE);

            foreach ($bm[0] as $block) {
                $from = $block[1];
                $to = $from + strlen($block[0]) - 1;

                if ($inComment($from) === $inComment($to)) {
                    continue;
                }

                $offenders[] = sprintf(
                    '%s line %d: a raw block starts and ends on opposite sides of a {{-- --}} boundary',
                    str_replace(base_path().'/', '', $file),
                    substr_count(substr($src, 0, $from), "\n") + 1
                );
            }
        }
    }

    /*
     * MUTATION NOTE. Write `@php` on its own inside any Blade comment in
     * resources/views and this lists it — and that template stops compiling.
     * Verified by doing exactly that and reading the ParseError. RUN.
     */
    expect($offenders)->toBe(
        [],
        "A Blade comment leaves a raw-php or verbatim block open. storeUncompiledBlocks() runs BEFORE "
        ."compileComments(), so the opener pairs with the next real closer further down the file, the "
        ."comment's own --}} is swallowed, and the template dies with a ParseError pointing somewhere "
        ."else entirely. Spell it without the @, or balance it inside the comment:\n\n  "
        .implode("\n  ", $offenders)."\n"
    );
});

it('compiles every storefront and admin template', function () {
    /*
     * The outcome, rather than the rule — because the rule above is one way to
     * break a template and this catches the rest of them too. The compiler is
     * driven directly and the result is linted, which is the only way to see a
     * ParseError without rendering every page.
     *
     * MUTATION NOTE. Put an unbalanced @php inside any Blade comment and this
     * names the file. RUN — it is how the nav-bar defect was finally located.
     */
    $compiler = new Illuminate\View\Compilers\BladeCompiler(
        app(Illuminate\Filesystem\Filesystem::class),
        storage_path('framework/views')
    );

    $broken = [];

    foreach (bladeTemplateFiles() as $file) {
        $php = $compiler->compileString((string) file_get_contents($file));

        // php -l reads a file, and the compiled output is not on disk anywhere
        // this test may assume. A tempfile per template, removed immediately.
        $tmp = tempnam(sys_get_temp_dir(), 'kbbblade').'.php';
        file_put_contents($tmp, $php);
        $lint = (string) shell_exec('php -l '.escapeshellarg($tmp).' 2>&1');
        @unlink($tmp);

        if (! str_contains($lint, 'No syntax errors detected')) {
            $broken[] = str_replace(base_path().'/', '', $file).': '
                .trim(explode("\n", $lint)[0] ?? $lint);
        }
    }

    expect($broken)->toBe([], "A template does not compile:\n\n  ".implode("\n  ", $broken)."\n");
});
