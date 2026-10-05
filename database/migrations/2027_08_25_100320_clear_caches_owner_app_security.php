<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/*
 * The owner app's security review (Lane SEC) adds routes (a catch-all 404
 * inside the app's group, the admin's security and unlock endpoints) and can
 * mount the app on its own host. A compiled route table, config, service list
 * and Blade cache would each keep serving the old shape, so all four are
 * cleared (CLAUDE.md, "How this ships").
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
            \Illuminate\Support\Facades\Cache::forget('kbb.owner_app_path');
            \Illuminate\Support\Facades\Cache::forget('kbb.owner_app_host');
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
