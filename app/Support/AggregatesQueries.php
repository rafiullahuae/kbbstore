<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Database\Query\Builder as QueryBuilderContract;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Counting and summing a builder that is also used for a page of rows.
 *
 * MySQL in strict mode raises error 1140 —
 *
 *   "In aggregated query without GROUP BY, expression #1 of SELECT list
 *    contains nonaggregated column ...; this is incompatible with
 *    sql_mode=only_full_group_by"
 *
 * — the moment an aggregate is added to a builder that still carries the
 * select list it was going to fetch rows with. An ORDER BY on a column that is
 * not in the aggregate is the same trap, and LIMIT/OFFSET silently truncates
 * the aggregate rather than erroring, which is worse: the number simply comes
 * back wrong. That bug shipped to production twice on this repo.
 *
 * The fix is always the same and always easy to forget, so it lives here
 * rather than being written out at each call site: clone the builder, strip
 * the parts that belong to the row query, then aggregate.
 *
 * SQLite does not raise 1140, so a call site that gets this wrong passes the
 * test suite and fails on the server. That is the whole reason this is a
 * shared helper with its own tests.
 */
trait AggregatesQueries
{
    /** Total matching rows, ignoring any paging already applied to $query. */
    protected function aggregateCount(EloquentBuilder|QueryBuilder $query, string $column = '*'): int
    {
        return (int) $this->forAggregate($query)->count($column);
    }

    /** Sum of $column across every matching row, ignoring paging. */
    protected function aggregateSum(EloquentBuilder|QueryBuilder $query, string $column): int
    {
        return (int) $this->forAggregate($query)->sum($column);
    }

    /**
     * A copy of $query safe to aggregate: no select list, no ordering, no
     * limit or offset. The original is left untouched so the caller can still
     * paginate it.
     */
    protected function forAggregate(EloquentBuilder|QueryBuilder $query): EloquentBuilder|QueryBuilder
    {
        $clone = clone $query;

        $base = $clone instanceof EloquentBuilder ? $clone->getQuery() : $clone;

        $this->stripRowParts($base);

        return $clone;
    }

    private function stripRowParts(QueryBuilderContract|QueryBuilder $base): void
    {
        // Named individually rather than via a loop over a property list: each
        // of these has bitten this codebase, and a reader should see exactly
        // which ones are cleared.
        $base->columns = null;
        $base->orders = null;
        $base->limit = null;
        $base->offset = null;

        // A UNION carries its own ordering and paging, and those are applied
        // after the aggregate, so they have to go too.
        $base->unionOrders = null;
        $base->unionLimit = null;
        $base->unionOffset = null;
    }
}
