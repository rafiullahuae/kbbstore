<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for imported content blocks. (Lane PJ-B)
 *
 * VIEWS are the reason. store/product.blade.php now expands
 * `[rey_global_section id="N"]` in the short description, and the Description
 * tab does the same through App\Support\ProductTabs; the admin console's import
 * card gains a "Remove all files" button and the HTML Blocks screen shows an
 * imported block's own shortcode. Compiled Blade is keyed by path with no
 * content check, so without this the product page keeps printing the shortcode
 * as letters and the console keeps drawing the old cards.
 *
 * No route is added: "Remove all files" reuses POST /admin-api/import/forget
 * with `entity=all`. Routes are cleared anyway, with config, services and
 * OPcache, for the standing reason CLAUDE.md gives -- packages 2.60.102-.106.
 *
 * Same body as 2027_07_07_000000_clear_caches_convert_to_set.php.
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
