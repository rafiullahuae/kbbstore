<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Order;

/*
 * GET /admin-api/orders/uncaptured lists Tabby orders authorised but never
 * captured — and nothing else. Behind orders.money (owner/manager). Read only.
 *
 * MUTATION NOTE: drop any filter in UncapturedOrdersController::index (the
 * payment_method, paid_at, captured_at, or status guard) and a matching
 * "excluded" expectation below goes red. Change the capability rule off
 * orders.money and the 403 assertion goes red.
 */

function ucAdmin(string $role): AdminUser
{
    return AdminUser::create([
        'name' => 'UC ' . $role,
        'email' => 'uc-' . $role . '-' . uniqid() . '@example.test',
        'password' => 'password-long-enough',
        'role' => $role,
    ]);
}

function ucOrder(array $attrs = []): Order
{
    return Order::create(array_merge([
        'order_number' => 'UC-' . uniqid(),
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

it('lists only authorised, uncaptured, live Tabby orders with flags and totals', function () {
    ucOrder(['order_number' => 'UC-RECENT', 'paid_at' => now()->subDays(5), 'total' => 15000]);  // capturable
    ucOrder(['order_number' => 'UC-OLD', 'paid_at' => now()->subDays(40), 'total' => 25000]);     // expired
    ucOrder(['order_number' => 'UC-CAPTURED', 'captured_at' => now()]);
    ucOrder(['order_number' => 'UC-CANCELLED', 'status' => 'cancelled']);
    ucOrder(['order_number' => 'UC-NOAUTH', 'paid_at' => null]);
    ucOrder(['order_number' => 'UC-TAMARA', 'payment_method' => 'tamara']);

    $json = $this->actingAs(ucAdmin('owner'), 'admin')
        ->getJson('/admin-api/orders/uncaptured')->assertOk()->json();

    expect($json['count'])->toBe(2);

    $nums = collect($json['orders'])->pluck('order_number')->all();
    expect($nums)->toContain('UC-RECENT')->toContain('UC-OLD')
        ->not->toContain('UC-CAPTURED')->not->toContain('UC-CANCELLED')
        ->not->toContain('UC-NOAUTH')->not->toContain('UC-TAMARA');
    expect($nums[0])->toBe('UC-OLD'); // oldest authorisation first

    $byNum = collect($json['orders'])->keyBy('order_number');
    expect($byNum['UC-RECENT']['expired'])->toBeFalse();
    expect($byNum['UC-OLD']['expired'])->toBeTrue();
    expect($json['capturable_total'])->toBe('150.00');
    expect($json['expired_total'])->toBe('250.00');
});

it('is gated to orders.money (owner/manager), 403 for other roles', function () {
    ucOrder();
    $this->actingAs(ucAdmin('editor'), 'admin')->getJson('/admin-api/orders/uncaptured')->assertForbidden();
    $this->actingAs(ucAdmin('manager'), 'admin')->getJson('/admin-api/orders/uncaptured')->assertOk();
});
