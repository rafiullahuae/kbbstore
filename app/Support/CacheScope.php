<?php

declare(strict_types=1);

namespace App\Support;

/**
 * One cache directory per DATABASE, instead of one per checkout.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * THE FILE CACHE WAS THE LAST UNNAMESPACED SHARED RESOURCE IN A WORKTREE,
 * AND IT HAS NOW COST TWO LANES A DAY EACH.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * ── WHAT WENT WRONG, TWICE, MEASURED BOTH TIMES ────────────────────────────
 *
 * `storage/framework/cache/data` is ONE directory for the whole checkout,
 * however separate the databases and web roots pointed at it are. Almost
 * everything this shop caches is READ OUT OF THE DATABASE and cached with
 * `rememberForever`, so a second process with a different database reads the
 * first one's answer back and believes it.
 *
 *   Lane AR   `tools/ar-preview.sh` served `<html lang="ar" dir="rtl">` on the
 *             LTR preview: SettingsService had cached the other preview's
 *             `language_rtl_enabled` under `kbb.settings`. In that lane's own
 *             words, "the single most misleading failure this harness could
 *             have", because which direction /ar renders in was the whole
 *             question it existed to answer.
 *
 *   Lane SEC  `tools/sec-preview.sh` answered 404 on /admin. Its own database
 *             had no `admin_path` row at all, and `kbb.admin_path` in this
 *             directory held `mr-cool` from something else entirely, so the
 *             console was at /mr-cool and nothing said so.
 *
 * Neither errors. Both look like data. That is the same class as `KBB_WP_DB`,
 * as `pkill -f`, and as the shared scratchpad — one shared resource, several
 * writers, and the collision is indistinguishable from a real answer.
 *
 * ── WHY THE FIX IS HERE AND NOT ON THE KEYS ────────────────────────────────
 *
 * Scoping the KEYS was the obvious repair and it is the wrong one. There are
 * dozens of them, many written as literal strings, and `Cache::forget` is
 * called on them from 40-odd places — `kbb.home.rails` alone is forgotten in
 * eight. A scoped read beside ONE missed literal forget is a cache that can
 * never be invalidated, which is a worse bug than the one being fixed and
 * exactly the shape CLAUDE.md's `recordManifest()` entry warns about.
 *
 * The STORE is one place, it covers every key that exists and every key anybody
 * adds later, and no call site changes at all.
 *
 * ── AND WHY THE SCOPE IS A SUBDIRECTORY ────────────────────────────────────
 *
 * `framework/cache/data/<scope>` rather than `framework/cache/data-<scope>`,
 * so that `rm -rf storage/framework/cache/data/*` — which
 * `tools/im-sf-lines.sh` does, and which is the reflex everybody has — still
 * clears every scope rather than silently clearing none.
 *
 * ── WHAT IT COSTS ──────────────────────────────────────────────────────────
 *
 * One sha1 of two short strings, computed when `config/cache.php` is evaluated,
 * which is once per process and NOT AT ALL when the config cache is compiled —
 * the derived path is frozen into it like every other config value. Measured
 * at well under a microsecond; the cache read it protects is 27us and the query
 * that read would otherwise be is 291us on MySQL.
 *
 * On the live shop there is one database, so there is one scope and the only
 * visible effect is that the directory has a name with a digest in it. The old
 * directory is left behind on the first deploy and holds nothing that is read.
 */
final class CacheScope
{
    /** The directory when nothing identifies the database. */
    public const UNSCOPED = 'shared';

    /**
     * A short, stable digest of the database this process talks to.
     *
     * READ FROM env() AND NOT FROM config(), deliberately: this is called from
     * `config/cache.php` while the configuration is still being assembled, and
     * `config('database.…')` there depends on file load order. `env()` is what
     * `config/database.php` itself reads, so the two cannot disagree.
     */
    public static function digest(?string $connection = null, ?string $database = null): string
    {
        $connection = $connection ?? (string) env('DB_CONNECTION', 'mysql');
        $database = $database ?? (string) env('DB_DATABASE', '');

        $connection = trim($connection);
        $database = trim($database);

        if ($database === '') {
            return self::UNSCOPED;
        }

        /*
         * The BASENAME is in the directory name as well as the hash, purely so
         * that a person looking at `storage/framework/cache/data/` can tell
         * whose cache is whose. The hash is what makes it unique; the name is
         * what makes it readable, and it is sanitised to the characters a
         * directory name may safely carry.
         */
        $label = preg_replace('/[^a-z0-9]+/i', '-', basename($database));
        $label = trim((string) $label, '-');
        $label = $label === '' ? 'db' : strtolower(substr($label, 0, 24));

        return $label.'-'.substr(sha1($connection.'|'.$database), 0, 10);
    }

    /** The file store's directory for this process's database. */
    public static function path(string $base): string
    {
        return rtrim($base, '/').'/'.self::digest();
    }
}
