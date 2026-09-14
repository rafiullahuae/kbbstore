<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add any column the checkout insert needs that the orders table is missing.
 *
 * The live site returned 500 on every Place order with
 * SQLSTATE[42S22]: Unknown column 'is_gift'. The columns were introduced by
 * 2026_09_13_090000_add_gift_and_order_notes and
 * 2026_09_13_120000_add_gift_fee_to_orders, both of which are recorded as run,
 * and neither of which left the columns behind on that database.
 *
 * Why it can happen is visible in those files: each adds its column with
 * ->after(...) naming the column the previous migration was supposed to create.
 * On MySQL an ALTER ... AFTER a column that does not exist is an error, so one
 * missing anchor takes out the whole chain, while the guards --
 * Schema::hasColumn -- make the result look like a clean no-op rather than a
 * failure.
 *
 * This migration deliberately uses no ->after(). Column order is cosmetic;
 * being able to take an order is not. Every column is guarded, so it is safe on
 * a database that already has them and safe to run twice.
 *
 * Definitions match the originals exactly, so a database repaired here and one
 * migrated from scratch end up the same shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        $added = [];

        Schema::table('orders', function (Blueprint $t) use (&$added) {
            $add = function (string $column, callable $define) use ($t, &$added): void {
                if (! Schema::hasColumn('orders', $column)) {
                    $define($t);
                    $added[] = $column;
                }
            };

            // Gift wrapping — 2.60.85 and 2.60.92. The pair that was missing.
            $add('is_gift', fn (Blueprint $t) => $t->boolean('is_gift')->default(false));
            $add('gift_note', fn (Blueprint $t) => $t->text('gift_note')->nullable());
            $add('gift_fee', fn (Blueprint $t) => $t->integer('gift_fee')->default(0));

            // The rest of what Store\CheckoutController::place() writes. Guarded
            // for the same reason: if one anchor broke the chain, the columns
            // after it in that chain are missing too, and finding out one 500 at
            // a time is not a diagnosis worth repeating.
            $add('customer_note', fn (Blueprint $t) => $t->text('customer_note')->nullable());
            $add('whatsapp_optin', fn (Blueprint $t) => $t->boolean('whatsapp_optin')->default(false));
            $add('ip_address', fn (Blueprint $t) => $t->string('ip_address', 45)->nullable());
            $add('coupon_code', fn (Blueprint $t) => $t->string('coupon_code')->nullable());
        });

        if (app()->runningInConsole()) {
            echo $added === []
                ? "orders already had every checkout column.\n"
                : 'Added to orders: '.implode(', ', $added)."\n";
        }
    }

    /*
     * No down(). These columns carry real order data -- a gift note is a
     * customer's message -- and this migration exists precisely because the
     * table was missing them. Dropping them on rollback would recreate the
     * outage it was written to end.
     */
    public function down(): void {}
};
