<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * Clear the compiled caches for the import-history routes and the moved CSS.
 *
 * TWO KINDS OF STALENESS, and this package carries both.
 *
 *   A NEWLY MOUNTED ROUTE FILE. routes/import-history-admin.php is required
 *   from routes/web.php for the first time in this package -- it never was
 *   before, which is why GET /admin-api/import/history, /history-page and
 *   /history.csv have answered 404 since the screen was written. The router
 *   dispatches against bootstrap/cache/routes-*.php and not against the
 *   source, so without this the corrected web.php copies across and the link
 *   in the admin goes on 404-ing.
 *
 *   A CHANGED BLADE AND CHANGED STYLESHEETS. The email layout gains its
 *   direction attribute, the set panel's rules move to logical padding, and
 *   two compiled stylesheets change name. A cached view is the old one.
 *
 * ▲ `migrations` IN update.json IS WHAT DECIDES WHETHER THIS RUNS AT ALL.
 *   UpdateRunner::hasMigrations() reads that flag and never looks at the files.
 *   The builder sets it from the presence of a file like this one; a package
 *   that adds a route and ships no migration declares false and clears
 *   nothing. That is not hypothetical -- 2.60.322 was built exactly that way
 *   and rebuilt before it went anywhere.
 *
 * Every call is guarded on its own: a host with no cached routes answers
 * route:clear happily, a read-only bootstrap/cache throws, and a cache that
 * cannot be cleared is one the next deploy clears. It is never a reason to
 * fail an update and strand the shop mid-package. The guard is safe in the
 * sense CLAUDE.md's swallowed-exception landmine is about -- it wraps a call
 * that writes no model state, so it leaves nothing dirty behind it.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['route:clear', 'view:clear', 'config:clear'] as $command) {
            try {
                Artisan::call($command);
            } catch (\Throwable) {
                // A cache that cannot be cleared is one the next deploy clears.
            }
        }
    }

    public function down(): void
    {
        // Nothing to undo: this migration has no schema and no data.
    }
};
