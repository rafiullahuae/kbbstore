<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/*
 * Lane IR: routes/image-seo-admin.php (Catalog -> Image SEO), the
 * `media.image_seo` capability, the 404 hook that answers a renamed image's old
 * URL with a 301, and the Media Library tile's score. A compiled route cache
 * would hide the routes, a cached role map would hide the capability and
 * compiled views would keep the old tile, so all three are cleared here -- the
 * same body every other clear_caches_* migration carries.
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
