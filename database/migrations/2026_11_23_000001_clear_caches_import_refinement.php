<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear the compiled caches for the import refinement — Lane GF.
 *
 * THREE NEW ROUTES SHIP WITH THIS PACKAGE, in routes/import-history-admin.php:
 *
 *     GET  /admin-api/import/history
 *     GET  /admin-api/import/history-page
 *     GET  /admin-api/import/history.csv
 *
 * The route table is compiled on the server and the host has no shell, so a
 * route added by an update package does not exist until `bootstrap/cache/
 * routes-*.php` is gone. Without this file the page that answers "what has
 * this shop already imported" 404s on a site whose routes are cached — and
 * that page is the one the owner is sent to when he is trying to work out
 * whether he needs to import at all. CLAUDE.md makes the pairing a convention
 * for exactly this reason.
 *
 * THE COMPILED VIEWS MATTER AS MUCH AS THE ROUTES HERE. This lane ships one
 * Blade file of its own — resources/views/admin/import-history.blade.php — and
 * EDITS ANOTHER, resources/views/admin/media-progress.blade.php, which now
 * draws a bar per entity. Compiled Blade is keyed by path with a filemtime
 * comparison an unzip does not reliably win, so a stale compiled view is a
 * blank page for the new one and the OLD table for the edited one, which is
 * worse: it looks like the feature shipped and did nothing.
 *
 * `config.php`, `services.php` and `packages.php` go too, for the reason every
 * clear_caches migration here gives: a compiled config left beside a cleared
 * route cache is the file most likely to be stale for an unrelated reason, and
 * the cost is one rebuild on the next request.
 *
 * THE SCHEMA CHANGE IS IN ITS OWN MIGRATION and lands before this one —
 * 2026_11_23_000000_create_import_history.php. Two jobs, two files, in the
 * order the timestamps give: a route that answers before its table exists is a
 * 500 on the first press.
 *
 * Best-effort, like every clear_caches migration here: a file that cannot be
 * unlinked mid-update must not fail the package and strand the site
 * half-updated. A stale cache is a visible bug; a failed migration is an outage.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cleared = 0;

        foreach ([
            storage_path('framework/views/*.php'),
            base_path('bootstrap/cache/config.php'),
            base_path('bootstrap/cache/routes-*.php'),
            base_path('bootstrap/cache/services.php'),
            base_path('bootstrap/cache/packages.php'),
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
            echo "Cleared {$cleared} compiled files; the import now recognises an export it has\n";
            echo "already read, and there is a page saying what has been imported and when.\n";
        }
    }

    public function down(): void {}
};
