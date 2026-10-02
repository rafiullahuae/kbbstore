<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for "You may also like" as a carousel.     (Lane PS)
 *
 * store/product.blade.php now includes partials/you-may-also-like.blade.php,
 * the admin console's Appearance → Product page grows a "You may also like"
 * tab and the product editor a "You may also like" panel, and
 * routes/product-editor-admin.php adds GET /admin-api/product-editor-also-like
 * (the picker's search). Compiled Blade is keyed by path and the route cache
 * knows only the routes it was built with, so without this the picker's
 * search 404s on a cached host.
 *
 * Same body as 2027_07_10_000000_clear_caches_unfinished_drafts.php.
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
