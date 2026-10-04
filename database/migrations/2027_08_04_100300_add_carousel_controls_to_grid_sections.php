<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lane HC: "Cards in view" and "Arrows" for every Grid section carousel. The
 * owner: "give this option on any carousel products section we create. so it
 * will be ease for us".
 *
 * `per_m` ships '2.3' — the value he chose for the homepage carousels in
 * 2.60.373 — on every row, existing ones included, because he asked for it as
 * the default for any carousel that lacks the setting. `per_d` ships NULL,
 * which means "Desktop columns, as before", so a laptop carousel is unchanged
 * until he moves it. Arrows: on for laptops, off for phones — the bundles and
 * Spotted defaults he already set.
 *
 * Guarded column by column, so a package applied twice does nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('grid_sections')) {
            return;
        }

        $add = [
            'per_m' => fn (Blueprint $t) => $t->string('per_m', 8)->default('2.3'),
            'per_d' => fn (Blueprint $t) => $t->string('per_d', 8)->nullable(),
            'arrows_m' => fn (Blueprint $t) => $t->boolean('arrows_m')->default(false),
            'arrows_d' => fn (Blueprint $t) => $t->boolean('arrows_d')->default(true),
        ];

        foreach ($add as $column => $define) {
            if (! Schema::hasColumn('grid_sections', $column)) {
                Schema::table('grid_sections', fn (Blueprint $t) => $define($t));
            }
        }
    }

    public function down(): void
    {
        foreach (['per_m', 'per_d', 'arrows_m', 'arrows_d'] as $column) {
            if (Schema::hasTable('grid_sections') && Schema::hasColumn('grid_sections', $column)) {
                Schema::table('grid_sections', fn (Blueprint $t) => $t->dropColumn($column));
            }
        }
    }
};
