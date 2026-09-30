<?php

declare(strict_types=1);

use App\Support\DemoProductDetails;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Give the demo catalogue a Description, an Ingredients list and a How to use,
 * so the product page's detail tabs have something to show. (Lane PDP2, R4)
 *
 *     "also put some demo tabs on the product page, so i can see in action."
 *
 * ── WHY A MIGRATION AND NOT JUST THE SEEDER ─────────────────────────────────
 *
 * Because his shop is already seeded. DemoCatalogueSeeder uses firstOrCreate(),
 * so the three columns it now sets are written on a FRESH install and on no
 * other. The product he screenshotted says "Demo product for layout testing.
 * Replaced by the WordPress migration." — those rows exist, they have no
 * description, ingredients or how_to_use, and a seeder edit alone would leave
 * him looking at the same single tab after applying the package.
 *
 * ── THE THREE THINGS THIS MUST NOT DO ───────────────────────────────────────
 *
 *   1. IT MUST NOT OVERWRITE ANYTHING. Every column is filled only where it is
 *      currently NULL or an empty string, per column and per row. A demo
 *      product someone has since written a real description for keeps it and
 *      still gains the other two.
 *
 *   2. IT MUST TOUCH ONLY DEMO PRODUCTS. Three marks, all required at once —
 *      `wc_id IS NULL`, `sku LIKE 'DEMO-%'` and a `short_description` equal to
 *      the seeder's own sentence. DemoProductDetails' header sets out why each
 *      alone is not enough and why the conjunction is. The brief for this round
 *      named the short_description match as the unambiguous fallback; it is
 *      used as one of three rather than instead of them.
 *
 *   3. IT MUST STILL BE HARMLESS WHEN THE WORDPRESS IMPORT LANDS. An imported
 *      product carries a real `wc_id` — that is the column the Migrator upserts
 *      on — so it fails the first mark before the other two are asked.
 *      DemoProductBackfillTest seeds a real product beside the demo ones and
 *      breaks each mark in turn, asserting the row is left byte-identical.
 *
 * ── AND IT IS RE-RUNNABLE ───────────────────────────────────────────────────
 *
 * Running it twice writes nothing the second time, because the rows it would
 * fill are no longer empty. There is no `down()` that deletes the copy: the
 * columns it wrote are indistinguishable from columns an owner filled in by
 * hand, and a migration that guesses which is which is a migration that throws
 * away real work.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A host that has not run 2026_10_05_000000 yet has no columns to fill.
        foreach (['description', 'ingredients', 'how_to_use'] as $column) {
            if (! Schema::hasColumn('products', $column)) {
                if (app()->runningInConsole()) {
                    echo "products.{$column} does not exist yet; demo backfill skipped.\n";
                }

                return;
            }
        }

        $rows = DB::table('products')
            ->whereNull('wc_id')
            ->where('sku', 'like', DemoProductDetails::SKU_PREFIX.'%')
            ->where('short_description', DemoProductDetails::SEEDED_SHORT_DESCRIPTION)
            ->get(['id', 'name', 'description', 'ingredients', 'how_to_use']);

        $filled = 0;

        foreach ($rows as $row) {
            $bodies = DemoProductDetails::for((string) $row->name);
            $update = [];

            foreach ($bodies as $column => $body) {
                // PER COLUMN, not per row: a demo product with a description
                // and no ingredients gains the ingredients and keeps the
                // description. `trim()` because an empty <p></p> is not what
                // this is guarding — a genuinely blank column is, and both
                // NULL and '' arrive here from different writers.
                if (trim((string) ($row->{$column} ?? '')) === '') {
                    $update[$column] = $body;
                }
            }

            if ($update === []) {
                continue;
            }

            $update['updated_at'] = now();

            DB::table('products')->where('id', $row->id)->update($update);
            $filled++;
        }

        if (app()->runningInConsole()) {
            echo "Demo detail tabs: filled {$filled} of {$rows->count()} demo products.\n";
        }
    }

    /**
     * Nothing. See the header: the copy this wrote cannot be told apart from
     * copy an owner typed, and a down() that guessed would delete real work.
     */
    public function down(): void {}
};
