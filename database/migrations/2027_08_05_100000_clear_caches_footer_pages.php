<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled routes and views for Lane FT: Appearance → Footer becomes four
 * pages (Site footer · Desktop / · Mobile, Cart & Checkout footer · Desktop /
 * · Mobile), each with a live preview served by the NEW route
 * POST admin-api/slim-footer/preview — which does nothing until the compiled
 * route table is gone. No setting is written: the one new control, "Brand size
 * on a phone", follows the desktop brand size until it is moved.
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
            echo "Appearance → Footer now opens on four pages, each with a live preview.\n";
        }
    }

    public function down(): void {}
};
