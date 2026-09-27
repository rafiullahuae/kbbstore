<?php

declare(strict_types=1);

/**
 * =============================================================================
 * sys_get_temp_dir() IS SHARED BETWEEN LANES, WHATEVER THE BOOTSTRAP INTENDED
 * =============================================================================
 *
 * `tests/bootstrap.php` gives each process a private temp tree and exports
 * TMPDIR at it — which works, and is what makes Chromium runnable. It ALSO
 * calls `ini_set('sys_temp_dir', …)` and used to claim, in a paragraph of its
 * own, that this made `sys_get_temp_dir()` answer the private tree, so "a test
 * globbing the temp root globs its own".
 *
 * That claim was false. Measured inside a running test:
 *
 *     sys_get_temp_dir()       => "/tmp"                        SHARED
 *     ini_get('sys_temp_dir')  => ""                            never set
 *     getenv('TMPDIR')         => "/tmp/kbb-run-<pid>-<rand>"   this did take
 *
 * `sys_temp_dir` is PHP_INI_SYSTEM — the same reason that comment correctly
 * refuses to set `upload_tmp_dir` at runtime — so the call returns false and
 * the function goes on answering the shared root.
 *
 * ── WHAT IT COST, TWICE ─────────────────────────────────────────────────────
 *
 * `GmImportAcceptsZipTest` and `GpAddressesLandTest` each swept their fixtures
 * in `afterEach` with `glob(sys_get_temp_dir().'/kbb-XX-*')`. Correct for one
 * suite; with several lane worktrees running at once it means ONE LANE DELETING
 * ANOTHER LANE'S IN-FLIGHT FIXTURE. The victim fails on a file that existed a
 * moment earlier and passes alone straight afterwards, which reads exactly like
 * flake — and the tell is that THE FILENAME IN THE ERROR MOVES BETWEEN RUNS,
 * the same signature CLAUDE.md records for the full-disk savepoint trap.
 *
 * bootstrap.php named both files as having hit it and believed it had fixed
 * them. Lane PG2 lost a run to it again months later.
 *
 * Both now scope their sweep by `getmypid()`. This pins that a third one does
 * not appear.
 */
it('has a temp root that really is shared, so the sweeps must be scoped', function () {
    /*
     * The premise, asserted rather than assumed — if a future PHP makes
     * `sys_temp_dir` settable, or the bootstrap finds another way, this is the
     * case that says so and the per-PID scoping below can be reconsidered.
     * It is deliberately not `toBe('/tmp')`: what matters is that the root is
     * NOT this process's private tree, however the platform spells it.
     */
    $private = (string) getenv('TMPDIR');

    expect($private)->not->toBe('', 'the bootstrap no longer exports TMPDIR');
    expect(str_contains($private, 'kbb-run-'.getmypid()))->toBeTrue();

    expect(sys_get_temp_dir())->not->toBe($private,
        'sys_get_temp_dir() now answers the private tree. If that is real and '
        .'deliberate, the per-PID scoping in the two sweeps below can go — but '
        .'check ini_get("sys_temp_dir") is genuinely set before believing it.');
});

it('lets no test sweep a temp namespace that is not its own', function () {
    /*
     * The actual guard. Any `glob()` of the shared temp root deletes across
     * lanes unless the pattern carries this process's pid.
     *
     * MUTATION: drop `getmypid()` from either GmImportAcceptsZipTest's or
     * GpAddressesLandTest's prefix and this is red, naming the file.
     *
     * bootstrap.php's own `kbb-run-*` sweep is exempt and named: it is the one
     * place that must see other processes' trees, and it is safe because it
     * deletes only what is more than a day old — long dead, never in flight.
     */
    $offenders = [];
    $files = [];
    $walk = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(base_path('tests'), FilesystemIterator::SKIP_DOTS)
    );

    foreach ($walk as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
            $files[] = $file->getPathname();
        }
    }

    expect(count($files))->toBeGreaterThan(50, 'the test walk found almost nothing; the path is wrong');

    foreach ($files as $path) {
        /*
         * THIS FILE IS EXEMPT FROM ITS OWN SCAN, and it has to be: the failure
         * message below builds the string `glob(sys_get_temp_dir()` in order to
         * NAME an offender, and the first run of this guard duly reported
         * itself. Excluding it by name rather than by cleverness, so the
         * exemption is visible.
         */
        if (basename($path) === 'TempRootIsSharedTest.php') {
            continue;
        }

        // Comments describe the defect at length in both files; strip them so
        // prose about the pattern cannot be read as code.
        $code = (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents($path));
        $code = (string) preg_replace('#^\s*//.*$#m', '', $code);

        if (! preg_match_all('#glob\(\s*sys_get_temp_dir\(\)([^)]*)\)#', $code, $m)) {
            continue;
        }

        foreach ($m[1] as $pattern) {
            if (str_contains($pattern, 'getmypid')) {
                continue;
            }

            // The bootstrap's day-old sweep is the one legitimate exception.
            if (basename($path) === 'bootstrap.php' && str_contains($pattern, 'kbb-run-')) {
                continue;
            }

            $offenders[] = basename($path).'  glob(sys_get_temp_dir()'.trim($pattern).')';
        }
    }

    expect($offenders)->toBe([], implode("\n", array_merge(
        ['These sweep a temp namespace shared with every other lane on this machine,'],
        ['so they delete fixtures other suites are still reading:'],
        $offenders,
        ['', 'Put getmypid() in the prefix, the way ZipBuilder::tempPrefix() does.']
    )));
});
