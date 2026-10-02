<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Clear compiled caches for the "Buy these together" bundle discount. (Lane RE)
 *
 * No route is new. Blade changed: partials/fbt.blade.php (the carousel, the
 * struck total and the savings line), the cart page, the cart drawer, the
 * checkout's summary and order block, the order-received summary and the
 * account order page (the bundle rows), and the admin screen partial (the
 * tiers card). Compiled views are cleared so none of them is served stale;
 * the settings map is forgotten so the four new settings are read fresh.
 *
 * Dated 2027_07_19_000900: after this lane's schema (000100) and its Arabic
 * drafts (000200), so it runs last.
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
        foreach (['kbb.settings', 'kbb.settings.map', 'kbb.modules', 'kbb.module_settings', 'kbb.bt.cats'] as $key) {
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
