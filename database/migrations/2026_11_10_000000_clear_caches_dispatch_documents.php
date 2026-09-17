<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the dispatch-documents package (Lane EK).
 *
 * A SECOND ONE, AND NOT A DUPLICATE OF 2026_10_01_000000. That migration
 * cleared the caches for the package that introduced the invoice and the
 * packing slip, and it ran once. The compiled route cache the live host is
 * serving today is a file listing the routes that existed when THAT package was
 * applied; it knows nothing about the two paths this package adds. A
 * clear_caches migration is per package, not per subject area, which is why
 * CLAUDE.md phrases the rule as "every package that adds a route also ships
 * one".
 *
 *   - ROUTES. routes/invoices-admin.php now also registers
 *     GET /admin-api/orders/{id}/delivery-note and
 *     GET /admin-api/orders/{id}/shipping-label. Without this, both are
 *     unknown to the router on the live host and the failure is the quiet kind:
 *     the order screen's Delivery note and Shipping Label buttons open a 404,
 *     which reads as "the documents are broken" rather than "the routes were
 *     never loaded". Packages 2.60.102–.106 are the standing reminder of what
 *     an inert release costs.
 *
 *   - VIEWS, AND THIS IS THE HALF THAT WOULD NOT SELF-CORRECT. The two new
 *     views and the new partial are new paths, and a new path has no stale
 *     compiled copy to serve. But this package also CHANGES four files that
 *     already exist on the server — invoices/document.blade.php,
 *     invoices/invoice.blade.php, invoices/packing-slip.blade.php and
 *     invoices/partials/parties.blade.php. Compiled Blade is keyed by source
 *     path and invalidated by mtime, and a package extracted with a preserved
 *     timestamp, or onto a filesystem with a coarse mtime, leaves the old
 *     compiled file looking current. The invoice would then keep rendering
 *     without the toolbar links, and — worse, because it is invisible — without
 *     the dir="auto" that keeps an Arabic address the right way round.
 *
 * OPcache too. Code128, and the two new controller methods, are additions; but
 * InvoiceController, InvoiceDocument, AdminCapabilities and AdminOrderController
 * are all CHANGED by this package, and a worker holding a stale compiled map is
 * how a changed method keeps running its old body. CLAUDE.md requires this for
 * any package changing a PHP class, and this one changes four.
 *
 * NO SCHEMA CHANGE. Nothing here reads or writes a column. The delivery note
 * and the label are rendered from `orders`, `order_items` and `settings`
 * exactly as the invoice and the packing slip already were; in particular no
 * carrier or tracking column is added, because this shop does not issue
 * tracking numbers — see the shipping-label view's header for why the label
 * therefore does not pretend to carry one.
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
