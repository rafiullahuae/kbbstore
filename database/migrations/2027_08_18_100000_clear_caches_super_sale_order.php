<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * 2.60.388: a new admin route (POST admin-api/page-banners/super-sale-order,
 * "Copy the order from kbeautybliss.com/super-sale/") and a changed screen.
 * The compiled route cache must go or the button answers 404.
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
            echo "Super Sale: the old site's order can be copied in one click.\n";
        }
    }

    public function down(): void {}
};
