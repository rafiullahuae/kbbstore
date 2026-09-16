<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the Media Library.
 *
 * THE ROUTE CACHE IS THE POINT. This package adds four routes —
 * GET /admin-api/media, POST /admin-api/media/rescan, GET and DELETE
 * /admin-api/media/{media} — in routes/media-library-admin.php, required from
 * routes/web.php. On this host the compiled route cache is a PHP file under
 * bootstrap/cache/, and the router reads that file in preference to the route
 * definitions. Until it is deleted the new routes DO NOT EXIST: every request
 * to them 404s, the screen shows an empty grid with an error, and the package
 * reports success. CLAUDE.md states the convention plainly — every package that
 * adds a route ships a clear_caches_* migration — and this is that.
 *
 * VIEWS MATTER TOO, and for the reason that does not self-correct. Compiled
 * Blade is keyed by path, and resources/views/admin/app.blade.php ALREADY
 * EXISTS on the server. Its compiled copy would keep being served, so the new
 * @include of admin.partials.media-library-screen would not be there, the
 * screen would never register its route override, and Media Library would go on
 * showing the "isn't installed yet" card the owner is asking about — while
 * every other part of this package worked. That is precisely the shape of
 * failure that makes a package look inert.
 *
 * Two new PHP classes ship with this (App\Support\MediaUsage,
 * App\Support\MediaBackfill, App\Http\Controllers\Admin\MediaLibraryApiController)
 * and two are changed (App\Models\Media, Admin\MediaUploadController). A stale
 * OPcache entry for a changed file is the usual risk and opcache_reset() is
 * cheap. Packages 2.60.102-.106 are the standing reminder of what guessing
 * wrong costs on a host with no shell.
 *
 * No schema change, and nothing here positions a column with an AFTER clause.
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
            echo "Cleared {$cleared} compiled files.\n";
        }
    }

    public function down(): void {}
};
