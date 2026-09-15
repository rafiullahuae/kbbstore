<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Turn a filtered row query into an aggregate-only query.
 *
 * THIS EXISTS BECAUSE THE SAME BUG SHIPPED TWICE.
 *
 * Laravel's selectRaw() APPENDS to the select list rather than replacing it, so
 * "(clone $rows)->selectRaw('COUNT(*)')" keeps every row column and adds an
 * aggregate beside them with no GROUP BY. And applySort()/forPage() MUTATE the
 * builder they are given, so a summary computed from the same instance the page
 * was built from also inherits its ORDER BY, LIMIT and OFFSET.
 *
 * Each of those breaks differently, and only one of them is loud:
 *
 *   - bare columns beside an aggregate  -> MySQL 1140, SQLite invents a row
 *   - an ORDER BY on a bare column      -> MySQL 1140, SQLite ignores it
 *   - a surviving OFFSET                -> WRONG ON EVERY ENGINE. An aggregate
 *     returns one row; skip 25 and there is none, so every total reads zero
 *     from page two on, silently, while the endpoint still answers 200.
 *
 * The Customers screen hit all three in production. The Orders screen then
 * copied the half-fixed helper and hit them again. Dropping the columns without
 * dropping their bindings is its own trap: the placeholders go and the values
 * stay, and the driver is handed more values than the statement has markers.
 *
 * So there is one implementation, and both screens use it. A third screen that
 * needs a summary uses it too rather than writing a fourth variant.
 *
 * What deliberately survives: the joins, the WHERE and any GROUP BY. The counts
 * describe the filtered set and read the joined derived tables, so removing
 * those would answer a different question.
 */
trait AggregatesQueries
{
    /**
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @param  array<int, mixed>  $bindings
     */
    protected function aggregateQuery(Builder $query, string $expression, array $bindings = []): QueryBuilder
    {
        $base = (clone $query)->toBase();

        // The row columns and the bindings that belong to them.
        $base->columns = null;
        $base->bindings['select'] = [];

        // The ordering, which names bare columns an aggregate may not.
        $base->orders = null;
        $base->bindings['order'] = [];

        // The page window. This one is a correctness bug, not a dialect one.
        $base->limit = null;
        $base->offset = null;

        return $base->selectRaw($expression, $bindings);
    }

    /**
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @param  array<int, mixed>  $bindings
     */
    protected function aggregate(Builder $query, string $expression, array $bindings = []): ?object
    {
        return $this->aggregateQuery($query, $expression, $bindings)->first();
    }
}
