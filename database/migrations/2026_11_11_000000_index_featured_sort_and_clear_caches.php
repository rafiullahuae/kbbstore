<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lane ES — the index the shop's DEFAULT sort has never had.
 *
 * WHAT WAS MEASURED, AND HOW.
 *
 * `php artisan kbb:page-cost` (App\Console\Commands\PageCost) against MySQL
 * 8.0 with 3,025 products, 6,000 orders, 27,000 order lines and 6,458 reviews.
 * Every figure below is the median of nine runs against a warm buffer pool, and
 * every one was taken twice with the index dropped in between, because the
 * first thing this lane measured was a 3x "win" that turned out to be the
 * buffer pool warming up. See docs/page-cost.md.
 *
 * THE QUERY. Store\ShopController::applyDefaultSort() is the sort a shopper
 * gets when they have chosen nothing, which is /shop and every category
 * archive:
 *
 *     ... WHERE status='publish' AND is_visible=1
 *           AND (published_at IS NULL OR published_at <= ?) AND deleted_at IS NULL
 *         ORDER BY featured DESC, position ASC, name ASC, id ASC
 *         LIMIT 24
 *
 * Nothing indexed that ordering, and `products_status_is_visible_index` does
 * not help: nearly every row in a shop's catalogue is published and visible, so
 * the filter excludes almost nothing and MySQL read all 3,025 rows and sorted
 * them to return twenty-four.
 *
 *     EXPLAIN   type=ref  key=products_status_is_visible_index
 *               rows=3025  Extra: Using where; Using filesort
 *
 *     /shop page 1        7.4 - 8.0 ms  ->  0.24 - 0.29 ms
 *
 * A thirtyfold difference, on the busiest listing on the site, and it grows
 * with the catalogue the way every filesort does: the sort is O(n) in the
 * table while an ordered index walk stops at the LIMIT.
 *
 * TWO INDEXES, BECAUSE THERE ARE TWO ORDER BY CLAUSES, and which one a store
 * runs is an owner's switch rather than a constant. applyDefaultSort() consults
 * `products.position` only when the `product_sorting` module is on:
 *
 *     module on   featured DESC, position ASC, name ASC, id ASC
 *     module off  featured DESC,               name ASC, id ASC
 *
 * `position` sits in the MIDDLE, so neither ordering is a prefix of the other
 * and one index cannot serve both. Measured separately, with each index dropped
 * and re-created twice:
 *
 *     module on   7.4 - 8.0 ms  ->  0.24 - 0.29 ms
 *     module off  5.8 - 6.8 ms  ->  0.25 - 0.28 ms
 *
 * The migration that turns `product_sorting` on for existing installs
 * (2026_09_17_000000_align_seo_and_sorting_module_toggles) means the live shop
 * runs the first shape today — and Store → Modules can turn it off this
 * afternoon, at which point the page is 6 ms again with only one index present.
 * A page that is fast until somebody flips a documented switch is not fixed.
 *
 * WHAT TWO MORE INDEXES ON `products` COST, measured rather than feared:
 * 0.156 MB and 0.141 MB at 3,025 rows (mysql.innodb_index_stats). `name` is a
 * varchar(255) and InnoDB stores the bytes a row actually has, not the maximum,
 * so these are the same order of size as the existing single-column indexes on
 * this table. `products` is written by imports and by the product editor, not by
 * shoppers, so the write side is a bulk operation that happens rarely against a
 * read side that happens on every page view.
 *
 * DESCENDING INDEX COLUMNS, hence raw DDL. Blueprint::index() cannot express a
 * per-column direction, and `featured DESC` is the whole point: MySQL 8 will
 * only skip the filesort if the index runs in the direction the ORDER BY asks
 * for. MySQL 8.0 and SQLite 3.8.3+ both accept the same statement, backticks
 * included, so one string serves the server and the suite.
 *
 * `id ASC` is named explicitly although InnoDB appends the primary key to every
 * secondary index anyway. Spelling it out costs nothing, and it means the index
 * definition and the ORDER BY it exists for can be compared line by line by
 * whoever reads this next.
 *
 * WHAT THIS DELIBERATELY DOES NOT INDEX. A deep page — /shop?paged=100 — is not
 * helped (9.5 ms before, 9.4 ms after): an ordered walk still has to pass every
 * entry it skips, so MySQL goes back to the filesort and is right to. And no
 * index was added to `order_items`, although the admin lists' derived tables
 * aggregate the whole of it. A covering (order_id, product_id, quantity, total)
 * appeared to halve that work on first measurement and then, re-measured
 * against a warm pool twice in each direction, bought 7.3 ms against 7.7 ms —
 * nothing. It is not here. docs/page-cost.md records both measurements.
 *
 * Guarded on the COLUMN SET rather than on the index name, and safe to run
 * twice: a database that already covers these columns under another name gets
 * nothing added, because a redundant index costs every write and buys nothing.
 *
 * REVERSIBLE, which the three index migrations before this one are not. They
 * carry an empty down(). An index is the one schema change that can be undone
 * with no data consequence whatsoever, so there is no reason for down() to be a
 * stub, and `migrate:rollback` on a package that should not have shipped is
 * exactly when somebody needs it to work.
 *
 * NO ROUTE IS ADDED, so no compiled ROUTE cache has to be cleared for this to
 * take effect. The config and view caches are cleared anyway, for the reason
 * every clear_caches_* migration in this directory gives: the same package
 * carries changes to App\Http\Controllers\Admin\OrdersApiController and
 * CatalogProductsApiController, and a compiled cache on the live host is how a
 * shipped change comes to be loaded from yesterday's copy.
 */
