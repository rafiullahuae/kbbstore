<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Order;
use App\Models\Refund;
use Tests\Support\CustomersAdminRoutes;

/**
 * Store → Customers: lifetime spend, net of refunds.
 *
 * The same defect the dashboard and Analytics had, in the third place it
 * mattered. `spend_fils` on this screen is
 *
 *     SUM(CASE WHEN status IN (REAL_STATUSES) THEN total ELSE 0 END)
 *
 * which is right about cancelled orders — that fix has its own long comment in
 * CustomersApiController — and silent about refunds. PaymentRefunder moves an
 * order to 'refunded' only on a FULL refund, so a customer who was given AED
 * 400 back on a AED 1,000 order kept the whole AED 1,000 in their lifetime
 * value, in the list's sort-by-value order, in the summary strip, and in the
 * CSV the owner exports to pick who to send an offer to.
 *
 * This is the figure the owner would use to decide who their best customers
 * are, so being wrong in the generous direction is not a harmless rounding: it
 * promotes the people who returned the most.
 *
 * A separate file rather than more cases inside AdminCustomersTest, which
 * belongs to the lane that built this screen.
 */

function aSpendCustomer(array $attributes = []): Customer
{
    static $n = 0;
    $n++;

    return Customer::create(array_merge([
        'name' => 'Spend Customer ' . $n,
        'email' => 'spend-customer-' . $n . '@example.test',
    ], $attributes));
}

function aSpendOrder(Customer $customer, int $fils, string $status = 'completed'): Order
{
    static $n = 0;
    $n++;

    return Order::create([
        'customer_id' => $customer->id,
        'order_number' => 'S-' . $n . '-' . uniqid(),
        'email' => $customer->email,
        'status' => $status,
        'subtotal' => $fils,
        'total' => $fils,
        'paid_at' => now(),
    ]);
}

function aSpendRefund(Order $order, int $fils, string $status = 'succeeded'): Refund
{
    return Refund::create([
        'order_id' => $order->id,
        'amount' => $fils,
        'status' => $status,
        'reason' => 'test',
    ]);
}

/**
 * Its own admin factory rather than borrowing one from a sibling test file:
 * a Pest helper defined in another file only exists when that file is also in
 * the run, so `--filter` against this file alone would die on an undefined
 * function rather than report the defect.
 */
function asSpendAdmin(): void
{
    CustomersAdminRoutes::wire(app());

    test()->actingAs(\App\Models\AdminUser::create([
        'name' => 'S Owner',
        'email' => 's-owner-' . uniqid() . '@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]), 'admin');
}

it('takes a partial refund off a customer lifetime spend', function () {
    asSpendAdmin();

    $customer = aSpendCustomer();
    $order = aSpendOrder($customer, 100000);   // AED 1,000.00
    aSpendRefund($order, 40000);               // AED   400.00 given back

    expect($order->fresh()->status)
        ->toBe('completed', 'fixture assumption: a partial refund leaves the status alone');

    $rows = test()->getJson('/admin-api/customers/list')->json('customers');
    $row = collect($rows)->firstWhere('id', $customer->id);

    expect($row)->not->toBeNull();
    expect($row['spend_fils'])
        ->toBe(60000, 'AED 400 was handed back, so this customer has paid the store AED 600');
});

it('does not take a failed refund attempt off a customer lifetime spend', function () {
    asSpendAdmin();

    $customer = aSpendCustomer();
    $order = aSpendOrder($customer, 100000);
    aSpendRefund($order, 40000, 'failed');

    $rows = test()->getJson('/admin-api/customers/list')->json('customers');
    $row = collect($rows)->firstWhere('id', $customer->id);

    expect($row['spend_fils'])
        ->toBe(100000, 'a refund that failed sent no money back');
});

it('nets refunds out of the summary strip and the average order value', function () {
    asSpendAdmin();

    $customer = aSpendCustomer();
    $a = aSpendOrder($customer, 100000);
    aSpendRefund($a, 40000);
    aSpendOrder($customer, 20000);

    // Net 60000 + 20000 = 80000 fils over 2 paid orders = 40000 fils.
    $summary = test()->getJson('/admin-api/customers/list')->json('summary');

    expect($summary['spend_fils'])->toBe(80000);
    expect($summary['aov_fils'])->toBe(40000, 'the average must divide the NET spend');
});

it('does not let a refund on a cancelled order reduce anything', function () {
    asSpendAdmin();

    $customer = aSpendCustomer();
    $paid = aSpendOrder($customer, 50000);
    $dead = aSpendOrder($customer, 90000, 'cancelled');

    // A refund row against an order that never counted as spend in the first
    // place. Subtracting it would push the customer below what they paid.
    aSpendRefund($dead, 90000);

    $rows = test()->getJson('/admin-api/customers/list')->json('customers');
    $row = collect($rows)->firstWhere('id', $customer->id);

    expect($row['spend_fils'])
        ->toBe(50000, 'a cancelled order is not spend, so a refund on it is not a deduction');
});

it('sorts by value using the net figure, not the gross one', function () {
    asSpendAdmin();

    $big = aSpendCustomer(['name' => 'Gross Biggest']);
    $order = aSpendOrder($big, 100000);
    aSpendRefund($order, 90000);            // really only spent AED 100

    $real = aSpendCustomer(['name' => 'Actually Biggest']);
    aSpendOrder($real, 50000);              // AED 500, kept

    $rows = test()->getJson('/admin-api/customers/list?sort=spend_desc')->json('customers');

    $names = collect($rows)->pluck('name')->values()->all();
    $iReal = array_search('Actually Biggest', $names, true);
    $iGross = array_search('Gross Biggest', $names, true);

    expect($iReal)->not->toBeFalse();
    expect($iGross)->not->toBeFalse();
    expect($iReal < $iGross)
        ->toBeTrue('"best customers" ranked by value must not promote the person who returned the most');
});
