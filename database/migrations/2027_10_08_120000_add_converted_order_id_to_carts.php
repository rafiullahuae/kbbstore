<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Lane CO: which order a `converted` basket became.
 *
 * `orders` has never carried a cart id, and a converted cart carried nothing
 * that named its order either, so "give this shopper their basket back" could
 * only find the basket by cookie and had to trust that the converted row under
 * that cookie belonged to the order being released. It did not always: a card
 * retry places a SECOND order out of the same basket, and a late "abandon" for
 * the first one then re-opened a basket that had just been bought again.
 *
 * Written by CheckoutController::place() in the same save that marks the cart
 * converted (no query of its own); cleared when the basket is put back. NULL on
 * every row converted before this column existed, which BasketRelease treats as
 * "unknown" rather than "no".
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('carts') || Schema::hasColumn('carts', 'converted_order_id')) {
            return;
        }

        Schema::table('carts', function (Blueprint $table) {
            $table->unsignedBigInteger('converted_order_id')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('carts') && Schema::hasColumn('carts', 'converted_order_id')) {
            Schema::table('carts', function (Blueprint $table) {
                $table->dropColumn('converted_order_id');
            });
        }
    }
};
