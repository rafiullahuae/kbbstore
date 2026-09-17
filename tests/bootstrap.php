<?php

declare(strict_types=1);

/**
 * The suite's bootstrap. Named by phpunit.xml and phpunit-mysql.xml in place of
 * vendor/autoload.php. It gives this process its own copy of everything every
 * process in this checkout otherwise shares and destroys for the others: the
 * database it migrates, the directory it compiles Blade into, the package
 * manifest it reads, and the two roots it writes to -- storage/ and the public
 * web root.
 *
 * Taken together those are the whole of it. Two suites can now run at the same
 * time, from one worktree or from two, and neither can observe the other.
 *
 * Why here, and why it has to be here. PHPUnit applies <php><env> and then
 * loads the bootstrap script -- in that order, see TextUI\Application::run(),
 * which calls (new PhpHandler)->handle() and only afterwards (new
 * BootstrapLoader)->handle(). Laravel reads the environment later still, when
 * the first test creates an application. This file sits in the one window
 * between the two, which makes it the only place a <env force="true"> value can
 * be adjusted without deleting the force that protects it.
 *
 * That force matters and is deliberately left alone. phpunit-mysql.xml's header
 * explains why every entry in it carries force="true": without it PHPUnit skips
 * any variable already exported, so a stray DB_CONNECTION in the shell makes
 * the "MySQL" run quietly execute against SQLite -- the exact false green that
 * file exists to prevent. Dropping force from DB_DATABASE to make it
 * overridable would reintroduce that hazard for the one variable that decides
 * which data gets dropped. So the XML keeps forcing its documented default and
 * the override arrives through a name of its own, KBB_TEST_DB, which nothing
 * else in the project reads and no framework sets by accident.
 *
 * The five incidents this closes:
 *
 * 1. phpunit-mysql.xml hard-codes `kbb_test`, and every concurrent worktree
 *    shared it. Two lanes running the MySQL suite at once destroy each other:
 *    RefreshDatabase's migrate:fresh drops the schema under the other run, and
 *    both then report hundreds of phantom failures alternating between
 *    "Table 'kbb_test.migrations' doesn't exist" and "Table 'categories'
 *    already exists". Four lanes hit it; every one of them worked around it by
 *    sed-ing a private copy of the config file. One variable replaces that:
 *
 *        KBB_TEST_DB=kbb_test_ce vendor/bin/pest -c phpunit-mysql.xml
 *
 * 2. On SQLite the suite migrated database/testing.sqlite -- the same file
 *    .env points a developer's own app at. Running the suite wiped whatever had
 *    been seeded for a preview (two lanes lost data that way), and two suite
 *    runs in one worktree shared one file and produced phantom failures from
 *    each other's migrate:fresh (one lane, six of them). Absent an explicit
 *    KBB_TEST_DB, an unattended SQLite run therefore gets a file of its own,
 *    named for the process that owns it and deleted when that process exits.
 *    Still file-backed, never :memory: -- the migration set does not survive an
 *    in-memory database (see CLAUDE.md).
 *
 * 3. storage/framework/views is shared too, and the migration set empties it on
 *    every migrate. Two runs at once therefore delete each other's compiled
 *    Blade files mid-render, which surfaces as unexplained 500s. See the
 *    comment on VIEW_COMPILED_PATH below.
 *
 * 4. bootstrap/cache/packages.php is shared, and the clear_caches_* migrations
 *    unlink it. PackageManifest does is_file() then require(), so a migrate in
 *    another process lands a fatal in a test that has nothing to do with
 *    packages. See the comment on APP_PACKAGES_CACHE below.
 *
 * 5. storage/ and the public web root are shared, and two tests EMPTY them --
 *    AdminImportScreenTest purges storage/app/import in beforeEach and again in
 *    afterEach, MediaLibraryTest empties public/uploads. Each is right about its
 *    own run and fatal to anybody else's: the other run's uploaded CSV goes
 *    between the write and the read. This was the sole cause of all eleven
 *    filesystem failures left over once 1-4 were closed. See the comment on
 *    LARAVEL_STORAGE_PATH and KBB_PUBLIC_PATH below.
 *
 * Nothing here can be asserted from inside itself, so the outcome is asserted
 * from the other end instead. tests/Feature/SuiteIsolationTest.php covers 1-4:
 * that the connection the app actually opened is the database that was asked
 * for, and that the views it compiles are its own.
 * tests/Feature/WritableRootIsolationTest.php covers 5, and does it by
 * performing the purge rather than by reading the variable back.
 */

