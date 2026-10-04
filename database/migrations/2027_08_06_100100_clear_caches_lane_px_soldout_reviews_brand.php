<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled views for Lane PX: the grid card's "Sold out" pill and button
 * (components/product-card + kbb.css), the review score box at half height
 * (partials/reviews + sorina-reviews.css) and the brand capsule on the product
 * page (kbb-product.css), and "You may also like" at 2.3 cards on a phone
 * (partials/you-may-also-like + kbb-product.css), with public/build rebuilt.
 * New controls, each
 * shipped on because the owner asked: Appearance → Product styles → Card
 * content → Sold-out label (also on Appearance → Product grid), Store → Reviews
 * → Review Settings → Compact summary, and Appearance → Product page → Type ·
 * Buy column → Brand name background / colour / corner radius, and Appearance
 * → Product page → You may also like → Cards in view on a phone (2 -> 2.3, a
 * moved default he asked for) / Arrows on a phone (off). No data is
 * written: each reads its default until saved.
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
            echo "Sold-out cards now say so; the review score box is half the height; the brand name sits on a light capsule; You may also like shows 2.3 cards on a phone.\n";
        }
    }

    public function down(): void {}
};
