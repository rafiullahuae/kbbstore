<?php

declare(strict_types=1);

use App\Support\CategoryRollup;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lane SC: each category's own "Sub-category products" choice.
 *
 * NULL on every row, which is "Use the shop setting", and the shop setting
 * ships "Include sub-categories" because the owner asked for it -- so once
 * this runs every parent category lists its sub-categories' products.
 * Catalog → Categories → Edit → "Only this category's own products" puts one
 * back; Appearance → Site layout → Product grid puts them all back.
 *
 * No index: the column is read off a row the page already holds, and the
 * cached tree, never searched on. The listing itself filters
 * `category_product.category_id IN (...)`, which is the leading column of
 * that table's primary key (category_id, product_id) -- already an index.
 *
 * The caches that hold a picture of the tree or its counts are cleared, so
 * the shop shows the new lists on the next request rather than 15 minutes on.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('categories') && ! Schema::hasColumn('categories', CategoryRollup::COLUMN)) {
            Schema::table('categories', fn (Blueprint $t) => $t->string(CategoryRollup::COLUMN, 8)->nullable());
        }

        self::flush();

        if (app()->runningInConsole()) {
            echo "Sub-category products: Appearance -> Site layout -> Product grid (shop setting: Include sub-categories);\n"
                ."each category can override it in Catalog -> Categories -> Edit.\n";
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('categories') && Schema::hasColumn('categories', CategoryRollup::COLUMN)) {
            Schema::table('categories', fn (Blueprint $t) => $t->dropColumn(CategoryRollup::COLUMN));
        }

        self::flush();
    }

    private static function flush(): void
    {
        try {
            CategoryRollup::flush();
            \App\Http\Controllers\Store\ShopController::flushSidebarCache();
            \App\Http\Controllers\Store\HomeController::flushCache();
        } catch (\Throwable) {
            // A cache that cannot be reached is not a failed migration.
        }
    }
};
