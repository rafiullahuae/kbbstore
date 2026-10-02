<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Clear compiled caches for the old shop's category title header and the
 * "Links to the old site" row. (Lane PT)
 *
 * Blade changed: store/shop.blade.php and store/brands.blade.php draw the new
 * components/kbb-title-header.blade.php, and admin/app.blade.php carries the new
 * row on Store -> Store Import / Export -> Addresses & pictures. A route was
 * added to routes/urls-media-admin.php -- a file routes/web.php already
 * requires, so nothing new is wired, but `route:cache` compiled the routes it
 * found at the time and POST /admin-api/urls-media/old-links does not exist on
 * the server until that cache is gone. The settings map is forgotten so the new
 * Appearance -> Site layout -> Category header defaults are read fresh, and the
 * shop's sidebar caches for the reason the banner migration gives.
 *
 * OPcache is the other half, as in every clear_caches migration here.
 *
 * No schema change here (the two sibling migrations), and nothing below
 * positions a column with an AFTER clause.
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
