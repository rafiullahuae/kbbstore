<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the bulk-document-printing package (Lane GC).
 *
 * A THIRD ONE, AND NOT A DUPLICATE. 2026_10_01_000000 cleared the caches for
 * the package that added the invoice and the packing slip; 2026_11_10_000000
 * did it for the delivery note and the dispatch label. Each ran once. The
 * compiled route cache the live host is serving today is a file listing the
 * routes that existed when the LAST package was applied, and it knows nothing
 * about the path this one adds. A clear_caches migration is per package, not
 * per subject area — CLAUDE.md phrases the rule as "every package that adds a
 * route also ships one".
 *
 *   - ROUTES. routes/bulk-documents-admin.php registers
 *     GET /admin-api/orders-bulk-documents. Without this, the router on the
 *     live host has never heard of it and the failure is the quiet kind: the
 *     new Print button appears on the Orders screen, the operator ticks twenty
 *     orders, and the tab that opens is a 404 — which reads as "bulk printing
 *     is broken" rather than "the route was never loaded". Packages
 *     2.60.102–.106 are the standing reminder of what an inert release costs.
 *
 *   - VIEWS, AND THIS IS THE HALF THAT WOULD NOT SELF-CORRECT. The new views
 *     and the six new partials are new paths, and a new path has no stale
 *     compiled copy to serve. But this package also CHANGES five Blade files
 *     that already exist on the server — invoices/document.blade.php and all
 *     four single documents, whose sheet bodies moved into partials. Compiled
 *     Blade is keyed by source path and invalidated by mtime, and a package
 *     extracted with a preserved timestamp, or onto a filesystem with a coarse
 *     mtime, leaves the old compiled file looking current. A stale
 *     invoice.blade.php would keep rendering its own inlined copy of the sheet
 *     — which still works, and would therefore go unnoticed while the two
 *     copies drifted apart — and a stale document.blade.php would fatal on the
 *     bulk page, whose $subject it has never heard of.
 *
 * OPcache too. BulkDocumentController, BulkDocumentSelection and
 * BulkDocumentRefused are additions, but AdminCapabilities and
 * Translation\InterfaceStrings are CHANGED by this package, and a worker
 * holding a stale compiled map is how a changed method keeps running its old
 * body. A stale AdminCapabilities is the one with teeth: the new route would be
 * unmapped, which fails CLOSED, so every admin who is not an owner would get a
 * 403 from a button that works for the owner testing it.
 *
 * NO SCHEMA CHANGE. Nothing here adds, reads or writes a column. Bulk printing
 * renders from `orders`, `order_items` and `settings` exactly as the single
 * documents already did, and the one write it performs on an invoice run —
 * orders.invoice_number and orders.invoiced_at, through
 * InvoiceNumbers::allocate() — is the write the single invoice route has been
 * making since 2026_10_01.
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
            echo "Cleared {$cleared} compiled files; Orders → tick → Print now has a route.\n";
        }
    }

    public function down(): void {}
};
