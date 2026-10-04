<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Lane EK: Emails → Customer emails and the template builder add routes
 * (routes/emails-templates-admin.php, inside the admin-api group), a Blade
 * directive pair every kit email now uses (@kitsections / @kitsec), a wrapped
 * translation loader, and re-written email views. None of that takes effect on
 * the server until the compiled route cache and the compiled views are dropped
 * -- CLAUDE.md's convention for every package that adds a route.
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

        foreach (['kbb.settings.map', 'kbb.shop.cats', 'kbb.shop.brands'] as $key) {
            try {
                Cache::forget($key);
            } catch (\Throwable) {
            }
        }

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        if (app()->runningInConsole()) {
            echo "Cleared {$cleared} compiled files (Lane EK: Customer emails and the template builder).\n";
        }
    }

    public function down(): void {}
};
