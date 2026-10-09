<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/*
 * Lane IGR: the Instagram API module is retired.
 *
 * THE OWNER, 9 October 2026: "the instagram new embed function should go auto
 * on the #KBeautyBlissSpoted. and the old instagram api etc will be discontinue
 * from the app, and also the instagram connect page too."
 *
 * This package REMOVES routes — the eleven admin-api/instagram paths
 * (routes/instagram-admin.php is no longer required from routes/web.php) and
 * the two admin-api/spotted/instagram paths — and changes compiled Blade (the
 * admin console, the homepage, the Spotted page). A compiled route table would
 * keep the removed routes alive and pointing at a controller nobody reaches
 * from the console, so the caches go, by the same convention as every package
 * that changes a route.
 *
 * The data migrations that follow (140100 credentials, 140200 homepage row,
 * 140300 shortcodes) run after this one and need nothing from it.
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
            echo "Cleared {$cleared} compiled files; Content -> Instagram (the API module) is retired.\n";
        }
    }

    public function down(): void {}
};
