<?php

/**
 * customers.orders_count, customers.total_spent and customers.last_order_at
 * exist, look authoritative, and are not.
 *
 * Phase 0 created them as denormalised lifetime figures. Nothing in this
 * application has ever written one, so on every install they read 0, 0 and NULL
 * for every customer — including one with hundreds of dirhams of history. Store
 * → Customers deliberately ignores them and aggregates from `orders` instead,
 * which is correct and is the reason that screen shows real numbers.
 *
 * The decision recorded here is that they stay retired: not maintained, and not
 * dropped either (the live server is a MySQL holding real rows, reachable only
 * through signed zip packages, where an irreversible DROP COLUMN buys nothing a
 * rule cannot). The danger is not the columns, it is a future importer filling
 * them in — at which point the same fact has two sources that drift apart, and
 * the wrong one is the one that looks like a column.
 *
 * These tests are what makes that decision enforceable rather than a comment
 * somebody eventually contradicts.
 */

use App\Models\Customer;
use App\Models\Order;
use Illuminate\Support\Facades\Schema;

it('still has the three columns, because retiring them is not dropping them', function () {
    foreach (Customer::UNMAINTAINED_COLUMNS as $column) {
        expect(Schema::hasColumn('customers', $column))->toBeTrue("customers.{$column} was dropped");
    }

    expect(Customer::UNMAINTAINED_COLUMNS)
        ->toBe(['orders_count', 'total_spent', 'last_order_at']);
});

it('leaves all three untouched when a customer places an order', function () {
    $customer = Customer::create(['email' => 'shopper@example.test']);

    Order::create([
        'order_number' => 'R-1',
        'customer_id' => $customer->id,
        'email' => 'shopper@example.test',
        'status' => 'completed',
        'total' => 45000,
    ]);

    // Read straight from the table: an accessor or an appended attribute would
    // hide the thing being asserted.
    $row = (array) \Illuminate\Support\Facades\DB::table('customers')->find($customer->id);

    expect((int) $row['orders_count'])->toBe(0)
        ->and((int) $row['total_spent'])->toBe(0)
        ->and($row['last_order_at'])->toBeNull();

    // Which is only safe because the real figure is computed, and is right.
    $spend = (int) Order::query()
        ->where('customer_id', $customer->id)
        ->whereIn('status', Order::REAL_STATUSES)
        ->sum('total');

    expect($spend)->toBe(45000);
});

it('never serialises a derived customer figure', function () {
    /*
     * A confident-looking 0 in an API response is indistinguishable from a
     * customer who has genuinely never ordered, and /api/* on this app is
     * unauthenticated. Hiding them is cheaper than auditing every future
     * endpoint that returns a Customer.
     */
    $customer = Customer::create(['email' => 'hidden@example.test']);

    $serialised = $customer->fresh()->toArray();

    foreach (Customer::UNMAINTAINED_COLUMNS as $column) {
        expect($serialised)->not->toHaveKey($column);
    }

    // The same guard that has always covered the credentials.
    expect($serialised)->not->toHaveKey('password')
        ->and($serialised)->not->toHaveKey('legacy_password');
});

it('casts none of them, because a cast implies something reads it', function () {
    $casts = (new Customer)->getCasts();

    foreach (Customer::UNMAINTAINED_COLUMNS as $column) {
        expect($casts)->not->toHaveKey($column);
    }

    // And the casts that ARE real are still there — otherwise this test would
    // pass just as well against a model with no casts at all.
    expect($casts)->toHaveKey('email_verified_at')
        ->and($casts)->toHaveKey('password');
});
