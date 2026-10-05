<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Lane FP: a changed shop view (the Filters drawer's backdrop), two new Site
 * layout sliders and a new Press feedback choice. No route. The compiled views
 * must go or a category page keeps the drawer without its backdrop.
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
            echo "Filters drawer: width control, tap outside to close, title stays put; press feedback on small icons only.\n";
        }
    }

    public function down(): void {}
};
