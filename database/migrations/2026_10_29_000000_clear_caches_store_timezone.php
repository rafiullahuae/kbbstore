<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Clear compiled caches for the shop-clock change.
 *
 * WHAT CHANGED, AND WHY A STALE COPY WOULD BE WORSE THAN NO CHANGE.
 *
 * Dates across the admin panel are now read on the shop's own clock
 * (App\Support\StoreTime, default Asia/Dubai) instead of on UTC, and every
 * money figure now excludes the orders Store -> Demo Content seeds
 * (App\Support\DemoSeed). Both are pure PHP, and the host cannot be shelled
 * into or restarted, so the PHP a package writes is not the PHP the server
 * runs until OPcache lets go of the old copy — the standing reason behind the
 * withdrawn packages 2.60.102-.106 recorded in CLAUDE.md.
 *
 * resources/views/admin/app.blade.php changed with them: the Business Details
 * screen gains the Time zone control that writes `store_timezone`, the
 * Dashboard says how many demo orders it left out, and the Analytics chart
 * names the zone its bars are drawn in. A stale compiled view would leave the
 * owner a panel that reads Dubai dates with no control to change the zone and
 * a chart caption still claiming a clock it is no longer using — a screen
 * describing behaviour the code does not have, which is the exact failure mode
 * this project keeps paying for.
 *
 * No route was added, so the route cache is dropped only for consistency with
 * the other entries here. The settings map is forgotten because
 * `store_timezone` is a new key and Setting::map() caches the whole map.
 *
 * No schema change, and nothing here positions a column with an AFTER clause,
 * the thing that made nine earlier migrations silent no-ops on MySQL.
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
        // no-op, and the settings are re-read from the database anyway.
        try {
            Cache::forget('kbb.settings.map');
        } catch (\Throwable) {
            // Nothing to do — a missing cache store is not a failed migration.
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
