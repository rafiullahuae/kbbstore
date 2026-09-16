<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Clear compiled caches for the Coupons screen redesign.
 *
 * Two Blade files changed — admin/partials/coupon-editor-screen.blade.php and
 * admin/partials/coupon-usage-screen.blade.php. Both are @include-d into
 * admin/app.blade.php, so the console is served from ONE compiled view whose
 * cache key is app.blade.php's own path and mtime. That file is untouched by
 * this change, which is exactly the trap: without this migration the server
 * would keep serving yesterday's compiled console, the owner would open
 * Coupons, see the old messy screen, and conclude the package had not applied.
 * That failure mode has already cost a round of chasing on this project.
 *
 * No route changed — this is layout and hierarchy inside two partials, with
 * the same endpoints, the same payload and the same field ids. The route cache
 * is cleared with the rest anyway because the cost is nil and a half-cleared
 * cache is the harder thing to reason about.
 *
 * OPcache is the other half. The host cannot be restarted or shelled into, so
 * the PHP a package writes is not the PHP the server runs until OPcache lets
 * go of the old copy — the standing reason behind the withdrawn packages
 * 2.60.102-.106 recorded in CLAUDE.md. Compiled Blade is PHP, so a stale
 * OPcache entry keeps the old screen alive even after the view cache is gone.
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
        // no-op, and the console's settings are re-read from the database.
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
