<?php

declare(strict_types=1);

use App\Models\Order;

/*
 * The `payments:tabby-uncaptured` command lists Tabby orders authorised but
 * never captured, and must NOT list anything else — a captured order, a dead
 * one, an unauthorised one, or another gateway. It changes no order row.
 *
 * MUTATION NOTE: drop any one of the four filters in the command
 * (whereNotNull('paid_at'), whereNull('captured_at'), whereNotIn status DEAD,
 * whereIn payment_method) and the matching "excluded" assertion below goes red.
 */

function tuOrder(array $attrs = []): Order
{
    return Order::create(array_merge([
        'order_number' => 'TU-' . uniqid(),
        'email' => 'buyer@example.com',
        'phone' => '+971500000000',
        'status' => 'processing',
        'currency' => 'AED',
        'subtotal' => 10000,
        'discount_total' => 0,
        'shipping_total' => 0,
        'tax_total' => 0,
        'fee_total' => 0,
        'total' => 10000,
        'payment_method' => 'tabby',
        'paid_at' => now()->subDays(3),
    ], $attrs));
}

it('lists only authorised, uncaptured, live Tabby orders and writes a CSV', function () {
    $csv = sys_get_temp_dir() . '/tu-' . uniqid() . '.csv';

    // Listed:
    tuOrder(['order_number' => 'TB-RECENT', 'paid_at' => now()->subDays(5)]);   // capturable
    tuOrder(['order_number' => 'TB-OLD', 'paid_at' => now()->subDays(40)]);     // likely expired
    // NOT listed:
    tuOrder(['order_number' => 'TB-CAPTURED', 'captured_at' => now()]);         // already captured
    tuOrder(['order_number' => 'TB-CANCELLED', 'status' => 'cancelled']);       // not live
    tuOrder(['order_number' => 'TB-NOAUTH', 'paid_at' => null]);                // never authorised
    tuOrder(['order_number' => 'TM-UNCAP', 'payment_method' => 'tamara']);      // other gateway

    $this->artisan('payments:tabby-uncaptured', ['--out' => $csv, '--days' => 30])
        ->assertExitCode(0);

    expect(is_file($csv))->toBeTrue();
    $out = (string) file_get_contents($csv);

    expect($out)->toContain('TB-RECENT')->toContain('TB-OLD')
        ->toContain('CAPTURE NOW')->toContain('likely expired');
    expect($out)->not->toContain('TB-CAPTURED')
        ->not->toContain('TB-CANCELLED')
        ->not->toContain('TB-NOAUTH')
        ->not->toContain('TM-UNCAP');

    // Read only: it captured nothing.
    expect(Order::where('order_number', 'TB-RECENT')->value('captured_at'))->toBeNull();
    expect(Order::whereNotNull('captured_at')->pluck('order_number')->all())->toEqual(['TB-CAPTURED']);

    @unlink($csv);
});

it('includes Tamara with --gateway=all and excludes it by default', function () {
    tuOrder(['order_number' => 'TB-ONE']);
    tuOrder(['order_number' => 'TM-ONE', 'payment_method' => 'tamara']);

    $allCsv = sys_get_temp_dir() . '/tu-all-' . uniqid() . '.csv';
    $this->artisan('payments:tabby-uncaptured', ['--gateway' => 'all', '--out' => $allCsv])->assertExitCode(0);
    expect(file_get_contents($allCsv))->toContain('TB-ONE')->toContain('TM-ONE');
    @unlink($allCsv);
});

it('reports cleanly when there is nothing to show', function () {
    $this->artisan('payments:tabby-uncaptured', ['--no-file' => true])
        ->expectsOutputToContain('Nothing to show')
        ->assertExitCode(0);
});
