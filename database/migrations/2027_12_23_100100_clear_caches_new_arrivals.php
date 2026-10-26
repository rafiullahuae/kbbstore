<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/*
 * Lane NA -- Catalog -> New Arrivals. The package adds routes
 * (routes/new-arrivals-admin.php), a console command and changes PHP classes
 * and views, so the compiled route, config, event and view caches go.
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

        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            echo "New Arrivals: cleared {$cleared} compiled files.\n";
        }
    }

    public function down(): void {}
};
