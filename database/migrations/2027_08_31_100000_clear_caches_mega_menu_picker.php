<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/*
 * Store -> Mega Menu -> Add items (Lane MX) adds two admin routes in
 * routes/mega-menu-picker-admin.php -- GET admin-api/mega-menu/sources and
 * POST admin-api/mega-menu/pick. The live server serves a compiled route
 * table, so a package that adds a route clears it (CLAUDE.md), together with
 * the compiled views: the menu editor's partial changed.
 *
 * It also drops the cached header menus, so NavigationService rebuilds them
 * with the live-address lookup on the first request after the package lands
 * rather than up to five minutes later.
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
            base_path('bootstrap/cache/services.php'),
            base_path('bootstrap/cache/packages.php'),
        ] as $pattern) {
            foreach (glob($pattern) ?: [] as $file) {
                if (is_file($file) && @unlink($file)) {
                    $cleared++;
                }
            }
        }

        try {
            app(\App\Services\NavigationService::class)->flush();
        } catch (\Throwable) {
        }

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        if (app()->runningInConsole()) {
            echo "Cleared {$cleared} compiled files.\n";
        }
    }

    public function down(): void {}
};
