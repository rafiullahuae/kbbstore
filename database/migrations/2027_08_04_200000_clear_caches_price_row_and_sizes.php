<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled views for 2.60.380: the price row keeps its place with or
 * without stars (kbb.css / kbb-grid-skins.css, rebuilt in public/build), and
 * Appearance → Product grid gains "Price text size" -- Price and Cut price,
 * phone and desktop (ProductStyles card_fs_price_* and the new card_fs_reg_*).
 * No data is written: the new selects read their default until saved.
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
            echo "Prices now line up on every card, with or without stars; price sizes are on Appearance → Product grid → Price text size.\n";
        }
    }

    public function down(): void {}
};
