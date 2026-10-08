<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/*
 * Appearance -> Coming Soon page (Lane CS): the package adds admin routes
 * (routes/coming-soon-admin.php), a capability (`comingsoon.manage`) and a
 * console partial, so the compiled route, config and view caches and the
 * cached role map are cleared, as every package that adds a route does
 * (CLAUDE.md). It writes NO setting: the page ships OFF, because the key is
 * absent until the owner saves the screen, and absent reads as off.
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
            base_path('bootstrap/cache/events.php'),
            base_path('bootstrap/cache/services.php'),
            base_path('bootstrap/cache/packages.php'),
        ] as $pattern) {
            foreach (glob($pattern) ?: [] as $file) {
                if (is_file($file) && @unlink($file)) {
                    $cleared++;
                }
            }
        }

        try {
            \Illuminate\Support\Facades\Cache::forget(\App\Support\AdminRoles::CACHE_KEY);
        } catch (\Throwable) {
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
