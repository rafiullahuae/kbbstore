<?php

/**
 * MySQL would reject these statements; SQLite runs them. So assert the SHAPE.
 *
 * The live Customers screen returned:
 *
 *   SQLSTATE[42000] 1140 Mixing of GROUP columns (MIN(),MAX(),COUNT(),...)
 *   with no GROUP columns is illegal if there is no GROUP BY clause
 *
 * while every test here passed. Cause: selectRaw() APPENDS to the select list
 * rather than replacing it, so the chip-count and summary queries carried all
 * 22 allowlisted columns from rowQuery() and then added COUNT(*)/SUM(...) with
 * no GROUP BY on the outer query. SQLite permits that and invents a row for the
 * bare columns; MySQL refuses.
 *
 * A test that merely calls the endpoint cannot catch this on SQLite — it comes
 * back 200. These inspect the SQL actually issued instead, which is
 * dialect-independent, and they fail against the pre-fix code.
 */

use App\Models\AdminUser;
use App\Models\Customer;
use Illuminate\Support\Facades\DB;
use Tests\Support\CustomersAdminRoutes;

/** Same harness AdminCustomersTest uses: wire the route file, sign in. */
function mysqlSafetyAdmin(): AdminUser
{
    CustomersAdminRoutes::wire(app());

    return AdminUser::create([
        'name' => 'Shape Owner',
        'email' => 'shape-' . uniqid() . '@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
}

/** Remove every balanced-parenthesis group, leaving only outer-query structure. */
function outerOnly(string $sql): string
{
    do {
        $before = $sql;
        $sql = preg_replace('/\([^()]*\)/', ' ', $sql) ?? $sql;
    } while ($sql !== $before);

    return $sql;
}

it('never mixes an aggregate with bare columns outside a GROUP BY', function () {
    Customer::create(['email' => 'a@kbb.test', 'name' => 'A']);
    Customer::create(['email' => 'b@kbb.test', 'name' => 'B']);

    $admin = mysqlSafetyAdmin();

    $seen = [];
    DB::listen(function ($q) use (&$seen) { $seen[] = $q->sql; });

    $this->actingAs($admin, 'admin')->get('/admin-api/customers/list')->assertOk();

    expect($seen)->not->toBeEmpty();

    foreach ($seen as $sql) {
        $outer = outerOnly($sql);

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
            "aggregate mixed with bare columns and no GROUP BY:\n".$sql);
    }
});

it('sends exactly as many bindings as the statement has placeholders', function () {
    // Dropping the select columns without dropping their bindings leaves the
    // driver more values than markers — the trap in the fix itself.
    Customer::create(['email' => 'c@kbb.test', 'name' => 'C']);

    $admin = mysqlSafetyAdmin();

    $mismatch = [];
    DB::listen(function ($q) use (&$mismatch) {
        if (substr_count($q->sql, '?') !== count($q->bindings)) {
            $mismatch[] = $q->sql;
        }
    });

    $this->actingAs($admin, 'admin')->get('/admin-api/customers/list')->assertOk();

    expect($mismatch)->toBe([]);
});

/**
 * A wildcard in the search box must mean a literal character on both engines.
 *
 * The original escaping relied on a backslash, which MySQL treats as the
 * default LIKE escape and SQLite does not recognise at all — so the same search
 * behaved differently in production than under the suite. Found by the Orders
 * lane; back-ported here with an explicit ESCAPE '!'.
 */
it('treats % and _ in the search box as literal characters', function () {
    Customer::create(['email' => 'promo@kbb.test', 'name' => 'KBB-100%-OFF']);
    Customer::create(['email' => 'other@kbb.test', 'name' => 'Ordinary Shopper']);

    $admin = mysqlSafetyAdmin();

    $hit = $this->actingAs($admin, 'admin')
        ->getJson('/admin-api/customers/list?search=' . urlencode('100%'))
        ->assertOk()->json('customers');

    // Finds the literal name, and does NOT match everything the way a bare
    // wildcard would.
    expect(collect($hit)->pluck('name')->all())->toBe(['KBB-100%-OFF']);

    $underscore = $this->actingAs($admin, 'admin')
        ->getJson('/admin-api/customers/list?search=' . urlencode('K_B'))
        ->assertOk()->json('customers');

    // '_' is a single-character wildcard unless escaped; "KBB" must not match.
    expect($underscore)->toBe([]);
});
