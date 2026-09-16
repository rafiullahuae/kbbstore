<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for Lane DC — the two delivery sources, the order-edit
 * tax re-price, and the importer's mail suppression.
 *
 * TWO BLADE FILES CHANGED.
 *
 *   admin/app.blade.php — Store → Delivery & Shipping. Each delivery-line row
 *   now shows the "Arrives in …" wording the same country carries on the
 *   Extended tab, and both tab hints name the other. Without this migration the
 *   server goes on serving the compiled console from storage/framework/views
 *   and the owner opens the tab he was told about to find nothing new in it —
 *   not an error, just a package that appears to have done nothing.
 *
 *   partials/checkout/delivery-options.blade.php — the arrival estimate the
 *   country-change refresh now sends. The console is one compiled view because
 *   app.blade.php includes its partials, and the same is true of the checkout:
 *   a package that rewrote only a partial would leave the parent's cached
 *   compile serving the old copy. The views are cleared wholesale.
 *
 * OPCACHE IS THE HALF THAT MATTERS MOST HERE, because the PHP changes are the
 * dangerous ones and the host can be neither restarted nor shelled into:
 *
 *   AdminOrderController::recalcTotals() now re-prices an edited order's tax
 *   from the rate and basis recorded on that order. A stale copy beside a fresh
 *   console would leave an edited order's tax describing a subtotal that no
 *   longer exists — which is the defect this package is for.
 *
 *   ImportRunner holds customer status mail for the length of the orders
 *   entity, through a new method on OrderStatusMailPolicy. A stale runner beside
 *   a fresh policy is harmless (nothing calls the new method), but a stale
 *   POLICY beside a fresh runner would be a fatal call to a method that is not
 *   there, in the middle of an import. Clearing OPcache is what makes the pair
 *   arrive together.
 *
 * NO ROUTE CHANGED. /api/checkout/rates, the admin order item endpoints and the
 * settings endpoint are all exactly where they were. The route cache is dropped
 * with the rest because it costs nothing and a half-cleared cache is harder to
 * reason about than an empty one.
 *
 * NO SCHEMA CHANGE, NO SEEDED ROW, NOTHING MIGRATED. In particular nothing
 * touches `delivery_texts` or `delivery_countries`: the two per-country delivery
 * sources were examined and deliberately NOT merged, so every sentence and every
 * estimate an owner has typed stays exactly where he typed it. Safe to run
 * twice, and nothing here positions a column with an AFTER clause — the thing
 * that made nine earlier migrations silent no-ops on MySQL.
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
