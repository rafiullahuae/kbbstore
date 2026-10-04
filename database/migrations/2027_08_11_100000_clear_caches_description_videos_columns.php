<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Lane PD: product-page HTML Block columns (headings on one line, three
 * columns on a phone) and video addresses in descriptions drawn as players,
 * plus the `kbb:fetch-description-videos` command. No schema change -- the
 * compiled views, config and the command list are dropped so the new code is
 * what serves the next request, the convention every package here follows.
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
            echo "Product descriptions now play their video clips; HTML Block columns stay three across on a phone.\n";
        }
    }

    public function down(): void {}
};
