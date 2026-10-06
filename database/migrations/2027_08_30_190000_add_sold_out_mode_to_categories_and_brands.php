<?php

declare(strict_types=1);

use App\Support\SoldOut;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lane SX: each category's and each brand's own "Sold-out products" choice.
 *
 * NULL on every row, which is "Use the shop default", and the shop default
 * ships "Show as usual" -- so this migration moves nothing on the shop. Eight
 * characters is the longest of SoldOut::MODES with room to spare. No index:
 * the column is read off a row the page already holds, never searched on.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['categories', 'brands'] as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, SoldOut::COLUMN)) {
                Schema::table($table, fn (Blueprint $t) => $t->string(SoldOut::COLUMN, 8)->nullable());
            }
        }

        SoldOut::flush();

        if (app()->runningInConsole()) {
            echo "Sold-out products: Appearance -> Site layout -> Product grid sets the shop default (Show as usual);\n"
                ."each category and brand can override it in Catalog -> Categories / Brands -> Edit.\n";
        }
    }

    public function down(): void
    {
        foreach (['categories', 'brands'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, SoldOut::COLUMN)) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn(SoldOut::COLUMN));
            }
        }

        SoldOut::flush();
    }
};
