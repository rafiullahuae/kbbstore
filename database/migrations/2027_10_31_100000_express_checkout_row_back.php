<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/*
 * 2.60.496 -- URGENT: the Express checkout row comes back.
 *
 * The owner, 14 October, on the live shop: "The Express Checkout section is
 * completely gone. which we had below top summary block ... where we had apple
 * / google pay with heading Express Checkout. i have told you that do not touch
 * that" -- and then: "express checkout will also show, and also the apple
 * pay only in the list too". 2.60.495 had MOVED the one Stripe button out of
 * the Express row into the payment list. partials/checkout/express-wallets now
 * draws the Express row exactly as before (heading, Apple Pay and Google Pay,
 * the OR line) and runs a second, separate Apple-Pay-only copy for the list.
 * Nothing stored changes; the compiled view caches go so the new partial is
 * read.
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
            base_path('bootstrap/cache/events.php'),
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

    public function down(): void
    {
    }
};
