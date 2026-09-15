<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the admin list audit package (Lane X).
 *
 * NO NEW ROUTE, so this is not the usual route-cache case. It is the other one:
 * three PHP classes changed, and on this host a worker can be holding compiled
 * bytecode for the old ones.
 *
 *   - App\Http\Controllers\Admin\AdminController — orders(), order(), stats()
 *     and analytics() all changed. analytics() in particular moved from adding
 *     rows up in PHP to aggregating in SQL, so a worker running the old
 *     bytecode against the new expectations would keep reporting `units_sold`
 *     as zero and nothing would say why. That is precisely the ambiguity the
 *     Customers screen's BUILD constant was added to remove: a fix that is
 *     correct and not running looks identical to a fix that is wrong.
 *
 *   - App\Http\Controllers\Admin\CustomersApiController and
 *     App\Http\Controllers\Admin\OrdersApiController — both CSV exports had a
 *     row ceiling that chunk() silently discarded. Same namespace, same
 *     directory, same risk of a stale compiled map.
 *
 * OPcache is what actually matters here; the view and config globs are kept for
 * the same reason every other clear_caches migration in this repo keeps them —
 * one convention, so nobody has to work out which subset a given package needs.
 *
 * No schema change. Every column this package reads —
 * `order_items.quantity`, `orders.shipping_total`, `orders.fee_total`,
 * `orders.shipping_method` — already exists and always has. That is the whole
 * finding: the code was naming columns that never existed while the real ones
 * sat there unread. Nothing here uses ->after(); see
 * tests/Feature/MigrationConventionTest.php for why.
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
