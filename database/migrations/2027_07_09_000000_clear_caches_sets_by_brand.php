<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for "Set shown first, by brand". (Lane PL)
 *
 * resources/views/admin/app.blade.php gains the per-brand list on Store ->
 * Site Search -> Sets in search. Compiled Blade is keyed by path, so without
 * this the console keeps drawing the old screen. No route is added: the list
 * rides the existing GET / POST admin-api/site-search.
 *
 * Same body as 2027_07_08_000100_clear_caches_search_terms.php.
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
