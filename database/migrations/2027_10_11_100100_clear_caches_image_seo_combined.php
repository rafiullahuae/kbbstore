<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/*
 * Lane IS2: Catalog -> Image SEO gains POST image-seo/selection (the
 * server-resolved "all N matching, except these" selection) and loses
 * POST image-seo/ids, and its screen partial changes. A compiled route cache
 * would keep the old table and compiled views the old screen, so both are
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
