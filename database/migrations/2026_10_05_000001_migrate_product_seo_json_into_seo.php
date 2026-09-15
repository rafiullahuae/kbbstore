<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Move per-product SEO out of the column nothing reads and into the one the
 * storefront actually publishes from.
 *
 * WHAT WAS WRONG. `products` carries two SEO columns, and the two halves of the
 * feature were wired to different ones by different lanes, each of which
 * checked its own half and wrote a confident comment about it:
 *
 *   - Admin\AdminController::updateProduct wrote `seo_json`, and getProduct
 *     read `seo_json` back. A closed loop: the values round-tripped inside the
 *     editor, so the screen looked like it worked.
 *
 *   - Store\ProductController — the controller that actually builds the <head>
 *     — reads `seo`. So do Admin\SchemaInspectorApiController and
 *     Admin\CatalogueAuditApiController, the two screens that report on SEO
 *     health.
 *
 * Nothing wrote `seo` and nothing read `seo_json`. The operator typed a meta
 * title, the admin showed it back to them, and Google was never told. The
 * earlier note in AdminController concluded "a sibling lane believed the column
 * was `seo`, and writing there would have put the operator's SEO fields
 * somewhere nothing reads" — the first half was right and the second half was
 * backwards, and the feature sat inert between the two.
 *
 * THE KEYS WERE WRONG TOO, which is why this is a mapping and not a copy. The
 * admin collected Yoast-shaped names (`seo_title`, `meta_description`) and the
 * storefront reads `title` and `desc`. Even pointed at the right column the old
 * payload would have published nothing, so the rename happens here as well:
 *
 *     seo_title        -> title
 *     meta_description -> desc
 *
 * Everything else is carried across untouched. focus_keyphrase, og_title and
 * the schema_* selections are not read by the storefront today, but they are
 * what the operator typed and dropping them on the floor during a repair is not
 * this migration's business.
 *
 * ANYTHING ALREADY IN `seo` WINS. That column was declared in the Phase 0
 * schema as the Yoast import target. It should be empty on this deployment —
 * the importer was never built — but if a row does carry a value it came from
 * the source of truth for that product's SEO, and a repair job must not
 * overwrite real data with data that was, by definition, never live. The merge
 * below fills gaps only.
 *
 * `seo_json` IS LEFT IN PLACE, not dropped. This migration is reversible by
 * doing nothing, the old column is the backup if any of the above is wrong
 * about a particular row, and dropping a column on MySQL 5.7 on shared hosting
 * to tidy up is not a trade worth making. A later package can drop it once
 * this has been live for a while.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('products')
            || ! Schema::hasColumn('products', 'seo_json')
            || ! Schema::hasColumn('products', 'seo')) {
            return;
        }

        DB::table('products')
            ->whereNotNull('seo_json')
            ->where('seo_json', '!=', '')
            ->orderBy('id')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    $legacy = json_decode((string) $row->seo_json, true);

                    if (! is_array($legacy) || $legacy === []) {
                        continue;
                    }

                    // The same normaliser the write path uses, so the rename
                    // cannot half-happen: if App\Support\ProductSeo learns
                    // another legacy spelling, rescued rows and newly saved
                    // rows agree about it without this file being touched.
                    $mapped = \App\Support\ProductSeo::normalise($legacy) ?? [];

                    $current = json_decode((string) ($row->seo ?? ''), true);
                    $current = is_array($current) ? $current : [];

                    // Existing `seo` keys win; the legacy blob fills the gaps.
                    $merged = $current + $mapped;

                    // Blank strings are not values. Leaving "" in `title` would
                    // make the storefront's `!empty($override['title'])` test
                    // false anyway, but it would also make the editor show an
                    // override where the operator set none.
                    $merged = array_filter(
                        $merged,
                        static fn ($v) => $v !== null && $v !== '' && $v !== []
                    );

                    if ($merged === $current) {
                        continue;
                    }

                    DB::table('products')
                        ->where('id', $row->id)
                        ->update(['seo' => $merged === [] ? null : json_encode($merged)]);
                }
            });
    }

    /**
     * Deliberately empty.
     *
     * `seo_json` was never cleared, so rolling forward and back loses nothing.
     * Emptying `seo` on the way down would destroy any genuine Yoast-imported
     * value that this migration merely merged into, which is a worse outcome
     * than a column carrying data a rollback did not ask it to carry.
     */
    public function down(): void
    {
    }
};
