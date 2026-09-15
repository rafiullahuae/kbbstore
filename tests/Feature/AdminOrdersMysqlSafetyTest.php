<?php

/**
 * MySQL would reject these statements; SQLite runs them. So assert the SHAPE.
 *
 * Production is MySQL and the suite is SQLite, which means a whole class of bug
 * is green here and a 500 there. The live Customers screen returned:
 *
 *   SQLSTATE[42000] 1140 Mixing of GROUP columns (MIN(),MAX(),COUNT(),...)
 *   with no GROUP columns is illegal if there is no GROUP BY clause
 *
 * while every test passed. Cause: selectRaw() APPENDS to the select list rather
 * than replacing it, so a count built as (clone $base)->selectRaw('COUNT(*)')
 * keeps every bare column from the row query and adds an aggregate after them
 * with no GROUP BY. SQLite permits that and invents a row for the bare columns;
 * MySQL refuses outright.
 *
 * A test that merely calls the endpoint cannot catch this on SQLite — it comes
 * back 200. These inspect the SQL actually issued instead, which is
 * dialect-independent, and they fail against the pre-fix shape.
 *
 * Copied from AdminCustomersMysqlSafetyTest and pointed at this lane's
 * endpoints, deliberately rather than shared: that file belongs to another lane.
 * Four further MySQL traps this codebase has already paid for are checked below
 * as well — a SELECT alias in WHERE, strftime/julianday, `||` for concat, and a
 * binding count that does not match the placeholders.
 */

use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Refund;
use Illuminate\Support\Facades\DB;
use Tests\Support\OrdersAdminRoutes;

