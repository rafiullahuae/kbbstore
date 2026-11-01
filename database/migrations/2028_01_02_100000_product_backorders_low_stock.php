<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Lane C (stock): a per-product low-stock level, and back orders that keep
 * selling. The owner: "We should be able to set low stock quantity for each
 * product on backend also on owner app, AND the back stock option should not
 * turn the product to out of stock."
 *
 * `products.low_stock_amount` -- NULL means "use the default" (Users & Roles
 * -> Owner app -> Low stock at, and the admin catalogue's 15). WooCommerce's own
 * name for the same number.
 *
 * `products.backorders` / `product_variants.backorders` -- "allow back
 * orders". Until now the only way to say it was stock_status = 'onbackorder',
 * which every shop page reads as SOLD OUT (the cart refuses anything but
 * 'instock'), so choosing "Allow backorders" in the owner app took the product
 * off sale. The flag lives in its own column now and the row's stock_status
 * stays 'instock', so the shop keeps selling it with no reader changed.
 *
 * DEFAULT MOVED, because he asked: every row stored as 'onbackorder' today is
 * converted to backorders = 1 + 'instock', i.e. those products become buyable
 * again. That is the brief ("should not turn the product to out of stock").
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('products') && ! Schema::hasColumn('products', 'low_stock_amount')) {
            Schema::table('products', fn (Blueprint $t) => $t->unsignedInteger('low_stock_amount')->nullable());
        }

        $moved = 0;

        foreach (['products', 'product_variants'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            if (! Schema::hasColumn($table, 'backorders')) {
                Schema::table($table, fn (Blueprint $t) => $t->boolean('backorders')->default(false));
            }

            $moved += DB::table($table)->where('stock_status', 'onbackorder')
                ->update(['backorders' => true, 'stock_status' => 'instock']);
        }

        if ($moved > 0) {
            // Named caches only, never Cache::flush() (sessions on some drivers).
            foreach ([
                [\App\Http\Controllers\Store\HomeController::class, 'flushCache'],
                [\App\Http\Controllers\Store\ShopController::class, 'flushSidebarCache'],
                [\App\Services\GridSections::class, 'flush'],
                [\App\Services\Seo\MerchantFeed::class, 'forget'],
                [\App\Services\VariantPricing::class, 'invalidate'],
                [\App\Services\VariantSwatches::class, 'invalidate'],
            ] as [$class, $method]) {
                try {
                    if (method_exists($class, $method)) {
                        $class::$method();
                    }
                } catch (\Throwable) {
                }
            }
        }

        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            echo "Back orders: {$moved} back-ordered rows are on sale again.\n";
        }
    }

    public function down(): void {}
};
