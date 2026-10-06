<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Lane SG (2.60.417): two admin routes added to routes/spotted-admin.php
 * (GET/POST /admin-api/spotted/instagram) and a scheduled command in
 * routes/console.php. A route does not exist until the compiled route table is
 * rebuilt (CLAUDE.md), so this clears it -- the convention every package that
 * adds a route follows.
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
            echo "#KBeautyBliss Spotted: the page now draws the Instagram posts ticked on Appearance → #KBeautyBliss Spotted → From Instagram.\n";
        }
    }

    public function down(): void {}
};
