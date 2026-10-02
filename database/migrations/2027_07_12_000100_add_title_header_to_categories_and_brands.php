<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The old shop's category (and brand) TITLE HEADER -- schema. (Lane PT)
 *
 * The owner: "We have a banner image on each category on the old site. Need to
 * bring that on the category pages as title background, like on /sunscreens/."
 * Exporter 1.11.0 carries it as `banner_image` (plus `banner_source_key`,
 * `title_override`, `subtitle`) in categories.csv and brands.csv, and
 * CategoryImporter / BrandImporter write it here.
 *
 * WHY NOT THE `banner` COLUMN LANE BW ADDED. That column is the OWNER'S banner:
 * configured by hand on Catalog -> Brands -> Banner, off until he turns it on,
 * and its docblock is explicit that "a separate column cannot be clobbered by
 * an importer that does not know it exists". An importer that wrote into it on
 * every run would overwrite whatever he had designed there each time he
 * re-imported. So the imported header has columns of its own, the importer is
 * their only writer, and App\Support\TitleHeader draws them only when no
 * hand-made banner is switched on -- his banner always wins.
 *
 *   header_image     the picture behind the title. A URL on the old site until
 *                    the picture pass copies it here; MediaAudit, MediaRewrite
 *                    and the sideloader count, fetch and re-point it exactly as
 *                    they do categories.image.
 *   header_source    which term-meta key it came from on the old shop, for the
 *                    owner to read back -- never rendered.
 *   header_title     a title written for the header on the old shop, if any.
 *   header_subtitle  a subtitle written for it, if any.
 *
 * Nullable, no default, no backfill: NULL is "nothing imported", and a page
 * whose category has nothing imported renders exactly what it rendered before
 * this migration -- StorefrontEnglishUnchangedTest pins that.
 *
 * No AFTER clause (MigrationConventionTest).
 */
return new class extends Migration
{
    private const COLUMNS = ['header_image', 'header_source', 'header_title', 'header_subtitle'];

    public function up(): void
    {
        foreach (['categories', 'brands'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            if (! Schema::hasColumn($table, 'header_image')) {
                Schema::table($table, fn (Blueprint $t) => $t->string('header_image', 2048)->nullable());
            }

            if (! Schema::hasColumn($table, 'header_source')) {
                Schema::table($table, fn (Blueprint $t) => $t->string('header_source', 255)->nullable());
            }

            if (! Schema::hasColumn($table, 'header_title')) {
                Schema::table($table, fn (Blueprint $t) => $t->string('header_title', 300)->nullable());
            }

            if (! Schema::hasColumn($table, 'header_subtitle')) {
                Schema::table($table, fn (Blueprint $t) => $t->string('header_subtitle', 300)->nullable());
            }
        }
    }

    public function down(): void
    {
        foreach (['categories', 'brands'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach (self::COLUMNS as $column) {
                if (Schema::hasColumn($table, $column)) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropColumn($column));
                }
            }
        }
    }
};
