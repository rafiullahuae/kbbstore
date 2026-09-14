<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for 2.60.92.
 *
 * This package adds three routes. A stale bootstrap/cache/routes-*.php takes
 * absolute priority over routes/web.php once it exists, so without this the
 * new brand URLs would keep 404ing exactly as they do today -- which is the
 * failure 2.60.48, 2.60.66 and 2.60.85 all hit. A new route means a cache
 * clear; there is no version of this rule with an exception in it.
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

    public function down(): void {}
};
