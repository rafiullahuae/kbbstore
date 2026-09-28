<?php

/*
 * Parse the JavaScript inside a Blade template, after Blade has compiled it.
 *
 *     php tools/blade-js-check.php resources/views/admin/app.blade.php
 *
 * ── WHY THIS IS NOT JUST `node --check` ON THE FILE ─────────────────────────
 *
 * The admin console is ~20,000 lines of JavaScript living inside a Blade
 * template, and none of it is parsed by anything before it reaches a browser.
 * PHP's linter reads the compiled PHP and is blind to the JS; Pest renders
 * pages and a screen nobody's test opens is never executed. A missing bracket
 * there is a BLANK ADMIN SCREEN with one message in the browser console and
 * nothing anywhere else.
 *
 * `node --check` on the raw template fails immediately, and on the two things
 * that are not the point: `@verbatim`, which is not JavaScript, and
 * `@json(...)` / `{{ }}`, which are Blade. So:
 *
 *   1. compile the template, which resolves @verbatim and turns every Blade
 *      island into a `<?php … ?>` block;
 *   2. replace each of those blocks with `0` — valid in expression position,
 *      which is where every one of them sits (`var x = @json(…)`,
 *      `'…' + {{ $n }} + '…'`), so the surrounding JS keeps its shape;
 *   3. concatenate the <script> bodies and hand them to node.
 *
 * What it therefore CANNOT catch: a Blade island that is not in expression
 * position, and a syntax error whose only symptom is inside one of them. Both
 * are rare and both are visible to PHP's own linter. What it does catch is the
 * unbalanced brace, the stray quote and the missing comma — which is what a
 * hand-edited 20,000-line script actually suffers from.
 */
require __DIR__.'/../vendor/autoload.php';

$file = $argv[1] ?? null;

if ($file === null || ! is_file($file)) {
    fwrite(STDERR, "usage: php tools/blade-js-check.php <template.blade.php>\n");
    exit(2);
}

$compiler = new Illuminate\View\Compilers\BladeCompiler(
    new Illuminate\Filesystem\Filesystem,
    sys_get_temp_dir()
);

$php = $compiler->compileString((string) file_get_contents($file));
$js = (string) preg_replace('#<\?php.*?\?>#s', '0', $php);

preg_match_all('#<script>(.*?)</script>#s', $js, $m);

if ($m[1] === []) {
    fwrite(STDERR, "no <script> blocks in {$file}\n");
    exit(2);
}

$out = tempnam(sys_get_temp_dir(), 'bladejs').'.js';
file_put_contents($out, implode("\n;\n", $m[1]));

$report = (string) shell_exec('node --check '.escapeshellarg($out).' 2>&1');
@unlink($out);

if (trim($report) !== '') {
    fwrite(STDERR, $report);
    exit(1);
}

echo count($m[1]), " script block(s) parsed in {$file}\n";
