<?php

declare(strict_types=1);

use App\Support\DemoProductDetails;
use App\Support\DemoProductShots;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Draw the five gallery shots of every demo product, so the thumbnail strip
 * under the photograph has something in it.                        (Lane GAL)
 *
 *   "also i can not see the product gallery thumnails, add some demo thumnails
 *    so i can see in action."
 *
 * ── WHY A MIGRATION ─────────────────────────────────────────────────────────
 *
 * Because the owner's shop is already seeded and has no shell of its own for
 * this: an update is a zip applied from Store → Core Updates, and a migration
 * is the only thing in that package that gets to RUN. DemoCatalogueSeeder draws
 * the same files on a fresh install; this is what reaches the rows he has.
 *
 * ── AND WHY IT WRITES NO COLUMN ─────────────────────────────────────────────
 *
 * This started as a backfill of `products`.images, the way
 * 2027_06_15_000000 backfilled the detail tabs, and the measurement is in
 * App\Support\DemoProductShots' header: it took the suite from 3 failures to
 * 26, across seven files that have nothing to do with galleries, because
 * `images` is CATALOGUE data — the importer's re-pointer, MediaAudit,
 * MediaUsageWriter::rebuild() and the image-variants backlog all walk it. On
 * the owner's shop it would have added 120 rows to Content → Media Library's
 * attachment counts, 120 items to the Image sizes backlog and 120 placeholder
 * URLs to his image sitemap and his products' schema.org.
 *
 * So this migration makes FILES and nothing else, and
 * Store\ProductController::gallery() tops up a demo product that has no shots
 * of its own from them. Nothing to roll back, nothing left behind when the
 * WordPress import gives these rows a wc_id, and no catalogue column moves.
 *
 * ── WHAT IT COSTS ───────────────────────────────────────────────────────────
 *
 * Measured on this container: five PNGs per product, 23,454 bytes on average,
 * 562,886 bytes for the whole 24-product demo catalogue, and about six seconds
 * of drawing the first time. A second application of the package costs 120
 * `is_file()` calls and nothing else, because the files are already there.
 *
 * Not one of those bytes travels in the package — CLAUDE.md records what
 * 2.60.102–.106 did to this shop, and `public/uploads/` is untracked besides.
 *
 * ── AND IT DRAWS FOR DEMO PRODUCTS ONLY ─────────────────────────────────────
 *
 * The same three marks, all required at once — `wc_id IS NULL`,
 * `sku LIKE 'DEMO-%'` and the seeder's own short_description.
 * DemoProductShots::isDemo() sets out why each alone is not enough. An imported
 * product carries a real `wc_id`, which is the column the Migrator upserts on,
 * so it fails the first mark before the other two are asked and not one byte is
 * drawn for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        $rows = DB::table('products')
            ->whereNull('wc_id')
            ->where('sku', 'like', DemoProductShots::SKU_PREFIX.'%')
            ->where('short_description', DemoProductDetails::SEEDED_SHORT_DESCRIPTION)
            ->get(['id', 'slug', 'name']);

        $drawn = 0;

        foreach ($rows as $row) {
            $urls = DemoProductShots::ensureFor((string) $row->slug, (string) $row->name);

            if ($urls !== []) {
                $drawn++;
            }
        }

        if (app()->runningInConsole()) {
            echo "Demo gallery: drew shots for {$drawn} of {$rows->count()} demo products.\n";
        }
    }

    /**
     * Nothing.
     *
     * The files are the whole of what this wrote, and they are indistinguishable
     * from pictures an operator uploaded into the same folder — the media
     * library lists them as such, which is the point. A down() that deleted
     * them would delete those too.
     */
    public function down(): void {}
};
