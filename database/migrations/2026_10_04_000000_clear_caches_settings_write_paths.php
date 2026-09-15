<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the settings write-paths package (Lane AL).
 *
 * WHY THIS PACKAGE NEEDS ONE. No route is added or renamed, so the route cache
 * is not the risk. OPcache is, and it is the worse of the two, because every
 * class this package touches ALREADY EXISTS — a worker holding a stale compiled
 * copy does not fail to autoload, it goes on serving the old code, and the old
 * code here is the defect itself:
 *
 *   - AdminController::updateSettings. Until the new copy is live, PUT
 *     /admin-api/settings keeps accepting any string for `free_ship`, `cod_fee`
 *     and `gift_fee` and keeps answering 200. Those read back through `(int)`
 *     as 0, so the free-shipping threshold is zero — `$subtotal >= 0` is true
 *     for every basket — and the store ships everything free while the console
 *     shows the operator a saved-looking value. A package that "succeeded" and
 *     left that in place is worse than one that visibly failed.
 *
 *   - AdminController::customers. The lifetime-spend figure still sums
 *     cancelled and refunded orders from a stale copy, so the Customers screen
 *     and this endpoint go on disagreeing about the same customer.
 *
 *   - EcommerceApiController, ShippingApiController,
 *     ExtendedDeliveryApiController, BundleApiController. Same shape: existing
 *     classes whose validation changed. A stale ShippingApiController still
 *     hands MySQL a delivery charge too large for a 32-bit column, which is a
 *     500 on the live host rather than a message on the screen.
 *
 * VIEWS ARE CLEARED TOO, though this package changes no Blade. The glob is
 * cheap, compiled views are regenerated on demand, and a settings package that
 * left a stale compiled view behind would be indistinguishable from this bug
 * class from the operator's side. Nothing is lost by being thorough here.
 *
 * NO SCHEMA CHANGE. This package adds no column and alters no table — it is
 * validation on the way in, one corrected aggregate, and one formatter swapped
 * for the currency-aware one. Nothing positions a column with an AFTER clause
 * either; see MigrationConventionTest for why that matters in this repository.
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
        }
    }

    public function down(): void {}
};
