<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/*
 * The shop as a Home Screen app (Lane PW) adds two route files
 * (routes/site-app.php, routes/site-app-admin.php), a capability
 * (siteapp.manage) and a block in the storefront head. A compiled route
 * table, config, service list and Blade cache would each keep serving the
 * shop without them, so all are cleared, and the roles cache so the new
 * capability is seen at once -- the same set every route-adding package in
 * this repository clears (CLAUDE.md, "How this ships").
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
