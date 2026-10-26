<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/*
 * The cart panel freeze fix adds a route (GET /api/cart/token), so the compiled
 * route cache is cleared with views, config and the event cache, and opcache
 * reset — otherwise the new path 404s until somebody clears it by hand. The
 * new cart.js arrives in public/build with the same package. Literals only: no
 * application class is loaded. Nothing in the database moves.
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

        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            echo "Cart token route: cleared {$cleared} compiled files.\n";
        }
    }

    public function down(): void {}
};
