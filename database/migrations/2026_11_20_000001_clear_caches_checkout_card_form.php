<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear the compiled caches for the new card form and the prefilled checkout —
 * Lane FY.
 *
 * NO ROUTES ARE ADDED BY THIS PACKAGE, so unlike its predecessor this is about
 * views alone. That makes it easier to think it is optional. It is not.
 *
 * Everything this lane changes on the storefront is a Blade template:
 * partials/checkout/stripe-card (three labelled boxes where there was one
 * strip, the padlock line, the save-card tick), partials/checkout/
 * stripe-elements (three Elements instead of one, and Link switched off), and
 * partials/checkout/payment-methods (the .payment_box now drawn for the card
 * gateway whether or not it has a description — which it no longer has).
 *
 * Compiled Blade is keyed by the view's PATH with a filemtime comparison for
 * freshness, and an update package is an unzip: the timestamps it lands are
 * whatever the archive carried and are not reliably newer than the compiled
 * copy the running site wrote. Landing these three views without clearing the
 * compiled cache produces the worst of both halves — the old combined card
 * element, or, if only one of the three is recompiled, a payment box whose
 * description has gone and whose card fields have gone with it, which is a
 * checkout that cannot take a card.
 *
 * `config.php` goes too, for the same reason it always does: nothing here
 * changes config, but a compiled config left beside a cleared view cache is the
 * one file most likely to be stale for an unrelated reason, and clearing it
 * costs one rebuild on the next request.
 *
 * No schema changes — the one column this package adds has its own migration
 * beside this file.
 *
 * Best-effort, like every clear_caches migration here: a file that cannot be
 * unlinked mid-update must not fail the package and strand the site
 * half-updated. A stale cache is a visible bug; a failed migration is an outage.
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
            echo "Cleared {$cleared} compiled files; the checkout now draws the card number,\n";
            echo "expiry and security code in their own labelled boxes.\n";
        }
    }

    public function down(): void {}
};
