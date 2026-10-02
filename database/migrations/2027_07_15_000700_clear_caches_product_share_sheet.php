<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Clear compiled caches for the product page's share sheet. (Lane QB)
 *
 * Blade changed: partials/product/share-bar is deleted, partials/product/
 * share-sheet is new and is included from trust-share-stack, and the admin
 * screen's Share tab changed. A view compiled before this package would still
 * include a partial that no longer exists — a 500 on every product page — so
 * the compiled views MUST go. No route was added; the route and config caches
 * are cleared anyway, as every clear_caches migration here does. The settings
 * map is forgotten so the sheet's new defaults are read fresh.
 *
 * Dated 2027_07_15_000700: clear of Lane QA's 2027_07_15_000000, and after
 * this lane's own two migrations so it runs last.
 *
 * No schema change.
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

        // Best effort: on a host with no cache table or a cold store this is a
        // no-op, and every one of these values is re-read from the database.
        foreach (['kbb.settings', 'kbb.settings.map', 'kbb.shop.cats', 'kbb.shop.brands'] as $key) {
            try {
                Cache::forget($key);
            } catch (\Throwable) {
                // A missing cache store is not a failed migration.
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
