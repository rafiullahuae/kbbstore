<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One product's own "You may also like" picks.                    (Lane PS)
 *
 * `{"mode": "first", "ids": [12, 7, 31]}` — App\Support\AlsoLikePicks carries
 * why this is JSON on the row rather than a pivot table. NULL on every
 * existing product, which reads as "use the rule": applying this moves nothing
 * until a product is given picks in Catalog → Products → (edit) → You may
 * also like.
 *
 * Guarded on the column, so a re-run after a half-applied package is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('products') && ! Schema::hasColumn('products', 'also_like')) {
            Schema::table('products', function (Blueprint $t) {
                $t->json('also_like')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('products') && Schema::hasColumn('products', 'also_like')) {
            Schema::table('products', function (Blueprint $t) {
                $t->dropColumn('also_like');
            });
        }
    }
};
