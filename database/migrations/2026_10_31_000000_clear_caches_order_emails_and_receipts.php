<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the order-emails-and-receipts change — Lane CX.
 *
 * WHAT CHANGED, AND WHY A HALF-APPLIED COPY WOULD BE WORSE THAN NO CHANGE.
 *
 * Four Blade templates and three PHP classes, and the Blade half is the reason
 * this migration exists. storage/framework/views holds a compiled copy of every
 * template, keyed on the template's path rather than on its contents, so a
 * package that rewrites a .blade.php file changes nothing at all on this host
 * until the compiled copy is dropped:
 *
 *   resources/views/store/checkout.blade.php          one rule that makes the
 *                                                     `hidden` attribute work on
 *                                                     this page again
 *   resources/views/emails/layout.blade.php           the invitation to reply,
 *                                                     removed
 *   resources/views/emails/order-invoice.blade.php    "Paid by" only when paid
 *
 * The PHP half is App\Mail\OrderStatusChanged, App\Services\Mail\MailSettings
 * and the mail templates that render them. This host cannot be shelled into or
 * restarted, so the PHP a package writes is not the PHP the server runs until
 * OPcache lets go of the old copy — the standing reason behind the withdrawn
 * packages 2.60.102-.106 recorded in CLAUDE.md.
 *
 * A HALF-APPLIED VERSION IS SPECIFICALLY BAD HERE. OrderStatusChanged gained a
 * public constant, CANCELLED_REFUND_NOTE, and a new property that the cancelled
 * body is built from. A cached copy of the old class rendered against the new
 * templates sends the cancellation email with no sentence about money at all —
 * and a cached copy of the old TEMPLATE keeps printing the withdrawn promise
 * that the refund is already in flight, which is the whole defect this lane
 * exists to remove, still running on a build that believes it has been fixed.
 *
 * MailSettings::SCHEMA gained `mail_cancelled_refund_note`, which Store → Mail
 * renders from the constant and MailApiController validates against it, so a
 * stale MailSettings makes the new box vanish from the screen and rejects a save
 * that carries it. The settings map itself is untouched: no row is written here,
 * and the key ships absent so that the email says nothing extra until the owner
 * types something.
 *
 * No route was added, so there is no route file for the integrator to wire; the
 * route cache is dropped for consistency with every other entry here.
 *
 * Nothing here positions a column with an AFTER clause, the thing that made nine
 * earlier migrations silent no-ops on MySQL. Nothing here writes to a table at
 * all.
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
