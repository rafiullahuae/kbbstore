<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for 2.60.338 -- the import, rehearsed. (Integrator)
 *
 * No route is added this round, so the route cache is not the reason. Two
 * things are:
 *
 * THE VIEW CACHE. resources/views/components/product-card.blade.php now asks
 * App\Support\LostPictures whether a picture the migration could not bring
 * across should draw the placeholder instead of a broken <img>, and
 * resources/views/admin/media-progress.blade.php keeps fetching while pictures
 * are still untried. Compiled Blade is keyed by path, so a stale copy would
 * serve the old card and the old progress loop over the new code.
 *
 * OPCACHE, for the classes that are NEW rather than changed --
 * App\Support\LostPictures and App\Console\Commands\ImportMediaFetch -- and
 * for the importers that gained alreadyCommitted() and the negative-price
 * refusal. A long-lived worker holding the old bytecode would resume a killed
 * import with the defect this package fixes.
 *
 * Same body as 2027_07_02_000000_clear_caches_import_parts.php.
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
