<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for "Unfinished (N)" in the admin top bar. (Lane PM)
 *
 * resources/views/admin/app.blade.php includes the new
 * partials/unfinished-drafts.blade.php, and the Banners, Set appearance, Grid
 * sections, Cart panel, Cart page, Checkout page, Page background, Footer,
 * Site layout, Security, Video rail, Review Settings, Rating Badge and
 * reset-guard partials all change. Compiled Blade is keyed by path, so without
 * this the console keeps asking "Leave this screen and lose them?" from the old
 * compiled copy. No route is added: drafts live in the browser.
 *
 * Same body as 2027_07_09_000000_clear_caches_sets_by_brand.php.
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
