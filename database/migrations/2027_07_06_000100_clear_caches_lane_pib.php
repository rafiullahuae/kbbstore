<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for Lane PI-B's round.                   (Lane PI-B)
 *
 * Compiled Blade is keyed by path, so a server that keeps its compiled views
 * keeps drawing the old pages after the package lands. This round changes:
 *
 *   - layouts/store.blade.php and store/post.blade.php: the new
 *     partials/breadcrumb-css (Appearance -> Header -> Breadcrumbs). Without
 *     it the trail stays on at its old spacing while the screen says off.
 *   - store/shop.blade.php: "Sort" becomes the select's <label>, and the
 *     pager is the shared partials/listing-pager.
 *   - store/collection.blade.php: the shared pager replaces Laravel's
 *     Tailwind one, whose chevrons drew 170px wide.
 *   - partials/drawers.blade.php: the "added" tick.
 *
 * The flag bar's own move is a settings default plus a stored row, which
 * 2027_07_06_000000_flag_bar_ships_off handles; this is the view half. No
 * route is added (the batch for "Load more on scroll" is the listing's own
 * URL with ?kbbbatch=1), so the route table only goes for the convention.
 *
 * Same body as 2027_07_05_000000_clear_caches_cleanup_button.php.
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
