<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `orders.voided_at` — the third thing a held payment can do.
 *
 * `captured_at` says the money was taken and `refunded_total` says it came
 * back. Neither can say what happens most often on a cancelled BNPL order: the
 * authorisation was RELEASED and nobody was ever charged. Until this column
 * existed there was nowhere to record that, so the act had no idempotency guard
 * and no way to be read back — and both matter for money:
 *
 *   - PaymentVoider claims this column with a conditional UPDATE against NULL
 *     before it calls the provider, exactly as PaymentCapturer claims
 *     `captured_at`. Without it a double-clicked Release button sends two
 *     closes; the second is harmless at Tabby today and is an unguarded write
 *     whatever the provider happens to do with it.
 *   - The order screen can distinguish "this hold is still open, go and release
 *     it" from "already released", which is the whole reason an operator would
 *     look.
 *
 * NULLABLE AND NULL FOR EVERY EXISTING ROW, which is Rule 1: applying this
 * package changes nothing about any order that already exists. A live
 * authorisation that was never released stays exactly as unreleased as it was
 * and shows up as releasable, which is the truth.
 *
 * hasColumn() guarded, like the capture-tracking migration beside it, so a
 * re-applied package is a no-op rather than an error that fails an update run.
 *
 * ------------------------------------------------------------------------
 * AND THE CACHES, WHICH ARE NOT OPTIONAL HERE.
 *
 * This package adds routes (routes/payments-tabby.php: the Tabby webhook
 * registration endpoints and the release-authorisation endpoint). A compiled
 * route cache on the live host knows none of them, so every one of them 404s
 * while the buttons render perfectly — the exact failure CLAUDE.md names. The
 * config cache goes with it because the route cache is rebuilt from it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('orders', 'voided_at')) {
            Schema::table('orders', function (Blueprint $t) {
                /*
                 * NO ->after(). Column order is cosmetic and this project has
                 * already paid for the alternative: on MySQL, ALTER ... AFTER a
                 * column that does not exist is an error, a hasColumn-guarded
                 * chain then records as run having done nothing, and SQLite
                 * ignores AFTER so the suite stays green over a checkout that
                 * cannot take an order. MigrationConventionTest forbids it by
                 * name and was right to catch this one.
                 */
                $t->timestamp('voided_at')->nullable();
            });
        }

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
            echo "Cleared {$cleared} compiled files. Tabby can now be registered for webhooks "
                . "(Store -> Payments -> Tabby) and an uncaptured Tabby authorisation can be released "
                . "from the order screen. No setting changed and nothing on the storefront moved.\n";
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('orders', 'voided_at')) {
            Schema::table('orders', function (Blueprint $t) {
                $t->dropColumn('voided_at');
            });
        }
    }
};
