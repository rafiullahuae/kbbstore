<?php

declare(strict_types=1);

/**
 * The suite's bootstrap. Named by phpunit.xml and phpunit-mysql.xml in place of
 * vendor/autoload.php. It gives this process its own copy of the two things
 * every process in this checkout otherwise shares and destroys for the others:
 * the database it migrates, and the directory it compiles Blade into.
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
 * The four incidents this closes:
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
 * Nothing here can be asserted from inside itself, so
 * tests/Feature/SuiteIsolationTest.php asserts the outcome from the other end:
 * that the connection the app actually opened is the database that was asked
 * for, and that the views it compiles are its own.
 */

require_once __DIR__.'/../vendor/autoload.php';

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
     * THE WEB ROOT, so a suite run can find the built assets.
     *
     * bootstrap/app.php ends with
     * usePublicPath(getenv('KBB_PUBLIC_PATH') ?: '/home/.../public_html/kbb-upgrade')
     * because on the live host the web root is a DIFFERENT directory from the
     * application root -- the standing arrangement recorded in CLAUDE.md, and
     * that hard-coded path is the server's, not this checkout's. Nothing sets
     * the variable locally, so every suite run resolved publicPath() to a
     * directory that does not exist here, and @vite() could not read
     * public/build/manifest.json:
     *
     *     Illuminate\Foundation\ViteException: Unable to locate file in Vite
     *     manifest: resources/css/kbb/kbb-banner.css.
     *
     * Seven tests in BrandEditorAndPageBannerTest answered 500 because of it,
     * and they answered 500 for a reason that has nothing to do with brands,
     * banners or anything else they assert. A lane that exported the variable
     * in its shell saw green; the next lane, in a fresh shell, saw seven reds
     * and a stack trace pointing at the framework. That is a false red the
     * suite should never have been able to produce, and it costs whoever hits
     * it the time to work out that the suite, not the code, is misconfigured.
     *
     * Defaulted, not forced: an explicit KBB_PUBLIC_PATH still wins, which is
     * what PublicAssetRelocationTest relies on when it hands a subprocess a
     * throwaway web root of its own.
     */
    $publicPath = getenv('KBB_PUBLIC_PATH');

    if (! is_string($publicPath) || trim($publicPath) === '') {
        $put('KBB_PUBLIC_PATH', dirname(__DIR__).'/public');
    }

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
