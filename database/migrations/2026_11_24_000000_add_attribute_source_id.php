<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `attributes` gets the external id every other imported table already has.
 *
 * WHAT THIS LANE FOUND BY READING THE SCHEMA FIRST. Almost nothing was missing.
 * `product_variants` has carried `price`, `sale_price`, `sku`, `stock`,
 * `stock_status`, `manage_stock`, `image` and `position` since the ORIGINAL
 * schema migration; `attribute_values.source_term_id` is there and was made
 * UNIQUE by 2026_09_22_000000_add_import_external_ids; `tags.source_term_id`
 * and both pivots are there. Three importers were written against that schema
 * and needed one column added to it -- this one.
 *
 * `attributes` is the exception, and it is the exception for the reason that
 * migration's own header gives: "a table whose rows cannot be matched back to
 * their WordPress originals has exactly one behaviour on a re-run: it doubles."
 * That migration found five tables that had been missed. This is the sixth. It
 * is not in its list because nothing imported attributes at the time, so the
 * table had no re-run to survive.
 *
 * WITHOUT IT THE ONLY KEY IS THE SLUG, and matching on a slug is precisely what
 * docs/IMPORT-READINESS.md rule 1 forbids. The specific harm here is not the
 * usual one -- a WooCommerce attribute's slug IS its taxonomy name, which is
 * rarely edited -- it is that Admin\AttributesApiController lets the owner
 * CREATE an attribute by hand, on this shop, today. "Size" typed into that
 * screen and `pa_size` arriving from WooCommerce are two different things that
 * want one unique slug, and with no external id anywhere on the row the
 * importer cannot tell them apart: it would silently adopt the hand-made row
 * and hang the imported terms off it, or refuse it with no way to say why.
 *
 * With the column, App\Services\Import\SlugGuard answers it the same way it
 * already answers for brands, categories and products: a holder that carries a
 * DIFFERENT WooCommerce attribute id is refused outright, and a holder with a
 * NULL one -- demonstrably not from WooCommerce -- is refused by default and
 * adopted under --adopt-by-slug, reported by name.
 *
 * READ IMMEDIATELY, WHICH IS THE WHOLE CONDITION FOR ADDING IT.
 * App\Services\Import\Entities\AttributeImporter matches on this column before
 * it looks at a slug, and writes it on every row it creates. A column nothing
 * reads is the "built, never wired up" find this project has made five times
 * this month, and docs/FX-YOAST-TIER-CENSUS.md declines the per-variant `gtin`
 * column on exactly that ground -- see docs/GH-VARIATIONS-AND-ATTRIBUTES.md for
 * why that one is still declined and this one is not.
 *
 * NULLABLE, because attributes created in the admin have no WooCommerce origin
 * and never will, and because `wp_woocommerce_attribute_taxonomies` can be
 * missing a definition row for a `pa_*` taxonomy that still has terms -- the
 * exporter writes an empty `attribute_id` there rather than inventing one.
 *
 * NO ->after(). See tests/Feature/MigrationConventionTest.php.
 *
 * NO down(). This column is the only link between a row here and the attribute
 * it came from in WordPress; dropping it makes the next import duplicate every
 * attribute it already imported -- the same reasoning, verbatim, as
 * 2026_09_22_000000_add_import_external_ids.
 */
return new class extends Migration
{
    private const INDEX = 'attributes_source_attribute_unique';

    public function up(): void
    {
        if (! Schema::hasTable('attributes')) {
            return;
        }

        if (! Schema::hasColumn('attributes', 'source_attribute_id')) {
            Schema::table('attributes', function (Blueprint $table): void {
                $table->unsignedBigInteger('source_attribute_id')->nullable();
            });

            $this->say('Added attributes.source_attribute_id');
        }

        if ($this->hasUnique()) {
            return;
        }

        $duplicate = $this->firstDuplicate();

        if ($duplicate !== null) {
            // Loud, and not fatal -- the same call add_import_external_ids
            // makes. A unique index refused by data would abort this migration
            // and every later one in the same Core Updates package.
            $this->say(
                'SKIPPED '.self::INDEX.': attributes already holds two rows with source_attribute_id = '
                .$duplicate.'. De-duplicate, then re-run this migration.'
            );

            return;
        }

        Schema::table('attributes', function (Blueprint $table): void {
            $table->unique(['source_attribute_id'], self::INDEX);
        });

        $this->say('Indexed attributes (source_attribute_id) as '.self::INDEX);
    }

    public function down(): void {}

    /**
     * Is this column ALREADY covered by a unique constraint, under any name?
     *
     * Asked of the driver, and asked about the COLUMN SET rather than only the
     * name, for the reason add_import_external_ids sets out at length: a
     * name-only check adds a second unique index over the identical column on
     * every database that already had one under a different name.
     */
    private function hasUnique(): bool
    {
        try {
            foreach (Schema::getIndexes('attributes') as $index) {
                if (strcasecmp((string) ($index['name'] ?? ''), self::INDEX) === 0) {
                    return true;
                }

                if (($index['unique'] ?? false) !== true) {
                    continue;
                }

                $have = array_map('strtolower', array_map('strval', (array) ($index['columns'] ?? [])));

                if ($have === ['source_attribute_id']) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            $this->say(
                'WARNING: could not read indexes on attributes ('.$e->getMessage()
                .'); assuming '.self::INDEX.' is absent'
            );

            return false;
        }

        return false;
    }

    /**
     * NULLs are excluded deliberately: both engines allow any number of them in
     * a unique index, and that is the point of the column being nullable.
     */
    private function firstDuplicate(): ?string
    {
        $row = DB::table('attributes')
            ->select('source_attribute_id')
            ->selectRaw('COUNT(*) as n')
            ->whereNotNull('source_attribute_id')
            ->groupBy('source_attribute_id')
            ->havingRaw('COUNT(*) > 1')
            ->first();

        return $row === null ? null : (string) $row->source_attribute_id;
    }

    private function say(string $line): void
    {
        if (app()->runningInConsole()) {
            echo $line."\n";
        }
    }
};
