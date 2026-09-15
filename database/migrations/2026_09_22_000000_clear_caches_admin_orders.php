<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the Store → Orders package (Lane V).
 *
 *   - ROUTES. routes/orders-admin.php adds GET /admin-api/orders/list,
 *     GET /admin-api/orders/export and three POST /admin-api/orders/bulk-*
 *     paths. A compiled route cache knows none of them, and the failure is the
 *     quiet kind: the Orders screen renders its chips, its filters and its
 *     export button perfectly and every request 404s, which reads as "the
 *     screen is broken" rather than "the routes were never loaded". Packages
 *     2.60.102–.106 are the standing reminder of what an inert release costs.
 *
 *   - VIEWS. resources/views/admin/app.blade.php grew the whole Orders list
 *     screen. Compiled Blade is keyed by path, so a stale copy of a file that
 *     already exists is exactly the case that does not self-correct: the admin
 *     console would keep serving the previous, unpaginated Orders screen from
 *     the compiled view while the new endpoints sit there unused.
 *
 * OPcache too. OrdersApiController is a new class, but AdminOrderController and
 * PaymentSettlementController sit next to it in the same namespace and a worker
 * holding a stale compiled map is how a new class fails to autoload at all.
 *
 * No schema change. This package adds no column and alters no table — the
 * Orders screen reads what `orders`, `order_items`, `refunds` and `customers`
 * already hold. Nothing here positions a column with an AFTER clause either,
 * which is what made nine earlier migrations in this repo silent no-ops on
 * MySQL — an ALTER naming a column that does not exist yet is an error there,
 * and the hasColumn guards wrapped around them made that error look clean.
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
