<?php

declare(strict_types=1);

use App\Support\CategoryOrder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * 2.60.501 -- a category's own starting order, and New Arrivals newest first.
 *
 * The owner: "in this category i need to view the new added products first. as
 * the collection name is New Arrivals", and "keep the new added by default,
 * only for this category, in rest we re-ordered, don't touch them".
 *
 * So: one nullable column (NULL = the shop default, which is every category's
 * own Reorder, exactly as now), and ONE row set -- the category whose slug is
 * new-arrivals, if this shop has it. No other category is written.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('categories', CategoryOrder::COLUMN)) {
            Schema::table('categories', function (Blueprint $table) {
                $table->string(CategoryOrder::COLUMN, 16)->nullable();
            });
        }

        DB::table('categories')->where('slug', 'new-arrivals')->update([CategoryOrder::COLUMN => 'date']);

        // Compiled views and config go, as every package that ships views does.
        foreach ([storage_path('framework/views/*.php'), base_path('bootstrap/cache/config.php')] as $pattern) {
            foreach (glob($pattern) ?: [] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('categories', CategoryOrder::COLUMN)) {
            Schema::table('categories', function (Blueprint $table) {
                $table->dropColumn(CategoryOrder::COLUMN);
            });
        }
    }
};
