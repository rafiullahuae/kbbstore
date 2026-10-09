<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for Lane QK7's free-delivery bar switch.
 *
 * NO NEW ROUTES. Changed Blade: partials/checkout/freeship-bar,
 * store/cart-inner and admin/partials/cart-page-screen. A compiled view is
 * keyed by PATH and checked by filemtime, and an unzip's timestamps are not
 * reliably newer than what is on disk, so a stale copy would keep drawing the
 * bar the owner asked to be rid of. Cleared here so it cannot.
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
            echo "Cleared {$cleared} compiled files; the free-delivery bar on the cart page\n"
                ."and the checkout now follows Appearance -> Checkout page -> Delivery labels.\n";
        }
    }

    public function down(): void {}
};
