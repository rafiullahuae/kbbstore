<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear the compiled caches for the media sideloader and its live progress
 * page — Lane GD.
 *
 * FOUR NEW ROUTES SHIP WITH THIS PACKAGE, in routes/media-sideload-admin.php:
 *
 *     GET  /admin-api/urls-media/progress
 *     GET  /admin-api/urls-media/progress-page
 *     GET  /admin-api/urls-media/sideload.csv
 *     POST /admin-api/urls-media/sideload
 *
 * The route table is compiled on the server and the host has no shell, so a
 * route added by an update package does not exist until `bootstrap/cache/
 * routes-*.php` is gone. Without this file the Fetch button 404s on a site
 * whose routes are cached, and — worse here than elsewhere — the live progress
 * page the owner was told to open would not exist at all, so the one screen
 * built to tell him what is happening would be the screen that is missing.
 * CLAUDE.md makes the pairing a convention for exactly this reason.
 *
 * THE COMPILED VIEWS MATTER MORE THAN USUAL FOR THIS ONE. This lane ships a
 * Blade file of its own — resources/views/admin/media-progress.blade.php — and
 * compiled Blade is keyed by path with a filemtime comparison that an unzip
 * does not reliably win. A stale compiled view here is a blank page.
 *
 * `config.php`, `services.php` and `packages.php` go too, for the reason every
 * clear_caches migration here gives: a compiled config left beside a cleared
 * route cache is the file most likely to be stale for an unrelated reason, and
 * the cost is one rebuild on the next request.
 *
 * THE SCHEMA CHANGE IS IN ITS OWN MIGRATION and lands before this one —
 * 2026_09_18_000000_create_media_sideload_tables.php. Two jobs, two files, in
 * the order the timestamps give: a route that answers before its tables exist
 * is a 500 on the first press.
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
            echo "Cleared {$cleared} compiled files; the shop can now fetch its own photographs\n";
            echo "from the old site, and the live progress page can be opened.\n";
        }
    }

    public function down(): void {}
};
