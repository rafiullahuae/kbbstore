<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One nullable timestamp, and it is the whole answer to "has this order's
 * coupon use already been handed back?".
 *
 * WHY A COLUMN AND NOT AN ABSENCE. Until now the only way to release a
 * redemption was to delete its row, which CouponService::releaseRedemptions()
 * did on the one path that had it: a card declined at the moment of placement.
 * Deleting works there because nothing else has happened yet. It is the wrong
 * shape for a cancellation, for two reasons that both cost money:
 *
 *   - THE FACT IS LOST. The row is the only record that a code was ever
 *     accepted on that order, and Store -> Coupons is drawn from those rows.
 *     Cancelling an order would silently erase it from the usage report while
 *     `orders.coupon_code` carried on printing the code on the order itself.
 *     It is also what CouponAdminApiController::destroy() counts to refuse
 *     deleting a code that has history.
 *
 *   - IT CANNOT BE CLAIMED. Two operators cancelling the same order at the
 *     same instant both SELECT the row, both delete it, and both decrement
 *     `coupons.usage_count` — one row released twice, and a one-per-customer
 *     code handed back twice. A conditional UPDATE against this column is a
 *     single statement the database settles: exactly one of the two gets a
 *     row count of 1 and only that one decrements. It is the same claim
 *     PaymentCapturer makes against `captured_at` and PaymentConfirmer against
 *     `paid_at`.
 *
 * NULL MEANS "STILL SPENT", which is what every existing row means, so nothing
 * about the live data changes when this runs. The rows imported from
 * WooCommerce have no redemption rows here at all — that is how an imported
 * order is told apart from one this shop redeemed, and this column does not
 * alter it.
 *
 * SAFE TO RUN TWICE. Update packages are re-applied by hand on this host more
 * often than anyone would like, and adding a column that is already there is an
 * error on both engines. Guarded by hasColumn, both ways.
 *
 * NO ->after(). It is MySQL-only syntax that SQLite's grammar drops on the
 * floor, so the suite and production would disagree about column order for no
 * gain — and nine migrations in this repo were silent no-ops for exactly that
 * reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('coupon_redemptions')) {
            return;
        }

        if (Schema::hasColumn('coupon_redemptions', 'released_at')) {
            return;
        }

        Schema::table('coupon_redemptions', function (Blueprint $t) {
            $t->timestamp('released_at')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('coupon_redemptions')) {
            return;
        }

        if (! Schema::hasColumn('coupon_redemptions', 'released_at')) {
            return;
        }

        Schema::table('coupon_redemptions', function (Blueprint $t) {
            $t->dropColumn('released_at');
        });
    }
};
