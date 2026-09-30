<?php

declare(strict_types=1);

use App\Support\CacheScope;

/**
 * =============================================================================
 * ONE CACHE DIRECTORY PER DATABASE, AND WHY THAT IS A BUG FIX AND NOT TIDYING
 * =============================================================================
 *
 * ── WHAT IT LOOKED LIKE, TWICE ─────────────────────────────────────────────
 *
 * `storage/framework/cache/data` was ONE directory for the whole checkout,
 * however separate the databases pointed at it were. Nearly everything this
 * shop caches is read out of the database and kept with `rememberForever`, so a
 * second process with a different database read the first one's answer back.
 *
 *   Lane AR   the Arabic preview served `<html lang="ar" dir="rtl">` on the LTR
 *             side, because SettingsService had cached the OTHER preview's
 *             `language_rtl_enabled` under `kbb.settings`. That lane called it
 *             "the single most misleading failure this harness could have".
 *
 *   Lane SEC  `/admin` answered 404 on a preview whose own database had no
 *             `admin_path` row at all: `kbb.admin_path` in that directory held
 *             `mr-cool`, left by something else, so the console was at
 *             /mr-cool and nothing said so. Half an hour, and the lane then
 *             worked around it by pinning KBB_ADMIN_PATH in its own script —
 *             which is a third harness patched instead of the trap disarmed.
 *
 * NEITHER ERRORS AND BOTH LOOK LIKE DATA, which is what makes this the same
 * class as `KBB_WP_DB`, `pkill -f` and the shared scratchpad in CLAUDE.md: one
 * unnamespaced shared resource, several writers, and a collision that reads as
 * a real answer.
 *
 * ── WHAT IT COSTS, MEASURED ────────────────────────────────────────────────
 *
 *   the digest                       0.88 us, once per process, and NOT AT ALL
 *                                    when the config cache is compiled
 *   the file-cache read it protects  27 us   (MySQL suite, `file` store)
 *   the query that read replaces     291 us  (MySQL), 45-67 us (SQLite)
 *
 * So the cache is worth keeping — deleting it was the other candidate and the
 * numbers refuse it — and scoping it costs three per cent of one of its own
 * reads.
 *
 * ── AND WHY NOT ON THE KEYS ────────────────────────────────────────────────
 *
 * Because `Cache::forget` is called on these keys from about forty places, many
 * with literal strings (`kbb.home.rails` alone in eight). A scoped read beside
 * one missed literal forget is a cache that can never be invalidated — worse
 * than the bug, and the exact shape of CLAUDE.md's `recordManifest()` entry.
 */
it('gives two different databases two different cache directories', function () {
    /*
     * ▲ THE WHOLE POINT. Same connection driver, different database: the two
     * processes must not be able to see each other's entries at all.
     *
     * MUTATION NOTE. Make CacheScope::digest() ignore its $database argument —
     * return a constant — and this is red. RUN.
     */
    expect(CacheScope::digest('mysql', 'kbb_prod'))
        ->not->toBe(CacheScope::digest('mysql', 'kbb_staging'));

    // The SQLite shape the previews and the suite actually use: a file path.
    expect(CacheScope::digest('sqlite', '/w/lane-a/preview.sqlite'))
        ->not->toBe(CacheScope::digest('sqlite', '/w/lane-b/preview.sqlite'));

    /*
     * ▲ AND THE BASENAME ALONE IS NOT ENOUGH. Every preview script in this
     * repository names its database `preview.sqlite`; it is the DIRECTORY that
     * differs. A digest built from basename() would have collided on every one
     * of them and fixed nothing at all.
     */
    expect(CacheScope::digest('sqlite', '/w/lane-a/preview.sqlite'))
        ->not->toBe(CacheScope::digest('sqlite', '/w/lane-b/preview.sqlite'));

    // Same database, same answer, every time: a scope that moved would be a
    // cache that is never warm.
    expect(CacheScope::digest('mysql', 'kbb_prod'))->toBe(CacheScope::digest('mysql', 'kbb_prod'));
});

it('puts the database in the directory name, so a person can tell whose cache is whose', function () {
    /*
     * The digest is what makes it unique; the label is what makes the directory
     * listing readable, and it is sanitised to what a directory name may carry.
     *
     * MUTATION NOTE. Drop the preg_replace and pass a database called
     * `../../etc` — the first expectation is red, because the scope would carry
     * path separators into a path. RUN.
     */
    expect(CacheScope::digest('mysql', 'kbb_prod'))->toStartWith('kbb-prod-')
        ->and(CacheScope::digest('sqlite', '/w/lane-a/preview.sqlite'))->toStartWith('preview-sqlite-');

    foreach (['../../etc', 'a/b/c', 'weird name!', str_repeat('x', 200), '..'] as $nasty) {
        $scope = CacheScope::digest('sqlite', $nasty);

        expect($scope)->toMatch('/^[a-z0-9][a-z0-9-]*$/')
            ->and(str_contains($scope, '/'))->toBeFalse()
            ->and(str_contains($scope, '..'))->toBeFalse();
    }

    // Nothing to scope by is a defined answer, not an empty path segment.
    expect(CacheScope::digest('mysql', ''))->toBe(CacheScope::UNSCOPED)
        ->and(CacheScope::digest('mysql', '   '))->toBe(CacheScope::UNSCOPED);
});

it('actually points the file store at the scoped directory', function () {
    /*
     * The class being right is worth nothing if config/cache.php does not use
     * it. This reads the resolved configuration, which is what the file store
     * will open.
     *
     * A SUBDIRECTORY, not a suffix: `rm -rf storage/framework/cache/data/*` is
     * the reflex everybody has and `tools/im-sf-lines.sh` does it by name, so
     * the scope has to sit UNDER that path or clearing it would silently clear
     * nothing.
     *
     * MUTATION NOTE. Put `storage_path('framework/cache/data')` back in
     * config/cache.php and both expectations are red. RUN.
     */
    $path = (string) config('cache.stores.file.path');
    $base = storage_path('framework/cache/data');

    expect($path)->toStartWith($base.'/')
        ->and(basename($path))->toBe(CacheScope::digest());

    // The lock path travels with it, or two databases would share locks over
    // entries they cannot see.
    expect((string) config('cache.stores.file.lock_path'))->toBe($path);
});

it('leaves the suite itself on the array store, which is why the suite never saw this', function () {
    /*
     * Said out loud because it is the reason this went unnoticed for so long
     * and the reason it cannot be caught by an ordinary test: phpunit.xml sets
     * CACHE_STORE=array, so the suite has never touched the file store at all.
     * Everything that was bitten was a PREVIEW or an artisan command, where the
     * default is `file` and the directory was shared.
     *
     * MUTATION NOTE. Change CACHE_STORE to `file` in phpunit.xml and this is
     * red — and the suite then writes cache files, which is what the array
     * store is there to avoid. RUN.
     */
    expect(config('cache.default'))->toBe('array');
});
