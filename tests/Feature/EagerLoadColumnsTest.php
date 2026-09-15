<?php

declare(strict_types=1);

/*
 * SQLITE RESOLVES A DOUBLE-QUOTED IDENTIFIER THAT MATCHES NO COLUMN AS A STRING
 * LITERAL. MYSQL RAISES 1054.
 *
 * This was found the hard way, while fixing something else. An eager load was
 * written as
 *
 *     Order::with('customer:id,name,email,phone,emirate,default_address')
 *
 * and `customers` has neither `emirate` nor `default_address`. On SQLite that
 * compiles to
 *
 *     select "id", "name", "email", "phone", "emirate", "default_address" ...
 *
 * and comes back 200 with a result column literally named `"emirate"` holding
 * the six-letter word "emirate" — SQLite's documented fallback for
 * MySQL-compatibility, a quoted name that resolves to nothing becomes a string.
 * Laravel's MySQL grammar quotes with backticks, so the same statement is
 * `select `emirate` ...` and the live server answers
 * SQLSTATE[42S22] 1054 Unknown column 'emirate' in 'field list'. A 500 on the
 * Orders screen that the whole SQLite suite calls green.
 *
 * It is the same failure mode as every landmine in CLAUDE.md: SQLite is the
 * more permissive engine, so it invents an answer where MySQL refuses.
 * SqlShape cannot catch this one from the text — Laravel quotes a real column
 * and a non-existent one identically, so there is nothing in the statement to
 * tell them apart.
 *
 * What CAN tell them apart is the schema. A constrained eager load spells its
 * columns out as a literal string in the source, so every one of them can be
 * read out of the file and checked against the table it will be run against.
 * That is what this does.
 *
 * It also catches the cheaper version of the same mistake — a column renamed in
 * a migration while an eager load somewhere still names the old one.
 */

use Illuminate\Support\Facades\Schema;

/**
 * Relation name => the table it loads from.
 *
 * Resolved by hand rather than by reflecting the model, because a relation may
 * be defined on any of a dozen models and the name alone does not say which.
 * Anything not listed here is reported as unmapped rather than skipped, so a
 * new relation cannot slip past by being unknown.
 */
function eagerLoadTables(): array
{
    return [
        'brand' => 'brands',
        'brands' => 'brands',
        'product' => 'products',
        'products' => 'products',
        'category' => 'categories',
        'categories' => 'categories',
        'customer' => 'customers',
        'items' => 'order_items',
        'order' => 'orders',
        'orders' => 'orders',
        'variants' => 'product_variants',
        'variant' => 'product_variants',
        'coupon' => 'coupons',
        'attributeValues' => 'attribute_values',
        'notes' => 'order_notes',
        'refunds' => 'refunds',
        'payments' => 'payments',
    ];
}

/**
 * Every `with('relation:a,b,c')` in app/, as [file, line, relation, columns].
 *
 * @return list<array{file: string, line: int, relation: string, columns: list<string>}>
 */
function constrainedEagerLoads(): array
{
    $found = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS)
    );

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());

        /*
         * Anchored on the eager-load call, not on the string.
         *
         * 'relation:a,b' is not a distinctive shape on its own — Laravel
         * validation rules are full of 'min:1', 'in:a,b' and 'max:255', and a
         * walk that matched those reported two hundred imaginary relations and
         * said nothing useful about the real ones. Only the argument list of
         * with()/load()/loadMissing() is read.
         */
        if (preg_match_all(
            '/(?:->|::)(?:with|load|loadMissing)\(\s*(\[[^\]]*\]|\'[^\']*\'|"[^"]*")/s',
            $source,
            $calls,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        ) === 0) {
            continue;
        }

        foreach ($calls as $call) {
            [$argument, $offset] = $call[1];

            if (preg_match_all("/'([A-Za-z_][A-Za-z0-9_.]*):([A-Za-z0-9_,]+)'/", $argument, $matches, PREG_SET_ORDER) === 0) {
                continue;
            }

            $line = substr_count(substr($source, 0, $offset), "\n") + 1;

            foreach ($matches as $match) {
                $found[] = [
                    'file' => str_replace(base_path() . '/', '', $file->getPathname()),
                    'line' => $line,
                    'relation' => $match[1],
                    'columns' => array_values(array_filter(explode(',', $match[2]))),
                ];
            }
        }
    }

    return $found;
}

it('finds the constrained eager loads it is meant to be checking', function () {
    // A walk that matched nothing would pass silently, which is the failure
    // shape this whole lane exists to remove.
    expect(count(constrainedEagerLoads()))->toBeGreaterThan(15);
});

it('names only columns that exist in every constrained eager load', function () {
    $tables = eagerLoadTables();

    $unmapped = [];
    $missing = [];

    foreach (constrainedEagerLoads() as $load) {
        // A nested path loads from the last segment's relation.
        $segments = explode('.', $load['relation']);
        $relation = end($segments);

        if (! isset($tables[$relation])) {
            $unmapped[] = "{$load['file']}:{$load['line']} loads '{$relation}' and this test does not know which table that is";

            continue;
        }

        $table = $tables[$relation];

        if (! Schema::hasTable($table)) {
            $unmapped[] = "{$load['file']}:{$load['line']} loads from '{$table}', which does not exist";

            continue;
        }

        $columns = Schema::getColumnListing($table);

        foreach ($load['columns'] as $column) {
            if (! in_array($column, $columns, true)) {
                $missing[] = "{$load['file']}:{$load['line']} selects {$table}.{$column}, which is not a column on that table"
                    . ' (SQLite returns the word "' . $column . '"; MySQL raises 1054)';
            }
        }
    }

    expect($unmapped)->toBe([], implode("\n", $unmapped));
    expect($missing)->toBe([], implode("\n", $missing));
});

/**
 * And the engine behaviour itself, pinned, so the reason above is not folklore.
 *
 * On SQLite this asserts the misfeature is real. On MySQL it asserts the same
 * statement is refused. Either way the suite records what the two engines
 * actually do with a quoted name that matches nothing.
 */
it('pins what each engine does with a quoted name that matches no column', function () {
    $driver = Illuminate\Support\Facades\DB::connection()->getDriverName();

    if ($driver === 'sqlite') {
        $row = Illuminate\Support\Facades\DB::selectOne('select "no_such_column_anywhere" as v from "customers" limit 1')
            ?? (object) ['v' => null];

        // Nothing in the table, so nothing comes back; insert one row to force
        // the expression to be evaluated.
        App\Models\Customer::create(['name' => 'Quoted', 'email' => 'quoted-' . uniqid() . '@example.test']);

        $row = Illuminate\Support\Facades\DB::selectOne('select "no_such_column_anywhere" as v from "customers" limit 1');

        expect($row->v)->toBe('no_such_column_anywhere',
            'SQLite stopped treating an unresolvable quoted identifier as a string literal — if so, this whole test can go');

        return;
    }

    App\Models\Customer::create(['name' => 'Quoted', 'email' => 'quoted-' . uniqid() . '@example.test']);

    expect(fn () => Illuminate\Support\Facades\DB::selectOne('select `no_such_column_anywhere` as v from `customers` limit 1'))
        ->toThrow(Illuminate\Database\QueryException::class);
});
