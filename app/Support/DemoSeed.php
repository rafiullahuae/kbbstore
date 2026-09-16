<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which rows are invented, answered from the table that already knows.
 *
 * THE PROBLEM. Store -> Demo Content seeds sample orders, customers and
 * products so the panel can be explored before real data exists. It records
 * every row it creates in `demo_seed_log` (type, model, record_id) so Remove
 * can delete exactly those rows. But nothing on `orders` marked a row as demo,
 * and no reporting query knew the log existed — so switching Demo Content on
 * added eight invented orders to the Dashboard's revenue, the Analytics
 * screen's totals and the Customers screen's lifetime spend, switching it off
 * took them away again, and no figure anywhere said it included invented money.
 *
 * WHY NO COLUMN ON `orders`. A boolean `is_demo` on `orders` was the obvious
 * shape and it is the wrong one here. `demo_seed_log` already carries exactly
 * this fact, per row, by model and id — it is the authority Remove itself
 * trusts to decide what to delete — so a column would be a SECOND answer to a
 * question that already has one, and two answers drift. It would also mean a
 * migration adding a column to a live `orders` table on shared hosting for a
 * fact that is already recorded. The join costs one correlated subquery against
 * a table that holds a few dozen rows and is indexed on (model, record_id).
 *
 * NOTHING IS ORPHANED WHEN DEMO CONTENT IS REMOVED. Because the exclusion is
 * derived rather than stored, removing demo content deletes the orders and
 * their log rows together and the figures simply go back to describing the same
 * real rows they described before. There is no flag left pointing at a row that
 * no longer exists, and no row left flagged after the log that flagged it is
 * gone.
 *
 * EXCLUDED, BUT SAID OUT LOUD. Reporting excludes demo rows so the owner's
 * money figures are real money. It also reports how many were excluded, because
 * an owner who seeded demo data deliberately in order to look at it is owed an
 * explanation for why the dashboard did not move — silently subtracting is its
 * own kind of lie. See counts().
 *
 * THE TABLE MAY NOT EXIST. DemoContentController::ensureTable() creates it
 * lazily for exactly the reason given there, so a build whose migration step
 * was skipped has no such table until Demo Content is first opened. Every entry
 * point here degrades to "nothing is demo", which is the truthful answer when
 * nothing has ever been seeded.
 */
final class DemoSeed
{
    public const TABLE = 'demo_seed_log';

    /**
     * Memoised in the CONTAINER, not in a process-level static.
     *
     * `Schema::hasTable()` is a real statement on both engines and this is
     * asked several times per reporting request. A static would be the exact
     * trap CLAUDE.md records against Setting::map(): a long-lived worker, or a
     * test suite, would hold one answer across a schema change. The container
     * is rebuilt per request and per test, so the memo lasts exactly as long as
     * the answer can be relied on.
     */
    private const MEMO_KEY = 'kbb.demo_seed_log.exists';

    public static function tableExists(): bool
    {
        $app = app();

        if (! $app->bound(self::MEMO_KEY)) {
            $app->instance(self::MEMO_KEY, Schema::hasTable(self::TABLE));
        }

        return (bool) $app->make(self::MEMO_KEY);
    }

    /**
     * Restrict a query to rows this store's customers really created.
     *
     * whereNotExists and not whereNotIn: `NOT IN (subquery)` yields NO ROWS AT
     * ALL the moment the subquery produces a single NULL, and it is the shape
     * that has to be reasoned about rather than read. The correlated EXISTS
     * says what it means, uses the (model, record_id) index, and is the same
     * statement on both engines.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  class-string<Model>  $model  the class DemoContentController logged
     * @param  string|null  $table  qualify the id column when the query joins
     *                              (OrderItem joins `orders`, so it must say
     *                              `orders.id` or the reference is ambiguous)
     * @return Builder<TModel>
     */
    public static function exclude(Builder $query, string $model, ?string $table = null): Builder
    {
        if (! self::tableExists()) {
            return $query;
        }

        $idColumn = ($table ?? $query->getModel()->getTable()) . '.' . $query->getModel()->getKeyName();

        return $query->whereNotExists(function ($sub) use ($model, $idColumn) {
            $sub->select(DB::raw(1))
                ->from(self::TABLE)
                ->whereColumn(self::TABLE . '.record_id', $idColumn)
                ->where(self::TABLE . '.model', $model);
        });
    }

