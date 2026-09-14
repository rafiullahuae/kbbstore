<?php

/**
 * The customer-side schema repair, and the error reporting that found the
 * need for it.
 *
 * Store → Customers returned a bare 500 "Server Error" on the live server
 * while answering 200 in every test here. The difference is the database: this
 * sandbox migrates SQLite from scratch, the server runs a MySQL that spent
 * most of this project's life never being migrated, because the updater's
 * `migrations` flag was never set until 2.60.114. That is the same cause as
 * the checkout outage (Unknown column 'is_gift'), and the order-side repair
 * deliberately stopped at nine tables — customers, addresses and carts were
 * never among them.
 */

use Illuminate\Support\Facades\Schema;

it('has every column the Customers screen selects', function () {
    // The 22 columns the one SELECT names across three tables. If any is
    // absent the whole statement fails, which is what a 500 with no detail
    // looks like from the outside.
    $expected = [
        'customers' => [
            'id', 'wp_user_id', 'name', 'first_name', 'last_name', 'email', 'phone',
            'email_verified_at', 'whatsapp_optin', 'notes', 'created_at', 'deleted_at',
            'password', 'legacy_password',
        ],
        'addresses' => ['id', 'customer_id', 'is_default', 'city', 'state', 'country'],
        'carts' => ['customer_id', 'last_activity_at'],
    ];

    foreach ($expected as $table => $columns) {
        foreach ($columns as $column) {
            expect(Schema::hasColumn($table, $column))
                ->toBeTrue("{$table}.{$column} is missing");
        }
    }
});

it('leaves a complete schema alone when the repair runs again', function () {
    $before = [];
    foreach (['customers', 'addresses', 'carts'] as $t) {
        $before[$t] = Schema::getColumnListing($t);
        sort($before[$t]);
    }

    // The suite has already migrated, so this is the "runs twice" case the
    // migration has to survive.
    $migration = require database_path('migrations/2026_09_21_000000_repair_customer_tables.php');
    $migration->up();

    foreach (['customers', 'addresses', 'carts'] as $t) {
        $after = Schema::getColumnListing($t);
        sort($after);
        expect($after)->toBe($before[$t]);
    }
});

it('uses no ->after(), which is what broke the original ALTER chain on MySQL', function () {
    $source = file_get_contents(database_path('migrations/2026_09_21_000000_repair_customer_tables.php'));

    // Comments stripped first: the doc block explains WHY ->after() is absent,
    // and a naive search matches that sentence rather than any code. Checking
    // the prose instead of the statements is how a guard like this passes
    // while the thing it guards against is still there.
    $code = implode('', array_map(
        static fn (array $t): string => (string) (is_array($t) ? ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT ? '' : $t[1]) : $t),
        array_map(static fn ($t) => is_array($t) ? $t : [0, $t], token_get_all($source)),
    ));

    // ALTER ... AFTER a column that does not exist is an error on MySQL and
    // silently ignored on SQLite — a green suite beside a broken server.
    expect($code)->not->toContain('->after(')
        // And prove the stripper actually kept the code it is searching.
        ->and($code)->toContain('Schema::hasColumn');
});
