<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Clear compiled caches for the Lane Z security sweep.
 *
 * Two classes changed and both have to be the new copy on the live host:
 *
 *   - Admin\NewsletterApiController grew a csvCell() guard and now routes every
 *     exported cell through it. A worker still holding the previous compiled
 *     copy keeps streaming the subscriber list unescaped, which is the whole
 *     bug — and it does it silently, because the export still downloads and
 *     still looks right until the owner opens it in Excel.
 *   - Store\CustomerAuthController gained firstName() and no longer reads the
 *     default guard for the greeting. A stale copy throws nothing and breaks
 *     nothing; it simply keeps the old behaviour, so the admin's name goes on
 *     being flashed to shoppers with no sign that the fix did not land.
 *
 * No route was added, so the route cache is not strictly load-bearing here; it
 * is cleared anyway because these packages are applied by hand on a host with
 * no shell, and a half-cleared cache is the failure mode nobody can debug from
 * the admin panel.
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

        Cache::forget('kbb.modules');
        Cache::forget('kbb.settings');
        Cache::forget('kbb.settings.map');

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        if (app()->runningInConsole()) {
            echo "Cleared {$cleared} compiled files.\n";
        }
    }

    public function down(): void {}
};
