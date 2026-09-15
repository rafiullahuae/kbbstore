<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Desktop-only: how many columns a parent item's mega panel uses. Null
 * means auto — the panel works out its own column count from how many
 * children it has, roughly 10 per column, rather than every panel using
 * the same fixed count regardless of size. Set to a specific number here
 * to override that for one item.
 *
 * Never read on mobile — the phone menu doesn't have a column concept at
 * all, it's an expand/collapse list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menu_items', function (Blueprint $t) {
            if (! Schema::hasColumn('menu_items', 'columns')) {
                $t->unsignedTinyInteger('columns')->nullable()->after('new_tab');
            }
        });
    }

    public function down(): void
    {
        Schema::table('menu_items', function (Blueprint $t) {
            $t->dropColumn('columns');
        });
    }
};
