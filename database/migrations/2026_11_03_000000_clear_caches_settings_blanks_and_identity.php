<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the blank-decimals fix and the support fields —
 * Lane DI.
 *
 * ── WHAT CHANGED, AND WHY EACH PIECE NEEDS THIS ─────────────────────────────
 *
 * FIVE BLADE FILES:
 *
 *   admin/app.blade.php          Store → Business Details → Business grows a
 *                                "How customers reach you" section, and its
 *                                Save posts three more keys.
 *   partials/header.blade.php    the WhatsApp chip reads
 *   partials/footer.blade.php    App\Support\SupportContact instead of each
 *   partials/mobile-chrome.blade.php
 *                                carrying the shop's phone number as a literal.
 *   emails/order-invoice-text.blade.php
 *                                the sign-off is $brand['signature'], the same
 *                                value the HTML half of that message prints.
 *
 * SEVEN PHP CLASSES the console or the mailer talks to:
 *
 *   AdminController              SETTING_RULES gains `support_email`,
 *                                `support_phone` and `brand_whatsapp`;
 *                                `currency_decimals` and `merchant_return_days`
 *                                move from `int` to a new `optint` rule that
 *                                accepts a blank box.
 *   Support\SupportContact       new — the one place the shipped phone numbers
 *                                live now.
 *   Mail\BrandedSubject          new — the store's name for a subject line.
 *   Mail\OrderConfirmation,
 *   Mail\OrderInvoice,
 *   Mail\OrderRefunded,
 *   Mail\OrderStatusChanged      five subject lines stop spelling the shop's
 *                                name out and follow Store name instead.
 *
 * WITHOUT THIS THE PACKAGE LANDS AND THE OWNER SEES NOTHING NEW. The server
 * renders Blade out of storage/framework/views, so the console goes on being
 * drawn from a compiled copy of a file that is no longer on disk — no contact
 * boxes, and the decimals box still refusing a Save it cannot explain.
 *
 * AND THE PAIRING IS THE DANGEROUS HALF, in the shape the invoice-identity
 * package recorded. A FRESH console beside a STALE controller posts three keys
 * the endpoint counts as unknown, drops, and answers `ok` to — "Business
 * details saved" over nothing written. The reverse is worse here than usual: a
 * fresh controller beside a stale console leaves the owner on a screen that
 * still cannot save his store name on a fresh shop, which is the entire defect
 * this lane exists to fix. OPcache is what makes both possible — the host has
 * no shell and cannot be restarted, so the PHP a package writes is not the PHP
 * the server runs until OPcache lets go.
 *
 * NO ROUTE CHANGED. Nothing was added to routes/. The route cache goes with the
 * rest because the cost is nil and a half-cleared cache is harder to reason
 * about than an empty one.
 *
 * ── NO SCHEMA CHANGE, AND NOTHING IS WRITTEN TO `settings` ──────────────────
 *
 * `support_email` and `brand_whatsapp` are already seeded on every install;
 * `support_phone` is not, and this migration does not seed it, because
 * SupportContact's chain ends in the string the templates have always printed.
 * A shop that applies this package and never opens the new section shows the
 * same header chip, the same footer line, the same mobile menu and the same
 * WhatsApp links, to the byte.
 *
 * THE ONE BEHAVIOUR THAT DOES MOVE ON APPLY is the five order-email subject
 * lines, which now say whatever Store → Business Details → Store name says. On
 * this shop that is "K-Beauty Bliss" where the subjects used to read "K Beauty
 * Bliss" — the hyphen the rest of every email has always carried. That is the
 * point of the change rather than a side effect of it: the subject is the most
 * visible identity claim the shop makes, and it was the one part that did not
 * follow the name.
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
