<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches -- the countries bar comes off and the breadcrumb gets
 * its switches.                                                  (Lane PI-B)
 *
 * layouts/store.blade.php and store/post.blade.php now include
 * partials/breadcrumb-css.blade.php, and Appearance -> Header grows a
 * Breadcrumbs tab. Compiled Blade is keyed by path, so without this a server
 * keeps drawing the layout without the new <style> -- the trail stays on at
 * its old spacing while the admin screen says it is off. The flag bar's own
 * move is a settings default and a stored row, which
 * 2027_07_06_000000_flag_bar_ships_off handles; this is the view half.
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
