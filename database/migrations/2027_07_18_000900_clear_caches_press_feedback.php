<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Clear the caches for 2.60.357: press feedback and the Product page tabs.
 *                                                              (Integrator)
 *
 * The store layout gained the press token on <html>, the admin console gained
 * a tab and a CSS rule, and SiteLayout gained a key. Compiled Blade and the
 * opcache are cleared so the new files are what runs, and the settings
 * snapshots so the new key's default is read. No data is written.
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
