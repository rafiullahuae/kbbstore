<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/*
 * 2.60.503: a blank line typed in a product description is one line of space
 * (RichText::markBlankLines(), ProductController::tabs(), kbb-product.css).
 * New PHP and a new stylesheet; the view, config and OPcache are cleared so
 * every product page picks them up at once. Nothing stored changes.
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
            base_path('bootstrap/cache/events.php'),
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

    public function down(): void
    {
    }
};
