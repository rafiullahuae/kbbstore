<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the cut-out upload routes. (Lane CUT)
 *
 * NEW ROUTES in routes/occasion-banner-admin.php and routes/gift-strip-admin.php
 * (cutout/upload, cutout/automatic). The router dispatches against
 * bootstrap/cache/routes-*.php, so without this the two new buttons answer 404.
 *
 * CHANGED BLADES: the two admin screens gain "Upload cut-out" and "Use
 * automatic"; compiled views are keyed by path, so they are cleared too.
 *
 * NO SETTING ROW IS WRITTEN. NO SCHEMA CHANGE.
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
            echo "Cleared {$cleared} compiled files; the cut-out Upload / Use automatic buttons are ready.\n";
        }
    }

    public function down(): void {}
};
