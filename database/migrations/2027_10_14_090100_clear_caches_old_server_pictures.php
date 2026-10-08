<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/*
 * Lane PX: three routes added to routes/media-sideload-admin.php
 * (GET/POST /admin-api/urls-media/old-server, GET .../old-server.csv) and a
 * new admin partial. Compiled routes and views are cleared so they exist on
 * the next request -- the convention CLAUDE.md sets for every package that
 * adds a route.
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
            echo "Cleared {$cleared} compiled files; Fetch missing pictures from the old server is\n";
            echo "now in Store -> Import and Platform -> Domain switch.\n";
        }
    }

    public function down(): void {}
};
