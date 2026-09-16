<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Clear compiled caches for the Mail screen repaint and the keyboard-operable
 * tick boxes.
 *
 * One Blade file changed, resources/views/admin/app.blade.php, and it changed
 * in three places: the mlf- stylesheet block, the Store -> Mail render code,
 * and the shared tick-box keyboard block at the foot of the main script. All
 * three live in the compiled view cache, so without this the console keeps
 * serving the old copy — the Mail screen would still be a flat list of boxes
 * and the tick boxes would still be unreachable from a keyboard, on a server
 * where the package had in fact applied cleanly. That is the worst version of
 * this failure: the change is present on disk and absent on screen.
 *
 * No route changed, so the route cache is already correct; it is cleared with
 * the rest because the cost is nil and a half-cleared cache is the harder thing
 * to reason about afterwards.
 *
 * OPcache is the other half. The host cannot be restarted or shelled into, so
 * the PHP a package writes is not the PHP the server runs until OPcache lets go
 * of the old copy — the standing reason behind the withdrawn packages
 * 2.60.102-.106 recorded in CLAUDE.md.
 *
 * Setting::map() is forgotten for the usual reason: its cache key carries no
 * file fingerprint, and it memoises in a process-level static besides. No mail
 * setting is read through it by this change, but the console reads the admin
 * theme through it, and the whole point of moving Mail onto tokens is that the
 * theme decides its colours.
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
