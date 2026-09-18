<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear the compiled caches for Store → Import → "Addresses & pictures" —
 * Lane GB.
 *
 * FOUR NEW ROUTES SHIP WITH THIS PACKAGE, in routes/urls-media-admin.php:
 *
 *     GET  /admin-api/urls-media/status
 *     GET  /admin-api/urls-media/map.csv
 *     POST /admin-api/urls-media/redirects
 *     POST /admin-api/urls-media/media
 *
 * The route table is compiled on the server and the host has no shell, so a
 * route added by an update package does not exist until `bootstrap/cache/
 * routes-*.php` is gone. Without this file the new screen's buttons all 404 on
 * a site whose routes are cached, and the screen looks broken in a way that
 * says nothing about why. CLAUDE.md makes the pairing a convention for exactly
 * this reason.
 *
 * `config.php`, `services.php` and `packages.php` go too, for the reason every
 * clear_caches migration here gives: a compiled config left beside a cleared
 * route cache is the file most likely to be stale for an unrelated reason, and
 * the cost is one rebuild on the next request.
 *
 * The compiled views go as well. This lane ships no Blade of its own — the
 * admin screen's two blocks are in docs/GB-MEDIA-AND-REDIRECTS.md for the
 * integrator to apply to resources/views/admin/app.blade.php, which this lane
 * may not edit — but the package that carries them lands that file, and
 * compiled Blade is keyed by path with a filemtime comparison that an unzip
 * does not reliably win.
 *
 * NO SCHEMA CHANGES. Nothing in this lane adds a column or a table: the URL map
 * writes into `redirects`, which has existed since the original schema
 * migration, and the media rewrite edits four columns that already exist.
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
            echo "Cleared {$cleared} compiled files; Store → Import can now build the URL\n";
            echo "map and take the product photographs off the old site.\n";
        }
    }

    public function down(): void {}
};
