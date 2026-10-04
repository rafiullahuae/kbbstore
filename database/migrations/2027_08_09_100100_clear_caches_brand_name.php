<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Lane BR. Clears the compiled route, view and config caches and the settings
 * memo, so the Brand tab's routes (routes/seo-keywords-admin.php) answer, and
 * the renamed store name, SEO site name and footer text show on the next page
 * view instead of after the cache TTL. Same body as every clear_caches_* here.
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

        foreach (['kbb.settings', 'kbb.settings.map', 'kbb.nav.primary', 'kbb.nav.mobile', 'kbb.nav.footer'] as $key) {
            \Illuminate\Support\Facades\Cache::forget($key);
        }

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        if (app()->runningInConsole()) {
            echo "Cleared {$cleared} compiled files.\n";
            echo "Store -> SEO Keywords -> Brand is ready; the shop now reads K-Beauty Bliss.\n";
        }
    }

    public function down(): void {}
};
