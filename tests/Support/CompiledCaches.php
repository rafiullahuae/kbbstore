<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * The compiled config / route / event caches, and why the suite throws them
 * away before every application it builds.
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
 * THE FIX, and its limit. Tests\TestCase::createApplication() calls discard()
 * immediately before building the application, so no compiled cache — this
 * process's own or anybody else's — is ever in place when LoadConfiguration
 * looks. What remains is a window of microseconds between the unlink and that
 * check, against a writer that holds the file for seconds; and once the unlink
 * has happened the other process does not write it again. That is a different
 * order of risk from the one measured above, and for THESE THREE it cannot be
 * closed by moving the paths. APP_CONFIG_CACHE and friends are inherited by
 * every subprocess a test spawns, and config, routes and events encode the
 * ENVIRONMENT — so redirecting them hands the preview boots' `artisan migrate`
 * the suite's own compiled config instead of its own, which was measured too,
 * as a preview reporting "Nothing to migrate" against the wrong database.
 *
 * packages.php and services.php are the opposite case, and tests/bootstrap.php
 * DOES redirect those: they describe what is installed under vendor/, which
 * every process in this checkout agrees about, so inheriting them is harmless.
 * They also need the stronger fix, because PackageManifest does is_file() and
 * then require() rather than reading its file once at boot, so discarding
 * before boot would not protect it.
 */
final class CompiledCaches
{
    /**
     * Absolute paths of the compiled caches the framework reads at boot.
     *
     * Spelled out against bootstrap/cache rather than asked of the running
     * application, and that is deliberate: this has to name the SHARED files,
     * the ones every process in this checkout writes and the clear_caches_*
     * migrations delete by the same literal path. Those are the files another
     * process can hand the suite.
     *
     * @return list<string>
     */
    public static function paths(): array
    {
        $cache = dirname(__DIR__, 2).'/bootstrap/cache';

        return [
            $cache.'/config.php',
            $cache.'/routes-v7.php',
            $cache.'/events.php',
        ];
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
        // clear_caches_* migrations glob it the same way.
        foreach (glob(dirname(__DIR__, 2).'/bootstrap/cache/routes-*.php') ?: [] as $stale) {
            if (is_file($stale)) {
                @unlink($stale);
            }
        }
    }
}
