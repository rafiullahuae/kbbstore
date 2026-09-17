<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the payment-reconciliation package.
 *
 *   - ROUTES. routes/payments-reconcile.php adds seven paths under
 *     /admin-api/payments/reconcile. A compiled route cache on the live host
 *     knows none of them, and the failure is the quiet kind that has already
 *     shipped twice on this project: the screen renders its Run button
 *     perfectly and every click 404s, which reads as a broken button rather
 *     than as routes that were never loaded.
 *
 *   - VIEWS. The screen lives in resources/views/admin/app.blade.php.
 *     Compiled Blade is keyed by path, so a stale copy of a file that already
 *     exists is exactly the case that does not self-correct.
 *
 * OPcache too. App\Services\Payments\Reconciliation\* and
 * App\Http\Controllers\Admin\PaymentReconciliationController are new classes,
 * and this package also adds METHODS to three classes that already exist —
 * StripeGateway, TabbyGateway and TamaraGateway now implement
 * ListsTransactions. A worker still holding a compiled copy of one of those
 * three would resolve the class, fail the instanceof, and report the gateway
 * as one that cannot be reconciled: a wrong answer rather than an error, which
 * is the worst shape a stale cache can take here.
 *
 * Runs after 2026_09_17_030000_create_payment_reconciliation_tables, which is
 * what creates the four tables these routes read.
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
