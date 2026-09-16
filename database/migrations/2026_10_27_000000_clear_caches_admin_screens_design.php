<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the Analytics / Business Details / SEO redesign.
 *
 * One Blade file changed (admin/app.blade.php) and the whole change lives in
 * its markup and its stylesheet, so without this the compiled view cache keeps
 * serving the old three screens and the owner applies the package, opens
 * Analytics, sees the same four squashed figures and concludes it failed. That
 * conclusion has been reached before on this project, which is why every
 * package ships one of these.
 *
 * No route changed, so the route cache is correct either way; it is cleared
 * with the rest because the cost is nil and a half-cleared cache is the harder
 * thing to reason about.
 *
 * OPcache is the other half. The host cannot be restarted or shelled into, so
 * the PHP a package writes is not the PHP the server runs until OPcache lets
 * go of the old copy — the standing reason behind the withdrawn packages
 * 2.60.102-.106 recorded in CLAUDE.md.
 *
 * Nothing is forgotten from the cache store here. The three screens read their
 * values over /admin-api/settings and /admin-api/analytics on every visit;
 * Setting::map()'s cached copy is what those endpoints already read, and this
 * change does not touch a single setting's value.
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
