<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the sample-order package (Lane O).
 *
 *   - ROUTES, and this is the one that is not optional. routes/sample-order-
 *     admin.php adds GET, POST and DELETE on /admin-api/sample-order. A
 *     compiled route cache on the live host is a file of the routes that
 *     existed when it was written, so it knows none of the three. The failure
 *     is the quiet kind CLAUDE.md warns about and the reason that convention
 *     exists: the Sample order card appears on Safety → Demo Content looking
 *     perfectly normal, and every button on it opens a 404 that reads as "the
 *     feature is broken" rather than "the routes were never loaded".
 *
 *   - VIEWS. resources/views/admin/app.blade.php grows the card, and
 *     resources/views/invoices/document.blade.php grows the SAMPLE banner that
 *     the invoice, the packing slip, the delivery note and the dispatch label
 *     all inherit. Both are files that ALREADY EXIST, which is exactly the case
 *     that does not self-correct: compiled Blade is keyed by path, so the admin
 *     console would go on serving the previous Demo Content screen — and the
 *     documents would go on printing with no banner on them — from a compiled
 *     copy, while the new code sat on disk unused. An unbannered sample invoice
 *     is the precise failure this feature exists to prevent.
 *
 * OPcache too. SampleOrder, SampleOrderController and the route file are new
 * classes, but this package CHANGES four that are not: AdminCapabilities (the
 * new `orders.sample` capability and its two route rules), OrderMailer (the
 * refusal to send for a sample order), InvoiceController (no invoice number is
 * minted for one) and InvoiceDocument (the `isSample` flag the banner reads).
 * A worker holding a stale compiled map for any of those keeps running the old
 * body — and for two of the four, the old body is the one that emails a sample
 * order's invoice out and burns an accountant's invoice number on it.
 *
 * NO SCHEMA CHANGE, DELIBERATELY. A sample order is marked by three things that
 * already have columns or tables: `orders.origin`, `orders.order_number`, and a
 * row in `demo_seed_log`. App\Support\DemoSeed's own header sets out why the
 * boolean column that would have been the obvious fourth is the wrong shape —
 * `demo_seed_log` already answers this question, and two answers drift. So
 * there is no ALTER TABLE on `orders` here, and nothing for the AFTER-clause
 * trap that made nine earlier migrations in this repo silent no-ops on MySQL to
 * catch hold of.
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
