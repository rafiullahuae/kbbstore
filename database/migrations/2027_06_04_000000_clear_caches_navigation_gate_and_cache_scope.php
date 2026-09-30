<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * Clear the compiled caches for the twelfth and thirteenth navigations, and for
 * the per-database cache scope. (Lane SEC, round 4 — shipped in 2.60.329)
 *
 * ▲ THIS MIGRATION WAS VERY NEARLY NOT WRITTEN, AND THE REASONING THAT ALMOST
 * SKIPPED IT IS WORTH KEEPING, because it is the exact shape CLAUDE.md warns
 * about. The release commit first said "no migration: nothing in this range
 * changes a route, and the two partials are console screens". Both halves of
 * that are wrong:
 *
 *   TWO EDITED BLADES. admin/partials/reviews-io-screen and instagram-screen
 *   are @included by admin/app.blade.php, so what the server has compiled is a
 *   copy of the CONSOLE with the old partials inlined into it. On this host the
 *   compiled views outlive the files they came from. Without a view:clear the
 *   Reviews.io Export button goes on replacing the whole screen with a 404 and
 *   the Instagram fallback goes on taking the console away — which is to say,
 *   the package applies and changes nothing, and nothing errors. That is the
 *   silent half of this class of defect.
 *
 *   A CHANGED config/cache.php. App\Support\CacheScope gives the file store one
 *   directory per database, and config/cache.php is read THROUGH THE COMPILED
 *   CONFIG when one exists. A server holding a cached config keeps the old
 *   single directory, so the fix is inert and, again, nothing errors.
 *
 * route:clear is included although this range adds no route. It costs nothing,
 * and a half-cleared cache is the confusing state — the same argument
 * 2027_05_11_000000_clear_caches_placing_overlay makes for running config:clear
 * when only views moved.
 *
 * ── WHAT THIS DOES **NOT** DO, AND MUST NOT ───────────────────────────────
 *
 * It does not delete anything under storage/framework/cache/data. The scope
 * change means a shop's existing entries are now read from a subdirectory that
 * is empty, so every scoped key MISSES ONCE and is recomputed from the database
 * — which is correct, cheap, and self-healing. Deleting the old entries would
 * be a write this migration has no reason to make, and `rm -rf` on that
 * directory by hand still clears every scope because they are subdirectories of
 * it.
 *
 * ▲ AND `migrations` IN update.json IS WHAT DECIDES WHETHER THIS RUNS AT ALL.
 * UpdateRunner::hasMigrations() reads that flag and never looks at the files.
 * Build the package with `php artisan kbb:package <version> --since=<ref>`.
 *
 * The guard wraps a call that writes no model state, so nothing is left dirty
 * for a later save to re-send — the distinction CLAUDE.md's swallowed-exception
 * landmine turns on.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['view:clear', 'config:clear', 'route:clear'] as $command) {
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
