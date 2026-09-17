<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Drop the compiled views, because this package adds a Blade partial that
 * resources/views/admin/app.blade.php now includes (T1b, Lane FC).
 *
 * NO NEW ROUTES. All nine /admin-api/translations/* endpoints already exist and
 * routes/web.php already requires routes/translations-admin.php inside the
 * admin-api group — the bilingual foundation shipped both, with its own
 * clear_caches migration. This package adds a SCREEN that calls them and adds
 * not one route, so the compiled route table is correct as it stands.
 *
 * WHY THE COMPILED VIEWS MUST GO, SPECIFICALLY. Blade names a compiled file by
 * a hash of the view's PATH, never its contents, and serves the compiled copy
 * until something deletes it or the source is seen to be newer. The freshness
 * check is a filemtime comparison, and an update package is an unzip: the
 * timestamps it lands are whatever the archive carried, which on this host is
 * not reliably newer than a compiled file written by the running site. If the
 * stale compiled app.blade.php survives, the console renders WITHOUT the
 * @include — so the Translation group appears in the sidebar (its NAV, TITLES
 * and LATE_RENDERED entries are in the same file, and the same stale copy would
 * omit those too) or, worse, with a half-applied pair: rows that route to a
 * partial the document never loaded. That is the silent dashboard-under-
 * another-screen's-heading failure LATE_RENDERED exists to prevent, arriving by
 * a different door.
 *
 * NO SCHEMA CHANGE AND NOTHING SEEDED, which is the whole of "everything off by
 * default". `language_ar_enabled` and `language_rtl_enabled` are absent after
 * this package exactly as they were before it, and SettingsService::get()
 * returns its default only when a row is ABSENT — so absent means off, /ar
 * stays a 404, and applying this changes nothing a shopper can see. Writing a
 * '0' here would be the same state by a longer route and one more row to get
 * wrong later.
 *
 * bootstrap/cache/config.php goes with them only because a package that lands
 * new code while a compiled container describes the old one is the failure this
 * whole convention exists for, and deleting it costs one rebuild on the next
 * request.
 *
 * Best-effort throughout, like every other clear_caches migration in this set:
 * a file that cannot be unlinked mid-update must not fail the package and
 * strand the site half-updated. A stale cache is a visible bug; a failed
 * migration is an outage.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cleared = 0;

        foreach ([
            storage_path('framework/views/*.php'),
            base_path('bootstrap/cache/config.php'),
        ] as $pattern) {
            foreach (glob($pattern) ?: [] as $file) {
                if (is_file($file) && @unlink($file)) {
                    $cleared++;
                }
            }
        }

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        if (app()->runningInConsole()) {
            echo "Cleared {$cleared} compiled files; the admin console includes a new partial\n";
            echo "(admin/partials/translation-screens.blade.php) and a stale compiled app.blade.php\n";
            echo "would render the Translation menu without the screens behind it.\n";
        }
    }

    /**
     * Nothing to undo. Deleting a cache is not a change to reverse, and a down()
     * that rebuilt one would be rebuilding it from the code that is live at the
     * moment of the rollback — which is the state the rollback is leaving.
     */
    public function down(): void {}
};
