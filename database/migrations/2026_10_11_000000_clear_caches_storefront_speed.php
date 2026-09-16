<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lane BH — storefront speed. An index, and the cache clear the code change needs.
 *
 * TWO THINGS, BOTH REQUIRED BY THE SAME PACKAGE.
 *
 * 1. AN INDEX ON products.total_sales.
 *
 * Three of the homepage's four product rails and the /shop "popularity" sort all
 * end in ORDER BY total_sales DESC, and nothing indexed that column, so MySQL
 * sorted the whole visible catalogue on every rebuild to return four rows.
 * Measured on MySQL 8.0 against this repo's schema, the best-sellers rail:
 *
 *     671 rows   (production's catalogue size)   0.496 ms -> 0.076 ms
 *     3 000 rows                                 1.728 ms -> 0.104 ms
 *     10 000 rows                               ~12    ms -> 0.08  ms
 *
 * EXPLAIN goes from "Using where; Using filesort" over 4 833 rows to a backward
 * index scan that reads eight. The win is real at today's size and grows with
 * the catalogue, because a filesort is O(n) in the table while an ordered index
 * walk stops at the LIMIT.
 *
 * A single-column index, not (status, is_visible, total_sales), and that was
 * measured rather than assumed: the composite was SLOWER at production scale
 * (0.174 ms against 0.076 ms). Nearly every row in the table is publish+visible,
 * so the prefix excludes almost nothing and only makes each index entry wider.
 * Ordering is the expensive part, and the single column is what serves it.
 *
 * NOT unique — total_sales is a counter, ties are the normal case.
 *
 * NO ->after(). Nothing here adds a column, so there is nothing to position, and
 * MigrationConventionTest fails any new migration that reaches for it. See
 * 2026_09_15_020000_repair_order_tables for what that clause cost this project.
 *
 * Guarded on the COLUMN SET rather than on the index name. A database that
 * already carries an index over total_sales under some other name does not need
 * a second one: redundant indexes cost every write and buy nothing, which is the
 * mistake 2026_09_22_000000_add_import_external_ids documents at length.
 *
 * 2. THE COMPILED CACHES.
 *
 * app/Providers/AppServiceProvider.php gained model hooks that evict the
 * homepage and /shop fragments on a catalogue write. A service provider is
 * compiled into bootstrap/cache/services.php and packages.php, so on the live
 * host the old provider keeps being loaded and the new hooks never run — the
 * exact failure mode every clear_caches_* migration in this directory exists to
 * prevent.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->indexTotalSales();
        $this->clearCompiled();
    }

    public function down(): void {}

    private function indexTotalSales(): void
    {
        if (! Schema::hasTable('products') || ! Schema::hasColumn('products', 'total_sales')) {
            $this->say('Skipped products.total_sales index: column is absent.');

            return;
        }

        if ($this->hasIndexOn('products', 'total_sales')) {
            $this->say('products.total_sales: already indexed, nothing added.');

            return;
        }

        Schema::table('products', function (Blueprint $t): void {
            $t->index('total_sales', 'products_total_sales_index');
        });

        $this->say('Indexed products (total_sales) as products_total_sales_index.');
    }

    /**
     * Is there ANY index whose first column is this one?
     *
     * First column, not merely "appears somewhere in an index": a composite
     * whose leading column is something else cannot serve ORDER BY total_sales,
     * so treating it as coverage would skip the index this migration exists to
     * add. Read back from the driver rather than assumed, because the live
     * MySQL's history is not the test SQLite's.
     */
    private function hasIndexOn(string $table, string $column): bool
    {
        try {
            $driver = Schema::getConnection()->getDriverName();

            if ($driver === 'sqlite') {
                foreach (DB::select("PRAGMA index_list('{$table}')") as $index) {
                    $info = DB::select("PRAGMA index_info('{$index->name}')");

                    if ($info !== [] && $info[0]->name === $column) {
                        return true;
                    }
                }

                return false;
            }

            return DB::table('information_schema.STATISTICS')
                ->where('TABLE_SCHEMA', DB::getDatabaseName())
                ->where('TABLE_NAME', $table)
                ->where('COLUMN_NAME', $column)
                ->where('SEQ_IN_INDEX', 1)
                ->exists();
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

        // The fragments the new hooks own. Their shape has not changed, but a
        // host mid-upgrade may be holding entries written by the old code.
        \App\Http\Controllers\Store\HomeController::flushCache();
        \App\Http\Controllers\Store\ShopController::flushSidebarCache();

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
