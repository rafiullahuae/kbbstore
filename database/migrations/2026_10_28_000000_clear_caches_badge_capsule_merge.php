<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Clear compiled caches for the Badge Themes / Rating Capsule merge.
 *
 * Three Blade files changed — admin/app.blade.php (one NAV row removed, two
 * TITLES entries renamed), admin/partials/review-badges-screen.blade.php (the
 * merged screen) and admin/partials/review-capsule-screen.blade.php (now inert)
 * — so the compiled view cache is stale. That matters more than usual here: the
 * whole console is ONE document, and a cached copy would keep drawing the old
 * sidebar, with a "Rating Capsule" row whose screen no longer claims that id.
 * A menu that is one update behind is indistinguishable from an update that
 * failed, which is the exact conclusion the owner drew last time.
 *
 * No route changed — everything here is client-side and the endpoints
 * (/admin-api/review-badges) are untouched — so the route cache is correct
 * either way; it is cleared with the rest because the cost is nil and a
 * half-cleared cache is the harder thing to reason about.
 *
 * OPcache is the other half. The host cannot be restarted or shelled into, so
 * the PHP a package writes is not the PHP the server runs until OPcache lets go
 * of the old copy — the standing reason behind the withdrawn packages
 * 2.60.102-.106 recorded in CLAUDE.md.
 *
 * Setting::map() is forgotten explicitly because its key carries no mtime
 * fingerprint and the seven badge switches are read through it. Nothing in this
 * package changes a setting's value; the forget is here so a stale process-wide
 * copy cannot make a correct screen look wrong on the first visit after apply.
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
        // no-op, and the badge settings are re-read from the database anyway.
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
