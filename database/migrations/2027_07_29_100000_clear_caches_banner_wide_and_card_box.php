<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear the caches for Lane PF2: the homepage banner's srcset gains its
 * 1280/1440/1600 copies, the product card's <img> states its frame's width and
 * height, and the first homepage rail's first two cards load eagerly.
 *
 * components/product-card.blade.php, partials/home/{grid,hs-rail,
 * slider-banner,single-banner}.blade.php and store/home.blade.php changed, so
 * compiled Blade and opcache go. Nothing visible moves, no data is written, no
 * route is added and no asset is rebuilt.
 *
 * THE OWNER HAS NOTHING TO PRESS. The banner copies are made after the
 * response by the first homepage view that finds them missing (a shop has
 * three to five banner pictures; ~180 ms each, which no shopper waits for), so
 * the second view after this package serves them.
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
            echo "Faster homepage banner on laptops and sized card photographs; nothing on the page moves.\n";
        }
    }

    public function down(): void {}
};
