<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the stock claim/return change.
 *
 * WHAT CHANGED, AND WHY A STALE COPY WOULD BE WORSE THAN NO CHANGE.
 *
 * The routine that takes units off the shelf moved out of CartService into a
 * new class, App\Services\StockClaim, so that the unauthenticated
 * Api\CheckoutController::session() could use the same one instead of
 * overselling. App\Services\Orders\OrderTransitionStock is new as well, and six
 * files now call into the two of them: both checkout controllers, the two admin
 * order controllers, AdminController and PaymentConfirmer.
 *
 * All of it is pure PHP, and this host cannot be shelled into or restarted, so
 * the PHP a package writes is not the PHP the server runs until OPcache lets go
 * of the old copy — the standing reason behind the withdrawn packages
 * 2.60.102-.106 recorded in CLAUDE.md. A half-applied version of this change is
 * specifically dangerous rather than merely stale: a cached CartService calling
 * a StockClaim that is not there is a fatal error on Place order, and a cached
 * checkout controller that has not picked up the new claim is the oversell bug
 * still running against a database that now has the ledger to record it.
 *
 * THE COMPANION MIGRATION IS THE SCHEMA HALF. 2026_10_30_000000 creates
 * `order_stock_claims`, and it runs first. This one only drops compiled files,
 * so the two are safe in either order on a host that reruns them.
 *
 * No route was added — this lane added none, which is why there is no route
 * file to wire — and the route cache is dropped only for consistency with the
 * other entries here. No settings key was added, so the settings map is left
 * alone.
 *
 * Nothing here positions a column with an AFTER clause, the thing that made
 * nine earlier migrations silent no-ops on MySQL.
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
