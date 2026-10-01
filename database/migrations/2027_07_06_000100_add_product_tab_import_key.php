<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which per-product tabs the WooCommerce import wrote. (Lane PI-A)
 *
 * The owner's old product pages showed extra tabs -- "Major Ingredients"
 * beside Description -- and the import now brings each one across as a tab of
 * that product's own (product_id set, source_key NULL). A re-import has to
 * UPDATE those rows rather than add a second copy, and has to remove one the
 * old shop no longer has, WITHOUT touching a tab the owner wrote himself in
 * Catalog -> Product tabs. Matching on the title cannot tell the two apart and
 * breaks the first time he renames one, so the row carries the key the import
 * gave it: `wc:1`, `wc:2`, ... in the order the old page drew them.
 *
 * NULL on every row that exists today and on every row the admin writes, so
 * nothing on the shop moves until an import runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_tabs') || Schema::hasColumn('product_tabs', 'import_key')) {
            return;
        }

        Schema::table('product_tabs', function (Blueprint $table) {
            $table->string('import_key', 16)->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('product_tabs') && Schema::hasColumn('product_tabs', 'import_key')) {
            Schema::table('product_tabs', function (Blueprint $table) {
                $table->dropColumn('import_key');
            });
        }
    }
};
