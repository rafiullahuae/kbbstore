<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for search: every word, sets first, Search Terms. (Integrator)
 *
 * routes/web.php gains GET admin-api/search-terms (Growth -> Search Terms) and
 * app.blade.php includes partials/search-terms-screen; the compiled route
 * table would 404 the new page without this. Compiled
 * Blade is keyed by path, so without this the console keeps drawing the old
 * screen.
 *
 * Same body as 2027_07_05_000000_clear_caches_cleanup_button.php.
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

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        if (app()->runningInConsole()) {
            echo "Cleared {$cleared} compiled files.\n";
        }
    }

    public function down(): void {}
};
