<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear the caches for Lane NV: the desktop menu row fits itself to the row.
 *
 * partials/nav-bar.blade.php now calls App\Support\NavRowFit, a new class, and
 * App\Services\HeaderSettings gained four fields (Appearance → Header →
 * Navigation: "Fit the menu to the row", "Fit it from", "Smallest text size",
 * "Largest text size"), so compiled Blade, the cached config/services and
 * opcache go. No data is written: the four settings read their defaults from
 * HeaderSettings::SCHEMA until the Header screen is saved, and the switch's
 * default is ON because the owner asked for it. No route is added. kbb.css and
 * app.js were rebuilt, and travel in public/build.
 *
 * WHAT MOVES ON THE SHOP: only a desktop menu with nine or more top-level
 * items. A shorter menu renders byte for byte as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cleared = 0;

        foreach ([
            storage_path('framework/views/*.php'),
            base_path('bootstrap/cache/config.php'),
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
            echo "The desktop menu now fills its row when it has nine or more items (Appearance → Header → Navigation).\n";
        }
    }

    public function down(): void {}
};
