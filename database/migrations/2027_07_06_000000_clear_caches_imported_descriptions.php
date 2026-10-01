<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Clear compiled caches for the imported-copy fixes. (Lane PI-A)
 *
 * Two of the owner's first reports after importing his WooCommerce shop:
 *
 *   - the search panel's "Popular right now" printed WooCommerce price markup
 *     as letters. The list is cached for fifteen minutes under
 *     `kbb.search.starter.products`, so without forgetting it the shop keeps
 *     serving the broken strings after the fix lands.
 *   - imported descriptions rendered as one run-on slab, and the short
 *     description printed a literal `<div>`. resources/views/store/product.blade.php
 *     and resources/views/partials/quick-view.blade.php changed, and compiled
 *     Blade is keyed by path.
 *
 * Same body as 2027_07_05_000000_clear_caches_cleanup_button.php, plus the
 * guarded cache forget from 2027_04_21_000000_clear_caches_set_price_on_grids.php.
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

        /*
         * Guarded, and it may not fail the update: an unreachable cache store
         * costs fifteen minutes of the old list, an update that dies half
         * applied costs the updater. See the 2027_04_21 migration.
         */
        try {
            Cache::forget('kbb.search.starter.products');
        } catch (\Throwable) {
            // See above.
        }

        if (app()->runningInConsole()) {
            echo "Cleared {$cleared} compiled files.\n";
        }
    }

    /** Clearing a cache is not a state change; the next request rebuilds it. */
    public function down(): void {}
};
