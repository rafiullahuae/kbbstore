<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the invoice heading, the Reply-To and the dispatch
 * timing line — Lane DE.
 *
 * FIVE BLADE FILES CHANGED, and every one of them is a document a customer
 * reads:
 *
 *   invoices/invoice.blade.php            the heading is now $doc['docType']
 *   emails/order-invoice.blade.php        the same, in the emailed copy
 *   emails/order-invoice-text.blade.php   and in its text part
 *   emails/layout.blade.php               the footer invites a reply only while
 *                                         a Reply-To address is configured
 *   partials/checkout/order-block.blade.php
 *                                         the two VAT rows are hidden with the
 *                                         `hidden` attribute instead of an
 *                                         inline style
 *
 * WITHOUT THIS THE PACKAGE LANDS AND NOTHING LOOKS DIFFERENT. The server serves
 * Blade from storage/framework/views, so the old compiled templates go on
 * rendering from files that are no longer on disk — and the symptom is not an
 * error, it is an invoice still headed as a tax document over an order that was
 * charged no tax, which is the whole thing this package was built to stop.
 *
 * WORSE THAN COSMETIC FOR TWO OF THEM. `invoices/invoice.blade.php` and the two
 * emailed invoice templates now read `$doc['docType']`, a key
 * App\Services\Invoices\InvoiceDocument only started returning in this package.
 * A stale compiled template beside a fresh class is harmless — it simply
 * ignores the key — but a FRESH template beside a stale class is an undefined
 * array key on the document the owner prints for his accountant. OPcache is the
 * half that makes that possible: the host cannot be restarted or shelled into,
 * so the PHP a package writes is not the PHP the server runs until OPcache lets
 * go of the old copy. Clearing both together is what keeps the pair in step.
 *
 * The emailed invoice is a partial of emails/layout.blade.php, and the checkout
 * order block is included by store/checkout.blade.php — Blade compiles a parent
 * and its includes into one file, so a package that rewrote only a partial would
 * leave the parent's cached compile serving the old copy. The views are cleared
 * wholesale, so that trap is not armed here either way.
 *
 * NO ROUTE CHANGED. Nothing was added to routes/. The route cache is dropped
 * with the rest because the cost is nil and a half-cleared cache is harder to
 * reason about than an empty one.
 *
 * NO SCHEMA CHANGE AND NO SEEDED ROW. Three settings keys are read by this
 * package — `invoice_doctype`, `mail_reply_to` and `mail_shipped_timing_note` —
 * and all three are rows in the existing `settings` table that this migration
 * deliberately does not write. Every one of them is blank until the owner types
 * something, and blank means the shop behaves exactly as it did before the
 * package: the invoice heading is derived from what the order actually records,
 * no Reply-To header is set and no invitation to reply is printed, and the
 * dispatch email says what it said yesterday to the byte.
 *
 * THE COMPILED ASSET BUNDLE IS NOT THIS MIGRATION'S TO FIX, and it matters here.
 * resources/js/kbb/checkout.js changed in the same lane: it now reveals the
 * checkout's VAT rows by clearing the `hidden` attribute rather than by writing
 * an inline display, which is the other half of the order-block change above.
 * public/build is a prebuilt artifact this repo tracks and only the integrator
 * rebuilds, so THIS PACKAGE MUST CARRY THE REBUILT BUNDLE. Applied without it, a
 * shopper who switches to a country on an exclusive tax basis would see a Total
 * with no VAT row above it. tests/Feature/CheckoutHiddenRowsTest.php pins the
 * two source halves together; the build itself is a person's job.
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
