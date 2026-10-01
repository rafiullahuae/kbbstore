<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the set price button and Visit. (Lane PK)
 *
 * resources/views/admin/partials/product-editor-screen.blade.php: a set's
 * "Use this total" is now "Apply to Price and Sale price" and fills the Price
 * card, and the editor's bar carries Visit. resources/views/admin/app.blade.php:
 * Catalog -> Products rows carry Visit beside Edit. Compiled Blade is keyed by
 * path, so without this the console keeps drawing the old screens -- including
 * the old button, which wipes a set's discount.
 *
 * Same body as 2027_07_07_000000_clear_caches_convert_to_set.php.
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
