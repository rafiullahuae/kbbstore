<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A category's OWN title header: a custom description and its look. (Lane PY)
 *
 * The owner, on Lane PT's preview: "The title will be get from the category
 * name itself, if custom title or description not entered by me ... i can be
 * able to update that background iamge, title font size etc and description
 * etc for each category."
 *
 * Lane PT already gave categories `header_image`, `header_title` and
 * `header_subtitle` (2027_07_12_000100). Two more, on `categories` only --
 * brands are not in his request, and a brand page follows the shop:
 *
 *   header_description  the description written for the header. Blank: the
 *                        category's own description is used.
 *   header_style        JSON: the category's own alignment, text treatment,
 *                        box style, title sizes and heights. Every key is
 *                        optional and a missing key means "follow
 *                        Appearance -> Site layout -> Category header".
 *                        App\Support\TitleHeader::sanitizeStyle() is the one
 *                        definition of what may be in it, run on the way in
 *                        and on the way out.
 *
 * ON THE ROW THE PAGE ALREADY LOADS. A category archive reads its category
 * with `select *` (CategoryPath::resolve), so both columns arrive with it and
 * the page runs no query it did not run before -- StorefrontQueryBudgetTest.
 *
 * Nullable, no default, no backfill: NULL is "nothing set", which is exactly
 * what every category is the moment this runs.
 *
 * No AFTER clause (MigrationConventionTest).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('categories')) {
            return;
        }

        if (! Schema::hasColumn('categories', 'header_description')) {
            Schema::table('categories', fn (Blueprint $t) => $t->text('header_description')->nullable());
        }

        if (! Schema::hasColumn('categories', 'header_style')) {
            Schema::table('categories', fn (Blueprint $t) => $t->json('header_style')->nullable());
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('categories')) {
            return;
        }

        foreach (['header_description', 'header_style'] as $column) {
            if (Schema::hasColumn('categories', $column)) {
                Schema::table('categories', fn (Blueprint $t) => $t->dropColumn($column));
            }
        }
    }
};
