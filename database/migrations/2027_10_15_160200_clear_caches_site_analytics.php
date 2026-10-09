<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/*
 * (Lane AN) Analytics adds routes (routes/site-analytics-admin.php, the owner
 * app's analytics lines); a route added by a package does nothing until the
 * compiled route table is gone, and the console's compiled views carry the new
 * screen. The repo's convention for every package that adds a route.
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
            echo "Cleared {$cleared} compiled files; Analytics is now in the sidebar under Dashboard.\n";
        }
    }

    public function down(): void {}
};
