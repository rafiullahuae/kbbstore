<?php

declare(strict_types=1);

use App\Models\PaymentProvider;
use App\Services\Payments\GatewayCredentials;
use App\Services\Payments\Gateways\TabbyGateway;
use App\Services\Payments\TabbyCaptureSweep;
use Illuminate\Support\Facades\Http;

/*
 * The owner, 26 October 2026: "reverse the auto capture functionality that you
 * did today ... only reverse whatever you did for tabby ... give me a switch".
 * 2.60.601 shipped Tabby auto-capture ON; this package's migration writes it
 * OFF, leaving the switch (Store -> Payments -> Tabby -> Settings -> "Capture
 * automatically") for him. What it looked like on the shop if this broke: the
 * every-five-minutes check kept capturing Tabby orders after he asked for it
 * to stop.
 *
 * MUTATION NOTE: change the migration's write to '1' and the first case is
 * red; drop its decrypt try/catch and nothing here notices, which is why the
 * credentials are asserted untouched.
 */
function tabbyOffMigration(): object
{
    return require database_path('migrations/2027_12_27_100000_tabby_auto_capture_off.php');
}

function tabbyOffProvider(array $config): void
{
    app()->forgetInstance(GatewayCredentials::class);
    PaymentProvider::query()->updateOrCreate(['id' => 'tabby'], ['enabled' => true, 'config' => $config]);
}

function tabbyOffNow(): bool
{
    app()->forgetInstance(GatewayCredentials::class);
    $gateway = app(\App\Services\Payments\GatewayRegistry::class)->find('tabby');
    expect($gateway)->toBeInstanceOf(TabbyGateway::class);

    return $gateway->autoCapture();
}

it('turns Tabby auto-capture off and keeps every other Tabby setting', function () {
    tabbyOffProvider(['public_key' => 'pk_test_x', 'secret_key' => 'sk_test_x', 'merchant_code' => 'AE', 'auto_capture' => '1']);
    expect(tabbyOffNow())->toBeTrue('the fixture did not start switched on');

    tabbyOffMigration()->up();

    expect(tabbyOffNow())->toBeFalse();
    $config = PaymentProvider::query()->find('tabby')->config;
    expect($config)->toMatchArray(['public_key' => 'pk_test_x', 'secret_key' => 'sk_test_x', 'merchant_code' => 'AE', 'auto_capture' => '']);
});

it('makes the five-minute check call nobody once it is off', function () {
    tabbyOffProvider(['public_key' => 'pk_test_x', 'secret_key' => 'sk_test_x', 'auto_capture' => '1']);
    tabbyOffMigration()->up();
    app()->forgetInstance(GatewayCredentials::class);

    Http::fake();
    app(TabbyCaptureSweep::class)->run();

    Http::assertNothingSent();
});

it('leaves an install with no Tabby row, and an Off, exactly as they were', function () {
    PaymentProvider::query()->where('id', 'tabby')->delete();
    tabbyOffMigration()->up();
    expect(PaymentProvider::query()->find('tabby'))->toBeNull();

    tabbyOffProvider(['public_key' => 'pk_test_x', 'secret_key' => 'sk_test_x', 'auto_capture' => '']);
    tabbyOffMigration()->up();
    expect(tabbyOffNow())->toBeFalse();
});

it('keeps the switch on the payments screen, worded for either position', function () {
    $fields = app(\App\Services\Payments\GatewayRegistry::class)->find('tabby')->configSchema();
    expect($fields)->toHaveKey('auto_capture')
        ->and($fields['auto_capture'][1])->toBe('Capture automatically')
        ->and($fields['auto_capture'][2])->toStartWith('OFF.');
});
