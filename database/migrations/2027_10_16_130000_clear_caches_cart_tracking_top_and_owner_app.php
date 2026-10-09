<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for Lane QK10: Cart Tracking moved to the top of the
 * console sidebar (third, under Analytics), and a Cart tracking screen in the
 * owner app.
 *
 * ONE NEW ROUTE: GET /{owner-app}/api/carts (routes/owner-app.php). A route
 * added to a cached route table does not exist until the cache is cleared.
 * Changed Blade: admin/app.blade.php, admin/partials/cart-tracking-screen and
 * owner-app/shell -- a compiled view is keyed by path and checked by
 * filemtime, and an unzip's timestamps are not reliably newer.
 *
 * NO SCHEMA CHANGE.
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

        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            echo "Cleared {$cleared} compiled files; Cart Tracking is now at the top of the sidebar,\n"
                ."and in the owner app under More -> Cart tracking.\n";
        }
    }

    public function down(): void {}
};