require_once __DIR__.'/../vendor/autoload.php';

/*
 * THE PSEUDO-RANDOM STREAM, pinned for the whole process.
 *
 * Tests\Support\DeterministicRandom carries the reasoning and the measurement:
 * the demo catalogue is drawn by a MIGRATION with an unseeded mt_rand(), once
 * per process, and everything the storefront tests render is that draw. The
 * per-application reseed in Tests\TestCase::createApplication() is what makes
 * it deterministic in practice; this is the same call once at process start, so
 * that anything drawing before the first application exists is pinned too.
 */
\Tests\Support\DeterministicRandom::reseed();

(static function (): void {
    /**
     * Publish a value everywhere Laravel's env() looks for one.
     *
     * Env::getRepository() stacks the $_SERVER, $_ENV and putenv adapters and
     * is built immutable, so whatever is already present when .env is parsed
     * wins -- which is what makes writing these three from here effective at
     * all, and what makes writing only one of them a coin toss.
     */
    $put = static function (string $name, string $value): void {
        putenv($name.'='.$value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    };

    /*
     * COMPILED VIEWS, one directory per process.
     *
     * storage/framework/views is shared by every process in this checkout, and
     * the migration set empties it: clear_caches_* and relocate_public_assets
     * both glob storage_path('framework/views/*.php') and unlink the lot. So
     * one lane's migrate:fresh deletes the compiled Blade files another lane's
     * run has ALREADY resolved and is about to require, and the second run
     * answers 500 to page after page:
     *
     *     ErrorException: require(storage/framework/views/fce5ced2....php):
     *     Failed to open stream: No such file or directory
     *
     * Measured, with two suites running at once against separate databases:
     * thirty of those in one pair of runs, across ProductSeoTest,
     * SearchFilterTest and anything else that rendered a storefront page at the
     * wrong moment. Nothing about it points at the real cause, and nothing
     * about it is reproducible alone.
     *
     * A directory per process ends it. Blade creates the directory itself and
     * recompiles what it does not find, so the only cost is one compile pass
     * per run -- which the clear_caches migrations were forcing anyway.
     */
    $views = dirname(__DIR__).'/storage/framework/views/suite-'.getmypid().'-'.bin2hex(random_bytes(4));

    if (! is_dir($views)) {
        @mkdir($views, 0o755, true);
    }

    $put('VIEW_COMPILED_PATH', $views);

    register_shutdown_function(static function () use ($views): void {
        foreach (glob($views.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($views);
    });

    /*
     * THE PACKAGE MANIFEST, also one per process.
     *
     * bootstrap/cache/packages.php and services.php are shared, and about
     * thirty clear_caches_* migrations unlink them by name. PackageManifest
     * does is_file() and then require(), so one run's migrate:fresh landing
     * between those two lines in another run is a fatal:
     *
     *     ErrorException: require(bootstrap/cache/packages.php):
     *     Failed to open stream: No such file or directory
     *
     * Measured in a pair of concurrent runs, in PaymentGatewayTest, which has
     * nothing whatever to do with packages.
     *
     * Redirecting is safe for THESE two and not for the other three. packages
     * and services describe what is installed in vendor/, which every process
     * in this checkout agrees about, so a subprocess inheriting the variable
     * reads a manifest identical to the one it would have built. config, routes
     * and events encode the ENVIRONMENT, and a preview's `php artisan migrate`
     * inheriting APP_CONFIG_CACHE reads the suite's database settings instead of
     * its own -- measured, as a preview reporting "Nothing to migrate" against
     * the wrong database. Those three are handled the other way, by
     * Tests\Support\CompiledCaches::discard() before each application is built.
     */
    $manifests = dirname(__DIR__).'/storage/framework/testing/manifest-'.getmypid().'-'.bin2hex(random_bytes(4));

    if (! is_dir($manifests)) {
        @mkdir($manifests, 0o755, true);
    }

    $put('APP_PACKAGES_CACHE', $manifests.'/packages.php');
    $put('APP_SERVICES_CACHE', $manifests.'/services.php');

    register_shutdown_function(static function () use ($manifests): void {
        foreach (glob($manifests.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($manifests);
    });

    /*
     * THE WRITABLE ROOTS, one pair per process.
     *
     * storage/ and the public web root are the two directories the application
     * WRITES to, and every process in this checkout shared both. The suite does
     * not merely read them: several tests empty them, on purpose, because a
     * backfill or an import can only be asserted against a directory whose
     * contents the test put there.
     *
     *     tests/Feature/AdminImportScreenTest.php   purges storage/app/import
     *                                               in beforeEach AND afterEach
     *     tests/Feature/MediaLibraryTest.php        empties public/uploads
     *
     * So two runs at once delete each other's fixtures between the upload and
     * the read, and the second run fails somewhere with no relation to the
     * deletion:
     *
     *     fopen(.../storage/app/import/woo/customers.csv):
     *     Failed to open stream: No such file or directory
     *
     * Measured: two concurrent runs of AdminImportScreenTest alone, against
     * SEPARATE databases, fail 5 and 8 of 29. The sharpest one to lose is the
     * security assertion at AdminImportScreenTest:313 -- "the uploaded filename
     * never decides where the file goes" -- which fails because the file it just
     * proved was at the one correct path has been deleted by the other run. A
     * shared workspace does not merely make that property flaky, it makes it
     * unprovable.
     *
     * Four more fixed paths collide the same way, and are fixed by the same
     * move: CustomerPasswordResetTest:453 (logs/lane-l-reset-test.log) and the
     * three browser previews at AdminProductPickerBrowserTest:80,
     * PaymentsGatewayTabsTest:288 and AdminMobileOverflowTest:78, each of which
     * builds and then rm -rf's a directory named for its lane and not for its
     * process.
     *
     * WHY THE ROOT AND NOT THE CALLERS. The alternative considered was to give
     * App\Services\ImportConsole\ImportWorkspace an overridable root and point
     * it somewhere private per process. It was rejected for one reason that
     * decides it: the property under test at AdminImportScreenTest:313 and :318
     * is that an importer writes to a single literal destination that the code
     * chose, and the test states it as the literal
     * storage_path('app/import/woo/brands.csv'). Threading a root through the
     * service replaces that literal with an indirection which agrees with
     * whatever the service does -- the assertion still passes, and no longer
     * catches the bug it exists to catch. Moving the root underneath leaves
     * every such literal spelled exactly as it is, in the test and in the
     * application, and changes no file under app/ at all. It also fixes the
     * class rather than one member of it: storage/app/updates/{backups,scratch},
     * storage/app/import/runs, storage/framework/cache and storage/logs are all
     * shared and mutable today, and none of them needed naming here.
     *
     * WHY THIS IS NOT THE useStoragePath() THE PREVIOUS LANE REJECTED. Its
     * objection was that useStoragePath() has to run before bootstrap(), so
     * parent::createApplication() could not be used and a framework method would
     * have to be reimplemented under every test in the suite. That is true of
     * useStoragePath() and irrelevant here, because the framework already reads
     * this from the environment and no call is needed:
     *
     *     Illuminate\Foundation\Application::storagePath()   (Laravel 11)
     *         if (isset($_ENV['LARAVEL_STORAGE_PATH'])) { ... }
     *         if (isset($_SERVER['LARAVEL_STORAGE_PATH'])) { ... }
     *
     * which is the same seam, and the same file, as the VIEW_COMPILED_PATH and
     * APP_PACKAGES_CACHE above. createApplication() is untouched.
     *
     * The second objection -- that storage/catalog/products.json is real source
     * data outside the suite's control -- is answered rather than argued with:
     * catalog is symlinked back to the checkout's own, so it reads identically.
     * (Nothing resolves it through storage_path() today; it is packaging source
     * data that tools/kbb-manifest.php reads by relative path. The symlink is so
     * that staying true stops depending on that remaining so.)
     *
     * public/ is the same move through this project's own seam, KBB_PUBLIC_PATH,
     * which bootstrap/app.php already reads with getenv() -- hence putenv() and
     * not $_ENV alone. Only build/ is tracked under public/, so only build/ is
     * linked back; uploads/ and anything else a test writes is scratch by
     * definition. Note that this overrides a KBB_PUBLIC_PATH exported for the
     * run: that is the point, since a value shared by two runs is the bug. The
     * tests that boot a preview subprocess pass their own KBB_PUBLIC_PATH
     * explicitly and are unaffected.
     *
     * Asserted from the other end, as the previous lane's were, in
     * tests/Feature/WritableRootIsolationTest.php: not "the variable is set",
     * but "a purge of the shared path deletes what is in it and does not touch
     * mine".
     */
    $sweepTree = static function (string $dir) use (&$sweepTree): void {
        foreach (glob($dir.'/*') ?: [] as $entry) {
            /*
             * is_dir() follows symlinks, and storage/catalog and public/build
             * are symlinks into the checkout. Recursing through one would
             * delete tracked files.
             */
            is_dir($entry) && ! is_link($entry) ? $sweepTree($entry) : @unlink($entry);
        }

        @rmdir($dir);
    };

    $roots = dirname(__DIR__).'/storage/framework/testing';

    if (! is_dir($roots)) {
        @mkdir($roots, 0o755, true);
    }

    // Whatever a SIGKILLed run left behind. A day is far longer than any run,
    // so this can never reach a tree another process is still using.
    foreach (glob($roots.'/roots-*') ?: [] as $stale) {
        if (is_dir($stale) && ! is_link($stale) && filemtime($stale) < time() - 86400) {
            $sweepTree($stale);
        }
    }

    $root = $roots.'/roots-'.getmypid().'-'.bin2hex(random_bytes(4));

    /*
     * The subdirectories Laravel expects to find rather than create. A missing
     * storage/framework/sessions or storage/logs is not a clear error when it
     * arrives, it is a write failure inside whatever was being rendered.
     */
    foreach ([
        'storage/app/public',
        'storage/framework/cache/data',
        'storage/framework/sessions',
        'storage/framework/testing',
        'storage/framework/views',
        'storage/logs',
        'public',
    ] as $child) {
        @mkdir($root.'/'.$child, 0o755, true);
    }

    /*
     * The two directories under these roots that are tracked content rather
     * than scratch. Linked, never copied: a copy is a second version of a file
     * git knows about, which is the mistake relocate_public_assets exists to
     * undo.
     *
     * public/build in particular is load-bearing and its absence does not look
     * like a missing symlink. @vite() reads public/build/manifest.json through
     * publicPath(), and without it every page carrying a bundled stylesheet
     * answers 500:
     *
     *     Illuminate\Foundation\ViteException: Unable to locate file in Vite
     *     manifest: resources/css/kbb/kbb-banner.css.
     *
     * Seven tests in BrandEditorAndPageBannerTest fail that way, none of them
     * saying anything about assets. That is also what the suite did before
     * these roots existed at all, because bootstrap/app.php falls back to the
     * LIVE HOST's web root when KBB_PUBLIC_PATH is unset -- a directory no
     * checkout has. Whoever exported the variable saw green and the next shell
     * saw seven reds.
     */
    foreach ([
        'storage/catalog' => dirname(__DIR__).'/storage/catalog',
        'public/build' => dirname(__DIR__).'/public/build',
    ] as $link => $target) {
        if (is_dir($target) && ! file_exists($root.'/'.$link)) {
            @symlink($target, $root.'/'.$link);
        }
    }

    $put('LARAVEL_STORAGE_PATH', $root.'/storage');
    $put('KBB_PUBLIC_PATH', $root.'/public');

    register_shutdown_function(static function () use ($root, $sweepTree): void {
        $sweepTree($root);
    });

    $requested = getenv('KBB_TEST_DB');
    $requested = is_string($requested) ? trim($requested) : '';

    if ($requested !== '') {
        $put('DB_DATABASE', $requested);

        return;
    }

    // No override. MySQL keeps the default the XML forced (kbb_test); only the
    // SQLite side needs a file that is not the developer's.
    $connection = getenv('DB_CONNECTION');

    if ($connection !== 'sqlite') {
        return;
    }

    $dir = dirname(__DIR__).'/database/testing';

    if (! is_dir($dir)) {
        @mkdir($dir, 0o755, true);
    }

    /*
     * Anything a run killed outright (SIGKILL, a container going away) left
     * behind. A day old is far longer than any suite run, so this can never
     * reach a database another process is still using.
     */
    foreach (glob($dir.'/suite-*.sqlite*') ?: [] as $stale) {
        if (is_file($stale) && filemtime($stale) < time() - 86400) {
            @unlink($stale);
        }
    }

    /*
     * Named for the owning process, so two runs in one worktree -- a lane's
     * foreground run and the background one it forgot about -- cannot collide.
     * The random half is for the case where two containers share the checkout
     * over a bind mount and PIDs are drawn from different namespaces.
     */
    $database = $dir.'/suite-'.getmypid().'-'.bin2hex(random_bytes(4)).'.sqlite';

    touch($database);

    $put('DB_DATABASE', $database);

    // SQLite writes siblings next to the database file depending on journal
    // mode; take the lot, and only ever the ones this process made.
    register_shutdown_function(static function () use ($database): void {
        foreach (['', '-journal', '-wal', '-shm'] as $suffix) {
            if (is_file($database.$suffix)) {
                @unlink($database.$suffix);
            }
        }
    });
})();
