<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Clear the caches for 2.60.370: the homepage Big savings bundles carousel.
 *                                                              (Integrator)
 *
 * The homepage Blade, the shared grid partial, kbb.css and new homepage
 * settings changed: compiled
 * Blade and the opcache are cleared so the new files are what runs. No data is
 * written.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (glob(storage_path('framework/views/*.php')) ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        foreach (['kbb.settings', 'kbb.settings.map', 'kbb.modules', 'kbb.module_settings'] as $key) {
            try {
                Cache::forget($key);
            } catch (\Throwable) {
                // A missing cache store is not a failed migration.
            }
        }
    }

    public function down(): void {}
};