/** Same harness AdminOrdersTest uses: wire the route file, sign in. */
function ordersShapeAdmin(): AdminUser
{
    OrdersAdminRoutes::wire(app());

    return AdminUser::create([
        'name' => 'Shape Owner',
        'email' => 'order-shape-' . uniqid() . '@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
}

/** A couple of rows of every shape the screen has to aggregate over. */
function ordersShapeData(): void
{
    $customer = Customer::create(['email' => 'shape-buyer@kbb.test', 'name' => 'Shape Buyer']);

    foreach ([['completed', 12000], ['cancelled', 4000], ['processing', 8000]] as $i => [$status, $total]) {
        $order = Order::create([
            'customer_id' => $i === 2 ? null : $customer->id,
            'order_number' => 'SHAPE-' . $i . '-' . uniqid(),
            'email' => 'shape-buyer@kbb.test',
            'status' => $status,
            'total' => $total,
            'subtotal' => $total,
            'billing_address' => ['first_name' => 'Shape', 'last_name' => 'Buyer', 'city' => 'Dubai'],
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'name' => 'Shape Item',
            'quantity' => 2,
            'unit_price' => intdiv($total, 2),
            'subtotal' => $total,
            'total' => $total,
        ]);

        Refund::create(['order_id' => $order->id, 'amount' => 100, 'status' => 'succeeded']);
    }
}

/** Remove every balanced-parenthesis group, leaving only outer-query structure. */
function ordersOuterOnly(string $sql): string
{
    do {
        $before = $sql;
        $sql = preg_replace('/\([^()]*\)/', ' ', $sql) ?? $sql;
    } while ($sql !== $before);

    return $sql;
}

/** Every statement the given request issues. @return list<object{sql:string,bindings:array}> */
function ordersSqlFor(callable $request): array
{
    $seen = [];

    DB::listen(function ($q) use (&$seen) {
        $seen[] = (object) ['sql' => $q->sql, 'bindings' => $q->bindings];
    });

    $request();

    return $seen;
}

it('never mixes an aggregate with bare columns outside a GROUP BY', function () {
    ordersShapeData();

    $admin = ordersShapeAdmin();

    $seen = ordersSqlFor(function () use ($admin) {
        test()->actingAs($admin, 'admin')->get('/admin-api/orders-list')->assertOk();
    });

    expect($seen)->not->toBeEmpty();

    foreach ($seen as $statement) {
        $outer = ordersOuterOnly($statement->sql);

        // Derived tables carry their own GROUP BY; those parens are gone now,
        // so this is the OUTER query's grouping only.
        if (stripos($outer, 'group by') !== false) {
            continue;
        }

        $from = stripos($outer, ' from ');
        $select = $from === false ? $outer : substr($outer, 0, $from);

        $hasAggregate = preg_match('/\b(count|sum|min|max|avg)\b/i', $select) === 1;

        if (! $hasAggregate) {
            continue;
        }

        // An aggregate with no GROUP BY: every other selected item must be an
        // aggregate or a constant. A quoted table.column here is the bug.
        expect($select)->not->toMatch('/"[a-z_]+"\."[a-z_]+"/i',
            "aggregate mixed with bare columns and no GROUP BY:\n" . $statement->sql);
    }
});

it('sends exactly as many bindings as each statement has placeholders', function () {
    ordersShapeData();

    $admin = ordersShapeAdmin();

    // Dropping the select columns without dropping their bindings leaves the
    // driver more values than markers — the trap inside the fix itself. Every
    // filter is on at once so the WHERE, the aggregates and the sort all
    // contribute bindings to the same statements.
    $seen = ordersSqlFor(function () use ($admin) {
        test()->actingAs($admin, 'admin')
            ->get('/admin-api/orders-list?search=Shape&filter=paid&sort=customer'
                . '&from=2019-01-01&to=2030-01-01&total_min=1&total_max=100000')
            ->assertOk();
    });

    $mismatch = [];

    foreach ($seen as $statement) {
        if (substr_count($statement->sql, '?') !== count($statement->bindings)) {
            $mismatch[] = $statement->sql;
        }
    }

    expect($mismatch)->toBe([]);
});

it('never puts a SELECT alias in a WHERE clause', function () {
    ordersShapeData();

    $admin = ordersShapeAdmin();

    $seen = ordersSqlFor(function () use ($admin) {
        test()->actingAs($admin, 'admin')
            ->get('/admin-api/orders-list?total_min=1&total_max=100000&search=Shape')
            ->assertOk();
    });

    // MySQL does not resolve a select alias in WHERE; SQLite does. Every alias
    // this controller creates is listed here, so filtering on one instead of on
    // the underlying column fails the test rather than the live site.
    $aliases = ['units', 'lines_count', 'refunded_fils', 'account_name', 'account_first_name', 'account_last_name'];

    foreach ($seen as $statement) {
        $outer = ordersOuterOnly($statement->sql);

        $where = stripos($outer, ' where ');

        if ($where === false) {
            continue;
        }

        $clause = substr($outer, $where);

        foreach ($aliases as $alias) {
            expect($clause)->not->toContain('"' . $alias . '"',
                'the alias ' . $alias . ' is used in a WHERE clause, which MySQL will not resolve:' . "\n" . $statement->sql);
        }
    }
});

it('uses no SQLite-only function and no double-pipe concatenation', function () {
    ordersShapeData();

    $admin = ordersShapeAdmin();

    $seen = ordersSqlFor(function () use ($admin) {
        test()->actingAs($admin, 'admin')
            ->get('/admin-api/orders-list?from=2019-01-01&to=2030-01-01&sort=customer&search=Shape')
            ->assertOk();

        test()->actingAs($admin, 'admin')->get('/admin-api/orders-export')->streamedContent();
    });

    foreach ($seen as $statement) {
        // julianday() does not exist on MySQL, and `||` is string concatenation
        // on SQLite but logical OR on MySQL — the worst kind of difference,
        // because it does not error, it silently returns 0. Neither is ever
        // emitted by Laravel's grammars, so either one in the issued SQL came
        // from a raw expression in this controller.
        expect($statement->sql)->not->toContain('julianday')
            ->and($statement->sql)->not->toContain('||');
    }

    // strftime IS emitted above — by SQLiteGrammar for whereDate(), which the
    // MySQL grammar compiles as date() instead. That is the framework choosing
    // per dialect and is correct, so the issued SQL cannot be asserted on. What
    // must not happen is this controller WRITING strftime into a raw expression,
    // where no grammar can translate it; that is a source-level fact. (`||` and
    // julianday need no source check: neither is ever grammar-generated, so the
    // assertion over the issued SQL above already catches them.)
    $source = (string) file_get_contents(app_path('Http/Controllers/Admin/OrdersApiController.php'));

    expect($source)->not->toContain('strftime')
        ->and($source)->not->toContain('julianday');
});

it('holds the same shape on the bulk routes, which also read aggregates', function () {
    ordersShapeData();

    $admin = ordersShapeAdmin();

    $ids = Order::query()->pluck('id')->all();

    $seen = ordersSqlFor(function () use ($admin, $ids) {
        test()->actingAs($admin, 'admin')
            ->postJson('/admin-api/orders-bulk-status', ['ids' => $ids, 'status' => 'shipped', 'force' => true])
            ->assertOk();
    });

    foreach ($seen as $statement) {
        expect(substr_count($statement->sql, '?'))->toBe(count($statement->bindings), $statement->sql);
        expect($statement->sql)->not->toContain('strftime')->and($statement->sql)->not->toContain('||');
    }
});
