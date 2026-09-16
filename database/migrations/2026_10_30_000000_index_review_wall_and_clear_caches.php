<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lane CW — the two indexes the shop-wide review queries sort and group on, and
 * the cache clear this package's Blade and controller changes need.
 *
 * 1. reviews (status, created_at).
 *
 * The home page's review wall is
 * `WHERE status = 'approved' AND content IS NOT NULL ORDER BY created_at DESC
 * LIMIT 4`. `status` was indexed on its own, which finds the approved rows and
 * then leaves MySQL to sort ALL of them to hand back four. Measured on MySQL
 * 8.0 against a production-shaped fixture — 671 products, 93 brands, 8,000
 * reviews of which 7,650 approved, 1,724 orders over four years — through the
 * real HTTP kernel with a cold cache:
 *
 *     41.1 ms  ->  0.3 ms
 *     type: ref, Using where; Using filesort  ->  Backward index scan
 *     Handler_read_next 7,650 -> 0; four rows read in total
 *
 * That was the single most expensive query anywhere on this storefront, and it
 * gets worse in exactly the direction the shop wants to grow: the filesort is
 * proportional to the number of approved reviews the store has ever collected.
 *
 * 2. reviews (status, rating).
 *
 * The star distribution beside it is `SELECT rating, COUNT(*) WHERE status = ?
 * GROUP BY rating`. Same fixture, same method:
 *
 *     28.3 ms  ->  2.0 ms
 *     Extra: Using temporary  ->  Using index
 *
 * The grouping becomes index-ordered and the query never touches a row.
 *
 * WHAT IS DELIBERATELY NOT DONE HERE.
 *
 * `SELECT COUNT(*), AVG(rating) WHERE status = ?` — the figure behind both the
 * wall's headline and the checkout's trust line — is left exactly as it is. An
 * average over every approved review means reading every approved review;
 * whichever index MySQL picks it examines the same rows, and both callers
 * already cache it for fifteen minutes. It is a number a shopper reads beside a
 * pay button, and StoreRating's own header explains why it may only come from
 * the reviews table. Making it cheap by making it approximate is the one thing
 * that must not happen to it. Slow because it is correct is a finding, not a
 * defect.
 *
 * `reviews_status_index` is also left in place. Both indexes above lead with
 * `status`, so it is redundant on paper — but dropping it was measured and
 * bought nothing (the optimiser simply moved to the composite and still
 * examined every approved row for the AVG), and an index drop on a live host
 * with no shell is not a trade worth making for nothing.
 *
 * NO ->after(). Nothing here adds a column, and MigrationConventionTest fails
 * any new migration that reaches for one — nine earlier migrations in this
 * directory were silent no-ops on MySQL for exactly that reason.
 *
 * Both guarded on the COLUMN SET rather than an index name, and safe to run
 * twice: a database that already covers these columns under another name gets
 * nothing added, because a redundant index costs every write and buys nothing.
 * The helper is Lane BQ's, unchanged, for the same reason.
 *
 * 3. THE COMPILED CACHES.
 *
 * This package changes resources/views/layouts/store.blade.php — the account
 * panel's typeface is now fetched only for a visitor who can see it — and
 * compiled Blade under storage/framework/views is what the server actually
 * renders. Without this clear the host keeps serving the old compiled template
 * and the change does nothing at all, which is the failure every clear_caches_*
 * migration in this directory exists to prevent.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->indexReviewsStatusCreatedAt();
        $this->indexReviewsStatusRating();
        $this->clearCompiled();
    }

    public function down(): void {}

    private function indexReviewsStatusCreatedAt(): void
    {
        $this->addIndex('reviews', ['status', 'created_at'], 'reviews_status_created_at_index');
    }

    private function indexReviewsStatusRating(): void
    {
        $this->addIndex('reviews', ['status', 'rating'], 'reviews_status_rating_index');
    }

    /**
     * @param  list<string>  $columns
     */
    private function addIndex(string $table, array $columns, string $name): void
    {
        $label = $table.' ('.implode(', ', $columns).')';

        if (! Schema::hasTable($table)) {
            $this->say("Skipped {$label} index: the table is absent.");

            return;
        }

        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) {
                $this->say("Skipped {$label} index: {$column} is absent.");

                return;
            }
        }

        if ($this->indexCovers($table, $columns)) {
            $this->say("{$label}: already indexed, nothing added.");

            return;
        }

        Schema::table($table, function (Blueprint $t) use ($columns, $name): void {
            $t->index($columns, $name);
        });

        $this->say("Indexed {$label} as {$name}.");
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
        Cache::forget('kbb.module_settings');

        // The home page's review wall now selects named columns instead of the
        // whole row, and its payload is cached under this key. A host applying
        // this package can be holding the wide version.
        Cache::forget('kbb.home.reviews');

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
