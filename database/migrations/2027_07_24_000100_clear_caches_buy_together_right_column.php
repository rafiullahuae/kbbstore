<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Lane RI: Appearance → Product page → Desktop sections — "Buy these
 * together" can be dragged into the Buy column (laptops only).
 *
 *   "ONLY IN DESKTOP: allow me option to bring the buy together section to
 *    the right collumn, by drag n drop."
 *
 * store/product.blade.php (the block can now be drawn inside `.buybox`), the
 * Desktop sections admin partial (cross-list drag) and ProductLayout (one new
 * slider, "Space above Buy these together · right column") changed; a stale
 * compiled view or a cached settings map would keep drawing the old way. The
 * usual sweep, as every clear_caches_* migration here.
 *
 * NO ROUTE IS ADDED: the placement saves through the existing
 * POST /admin-api/product-page, under its existing capability.
 *
 * NOTHING IS WRITTEN: the block stays under the two columns until he drags it,
 * and the new slider reads its code default (20px) while `pdplay_bt_gap_d` is
 * absent — so applying this package moves nothing.
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
