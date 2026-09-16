<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The record of units actually taken off a shelf for an order.
 *
 * ---------------------------------------------------------------------------
 * Why a table and not a derivation from the order's own lines
 * ---------------------------------------------------------------------------
 *
 * Putting stock back when an order is cancelled needs an answer to "how many
 * units did this order take, and off which shelf". Every way of DERIVING that
 * answer after the fact is wrong in this shop, and each one invents inventory
 * in a different way:
 *
 *   - FROM THE ORDER LINES. They are snapshots, and the admin order screen can
 *     add, edit and remove them after placement (AdminOrderController::addItem,
 *     updateItem, removeItem). A line added by hand never came off a shelf.
 *
 *   - FROM `manage_stock` AT RETURN TIME. An order placed while a product
 *     counted no stock took nothing. Switch `manage_stock` on a month later,
 *     cancel that order, and a derivation credits the shelf with units that
 *     were never removed from it.
 *
 *   - FROM WHICH SHELF THE LINE POINTS AT. A variant line comes off the
 *     VARIANT's shelf only when the variant counts its own stock; otherwise it
 *     comes off the parent product's. Which of the two applied is a fact about
 *     the moment of the claim, not about the row as it stands now.
 *
 *   - FROM THE ORDER EXISTING AT ALL. Imported WooCommerce orders and orders
 *     typed by staff through ManualOrderBuilder never claimed anything, and
 *     ManualOrderBuilder says so in its own class comment on purpose.
 *
 * So the claim is recorded when it happens, by the only code that knows what
 * it did: App\Services\StockClaim. A row here means "these units left this
 * shelf for this order". No row means nothing was taken, and nothing may be
 * given back. That is the whole design.
 *
 * ---------------------------------------------------------------------------
 * The columns
 * ---------------------------------------------------------------------------
 *
 * `shelf_table` and `shelf_id` name the row that was decremented — `products`
 * or `product_variants` — rather than the product and variant the line asked
 * for, because those two are not the same question (see the third bullet
 * above). StockClaim::SHELVES is an explicit allowlist and the release path
 * refuses any other value rather than interpolating a table name it was handed.
 *
 * `marked_outofstock` records whether THIS claim is what emptied the shelf and
 * set `stock_status` to `outofstock`. A return puts the status back only when
 * it does — otherwise an unrelated cancellation would quietly re-list a
 * product the owner had marked sold out by hand for reasons of their own.
 *
 * `released_at` is the double-return guard, and it is a recorded fact rather
 * than an inference from the order's status: cancelling an order twice, or
 * cancelling it and then refunding it, must not add the units back twice.
 * StockClaim::release() claims a row by UPDATE ... WHERE released_at IS NULL
 * and checks the affected row count, so of two concurrent releases exactly one
 * moves stock — the same shape as the conditional decrement on the way out.
 *
 * No foreign key to `orders`. Orders soft-delete and the import writes rows
 * this application did not create; a claim is an inventory fact that should
 * outlive the order row being trashed, not a child of it.
 *
 * Nothing here positions a column with an AFTER clause — the thing that made
 * nine earlier migrations silent no-ops on MySQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('order_stock_claims')) {
            return;
        }

        Schema::create('order_stock_claims', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('order_id')->index();

            // Which row was decremented. Not which product the line named.
            $table->string('shelf_table', 32);
            $table->unsignedBigInteger('shelf_id');

            // Kept for the operator reading the table, never for the arithmetic.
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('product_variant_id')->nullable();

            $table->integer('quantity');

            $table->boolean('marked_outofstock')->default(false);

            $table->timestamp('released_at')->nullable();
            $table->string('released_reason', 60)->nullable();

            $table->timestamps();

            // The release path's own query: one order's outstanding claims.
            $table->index(['order_id', 'released_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_stock_claims');
    }
};
