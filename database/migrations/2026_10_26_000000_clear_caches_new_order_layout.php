<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the New Order screen's layout revision.
 *
 * One Blade file changed — admin/partials/manual-order-screen.blade.php — and
 * it is pulled into admin/app.blade.php at the very end, so the compiled view
 * BOTH files share is stale. Without this the server keeps rendering the old
 * two-column screen from storage/framework/views and the package looks like it
 * did nothing, which is how a package gets applied twice.
 *
 * No route changed, so the route cache is already correct; it is cleared with
 * the rest because the cost is nil and a half-cleared cache is the harder
 * thing to reason about later.
 *
 * OPcache is the other half. The host cannot be restarted or shelled into, so
 * the PHP a package writes is not the PHP the server runs until OPcache lets
 * go of the old copy — the standing reason behind the withdrawn packages
 * 2.60.102-.106 recorded in CLAUDE.md.
 *
 * Nothing here touches settings, so no Setting::map() cache key needs
 * forgetting: this change is markup and CSS only.
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

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        if (app()->runningInConsole()) {
            echo "Cleared {$cleared} compiled files.\n";
        }
    }

    public function down(): void {}
};
