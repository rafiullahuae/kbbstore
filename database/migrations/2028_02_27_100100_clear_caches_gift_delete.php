<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/*
 * Lane GSX: Free gifts -- two new routes (DELETE free-gifts/products/{id}, routes/free-gifts-admin.php; POST {app}/api/gifts/products/{id}/delete,
 * routes/owner-app.php): deleting a gift-only product, and the range editor's inline gift stock. Clears compiled views, routes, config, services
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
            echo "Cleared {$cleared} compiled files; Free gifts -> delete gift-only products is ready.\n";
        }
    }

    public function down(): void {}
};
