<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the Invoice tab and the support block — Lane DG.
 *
 * ── WHAT CHANGED, AND WHY EACH HALF NEEDS THIS ──────────────────────────────
 *
 * TWO BLADE FILES:
 *
 *   admin/app.blade.php   Store → Business Details grows a third tab, Invoice,
 *                         holding the eight seller-identity settings
 *                         App\Services\Invoices\InvoiceDocument has read since
 *                         it was written and nothing could write. The Tax tab
 *                         gains a pointer to it for the TRN.
 *   invoices/invoice.blade.php
 *                         @section('title') is $doc['docType'] rather than the
 *                         literal 'Invoice'. document.blade.php prints that into
 *                         <title>, which is the default FILENAME a browser
 *                         offers in its Save-as-PDF dialog — so a sheet headed
 *                         "Tax Invoice" was being filed as "Invoice".
 *
 * TWO PHP CLASSES, both of which the admin console talks to:
 *
 *   AdminController       SETTING_RULES gains the eight `invoice_*` keys, and
 *                         checkSetting() gains the three rules they needed
 *                         (`email`, `weburl`, `ident`).
 *   Mail\EmailBranding    the support block's email channel no longer offers
 *                         the `no-reply@<domain>` that MailSettings::
 *                         fromAddress() derives from APP_URL.
 *
 * WITHOUT THIS THE PACKAGE LANDS AND THE OWNER SEES NOTHING NEW. The server
 * renders Blade out of storage/framework/views, so the console goes on being
 * drawn from a compiled copy of a file that is no longer on disk — no Invoice
 * tab, no boxes, and no way to tell from the screen that anything shipped.
 *
 * AND THE PAIRING IS THE DANGEROUS HALF, in the same shape the doctype package
 * recorded. The new screen POSTS eight keys that only the new SETTING_RULES
 * knows. A stale console beside a fresh controller is merely invisible; a FRESH
 * console beside a stale controller posts eight keys the endpoint counts as
 * unknown, drops, and answers `ok` to — the screen says "Business details
 * saved" and writes nothing, which is the exact failure this lane exists to
 * end, reproduced by a half-applied package. OPcache is what makes that
 * possible: the host has no shell and cannot be restarted, so the PHP a package
 * writes is not the PHP the server runs until OPcache lets go. Clearing both
 * together is what keeps the pair in step.
 *
 * NO ROUTE CHANGED. Nothing was added to routes/. The route cache goes with the
 * rest because the cost is nil and a half-cleared cache is harder to reason
 * about than an empty one.
 *
 * ── NO SCHEMA CHANGE, AND NO SEEDED ROW, DELIBERATELY ───────────────────────
 *
 * All eight settings are rows in the existing `settings` table and this
 * migration writes NONE of them. The owner's business name, address, TRN, email,
 * phone, website, footer and document heading are facts about his company that
 * this project does not know and will not invent: a seeded placeholder would be
 * a false statement printed on a document people file with a tax authority.
 *
 * Blank is therefore the shipped state of every one, and every reader in
 * InvoiceDocument already falls back, so an install that applies this package
 * and never opens the tab prints exactly the invoice it printed yesterday, to
 * the byte. The heading in particular stays "Invoice" until a TRN is entered —
 * which is now possible for the first time.
 *
 * The one behaviour that DOES move on apply is the support block, and only on a
 * shop that left Store → Mail's From box empty: its order emails stop offering
 * `no-reply@<domain>` under "Email us". A shop that has set a support email, a
 * reply-to address or a From address of its own is unaffected, and
 * tests/Feature/SupportBlockNoReplyTest.php pins all three.
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
