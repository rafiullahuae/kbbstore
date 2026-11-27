<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Lane GSX: a gift-only product whose stock was counted back up while its status stayed "out of stock".
 *
 * The owner, 27 Nov 2026: one Maybelline mascara (a gift-only product) was put back to 1 in stock and the
 * gift ranges still read "Out of stock -- skipped until restocked", and the shop never gave it. The product
 * editor saved the count and kept the status its dropdown still showed (StockLevels::followStock() now stops
 * that for every later save). This puts the status of the rows ALREADY stored that way right: only gift-only
 * products (never on sale, so nothing in the shop changes), only when the stock is tracked, above zero, not a
 * product with options and not one allowing back orders. Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('products') || ! Schema::hasColumn('products', 'gift_only')) {
            return;
        }

        $fixed = DB::table('products')
            ->where('gift_only', true)
            ->where('manage_stock', true)
            ->where('stock', '>', 0)
            ->where('stock_status', 'outofstock')
            ->where(fn ($q) => $q->where('backorders', false)->orWhereNull('backorders'))
            ->where('type', '!=', 'variable')
            ->whereNull('deleted_at')
            ->update(['stock_status' => 'instock', 'updated_at' => now()]);

        if (app()->runningInConsole()) {
            echo "Gift-only products back in stock from their count: {$fixed}.\n";
        }
    }

    public function down(): void {}
};
