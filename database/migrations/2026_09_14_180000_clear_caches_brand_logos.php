<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the brand logos / display-mode package.
 *
 * This package adds four routes — GET/POST /admin-api/brands and
 * PUT/DELETE /admin-api/brands/{brand} — through routes/brands-admin.php,
 * which the integrator requires from inside web.php's guarded admin-api
 * group. A stale bootstrap/cache/routes-*.php would keep serving the previous
 * table, and the whole Brands screen would 404 against an admin that reports
 * nothing wrong. CLAUDE.md: every package that adds a route ships one of
 * these.
 *
 * The compiled views go with them because store/brands.blade.php changed (the
 * three display modes and the count-derived grid) and admin/app.blade.php
 * changed (the real Brands tab). The config cache goes because
 * `brands_display` is now read through SettingsService on the directory page.
 * OPcache is reset for the new BrandsApiController and for
 * BrandController::gridMinimum(), which the view calls into — a worker holding
 * the old compiled class would 500 on a method that did not exist when it was
 * compiled.
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
        }
    }

    public function down(): void {}
};
