<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Clear the caches for Lane HB: the #KBeautyBliss Spotted page and section,
 * and the new site footer.
 *
 * TWO ROUTE FILES ARE NEW (routes/spotted.php, routes/spotted-admin.php), so
 * the compiled route table must go — a route added by a package does nothing
 * until it does (CLAUDE.md). The footer Blade, the homepage partial, kbb.css
 * and two new settings modules changed too, so compiled Blade, the config and
 * the opcache are cleared and the settings maps forgotten. No data is written.
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

        // Best effort: on a host with no cache table or a cold store this is a
        // no-op, and every one of these values is re-read from the database.
        foreach (['kbb.settings', 'kbb.settings.map', 'kbb.modules', 'kbb.module_settings', 'kbb.spotted.cards.home.en', 'kbb.spotted.cards.page.en', 'kbb.spotted.cards.home.ar', 'kbb.spotted.cards.page.ar'] as $key) {
            try {
                Cache::forget($key);
            } catch (\Throwable) {
                // A missing cache store is not a failed migration.
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