return new class extends Migration
{
    /** @var array<string, array<string, string>> name => [column => direction] */
    private const INDEXES = [
        'products_featured_position_name_index' => [
            'featured' => 'DESC',
            'position' => 'ASC',
            'name' => 'ASC',
            'id' => 'ASC',
        ],
        'products_featured_name_index' => [
            'featured' => 'DESC',
            'name' => 'ASC',
            'id' => 'ASC',
        ],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $name => $columns) {
            $this->addIndex('products', $name, $columns);
        }

        $this->clearCompiled();
    }

    public function down(): void
    {
        foreach (array_keys(self::INDEXES) as $name) {
            if (! $this->indexNamed('products', $name)) {
                continue;
            }

            // Not Schema::table()->dropIndex(): on SQLite that path wants to
            // know the table's whole definition, and DROP INDEX is one
            // statement both engines understand by name alone.
            DB::statement('DROP INDEX '.$this->quotedIndex('products', $name));
        }

        $this->clearCompiled();
    }

    /** @param array<string, string> $columns */
    private function addIndex(string $table, string $name, array $columns): void
    {
        $label = $table.' ('.implode(', ', array_keys($columns)).')';

        if (! Schema::hasTable($table)) {
            $this->say("Skipped {$label} index: the table is absent.");

            return;
        }

        foreach (array_keys($columns) as $column) {
            if (! Schema::hasColumn($table, $column)) {
                $this->say("Skipped {$label} index: {$column} is absent.");

                return;
            }
        }

        if ($this->indexCovers($table, array_keys($columns))) {
            $this->say("{$label}: already indexed, nothing added.");

            return;
        }

        $spec = [];

        foreach ($columns as $column => $direction) {
            $spec[] = '`'.$column.'` '.$direction;
        }

        DB::statement('CREATE INDEX `'.$name.'` ON `'.$table.'` ('.implode(', ', $spec).')');

        $this->say("Indexed {$label} as {$name}.");
    }

    /**
     * Is there ANY index whose LEADING columns are these, in this order?
     *
     * Leading, rather than "contains these columns somewhere": an index whose
     * first column is something else cannot serve this ORDER BY, so treating it
     * as coverage would skip the index this migration exists to add. An index
     * with EXTRA columns after them IS coverage, which is why this is a prefix
     * test rather than an equality test — and it is also why the two indexes
     * here are added longest-first, so the four-column one cannot be mistaken
     * for coverage of the three-column one. (It is not: `position` sits second
     * in one and `name` second in the other, so neither is a prefix of the
     * other. The ordering of the list is belt and braces.)
     *
     * Read back from the driver rather than assumed, because the live MySQL's
     * history is not the test SQLite's.
     *
     * @param  list<string>  $columns
     */
    private function indexCovers(string $table, array $columns): bool
    {
        try {
            foreach ($this->indexColumns($table) as $names) {
                if (array_slice($names, 0, count($columns)) === $columns) {
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

    /** @return array<string, list<string>> index name => its columns, in order */
    private function indexColumns(string $table): array
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            $out = [];

            foreach (DB::select("PRAGMA index_list('".$table."')") as $index) {
                $out[(string) $index->name] = array_map(
                    static fn ($row) => (string) $row->name,
                    DB::select("PRAGMA index_info('".$index->name."')")
                );
            }

            return $out;
        }

        $rows = DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->orderBy('INDEX_NAME')
            ->orderBy('SEQ_IN_INDEX')
            ->get(['INDEX_NAME', 'COLUMN_NAME']);

        $out = [];

        foreach ($rows as $row) {
            $out[(string) $row->INDEX_NAME][] = (string) $row->COLUMN_NAME;
        }

        return $out;
    }

    private function indexNamed(string $table, string $name): bool
    {
        if (! Schema::hasTable($table)) {
            return false;
        }

        try {
            return array_key_exists($name, $this->indexColumns($table));
        } catch (\Throwable) {
            return false;
        }
    }

    /** MySQL names the table, SQLite does not. */
    private function quotedIndex(string $table, string $name): string
    {
        return Schema::getConnection()->getDriverName() === 'sqlite'
            ? '`'.$name.'`'
            : '`'.$name.'` ON `'.$table.'`';
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

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        $this->say("Cleared {$cleared} compiled files.");
    }

    private function say(string $message): void
    {
        if (isset($this->output) && $this->output !== null) {
            $this->output->writeln('  '.$message);
        }
    }
};
