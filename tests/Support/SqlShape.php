<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * Dialect guard: judge the SQL a request ISSUES, not the answer it returns.
 *
 * The suite runs on SQLite and production runs MySQL. SQLite is the more
 * permissive of the two, so a statement MySQL rejects outright comes back 200
 * here and the test passes. That gap has cost this store two outages — the
 * Customers screen (SQLSTATE 1140, aggregate mixed with bare columns) and the
 * checkout column chain (ALTER ... AFTER a column that did not exist).
 *
 * A test that asserts the SHAPE of the statement closes the gap without needing
 * a MySQL to run against, so it works in CI, on a laptop, and in the SQLite
 * suite every lane already runs. phpunit-mysql.xml runs the same suite against
 * a real server; this is the half that still works when there isn't one.
 *
 * Usage:
 *
 *     $sql = SqlShape::capture(fn () => $this->get('/shop')->assertOk());
 *
 *     expect(SqlShape::violations($sql))->toBe([]);
 *
 * Every rule below is a statement MySQL rejects or silently answers
 * differently. None of them fire on portable SQL, so a violation is a bug in
 * the query, never a reason to relax the rule.
 */
final class SqlShape
{
    /**
     * Functions and operators SQLite has and MySQL does not (or reads
     * differently). `||` is the sharp one: string concatenation in SQLite,
     * boolean OR in MySQL, so it does not error — it returns 0 or 1 and the
     * page renders with the wrong text in it.
     */
    private const SQLITE_ONLY = [
        'strftime' => 'strftime() is SQLite-only; MySQL has DATE_FORMAT()',
        'julianday' => 'julianday() is SQLite-only',
        'unixepoch' => 'unixepoch() is SQLite-only; MySQL has UNIX_TIMESTAMP()',
        'sqlite_version' => 'sqlite_version() is SQLite-only',
        'sqlite_master' => 'sqlite_master is SQLite-only; MySQL has information_schema',
    ];

    /**
     * Run $fn with a query log attached.
     *
     * @return list<array{sql: string, bindings: array<int, mixed>, schema: bool}>
     */
    public static function capture(callable $fn): array
    {
        $seen = [];

        DB::listen(function ($query) use (&$seen) {
            $seen[] = [
                'sql' => $query->sql,
                'bindings' => $query->bindings,
                'schema' => self::issuedBySchemaBuilder($query->sql),
            ];
        });

        $fn();

        return $seen;
    }

    /**
     * Was this statement written by the framework's own schema grammar?
     *
     * Schema::hasTable() on SQLite reads sqlite_master; on MySQL the SAME call
     * reads information_schema, because the grammar is chosen per driver. So a
     * `sqlite_master` statement that came out of Illuminate\Database\Schema is
     * portable by construction and flagging it is a false positive — one with
     * teeth, because five live admin endpoints call Schema::hasTable() and the
     * guard could not be extended to cover any of them while it fired here.
     *
     * Decided by the call stack rather than by the text, deliberately. Matching
     * on "looks like introspection" would also excuse app code that queries
     * sqlite_master itself, which is exactly the SQLite-only statement this
     * class exists to catch. The stack cannot be imitated by a query the
     * application wrote.
     *
     * The backtrace is only walked for statements that would otherwise be
     * reported, so the ordinary case costs one substring scan.
     */
    private static function issuedBySchemaBuilder(string $sql): bool
    {
        $suspect = false;

        foreach (array_keys(self::SQLITE_ONLY) as $needle) {
            if (stripos($sql, $needle) !== false) {
                $suspect = true;
                break;
            }
        }

        if (! $suspect) {
            return false;
        }

        return self::fromSchemaBuilder();
    }

