<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for 2.60.109.
 *
 * This package is almost entirely routes, which makes the route cache the
 * whole reason this file exists. It moves the brand directory to
 * /korean-skincare-brands/, turns /brands/ and /brand/{slug}/ into 301s, adds
 * a per-brand landing page, and moves every article from
 * /skincare-guide/{slug}/ to the site root. A stale bootstrap/cache/routes-*
 * file would keep serving the previous table: the new addresses would 404 and
 * the old ones would keep returning 200, which is the worst of both — the site
 * would look fine while every link and canonical tag pointed somewhere else.
 *
 * The Blade views for brands, the Journal index and the article all changed in
 * the same release, so the compiled views go too. OPcache is reset for the
 * controller changes (BrandController::show and PageController::legacyPost are
 * new methods, and a worker holding the old compiled class would 500 on them).
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
