<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/*
 * Lane LN15 (2.60.673): English product descriptions mark their headings
 * (RichText::markHeadings() via ProductController::tabs()) and kbb-product.css
 * takes the gap out under them. Clears compiled views and opcache so the new
 * controller and stylesheet are served at once. Same body as every clear_caches_*.
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
            echo "Cleared {$cleared} compiled files; the bonus gift now sits inside the Free gifts box.\n";
        }
    }

    public function down(): void {}
};
