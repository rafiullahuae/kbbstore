<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * The compiled config / route / event caches, and why the suite keeps its own
 * and throws them away before every application it builds.
 *
 * WHAT GOES WRONG. A compiled config cache outranks the environment completely:
 * LoadConfiguration asks Application::configurationIsCached() first and, when a
 * file is there, `require`s it and never calls env() at all. So a
 * bootstrap/cache/config.php written by ANY process is a total override of the
 * suite's database settings — past phpunit-mysql.xml's force="true", past
 * tests/bootstrap.php, past everything.
 *
 * Demonstrated, not theorised. With a config cache on disk naming sqlite,
 *
 *     vendor/bin/pest -c phpunit-mysql.xml tests/Feature/OrderNumbersTest.php
 *
 * fails all eleven tests with
 *
 *     SQLSTATE[HY000]: General error: 1 no such column: deleted_at
 *     (Connection: sqlite, SQL: update "orders" set "deleted_at" = ...)
 *
 * — a MySQL run answering out of a half-migrated SQLite file. Phantom failures
 * for the tests the other engine cannot satisfy, and a false green for the rest.
 *
 * AND WRITING ONE IS ORDINARY. The migration set does it to itself:
 * warm_caches_2_60_4 calls Artisan::call('config:cache') and route:cache, and
 * the later clear_caches_* migrations delete the files again. Every `php artisan
 * migrate` against this checkout therefore holds a config cache in place for as
 * long as the migration set takes to run — another lane's terminal, the
 * `migrate --force` subprocess the browser preview tests boot (its app root is a
 * symlink to this very checkout), an UpdateRunner apply. Any test whose
 * application is created inside that window is handed another process's
 * configuration, and nothing in the failure says so.
 *
 * ------------------------------------------------------------------------
 * THE FIX AS IT FIRST STOOD, and the limit its author recorded.
 *
 * Tests\TestCase::createApplication() calls discard() immediately before
 * building the application, so no compiled cache — this process's own or
 * anybody else's — is ever in place when LoadConfiguration looks. What remains
 * is a window between the unlink and that check, against a writer that holds
 * the file for seconds; and once the unlink has happened the other process does
 * not write it again.
 *
 * The author judged that window a different order of risk from the measured
 * failure above, and judged that for THESE THREE it could not be closed by
 * moving the paths, because APP_CONFIG_CACHE and friends are inherited by every
 * subprocess a test spawns, and config, routes and events encode the
 * ENVIRONMENT — so redirecting them would hand the preview boots'
 * `artisan migrate` the suite's own compiled config instead of its own. That
 * too was measured, as a preview reporting "Nothing to migrate" against the
 * wrong database.
 *
 * Both halves of that were right, and the second half is why the redirection
 * below is accompanied by environmentFor(). What has changed is only the first
 * half: the size of the window, and what happens inside it.
 *
 * ------------------------------------------------------------------------
 * WHY THE WINDOW HAD TO BE CLOSED AFTER ALL: it is not a wrong read, it is a
 * FATAL one, and it is not microseconds wide.
 *
 * Illuminate\Filesystem\Filesystem::put() — which is what config:cache calls —
 * is file_put_contents() with no LOCK_EX and no temp-file-and-rename. The file
 * is therefore TRUNCATED and then refilled in place, and a reader that opens it
 * in between does not get stale configuration, it gets broken PHP:
 *
 *     ParseError: syntax error, unexpected string content "Mon"
 *       at bootstrap/cache/config.php:568
 *       at Illuminate/Foundation/Application.php:342
 *       at tests/TestCase.php:40
 *
 * A ParseError at boot is not a test failure. It is the whole application
 * failing to come up, in a test that has nothing to do with configuration.
 *
 * MEASURED, against this checkout's real bootstrap/cache/config.php (20,886
 * bytes, 813 lines — the tear above is at line 568, which is two thirds of the
 * way in):
 *
 *   - one file_put_contents() of it takes 27µs at p50, 64µs at p95, 192µs at
 *     worst. That whole interval is the window, not the tail of it.
 *   - a sampler that stats the file continuously sees it SHORT in 1.8% of the
 *     moments it sees it at all, and the partial lengths it observes are 0,
 *     16384 and 20480 bytes — the page boundaries the write is flushed on.
 *   - a reader doing exactly what TestCase::createApplication() does — discard(),
 *     then file_exists(), then require() — took a ParseError on 515 of the 2,328
 *     boots that found a file. Twenty-two per cent.
 *
 * Reproduced on demand, repeatedly, not caught once. The window is entered by
 * every `php artisan migrate` run against this checkout, which is every lane's
 * terminal and every preview boot in this suite.
 *
 * ------------------------------------------------------------------------
 * SO THE PATHS DO MOVE, and the original objection is answered rather than
 * overruled.
 *
 * tests/bootstrap.php now points APP_CONFIG_CACHE, APP_ROUTES_CACHE and
 * APP_EVENTS_CACHE at a directory named for this process. The suite therefore
 * never reads bootstrap/cache/config.php at all, which closes the tear AND the
 * original measured failure — an intact config cache from another process is a
 * file this run no longer looks at, rather than a file it races to delete first.
 * That is the part discard() alone could never do: discard() narrows the window,
 * redirection removes it.
 *
 * The objection was that a subprocess inherits the variable. It does — verified,
 * not assumed. A child spawned the way the preview helpers spawn one (a shell
 * `VAR=v php artisan` prefix, which ADDS to the inherited environment, running
 * base_path('artisan')) computes:
 *
 *     nothing exported   -> <checkout>/bootstrap/cache/config.php
 *     parent redirected  -> <the parent's private path>
 *
 * The first line is worth pausing on: TODAY, with no redirection anywhere, the
 * child already computes the parent's path, because the child's app root is
 * this checkout. Parent and child have always shared this file. Redirection
 * does not create the sharing; it only moves where the sharing happens.
 *
 * What redirection would add, left alone, is worse than the sharing: the 237
 * clear_caches_* migrations unlink the LITERAL base_path('bootstrap/cache/
 * config.php'), so a cache written to a redirected path is never cleaned by the
 * migration set at all. Two things answer that, and both are needed:
 *
 *   - paths() below returns the redirected locations AS WELL AS the shared
 *     ones, so discard() — which runs before every single application this
 *     suite builds, far more often than the migration set runs — cleans both.
 *   - environmentFor() gives each preview subprocess a compiled-cache directory
 *     of ITS OWN, so it neither reads nor writes the suite's. The child stops
 *     inheriting, and "Nothing to migrate against the wrong database" stops
 *     being reachable by construction rather than by argument.
 *
 * The five browser-preview tests that exercise exactly this were skipped when
 * the objection was written, which is why it could only be argued. They are not
 * unrunnable — node and Chromium are both present and the skip is the opt-in
 * gate KBB_BROWSER_TESTS. Run with it set, the preview half is 37 passing tests
 * and 673 assertions, before this change and after it.
 *
 * WHAT THE OTHER FIX WOULD HAVE COST. The tear alone can be closed without
 * moving anything, by writing the file to a temp name in the same directory and
 * rename()ing it over — atomic on one filesystem, and measured here at zero
 * short files in 3.5 million samples for about 10µs of extra write time. It is
 * the better fix for the APPLICATION and it is not a substitute for this one,
 * for two reasons. It would have to live in app/ to reach the writers that
 * matter, because the processes that tear this suite are other people's
 * terminals and the preview children, none of which load anything under tests/.
 * And it only makes the shared file WHOLE; it leaves it SHARED, so the original
 * measured failure — a MySQL run reading an intact config cache that names
 * sqlite — would survive it untouched, still narrowed to a window by discard()
 * rather than removed. Worth doing in app/Providers by whoever owns that lane;
 * it does not close what this file is about.
 *
 * packages.php and services.php are a third case again, and tests/bootstrap.php
 * redirects those WITHOUT giving subprocesses their own: they describe what is
 * installed under vendor/, which every process in this checkout agrees about, so
 * inheriting them is harmless. They also need the stronger fix, because
 * PackageManifest does is_file() and then require() rather than reading its file
 * once at boot, so discarding before boot would not protect it.
 */
final class CompiledCaches
{
    /** The three cache locations the framework resolves from the environment. */
    private const VARIABLES = [
        'APP_CONFIG_CACHE' => 'config.php',
        'APP_ROUTES_CACHE' => 'routes-v7.php',
        'APP_EVENTS_CACHE' => 'events.php',
    ];

    /**
     * Absolute paths of the compiled caches that could reach this process.
     *
     * TWO SETS, and both are deliberate.
     *
     * The shared ones, spelled out against bootstrap/cache rather than asked of
     * the running application: these are the files every process in this
     * checkout writes and the clear_caches_* migrations delete by the same
     * literal path. Those are the files another process can hand the suite, and
     * they are still listed here even though this run no longer READS them —
     * tests/Feature/SuiteIsolationTest.php plants one and asserts it is gone,
     * and a developer who ran `php artisan config:cache` by hand in this
     * checkout should not have to reason about which of two files it landed in.
     *
     * And this process's own, wherever tests/bootstrap.php redirected them.
     * These are the ones the application actually reads, and nothing else on
     * this machine cleans them: the 237 clear_caches_* migrations unlink the
     * literal shared path and would walk straight past a redirected file.
     * discard() is the only thing that empties them, which is why it has to
     * know about both.
     *
     * Read from the environment rather than from the application, for the same
     * reason the shared half is a literal: this has to work while no
     * application exists, which is precisely when createApplication() calls it.
     *
     * @return list<string>
     */
    public static function paths(): array
    {
        $shared = dirname(__DIR__, 2).'/bootstrap/cache';

        $paths = [];

        foreach (self::VARIABLES as $variable => $basename) {
            $paths[] = $shared.'/'.$basename;

            $redirected = getenv($variable);

            /*
             * An empty value is not a path. Env::get() would hand '' to
             * Application::normalizeCachePath(), which does not treat it as
             * absolute and resolves it to basePath('') — the checkout root
             * itself. Unlinking that is nonsense, so it is skipped here rather
             * than trusted.
             */
            if (is_string($redirected) && trim($redirected) !== '') {
                $paths[] = $redirected;
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * Remove every compiled cache, so the next application is built from the
     * environment.
     *
     * These are build artefacts: gitignored, regenerated on demand, and already
     * deleted by every clear_caches_* migration in the set. Removing them costs
     * a developer nothing they cannot rebuild with one command, and it is the
     * only thing that stops a run reading somebody else's database settings.
     */
    public static function discard(): void
    {
        foreach (self::paths() as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        // routes-*.php rather than the one current name: the cache is versioned
        // (routes-v7.php today) and a file left by another framework version is
        // still a file routesAreCached() might find after an upgrade. The
        // clear_caches_* migrations glob it the same way. Every directory any
        // of the paths above lives in, so an upgrade cannot leave a stale route
        // cache behind in the redirected half either.
        foreach (self::directories() as $directory) {
            foreach (glob($directory.'/routes-*.php') ?: [] as $stale) {
                if (is_file($stale)) {
                    @unlink($stale);
                }
            }
        }
    }

    /**
     * The environment a SUBPROCESS needs so that it compiles into a directory
     * of its own instead of inheriting the suite's.
     *
     * Every preview helper in tests/Feature builds its child's environment as a
     * shell prefix — `VAR=value VAR=value php artisan migrate --force` — and a
     * shell prefix ADDS to the inherited environment rather than replacing it.
     * So without these three entries the child follows the parent's
     * APP_CONFIG_CACHE to the suite's private file: it would boot from the
     * suite's compiled config (the "Nothing to migrate" against the wrong
     * database that Tests\Support\CompiledCaches' header records) and its own
     * warm_caches_2_60_4 would then overwrite that file with the PREVIEW's
     * settings, which the suite would read at its next boot.
     *
     * Passing a path explicitly is the only way to break the inheritance. It
     * cannot be done by clearing the variable: `APP_CONFIG_CACHE= php ...`
     * leaves an empty string, which Application::normalizeCachePath() does not
     * read as absolute and resolves to basePath(''), the checkout root.
     *
     * A directory of the child's OWN rather than the shared bootstrap/cache,
     * because two suites running at once each boot previews, and pointing both
     * children at the shared file would reintroduce between the children
     * exactly the tear this class exists to close. Callers pass a path under
     * their own preview directory, which is already per-process by way of
     * LARAVEL_STORAGE_PATH.
     *
     * @return array<string, string>
     */
    public static function environmentFor(string $directory): array
    {
        if (! is_dir($directory)) {
            @mkdir($directory, 0o755, true);
        }

        $environment = [];

        foreach (self::VARIABLES as $variable => $basename) {
            $environment[$variable] = $directory.'/'.$basename;
        }

        return $environment;
    }

    /** Every distinct directory paths() names. @return list<string> */
    private static function directories(): array
    {
        return array_values(array_unique(array_map('dirname', self::paths())));
    }
}
