<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Clear compiled caches for the Analytics date filter.
 *
 * resources/views/admin/app.blade.php changed — the whole Analytics screen was
 * rewritten around the new filter — so the compiled view cache is stale and the
 * server would keep rendering the old fourteen-day screen from it, against an
 * endpoint that no longer sends `daily` or `daily_peak_aed`. That combination
 * does not error; it draws an empty chart, which is the worst of the three
 * possible outcomes because nobody would know to look.
 *
 * No route changed — /admin-api/analytics already existed and only gained query
 * parameters — so the route cache is correct either way. It is cleared with the
 * rest because the cost is nil and a half-cleared cache is the harder thing to
 * reason about.
 *
 * OPcache is the other half. The host cannot be restarted or shelled into, so
 * the PHP a package writes is not the PHP the server runs until OPcache lets go
 * of the old copy — the standing reason behind the withdrawn packages
 * 2.60.102-.106 recorded in CLAUDE.md. app/Support/AnalyticsRange.php is a NEW
 * class, and AdminController now references it, so a stale controller beside a
 * fresh autoload map is exactly the shape that takes a screen down.
 *
 * config.php is in the list for a specific reason this time: AnalyticsRange::
 * timezone() reads `kbb.display_timezone`, so a cached config would keep the
 * shop on whatever timezone was compiled in.
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
