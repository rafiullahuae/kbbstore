<?php

declare(strict_types=1);

/**
 * What the suite is allowed to be pointed at, asserted from inside a run.
 *
 * Every rule here is a lost afternoon. Several lanes share this repository and
 * run both engines concurrently, and each of the things below has at some point
 * made a run report failures that belonged to another process, or a pass that
 * meant nothing:
 *
 *   - phpunit-mysql.xml hard-coded `kbb_test` and every worktree used it, so
 *     two lanes' migrate:fresh dropped the schema under each other. The whole
 *     of the resulting failure list is phantom.
 *   - phpunit.xml pointed at database/testing.sqlite, the same file .env points
 *     a developer's own app at, so a suite run wiped seeded preview data — and
 *     two runs in one checkout wiped each other's.
 *   - storage/framework/views is shared as well, and the migration set empties
 *     it on every migrate, so one run deletes the compiled Blade files another
 *     run is mid-way through rendering. Thirty 500s in one measured pair of
 *     concurrent runs, none of them reproducible alone.
 *   - a compiled config cache on disk replaces the database settings outright,
 *     because LoadConfiguration `require`s it instead of calling env(). One
 *     `php artisan migrate` running anywhere against this checkout leaves such
 *     a file for as long as it takes the migration set to reach the
 *     clear_caches_* migrations.
 *
 * tests/bootstrap.php settles the first two before Laravel boots, and
 * Tests\TestCase::createApplication() clears the third before each application
 * is built. This file is the other end of all of it: it asserts the connection
 * the application actually opened, rather than trusting that the configuration
 * said the right thing.
 */

use Illuminate\Support\Facades\DB;

/* ------------------------------------------------------- the database name -- */

it('connects to the database KBB_TEST_DB names', function () {
    $requested = getenv('KBB_TEST_DB');
    $requested = is_string($requested) ? trim($requested) : '';

    if ($requested === '') {
        test()->markTestSkipped(
            'KBB_TEST_DB is not set, so there is no request to honour. '
            .'Set it (KBB_TEST_DB=kbb_test_ce vendor/bin/pest -c phpunit-mysql.xml) to exercise this.'
        );
    }

    /*
     * getDatabaseName() on the LIVE connection, not config(). The point of the
     * override is that phpunit-mysql.xml keeps force="true" on DB_DATABASE --
     * every entry in that file is forced, because an unforced DB_CONNECTION
     * lets an exported value downgrade the "MySQL" run to SQLite in silence.
     * So the assertion has to be that the database actually opened is the one
     * asked for, which is the only thing that distinguishes a working override
     * from a config value nothing read.
     */
    expect(DB::connection()->getDatabaseName())->toBe($requested);
});

it('never migrates the database .env points the developer at', function () {
    if (DB::connection()->getDriverName() !== 'sqlite') {
        test()->markTestSkipped('Only the SQLite engine shares a file with the developer.');
    }

    $live = (string) DB::connection()->getDatabaseName();

    // The file .env ships with, and the file phpunit.xml used to name. A suite
    // run migrate:fresh-es whatever this is, so it must never be that one.
    $developers = base_path('database/testing.sqlite');

    expect($live)->not->toBe($developers);

    // realpath as well as the string, because 'database/testing.sqlite'
    // relative and the absolute form are the same file and only one of them
    // fails the comparison above.
    if (is_file($developers)) {
        expect(realpath($live))->not->toBe(realpath($developers));
    }

    // And it belongs to THIS process, which is what stops a lane's foreground
    // run and the background run it forgot about from migrating one file.
    $requested = getenv('KBB_TEST_DB');

    if (! is_string($requested) || trim($requested) === '') {
        $ok = str_contains(basename($live), '-'.getmypid().'-');

        expect($ok)->toBeTrue("the suite's SQLite database is not named for this process: {$live}");
    }
});

it('runs on a file-backed database, never :memory:', function () {
    if (DB::connection()->getDriverName() !== 'sqlite') {
        test()->markTestSkipped('MySQL has no in-memory mode to fall into.');
    }

    /*
     * Stated here because the isolation above is one keystroke away from being
     * "solved" with :memory:, which looks like perfect per-process isolation
     * and is not usable: the migration set does not survive it (menu_items
     * disappears before add_menu_item_options runs). See CLAUDE.md.
     */
    $live = (string) DB::connection()->getDatabaseName();

    expect($live)->not->toBe(':memory:')
        ->and(is_file($live))->toBeTrue("the suite's database is not a file on disk: {$live}");
});

/* ------------------------------------------------------- the config cache -- */

