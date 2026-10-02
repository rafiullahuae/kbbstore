<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Lane RF: Appearance → Product page → Desktop sections — the laptop order of
 * the four full-width blocks under the two columns.
 *
 * store/product.blade.php (the wrapper's class and style) and the admin
 * console (a new screen partial, included from the Mobile sections partial)
 * changed, and a stale compiled copy of either would keep drawing the old
 * template — or a new template's markup against an old one's. The usual
 * sweep, as every clear_caches_* migration here.
 *
 * NO ROUTE IS ADDED: the tab saves through the existing
 * POST /admin-api/product-page, under its existing capability.
 *
 * NOTHING IS WRITTEN. The order reads its default when `pdpds_order` is
 * absent, and the default order prints nothing at all onto the page, so
 * applying this package moves nothing until he drags a block.
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
        foreach (['kbb.settings', 'kbb.settings.map', 'kbb.modules', 'kbb.module_settings'] as $key) {
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
