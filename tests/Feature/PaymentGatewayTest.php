<?php

/**
 * Phase 11 — the gateway abstraction, and Cash on Delivery on top of it.
 *
 * Note on isolation: RefreshDatabase does not roll back the FIRST test in a
 * process, so nothing here relies on starting from an empty table. Every test
 * seeds exactly the provider rows it needs and clears the rest first.
 */

use App\Models\PaymentProvider;
use App\Services\Payments\GatewayRegistry;
use App\Services\Payments\Gateways\CashOnDelivery;

beforeEach(function () {
    PaymentProvider::query()->delete();
    app(\App\Services\Payments\GatewayCredentials::class)->forget();
});

function provider(string $id, array $attrs = []): PaymentProvider
{
    return PaymentProvider::create(array_merge([
        'id' => $id,
        'title' => ucfirst($id),
        'enabled' => true,
        'mode' => 'test',
        'position' => 0,
    ], $attrs));
}

/* ------------------------------------------------------------- the registry */

it('knows the gateways this build ships', function () {
    $ids = app(GatewayRegistry::class)->all()->map->id()->all();

    expect($ids)->toContain('cod');
});

it('returns null for a gateway id it has no code for', function () {
    $registry = app(GatewayRegistry::class);

    expect($registry->find('bitcoin'))->toBeNull()
        ->and($registry->supports('bitcoin'))->toBeFalse();
});

it('ignores an enabled provider row for a gateway it cannot run', function () {
    // A stray row must not be offered and then 500 on submit.
    provider('cod');
    provider('paypal');

    $ids = app(GatewayRegistry::class)->availableFor(10000)->map->id()->all();

    expect($ids)->toBe(['cod']);
});

it('offers nothing when every provider row is disabled', function () {
    provider('cod', ['enabled' => false]);

    expect(app(GatewayRegistry::class)->availableFor(10000))->toBeEmpty();
});

it('builds the exact array shape the checkout blade iterates', function () {
    provider('cod');

    $row = app(GatewayRegistry::class)->checkoutList(10000)[0];

    expect($row)->toHaveKeys(['id', 'title', 'description', 'fee_html', 'fee_fils']);
});

it('prefers the merchant title from the provider row over the class default', function () {
    provider('cod', ['title' => 'Pay the courier']);

    expect(app(GatewayRegistry::class)->checkoutList(10000)[0]['title'])
        ->toBe('Pay the courier');
});

/* ----------------------------------------------------- cash on delivery ---- */

it('offers cash on delivery with no credentials at all', function () {
    provider('cod');

    expect(app(GatewayRegistry::class)->find('cod')->configured())->toBeTrue();
    expect(app(GatewayRegistry::class)->availableFor(10000)->map->id()->all())->toBe(['cod']);
});

it('honours the Payment & Shipping Rules window through the gateway', function () {
    provider('cod');

    $settings = app(\App\Services\SettingsService::class);
    $settings->setModule('pay_ship_rules', true);
    $settings->setModuleSetting('pay_ship_rules', 'cod_min', 5000);
    $settings->setModuleSetting('pay_ship_rules', 'cod_max', 50000);

    $cod = app(CashOnDelivery::class);

    expect($cod->availableFor(4999))->toBeFalse()
        ->and($cod->availableFor(5000))->toBeTrue()
        ->and($cod->availableFor(50000))->toBeTrue()
        ->and($cod->availableFor(50001))->toBeFalse();

    // And the registry drops it from the offered list for the same reason.
    expect(app(GatewayRegistry::class)->availableFor(4999))->toBeEmpty();
    expect(app(GatewayRegistry::class)->availableFor(10000)->map->id()->all())->toBe(['cod']);
});

it('reads the cod fee from settings, in fils', function () {
    app(\App\Services\SettingsService::class)->set('cod_fee', 1500);

    expect(app(CashOnDelivery::class)->feeFils(10000))->toBe(1500);
});

it('moves a cod order to processing without marking it paid', function () {
    $order = \App\Models\Order::create([
        'order_number' => 'COD-'.uniqid(),
        'email' => 'buyer@example.com',
        'status' => 'pending',
        'currency' => 'AED',
        'total' => 10000,
    ]);

    $start = app(CashOnDelivery::class)->start($order);

    expect($start->ok())->toBeTrue()
        ->and($start->redirectUrl)->toBeNull();

    $order->refresh();

    expect($order->status)->toBe('processing')
        // No cash has changed hands yet. paid_at would make it look settled
        // in every report that reads that column.
        ->and($order->paid_at)->toBeNull();
});
