<?php

declare(strict_types=1);

/**
 * The JavaScript inside the admin templates parses.
 *
 * ── THE GAP THIS FILLS ──────────────────────────────────────────────────────
 *
 * resources/views/admin/app.blade.php is about 20,000 lines of JavaScript
 * inside a Blade template, and until this file NOTHING parsed it. PHP's linter
 * reads the compiled PHP and is blind to the JS. Pest renders pages, so a
 * screen no test opens is never executed. `php -l` is green on a template whose
 * console cannot boot.
 *
 * The failure mode is the worst kind: a missing bracket anywhere in that file
 * is a BLANK ADMIN SCREEN, with one message in the browser's console and
 * nothing in any log, on a shop whose owner has to apply packages by hand to
 * get a fix back. Two lanes a day edit this file.
 *
 * ── WHY IT COMPILES FIRST ───────────────────────────────────────────────────
 *
 * `node --check` on the raw template fails on `@verbatim`, which is not
 * JavaScript, and on `@json(...)` and `{{ }}`, which are Blade. So the template
 * is compiled (which resolves @verbatim and turns every Blade island into a
 * `<?php … ?>` block), each island is replaced by `0` — valid in expression
 * position, which is where all of them sit — and what is left is handed to
 * node. tools/blade-js-check.php carries the full reasoning and is the same
 * code, runnable by hand.
 *
 * What this cannot catch is a Blade island somewhere other than expression
 * position. What it does catch is the unbalanced brace, the stray quote and the
 * missing comma, which is what a hand-edited 20,000-line script actually
 * suffers from.
 */
function adminTemplatesWithScript(): array
{
    $files = array_merge(
        [resource_path('views/admin/app.blade.php')],
        glob(resource_path('views/admin/partials/*.blade.php')) ?: []
    );

    return array_values(array_filter(
        $files,
        static fn (string $f): bool => str_contains((string) file_get_contents($f), '<script>')
    ));
}

it('parses the JavaScript in every admin template that carries some', function () {
    if (trim((string) shell_exec('command -v node 2>/dev/null')) === '') {
        $this->markTestSkipped('node is not on PATH; the CI image has it.');
    }

    $files = adminTemplatesWithScript();

    expect(count($files))->toBeGreaterThan(
        5,
        'The scan found almost no admin templates with script in them, so it is looking in the '
        .'wrong place and would pass on anything.'
    );

    $broken = [];

    foreach ($files as $file) {
        $report = (string) shell_exec(
            'php '.escapeshellarg(base_path('tools/blade-js-check.php'))
            .' '.escapeshellarg($file).' 2>&1'
        );

        if (! str_contains($report, 'script block(s) parsed')) {
            $broken[] = str_replace(base_path().'/', '', $file).":\n      "
                .trim(str_replace("\n", "\n      ", $report));
        }
    }

    /*
     * MUTATION NOTE. Delete one closing brace anywhere in app.blade.php's
     * console script and this names the file and quotes node's own error with
     * its line number. RUN — on `function odSetContents(it){`, whose closing
     * brace was removed and put back.
     */
    expect($broken)->toBe(
        [],
        "An admin template's JavaScript does not parse. This is a blank screen in the console, with "
        ."one message in the browser and nothing in any log:\n\n  ".implode("\n  ", $broken)."\n"
    );
});
