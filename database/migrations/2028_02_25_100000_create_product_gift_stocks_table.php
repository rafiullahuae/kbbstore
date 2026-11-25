<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Lane GST: a product's own GIFT STOCK (the owner, 25 Nov 2026: "it should also count as seperate quantity when we
 * set on gifts page" -- chosen: "Own gift stock: gifts use only that number; shop sales use only the shop stock").
 *
 * One row per product that has a gift stock. NO ROW = the product gives from its shop stock exactly as before, so
 * every product, range and order already in the shop behaves the same until a gift stock is set on the Gifts page.
 *
 * The columns are a shelf's columns -- `stock`, `stock_status`, `backorders` -- because StockClaim takes units off a
 * shelf by table name (StockClaim::SHELVES) and puts them back the same way on a cancel or a failed payment: a gift
 * line claims from this row, its ledger row names this table, and release() credits it here, never the shop stock.
 * `backorders` is always false: a gift that has run out is skipped (its backup gives), never owed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('product_gift_stocks')) {
            return;
        }

        Schema::create('product_gift_stocks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id')->unique();
            $table->integer('stock')->default(0);
            $table->string('stock_status', 20)->default('instock');
            $table->boolean('backorders')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_gift_stocks');
    }
};
