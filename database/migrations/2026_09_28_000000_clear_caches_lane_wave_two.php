<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Clear compiled caches for the five-lane wave.
 *
 * Storefront controllers, SettingsService, BundleService, AdminController,
 * both admin list controllers, the whole new mail stack and MailServiceProvider
 * all changed, and the module registry gained five rows. Stale bytecode here
 * would keep the /app 500, keep bundle prices a fil light, keep Analytics
 * reporting zero units, and keep the store silent on every order — each of
 * which looks like the fix not working rather than a cache.
 *
 * MailServiceProvider gained a boot(), so bootstrap/cache/services.php and
 * packages.php are stale too; both are in the list below.
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
