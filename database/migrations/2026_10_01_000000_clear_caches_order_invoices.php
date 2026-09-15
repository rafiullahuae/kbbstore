<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the order invoices package (Lane AE).
 *
 *   - ROUTES. routes/invoices-admin.php adds GET /admin-api/orders/{id}/invoice
 *     and GET /admin-api/orders/{id}/packing-slip. A compiled route cache on
 *     the live host knows neither, and the failure is the quiet kind: the order
 *     screen grows two buttons that open a 404, which reads as "the invoice is
 *     broken" rather than "the routes were never loaded". Packages 2.60.102–.106
 *     are the standing reminder of what an inert release costs.
 *
 *   - VIEWS. resources/views/invoices/* and the two emails/order-invoice*
 *     templates are new files, but compiled Blade is keyed by path and
 *     resources/views/admin/app.blade.php will grow the buttons that open them
 *     when the integrator wires the screen up. A stale copy of a file that
 *     already exists is exactly the case that does not self-correct: the admin
 *     console keeps serving the previous order screen from the compiled view
 *     while the new endpoints sit there unused.
 *
 * OPcache too. InvoiceController, InvoiceNumbers, InvoiceDocument and
 * App\Mail\OrderInvoice are new classes, but AdminOrderController and
 * OrderMailer — both CHANGED by this package — sit in namespaces a worker may
 * be holding a stale compiled map for, and a stale map is how a changed method
 * keeps running its old body. CLAUDE.md requires this for any package changing
 * a PHP class, and this one changes two.
 *
 * NO SCHEMA CHANGE. `orders.invoice_number` and `orders.invoiced_at` already
 * exist — Phase 0 created them (`unsignedBigInteger` unique, and a nullable
 * timestamp) and 2026_09_22_000000_add_import_external_ids re-asserts the
 * unique index on a server whose repair migration dropped it. This package
 * fills columns that were already there and adds none, so nothing here
 * positions a column with an AFTER clause either — which is what made nine
 * earlier migrations in this repo silent no-ops on MySQL.
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
