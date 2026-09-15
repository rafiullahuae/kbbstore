<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the product gallery <img> change.
 *
 * VIEWS are the whole point again. Two Blade files changed —
 * resources/views/partials/product-gallery.blade.php and
 * resources/views/store/product.blade.php — and both already exist on the
 * server, which is precisely the case that does not self-correct: compiled
 * Blade is keyed by path, so the product page would keep rendering the
 * previous compiled copy. The gallery would still paint every photograph as a
 * CSS background and the tab would still repeat the brand, making the package
 * look inert while reporting success.
 *
 * A new PHP class ships with this one (app/Support/ProductTitle.php), and it is
 * referenced from compiled Blade. A stale OPcache entry for a file that did not
 * previously exist is not the usual risk, but the compiled views that call it
 * are the risk, and opcache_reset() is cheap. Packages 2.60.102-.106 are the
 * standing reminder of what guessing wrong costs on a host with no shell.
 *
 * No route was added, so no route cache concern beyond the routine clear below.
 *
 * No schema change, and nothing here positions a column with an AFTER clause —
 * the thing that made nine earlier migrations in this repo silent no-ops on
 * MySQL.
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
