<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Clear compiled caches for the mobile tab-bar switch and the Customers
 * error reporting.
 *
 * VIEWS. partials/mobile-chrome.blade.php now wraps the floating bottom bar in
 * an @if, and admin/app.blade.php reports the real HTTP status when the
 * Customers list fails. Compiled Blade under storage/framework/views is keyed
 * by template path, so a stale compile keeps rendering the bar for an owner who
 * has switched it off, and keeps showing the old guess-based error message.
 *
 * MODULES CACHE. ModuleRegistry gained the mobile_tabbar row, and
 * moduleEnabled() reads the module list through a rememberForever cache under
 * kbb.modules. Without forgetting it the new switch does not appear on Store →
 * Modules at all, which looks exactly like the feature not shipping.
 *
 * OpCache too: ModuleRegistry is a class with a changed constant. A worker
 * holding the previous compiled copy serves the old module list.
 *
 * No routes added, so nothing here depends on the route cache — but it is
 * cleared with the rest, as every package in this project does.
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
