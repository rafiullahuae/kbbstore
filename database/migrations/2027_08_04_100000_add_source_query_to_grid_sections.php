<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lane HC: a Grid section's "Brands and categories, mixed" source keeps its
 * four values — brands, categories, sort, in stock — in one nullable JSON
 * column. NULL for every existing row, so every existing section reads exactly
 * the query it read before (GridSections::specKey() only looks at it when the
 * source is `query`, which no row holds until the owner picks it).
 *
 * Guarded both ways, so a package applied twice, or applied over a table an
 * older install never created, does nothing rather than failing the update.
 * `json` is TEXT on SQLite and JSON on MySQL 5.7+/MariaDB 10.2+; both cast
 * through Eloquent's `array` cast identically.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('grid_sections') || Schema::hasColumn('grid_sections', 'source_query')) {
            return;
        }

        Schema::table('grid_sections', function (Blueprint $table) {
            $table->json('source_query')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('grid_sections') && Schema::hasColumn('grid_sections', 'source_query')) {
            Schema::table('grid_sections', function (Blueprint $table) {
                $table->dropColumn('source_query');
            });
        }
    }
};
