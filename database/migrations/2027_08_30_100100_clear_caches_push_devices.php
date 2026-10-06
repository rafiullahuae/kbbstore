<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/*
 * Lane PD adds three routes to routes/push-admin.php (GET push/devices, PUT
 * push/devices/{id}, POST push/devices/test) under the existing push.view and
 * push.send. A route added in routes/ does not take effect until the compiled
 * route cache is cleared, so this package clears it, the way every package
 * that adds a route does (CLAUDE.md).
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
