<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Lane SO. The owner: "when i did the sorting of Super Sale category, then it
 * also effected the Medicube brand's sorting too ... NO ANY CATEGORY OR BRAND
 * should disturb the sorting of each other in any case." And: "remove the
 * filter at all ... keep turned off completely on all pages by default."
 *
 * 1. Every category and every brand gets its own order (App\Support\ScopeOrder
 *    says where each lives and why): `category_product.category_position` and
 *    `products.brand_position`.
 *
 *    SEEDED FROM WHAT EVERY PAGE SHOWS TODAY. Each copies the product's current
 *    `products.position` value -- not a rank -- so every tie stays a tie and
 *    each page's own tie-break (featured then name on a category page, name on
 *    a brand page and /super-sale/) decides exactly as it did. Applying this
 *    moves nothing on any category or brand page. It can only keep what is
 *    stored: where one scope's Reorder already overwrote another's numbers,
 *    that is the order both start from, and re-saving the one that matters
 *    once puts it back -- after which no other scope can move it.
 *
 *    Seeded only when the column is new, so running this twice never throws
 *    away an order saved in between.
 *
 * 2. The Filters button on phones stored off. The laptop's filter column and
 *    the shop links are new switches whose default is already off.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('category_product', 'category_position')) {
            Schema::table('category_product', function (Blueprint $t) {
                $t->integer('category_position')->nullable();
            });

            DB::table('category_product')->update([
                'category_position' => DB::raw('(SELECT products.position FROM products WHERE products.id = category_product.product_id)'),
            ]);
        }

        if (! Schema::hasColumn('products', 'brand_position')) {
            Schema::table('products', function (Blueprint $t) {
                $t->integer('brand_position')->nullable();
                $t->index(['brand_id', 'brand_position'], 'products_brand_id_brand_position_index');
            });

            DB::table('products')->whereNotNull('brand_id')->update(['brand_position' => DB::raw('position')]);
        }

        if (Schema::hasTable('settings')) {
            DB::table('settings')->where('key', 'layout_filters_m')->update(['value' => '0']);
        }

        foreach (glob(storage_path('framework/views/*.php')) ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        foreach (['kbb.settings', 'kbb.settings.map', 'kbb.shop.cats', 'kbb.shop.brands'] as $key) {
            try {
                Cache::forget($key);
            } catch (\Throwable) {
                // A missing cache store is not a failed migration.
            }
        }

        if (app()->runningInConsole()) {
            echo "Each category and brand now keeps its own order (seeded from today's); filters off.\n";
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', 'brand_position')) {
            // MySQL lets the composite index stand in for the brand_id foreign
            // key's own index and drops that one, then refuses to drop ours.
            // A plain brand_id index first gives the key something to keep.
            $plain = ! Schema::hasIndex('products', ['brand_id']);

            Schema::table('products', function (Blueprint $t) use ($plain) {
                if ($plain) {
                    $t->index('brand_id', 'products_brand_id_index');
                }
                $t->dropIndex('products_brand_id_brand_position_index');
                $t->dropColumn('brand_position');
            });
        }

        if (Schema::hasColumn('category_product', 'category_position')) {
            Schema::table('category_product', function (Blueprint $t) {
                $t->dropColumn('category_position');
            });
        }
    }
};
