<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A savings label on a product variant — "Save 24%", "Best value".
 *
 * This is what turns a variant into a bundle offer on the product page. Guarded
 * with hasColumn so it is safe whether or not the column already exists, and
 * safe to run again after a restore.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_variants')) {
            return;
        }

        if (! Schema::hasColumn('product_variants', 'tag')) {
            Schema::table('product_variants', function (Blueprint $table) {
                $table->string('tag', 40)->nullable()->after('sku');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('product_variants') && Schema::hasColumn('product_variants', 'tag')) {
            Schema::table('product_variants', function (Blueprint $table) {
                $table->dropColumn('tag');
            });
        }
    }
};
