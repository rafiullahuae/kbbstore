<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Clear compiled caches for the product page's delivery box, "Authenticity
 * Guaranteed" line and share bar. (Lane PW)
 *
 * Blade changed: store/product.blade.php gains two @include lines and
 * resources/views/partials/product/ is new; admin/app.blade.php includes
 * admin/partials/product-trust-share-screen. No route was added — the new
 * settings travel on the existing GET/POST /admin-api/product-page — but the
 * route and config caches are cleared anyway, as every clear_caches migration
 * here does, so a package applied over a cached box cannot serve a view
 * compiled before it. The settings map is forgotten so the new
 * Appearance → Product page → Trust · defaults are read fresh.
 *
 * OPcache is the other half, as in every clear_caches migration here.
 *
 * No schema change, and nothing below positions a column with an AFTER clause.
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
        foreach (['kbb.settings.map', 'kbb.shop.cats', 'kbb.shop.brands'] as $key) {
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
