<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/*
 * Owner app → Customise app (Lane OA4) adds two admin routes
 * (GET/PUT admin-api/owner-app/ui) and changes the app's shell view (the font
 * preload follows the font choice). A compiled route table and Blade cache
 * would keep serving the old shape, so both are cleared with the rest
 * (CLAUDE.md, "How this ships").
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