    /**
     * The same restriction on a PLAIN query builder, which has no model to
     * infer a table from.
     *
     * countedRefunds() is built from the `refunds` table joined to `orders`, so
     * there is no Eloquent model in the chain and the id column has to be named
     * outright. Netting real refunds out of a gross figure that excluded demo
     * orders - or the reverse - would give a number that is neither, so the two
     * sides use the same definition of "demo".
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  class-string<Model>  $model
     * @param  string  $idColumn  qualified, e.g. 'orders.id'
     * @return \Illuminate\Database\Query\Builder
     */
    public static function excludeQuery(
        \Illuminate\Database\Query\Builder $query,
        string $model,
        string $idColumn,
    ): \Illuminate\Database\Query\Builder {
        if (! self::tableExists()) {
            return $query;
        }

        return $query->whereNotExists(function ($sub) use ($model, $idColumn) {
            $sub->select(DB::raw(1))
                ->from(self::TABLE)
                ->whereColumn(self::TABLE . '.record_id', $idColumn)
                ->where(self::TABLE . '.model', $model);
        });
    }

    /**
     * The ids of one model's demo rows, as a SET (id => true).
     *
     * For list screens, which show demo rows and badge them rather than hiding
     * them. Keyed rather than a list because the caller is testing membership
     * once per row and `in_array()` over a few dozen ids per row is the shape
     * that turns into a visible cost on a long Orders table.
     *
     * @param  class-string<Model>  $model
     * @return array<int, true>
     */
    public static function idsFor(string $model): array
    {
        if (! self::tableExists()) {
            return [];
        }

        return DB::table(self::TABLE)
            ->where('model', $model)
            ->pluck('record_id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();
    }

    /**
     * How many rows of each model the demo seeder created, in ONE statement.
     *
     * This is the disclosure half. A screen that shows real figures and says
     * nothing about the invented rows it left out is only half-honest: an owner
     * who just clicked "Import demo orders" and saw the dashboard not move
     * would reasonably conclude the import failed. The keys are the short names
     * a screen would print — `orders`, `customers`, `products` — not class
     * names.
     *
     * @return array<string, int>
     */
    public static function counts(): array
    {
        $out = ['orders' => 0, 'customers' => 0, 'products' => 0];

        if (! self::tableExists()) {
            return $out;
        }

        $map = [
            \App\Models\Order::class => 'orders',
            \App\Models\Customer::class => 'customers',
            \App\Models\Product::class => 'products',
        ];

        /*
         * Grouped in the database rather than three COUNT(*) round trips, and
         * `model` is both selected and grouped — a bare column beside an
         * aggregate is the MySQL 1140 that took the Customers screen down, and
         * ONLY_FULL_GROUP_BY is on in the MySQL half of the suite.
         */
        $rows = DB::table(self::TABLE)
            ->whereIn('model', array_keys($map))
            ->groupBy('model')
            ->selectRaw('model, COUNT(*) as n')
            ->get();

        foreach ($rows as $row) {
            $key = $map[(string) $row->model] ?? null;

            if ($key !== null) {
                $out[$key] = (int) $row->n;
            }
        }

        return $out;
    }

    /**
     * The disclosure block a reporting endpoint hands its screen.
     *
     * `excluded` is true whenever anything was left out, so a screen's
     * condition is one field rather than a sum of three.
     *
     * @return array{excluded: bool, orders: int, customers: int, products: int}
     */
    public static function disclosure(): array
    {
        $counts = self::counts();

        return [
            'excluded' => array_sum($counts) > 0,
            'orders' => $counts['orders'],
            'customers' => $counts['customers'],
            'products' => $counts['products'],
        ];
    }
}