    /**
     * Is the statement being logged RIGHT NOW one the framework's own schema
     * builder issued?
     *
     * Call it from inside a DB::listen closure: the listener runs on the same
     * stack as the query, so the schema builder's frames are still below it.
     *
     * This is the portable way to tell an introspection statement from a
     * statement the application wrote, and it is portable precisely because it
     * does not read the SQL. Schema::hasColumn() is ONE statement against
     * information_schema on MySQL and TWO pragmas on SQLite, and the text of
     * neither resembles the text of the other. A test that counts "how many
     * queries did this endpoint issue" and hard-codes a number has therefore
     * pinned the driver, not the endpoint -- which is how
     * RoutineTaggingJobTest read 4 on SQLite and 3 against a real server.
     * Counting work statements and probes separately says what was meant.
     */
    public static function fromSchemaBuilder(): bool
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 40) as $frame) {
            $class = $frame['class'] ?? '';

            if (str_starts_with($class, 'Illuminate\\Database\\Schema\\')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every portability problem in a captured log, as readable strings.
     *
     * @param  list<array{sql: string, bindings: array<int, mixed>, schema?: bool}>  $captured
     * @return list<string>
     */
    public static function violations(array $captured): array
    {
        $problems = [];

        foreach ($captured as $entry) {
            foreach (self::inspect($entry['sql'], $entry['bindings'], (bool) ($entry['schema'] ?? false)) as $problem) {
                $problems[] = $problem . "\n    " . self::trim($entry['sql']);
            }
        }

        return array_values(array_unique($problems));
    }

    /**
     * @param  array<int, mixed>  $bindings
     * @return list<string>
     */
    private static function inspect(string $sql, array $bindings, bool $schema = false): array
    {
        $problems = [];

        // Placeholders and bindings must agree. This is the trap in fixing one
        // of the other rules: drop a select column without dropping the binding
        // it carried and the driver gets one value too many.
        if (substr_count($sql, '?') !== count($bindings)) {
            $problems[] = sprintf(
                'binding count (%d) does not match placeholder count (%d)',
                count($bindings),
                substr_count($sql, '?')
            );
        }

        if (! $schema) {
            foreach (self::SQLITE_ONLY as $needle => $why) {
                if (preg_match('/\b' . preg_quote($needle, '/') . '\b/i', $sql) === 1) {
                    $problems[] = $why;
                }
            }
        }

        // `||` between two things is concatenation here and OR on the server.
        if (str_contains($sql, '||')) {
            $problems[] = '`||` concatenates in SQLite and means OR in MySQL';
        }

        $outer = self::outerOnly($sql);

        // A derived table carries its own GROUP BY, and its parentheses are
        // gone by now, so what is left is the outer query's grouping only.
        if (stripos($outer, 'group by') !== false) {
            return array_merge($problems, self::aliasInWhere($sql, $outer));
        }

        $select = self::segment($outer, 'select');

        if (preg_match('/\b(count|sum|min|max|avg|group_concat)\b/i', $select) !== 1) {
            return array_merge($problems, self::aliasInWhere($sql, $outer));
        }

        /*
         * From here the outer query aggregates and has no GROUP BY, so it
         * returns exactly one row and every other thing it mentions has to be
         * an aggregate or a constant.
         */

        // A quoted table.column in the select list is the original 1140.
        if (preg_match('/[`"][a-z_0-9]+[`"]\.[`"][a-z_0-9]+[`"]/i', $select) === 1) {
            $problems[] = 'aggregate mixed with bare columns and no GROUP BY (MySQL: SQLSTATE 42000 / 1140)';
        }

        // ORDER BY raises the same 1140 on MySQL, and ordering a single-row
        // aggregate is meaningless on every driver, so there is never a reason
        // for one to be here.
        if (stripos($outer, ' order by ') !== false) {
            $problems[] = 'aggregate with no GROUP BY still carries an ORDER BY (MySQL: SQLSTATE 42000 / 1140)';
        }

        // OFFSET is worse than a dialect problem: an aggregate returns one row,
        // so skipping any rows returns none and the caller reads zero. This is
        // how a paginated screen shows correct totals on page one and zeros on
        // page two, on SQLite and MySQL alike.
        if (preg_match('/\boffset\s+[1-9]/i', $outer) === 1) {
            $problems[] = 'aggregate with no GROUP BY carries a non-zero OFFSET and will return no row at all';
        }

        return array_merge($problems, self::aliasInWhere($sql, $outer));
    }

    /**
     * A column alias used in WHERE.
     *
     * SQLite resolves `select x + 1 as total ... where total > 5`. MySQL does
     * not: aliases are visible to GROUP BY, HAVING and ORDER BY, never to
     * WHERE, and the statement fails with "Unknown column 'total' in
     * 'where clause'".
     *
     * Laravel quotes every real column it emits, so an unquoted bare word in
     * the WHERE segment that also appears as an alias is the mistake and not a
     * coincidence.
     *
     * @return list<string>
     */
    private static function aliasInWhere(string $sql, string $outer): array
    {
        $where = self::segment($outer, 'where');

        if ($where === '') {
            return [];
        }

        $select = self::segment($outer, 'select');

        if (preg_match_all('/\bas\s+[`"]?([a-z_][a-z_0-9]*)[`"]?/i', $select, $matches) === 0) {
            return [];
        }

        $problems = [];

        foreach (array_unique($matches[1]) as $alias) {
            // Unquoted, unqualified, and a whole word.
            if (preg_match('/(?<![`"\.])\b' . preg_quote($alias, '/') . '\b(?![`"\.])/i', $where) === 1) {
                $problems[] = sprintf(
                    'select alias `%s` is used in WHERE; MySQL cannot see it there',
                    $alias
                );
            }
        }

        return $problems;
    }

    /**
     * One clause of the outer query, from an already-paren-stripped statement.
     */
    private static function segment(string $outer, string $clause): string
    {
        $starts = [
            'select' => '/^\s*select\s/i',
            'where' => '/\swhere\s/i',
        ];

        $ends = [
            'select' => ['/\sfrom\s/i'],
            'where' => ['/\sgroup\s+by\s/i', '/\sorder\s+by\s/i', '/\slimit\s/i', '/\shaving\s/i'],
        ];

        if (preg_match($starts[$clause], $outer, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return '';
        }

        $from = $m[0][1] + strlen($m[0][0]);
        $to = strlen($outer);

        foreach ($ends[$clause] as $pattern) {
            if (preg_match($pattern, $outer, $e, PREG_OFFSET_CAPTURE, $from) === 1) {
                $to = min($to, $e[0][1]);
            }
        }

        return substr($outer, $from, $to - $from);
    }

    /**
     * Remove every balanced-parenthesis group, leaving outer structure only.
     *
     * Subqueries, derived tables and the arguments of COUNT()/CASE all go, so
     * what remains is the one query whose GROUP BY, ORDER BY and WHERE the
     * server will judge as a unit.
     */
    private static function outerOnly(string $sql): string
    {
        do {
            $before = $sql;
            $sql = preg_replace('/\([^()]*\)/', ' ', $sql) ?? $sql;
        } while ($sql !== $before);

        return $sql;
    }

    private static function trim(string $sql): string
    {
        $sql = (string) preg_replace('/\s+/', ' ', $sql);

        return strlen($sql) > 400 ? substr($sql, 0, 400) . ' ...' : $sql;
    }
}
