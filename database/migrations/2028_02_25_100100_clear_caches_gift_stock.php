<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/*
 * Lane GS: Growth & Marketing -> Free gifts -> Gift products -- a new route (POST free-gifts/products/{id}/gift-stock,
 * routes/free-gifts-admin.php) and the screen's inline gift stock. Clears compiled views, routes, config, services
 * and opcache so all of it shows at once. Same body as every clear_caches_*.
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
            echo "Cleared {$cleared} compiled files; Free gifts -> gift stock is ready.\n";
        }
    }

    public function down(): void {}
};
