<?php

declare(strict_types=1);

/**
 * The admin console's JavaScript must actually parse.
 *
 * This exists because of a real near-miss. A scripted edit to
 * resources/views/admin/app.blade.php duplicated 3,800 lines, leaving two
 * `const NOT_BUILT_NOTE` declarations in one scope. That is a SyntaxError:
 * the browser refuses the whole script block, so EVERY screen in the console
 * goes blank at once — not the edited one, all of them.
 *
 * Nothing in this suite could see it. Blade is not PHP, so `php -l` does not
 * read it (and CLAUDE.md's command list says not to try). The admin's script
 * is never executed by a feature test. Every assertion about this file matches
 * strings inside it, and a string assertion passes just as happily against a
 * file containing two copies of the string.
 *
 * Several lanes edit this file at once and it is the single largest file in
 * the repo. A parse check is the cheapest guard that catches the whole class:
 * duplicate declarations, an unbalanced brace from a bad merge, a stray
 * template literal. It says nothing about behaviour — only that the console
 * will not refuse to start.
 */
it('parses every script block in the admin console', function () {
    $node = trim((string) shell_exec('command -v node 2>/dev/null'));

    if ($node === '') {
        test()->markTestSkipped('node is not available to parse the script with');
    }

    foreach ([
        'resources/views/admin/app.blade.php',
        'resources/views/admin/partials/media-picker.blade.php',
        'resources/views/admin/partials/product-editor-screen.blade.php',
        'resources/views/admin/partials/media-library-screen.blade.php',
        'resources/views/admin/partials/review-settings-screen.blade.php',
        'resources/views/admin/partials/review-bulk-screens.blade.php',
        'resources/views/admin/partials/html-blocks-screen.blade.php',
        'resources/views/admin/partials/manual-order-screen.blade.php',
        'resources/views/admin/partials/category-tree-screen.blade.php',
        'resources/views/admin/partials/coupon-usage-screen.blade.php',
        'resources/views/admin/partials/coupon-editor-screen.blade.php',
        'resources/views/admin/partials/reviews-io-screen.blade.php',
        'resources/views/admin/partials/review-badges-screen.blade.php',
        'resources/views/admin/partials/review-capsule-screen.blade.php',
        'resources/views/admin/partials/review-assign-screen.blade.php',
    ] as $view) {
        $path = base_path($view);

        if (! is_file($path)) {
            continue;
        }

        $source = file_get_contents($path);

        /*
         * Blade directives are stripped first. @verbatim / @endverbatim sit
         * between the <script> tags in these files — that is how the console
         * stops Blade reading {{ }} inside a JavaScript template literal — and
         * they are Blade, not JavaScript. Left in, the parser stops on
         * @endverbatim and reports a syntax error in a file that is fine.
         */
        $source = preg_replace('/^[ \t]*@(?:end)?verbatim[ \t]*$/m', '', $source);
        $source = preg_replace('/^[ \t]*@include\([^)]*\)[ \t]*$/m', '', $source);

        /*
         * Blade expressions that PRODUCE a value stand in as a literal, because
         * what this test checks is the surrounding JavaScript's shape, not what
         * Laravel will substitute. @json(...) is matched one level of nesting
         * deep, which covers @json(Foo::bar()) — the only form these files use.
         */
        $source = preg_replace('/@json\((?:[^()]|\([^()]*\))*\)/', 'null', $source);
        $source = preg_replace('/\{!!.*?!!\}/s', 'null', $source);
        $source = preg_replace('/\{\{.*?\}\}/s', 'null', $source);

        preg_match_all('/<script>(.*?)<\/script>/s', $source, $blocks);

        expect($blocks[1])->not->toBeEmpty("no script block found in {$view}");

        foreach ($blocks[1] as $i => $script) {
            /*
             * Each block is checked on its own, which is what the browser does:
             * a SyntaxError in one <script> does not stop the next one running,
             * and concatenating them here would invent collisions between two
             * blocks that never share a scope.
             */
            $tmp = tempnam(sys_get_temp_dir(), 'kbbjs') . '.js';
            file_put_contents($tmp, $script);

            $out = [];
            $status = 0;
            exec(escapeshellarg($node) . ' --check ' . escapeshellarg($tmp) . ' 2>&1', $out, $status);

            @unlink($tmp);

            expect($status)->toBe(
                0,
                "script block " . ($i + 1) . " of {$view} does not parse:\n" . implode("\n", $out)
            );
        }
    }
});
