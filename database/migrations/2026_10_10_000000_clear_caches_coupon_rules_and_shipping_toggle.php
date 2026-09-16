<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the bug-audit package (Lane BG).
 *
 * NO NEW ROUTE and NO SCHEMA CHANGE. This is the other case the convention
 * covers: PHP classes changed, and on this host a worker can be holding
 * compiled bytecode for the old ones. Both fixes here are silent when stale —
 * the store keeps answering, with the old numbers — which is exactly the
 * ambiguity the convention exists to remove.
 *
 *   - App\Services\CouponService — eligibleItems() now re-reads the coupon's
 *     rule columns when the caller handed it a partial row. Every storefront
 *     path eager-loads `coupon:id,code,type,amount`, so `product_ids`,
 *     `excluded_product_ids`, `category_ids`, `excluded_category_ids` and
 *     `exclude_sale_items` all read null on that instance and every rule
 *     failed OPEN: a code restricted to one product discounted the whole
 *     basket, and the inflated figure went into `orders.discount_total` and
 *     into the redemption row. A worker on the old bytecode keeps giving that
 *     money away and nothing says so.
 *
 *   - App\Http\Controllers\Admin\ShippingApiController and
 *     App\Models\ShippingZone — Store → Delivery & Shipping read the
 *     storefront's `methods()` relation, which filters `enabled = true`, so a
 *     delivery method vanished from the screen the moment it was switched off
 *     and could never be switched back on. The screen now reads
 *     `allMethods()`. Stale bytecode here leaves the owner looking at a screen
 *     that is still missing the row they need.
 *
 * OPcache is what actually matters. The view, config and route globs are kept
 * for the reason every other clear_caches migration in this repo keeps them:
 * one convention, so nobody has to work out which subset a given package needs.
 *
 * Nothing here uses ->after() — there is no ALTER at all. See
 * tests/Feature/MigrationConventionTest.php for why that matters on this host.
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
