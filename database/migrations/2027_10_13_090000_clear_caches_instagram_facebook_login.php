<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/*
 * Lane IG2: Content -> Instagram gains "Connect with Facebook" (Instagram API
 * with Facebook Login) and three routes in routes/instagram-admin.php --
 * POST /admin-api/instagram/fb/app, /fb/check and /fb/pick. A compiled route
 * cache would hide them, so the compiled files and the cached role map are
 * cleared here -- the same body every other clear_caches_* migration carries.
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
