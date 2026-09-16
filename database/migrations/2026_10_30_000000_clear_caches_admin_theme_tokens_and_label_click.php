<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Clear compiled caches for the admin theme-token pass and the label click.
 *
 * Three Blade files changed and every one of them is compiled and cached:
 *
 *   resources/views/admin/app.blade.php          — the shared <style> block
 *                                                  (console chrome now reads
 *                                                  its colours from the tokens)
 *                                                  and the shared script block
 *                                                  at the foot, where clicking
 *                                                  the words beside a tick box
 *                                                  is forwarded to the box.
 *   resources/views/admin/partials/media-picker.blade.php
 *   resources/views/admin/login.blade.php
 *
 * Without this the console keeps serving the previous compiled copy on a server
 * where the package applied cleanly: the dark theme would still leave the icon
 * buttons, the user chip and the modal glowing white, and the words beside
 * every tick box would still do nothing. That is the worst shape this failure
 * takes — the change present on disk and absent on the screen — and it is the
 * standing reason behind the withdrawn packages 2.60.102-.106 in CLAUDE.md.
 *
 * No route changed, so the route cache is already correct; it goes with the
 * rest because the cost is nil and a half-cleared cache is the harder thing to
 * reason about afterwards.
 *
 * OPcache is the other half: the host cannot be restarted or shelled into, so
 * the PHP a package writes is not the PHP the server runs until OPcache lets
 * go of its copy.
 *
 * Setting::map() is forgotten because the console reads the chosen admin theme
 * through it, and which theme is in force is exactly what decides the colours
 * this change moved onto tokens. Its cache key carries no file fingerprint and
 * it memoises in a process-level static besides.
 *
 * No schema change. Nothing here positions a column with an AFTER clause, the
 * thing that made nine earlier migrations silent no-ops on MySQL.
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
        // no-op, and every setting behind it is re-read from the database.
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