it('discards a config cache another process left in bootstrap/cache', function () {
    $poison = base_path('bootstrap/cache/config.php');

    if (file_exists($poison)) {
        test()->markTestSkipped('bootstrap/cache/config.php already exists; refusing to overwrite it.');
    }

    $expected = (string) config('database.default');

    /*
     * The file a concurrent `php artisan migrate` leaves behind, reduced to the
     * one key that matters. warm_caches_2_60_4 calls config:cache from inside
     * the migration set, so the browser previews' `migrate --force` subprocess,
     * another lane's terminal and an UpdateRunner apply all produce one of
     * these against this very checkout.
     *
     * Without the discard the reproduction is brutal and silent: with a config
     * cache naming sqlite present, every test in OrderNumbersTest.php fails a
     * MySQL run with "no such column: deleted_at (Connection: sqlite)".
     */
    file_put_contents($poison, '<?php return '.var_export([
        'database' => ['default' => 'kbb-poison-not-a-real-connection'],
        'app' => ['key' => 'base64:'.base64_encode(str_repeat('k', 32))],
    ], true).';');

    $original = $this->app;

    try {
        // The real seam, not a re-implementation of it: this is the method the
        // framework calls for every test in the suite.
        $fresh = $this->createApplication();

        expect(file_exists($poison))->toBeFalse('the compiled config cache was left in place')
            ->and($fresh['config']->get('database.default'))->toBe($expected);
    } finally {
        @unlink($poison);

        /*
         * Building an Application calls Container::setInstance() on itself and
         * re-points the facade root and Eloquent's connection resolver at it.
         * That is the landmine tests/Pest.php documents: it repairs it there for
         * the NEXT test, and this repairs it for the rest of this one, or the
         * assertions below would be measuring the throwaway application.
         */
        Illuminate\Container\Container::setInstance($original);
        Illuminate\Support\Facades\Facade::clearResolvedInstances();
        Illuminate\Support\Facades\Facade::setFacadeApplication($original);
        Illuminate\Database\Eloquent\Model::setConnectionResolver($original['db']);
    }

    // The container really is back, and the connection with it.
    expect(app())->toBe($original)
        ->and(DB::connection()->getName())->toBe(config('database.default'));
});

/* ------------------------------------------------------ the compiled views -- */

it('compiles Blade into a directory of its own', function () {
    $compiled = (string) config('view.compiled');
    $shared = base_path('storage/framework/views');

    /*
     * The shared directory is not merely inconvenient, it is actively emptied:
     * clear_caches_* and relocate_public_assets both glob
     * storage_path('framework/views/*.php') and unlink everything they find. A
     * second run rendering a page while the first migrates loses the compiled
     * file between resolving it and requiring it, and answers 500.
     */
    expect($compiled)->not->toBe($shared)
        ->and($compiled)->not->toBe((string) realpath($shared));

    $owned = str_contains($compiled, '-'.getmypid().'-');

    expect($owned)->toBeTrue("compiled views are not in a directory named for this process: {$compiled}");

    // And it is real and writable, or the first render of the run is the 500
    // this is supposed to prevent.
    expect(is_dir($compiled))->toBeTrue("the compiled view directory does not exist: {$compiled}")
        ->and(is_writable($compiled))->toBeTrue("the compiled view directory is not writable: {$compiled}");
});

it('compiles nothing into the set of files the migration set sweeps', function () {
    /*
     * The race, stated as the set difference that prevents it rather than as a
     * timing window that cannot be reproduced on demand.
     *
     * clear_caches_* and relocate_public_assets each do
     *
     *     foreach (glob(storage_path('framework/views/*.php')) as $file) unlink($file);
     *
     * so every file that glob can see is a file another process's migrate will
     * delete out from under this one, between Blade resolving the path and PHP
     * requiring it. As long as nothing this run compiles appears in that glob,
     * the window does not exist.
     */
    $this->get('/')->assertOk();

    $mine = glob(rtrim((string) config('view.compiled'), '/').'/*.php') ?: [];

    expect($mine)->not->toBeEmpty('nothing compiled, so this test proves nothing');

    $sweep = glob(storage_path('framework/views/*.php')) ?: [];

    $exposed = array_values(array_intersect(
        array_map('realpath', $mine),
        array_map('realpath', $sweep)
    ));

    expect($exposed)->toBe(
        [],
        'these compiled views are in reach of the migration set\'s sweep, so a concurrent '
        .'migrate will delete them mid-render: '.implode(', ', $exposed)
    );
});

/* --------------------------------------------------- the package manifest -- */

it('reads a package manifest no other process can delete', function () {
    /*
     * About thirty clear_caches_* migrations unlink
     * base_path('bootstrap/cache/packages.php') by name, and PackageManifest
     * does is_file() and then require(). One run's migrate landing between
     * those two lines in another run is an outright fatal, in whichever test
     * happened to be building an application:
     *
     *     ErrorException: require(bootstrap/cache/packages.php):
     *     Failed to open stream: No such file or directory
     *
     * Measured in PaymentGatewayTest during a pair of concurrent runs — a test
     * with nothing whatever to do with packages, which is the give-away that it
     * was never about the test it landed in.
     */
    foreach ([
        'packages' => $this->app->getCachedPackagesPath(),
        'services' => $this->app->getCachedServicesPath(),
    ] as $what => $path) {
        $shared = base_path('bootstrap/cache/'.$what.'.php');

        expect($path)->not->toBe($shared);

        $owned = str_contains((string) $path, '-'.getmypid().'-');

        expect($owned)->toBeTrue("the {$what} manifest is not named for this process: {$path}");
    }
});
