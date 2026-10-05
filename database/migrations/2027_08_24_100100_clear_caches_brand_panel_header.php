<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Lane BR2: the brand page's Panel header -- a changed brand view, a new
 * partial and stylesheet, nine Site layout settings and the brand pop-up's
 * layout controls. No route. The compiled views must go or a brand page keeps
 * the old header.
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
            echo "Brand page: Panel header (logo, name and description on the banner), with per-brand sizes.\n";
        }
    }

    public function down(): void {}
};
