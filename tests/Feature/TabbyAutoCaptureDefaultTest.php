<?php

declare(strict_types=1);

use App\Models\PaymentProvider;
use App\Services\Payments\GatewayCredentials;
use App\Services\Payments\Gateways\TabbyGateway;

/**
 * =============================================================================
 * TABBY AUTO-CAPTURE IS ON BECAUSE THE OWNER SAID SO, AND OFF STAYS OFF
 * =============================================================================
 *
 * The exact sibling of TamaraAutoCaptureDefaultTest. The owner asked for Tabby
 * capture to be automatic — "the tabby orders must be captured automatically
 * ... before in wordpress website the orders were auto capturing" — so the
 * switch ships On. It is a MIGRATION rather than a changed default in
 * autoCapture() for the one reason that makes the two cases below worth more
 * than one: the payments screen writes a bool as '1' for On and '' for Off, and
 * GatewayCredentials::get() returns '' for an absent key, so "never set" and
 * "switched Off" are the same string. A code default of "on unless '0'" would
 * read an owner's explicit Off as On and take a customer's money after he had
 * said not to. The migration writes '1' ONCE, only when the key is ABSENT
 * (array_key_exists), so an Off written over it is a real, distinguishable Off.
 */
function tabbyDefaultMigration(): object
{
    return require database_path('migrations/2027_12_21_100000_tabby_auto_capture_on_by_default.php');
}

function tabbyDefaultProvider(array $config): PaymentProvider
{
    app()->forgetInstance(GatewayCredentials::class);

    return PaymentProvider::query()->updateOrCreate(
        ['id' => 'tabby'],
        ['enabled' => true, 'config' => $config],
    );
}

function tabbyAutoCaptureNow(): bool
{
    // A fresh resolve, because GatewayCredentials memoises per gateway for the
    // life of the instance and this file writes the row underneath it.
    app()->forgetInstance(GatewayCredentials::class);

    $gateway = app(\App\Services\Payments\GatewayRegistry::class)->find('tabby');

    expect($gateway)->toBeInstanceOf(TabbyGateway::class);

    return $gateway->autoCapture();
}

it('turns auto-capture on for an install that has never set the switch', function () {
    /*
     * MUTATION NOTE, RUN: change the migration's write to
     * `$config['auto_capture'] = '';` and this is red — autoCapture() reads ''
     * as off, which is the whole point of the string it writes.
     */
    tabbyDefaultProvider(['public_key' => 'pk_test_x', 'secret_key' => 'sk_test_x']);

    expect(tabbyAutoCaptureNow())->toBeFalse('the fixture did not start from the un-set state');

    tabbyDefaultMigration()->up();

    expect(tabbyAutoCaptureNow())->toBeTrue();

    $config = PaymentProvider::query()->find('tabby')->config;

    expect($config['auto_capture'])->toBe('1')
        ->and($config['secret_key'])->toBe('sk_test_x', 'the migration rewrote a credential it was not asked to touch');
});

it('leaves an owner who switched it off switched off', function () {
    /*
     * THE CASE THAT MAKES THIS A MIGRATION RATHER THAN A CODE DEFAULT.
     *
     * MUTATION NOTE, RUN: change the migration's guard to
     * `if (($config['auto_capture'] ?? '') !== '')` — which reads as the same
     * thing and is not — and this case is red: the owner's Off becomes On.
     */
    tabbyDefaultProvider(['public_key' => 'pk_test_x', 'secret_key' => 'sk_test_x', 'auto_capture' => '']);

    tabbyDefaultMigration()->up();

    expect(tabbyAutoCaptureNow())->toBeFalse('an explicit Off was overwritten');

    expect(PaymentProvider::query()->find('tabby')->config)
        ->toHaveKey('auto_capture')
        ->and(PaymentProvider::query()->find('tabby')->config['auto_capture'])->toBe('');
});

it('does not switch on an install that has already switched it on', function () {
    tabbyDefaultProvider(['public_key' => 'pk_test_x', 'secret_key' => 'sk_test_x', 'auto_capture' => '1']);

    tabbyDefaultMigration()->up();

    expect(tabbyAutoCaptureNow())->toBeTrue();
});

it('does nothing at all when Tabby has never been set up', function () {
    // No `tabby` row. The migration must not create one: a provider row with a
    // config blob and no credentials is a gateway the payments screen would
    // draw as half-configured.
    PaymentProvider::query()->where('id', 'tabby')->delete();

    tabbyDefaultMigration()->up();

    expect(PaymentProvider::query()->find('tabby'))->toBeNull();
});
