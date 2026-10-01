<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for "convert a product to a set". (Lane PI-A, item 10)
 *
 * resources/views/admin/partials/product-editor-screen.blade.php now names the
 * conversion on a saved product's Product type field, asks once before it
 * happens, and says on a set's Stock card what its own count means. Compiled
 * Blade is keyed by path, so without this the console keeps drawing the old
 * screen.
 *
 * Same body as 2027_07_05_000000_clear_caches_cleanup_button.php.
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
