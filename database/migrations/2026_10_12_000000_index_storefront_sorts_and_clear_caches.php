<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lane BQ — two indexes the storefront's hot queries sort and filter on, and
 * the cache clear the code change in the same package needs.
 *
 * 1. AN INDEX ON products.created_at.
 *
 * /new-in is `ORDER BY created_at DESC, id DESC` over the whole visible
 * catalogue, and /shop?orderby=date is the same ORDER BY. Nothing indexed that
 * column, so MySQL read every visible row and sorted all of them to return the
 * twenty-four on the page. Measured on MySQL 8.0 against this repo's schema,
 * best of five, /new-in's first page:
 *
 *     671 rows   (production's catalogue size)   0.557 ms -> 0.175 ms
 *     10 000 rows                               11.077 ms -> 0.165 ms
 *
 * EXPLAIN goes from `type: ALL` over 671 rows with `Using filesort` to a
 * `Backward index scan` that reads twenty-four. The gap widens with the
 * catalogue for the same reason Lane BH's total_sales index does: a filesort is
 * O(n) in the table and an ordered index walk stops at the LIMIT. At 10 000
 * products it is the difference between eleven milliseconds and a sixth of one,
 * per request, on a shared host.
 *
 * A SINGLE COLUMN, not (status, is_visible, created_at), and for the reason
 * that migration already measured and wrote down: almost every row in this
 * table is publish+visible, so the prefix excludes nothing and only widens each
 * entry, while the ordering is the expensive part and the single column is what
 * serves it.
 *
 * 2. AN INDEX ON reviews (product_id, status).
 *
 * The product page runs two queries and both filter on exactly this pair — the
 * star-distribution summary and the review list. `product_id` is indexed
 * because it is a foreign key and `status` is indexed on its own, so MySQL
 * picked the foreign key's index and then threw away the rows with the wrong
 * status. On 40 000 reviews across 671 products:
 *
 *     summary   86 rows examined at 50% filtered -> 28 rows at 100%
 *     list      0.294 ms -> 0.195 ms, summary 0.244 ms -> 0.210 ms
 *
 * Small in milliseconds today and it does not stay small: rows examined is
 * proportional to a product's TOTAL review count, and this store's reason for
 * existing is that products accumulate reviews. A composite is also what the
 * foreign key needs, since product_id leads it.
 *
 * NOT (product_id, status, created_at), which would additionally serve the
 * list's ORDER BY. Two of the three ordering options on Store → Reviews →
 * Review Settings are not created_at, so that third column would earn its width
 * on one setting out of three, and the summary query — which has no ORDER BY at
 * all — would carry it on every row for nothing.
 *
 * NO ->after(). Nothing here adds a column, and MigrationConventionTest fails
 * any new migration that reaches for it.
 *
 * Both guarded on the COLUMN SET rather than on an index name: a database that
 * already covers these columns under another name does not need a second index,
 * because a redundant index costs every write and buys nothing.
 *
 * 3. THE COMPILED CACHES.
 *
 * app/Providers/AppServiceProvider.php gained model hooks that evict the new
 * shipping-zone cache whenever a zone, a location or a method is written. A
 * service provider is compiled into bootstrap/cache/services.php and
 * packages.php, so on the live host the old provider keeps being loaded, the
 * hooks never register, and the zone cache would then never be evicted at all —
 * which would leave the shipping screen looking broken. That is the precise
 * failure mode every clear_caches_* migration in this directory exists to
 * prevent, and this package must not ship without it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->indexProductCreatedAt();
        $this->indexReviewsProductStatus();
        $this->clearCompiled();
    }

    public function down(): void {}

    private function indexProductCreatedAt(): void
    {
        if (! Schema::hasTable('products') || ! Schema::hasColumn('products', 'created_at')) {
            $this->say('Skipped products.created_at index: column is absent.');

            return;
        }

        if ($this->indexCovers('products', ['created_at'])) {
            $this->say('products.created_at: already indexed, nothing added.');

            return;
        }

        Schema::table('products', function (Blueprint $t): void {
            $t->index('created_at', 'products_created_at_index');
        });

        $this->say('Indexed products (created_at) as products_created_at_index.');
    }

    private function indexReviewsProductStatus(): void
    {
        if (! Schema::hasTable('reviews')
            || ! Schema::hasColumn('reviews', 'product_id')
            || ! Schema::hasColumn('reviews', 'status')) {
            $this->say('Skipped reviews (product_id, status) index: a column is absent.');

            return;
        }

        if ($this->indexCovers('reviews', ['product_id', 'status'])) {
            $this->say('reviews (product_id, status): already indexed, nothing added.');

            return;
        }

        Schema::table('reviews', function (Blueprint $t): void {
            $t->index(['product_id', 'status'], 'reviews_product_id_status_index');
        });

        $this->say('Indexed reviews (product_id, status) as reviews_product_id_status_index.');
    }

    /**
     * Is there ANY index whose LEADING columns are these, in this order?
     *
     * Leading, rather than "contains these columns somewhere": an index whose
     * first column is something else cannot serve an ORDER BY or a lookup on
     * these, so treating it as coverage would skip the index this migration
     * exists to add. An index with EXTRA columns after them is coverage, which
     * is why this is a prefix test and not an equality test.
     *
     * Read back from the driver rather than assumed, because the live MySQL's
     * history is not the test SQLite's.
     *
     * @param  list<string>  $columns
     */
    private function indexCovers(string $table, array $columns): bool
    {
        try {
            if (Schema::getConnection()->getDriverName() === 'sqlite') {
                foreach (DB::select("PRAGMA index_list('{$table}')") as $index) {
                    $names = array_map(
                        static fn ($row) => $row->name,
                        DB::select("PRAGMA index_info('{$index->name}')")
                    );

                    if (array_slice($names, 0, count($columns)) === $columns) {
                        return true;
                    }
                }

                return false;
            }

            $rows = DB::table('information_schema.STATISTICS')
                ->where('TABLE_SCHEMA', DB::getDatabaseName())
                ->where('TABLE_NAME', $table)
                ->where('SEQ_IN_INDEX', '<=', count($columns))
                ->orderBy('INDEX_NAME')
                ->orderBy('SEQ_IN_INDEX')
                ->get(['INDEX_NAME', 'COLUMN_NAME']);

            $byIndex = [];

            foreach ($rows as $row) {
                $byIndex[$row->INDEX_NAME][] = $row->COLUMN_NAME;
            }

            foreach ($byIndex as $names) {
                if ($names === $columns) {
                    return true;
                }
            }

            return false;
        } catch (\Throwable $e) {
            // An unreadable catalogue is not a reason to abort the package. Say
            // so and let the add attempt decide.
            $this->say('Could not read indexes on '.$table.': '.$e->getMessage());

            return false;
        }
    }

    private function clearCompiled(): void
    {
        $cleared = 0;

        foreach ([
            storage_path('framework/views/*.php'),
            base_path('bootstrap/cache/config.php'),
            base_path('bootstrap/cache/routes-*.php'),
            base_path('bootstrap/cache/services.php'),
            base_path('bootstrap/cache/packages.php'),
        ] as $pattern) {
            foreach (glob($pattern) ?: [] as $file) {
                if (is_file($file) && @unlink($file)) {
                    $cleared++;
                }
            }
        }

        Cache::forget('kbb.modules');
        Cache::forget('kbb.settings');
        Cache::forget('kbb.settings.map');

        // New in this package: the whole module_settings table read as one
        // payload, and the shipping zones with their locations and methods. A
        // host mid-upgrade cannot be holding either yet, but a host that is
        // re-applying the package can.
        Cache::forget('kbb.module_settings');
        Cache::forget(\App\Services\ShippingService::ZONES_KEY);

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        $this->say("Cleared {$cleared} compiled files.");
    }

    private function say(string $message): void
    {
        if (app()->runningInConsole()) {
            echo $message."\n";
        }
    }
};
