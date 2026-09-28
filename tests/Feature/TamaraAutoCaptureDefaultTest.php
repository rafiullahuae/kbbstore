<?php

declare(strict_types=1);

use App\Models\PaymentProvider;
use App\Services\Payments\GatewayCredentials;
use App\Services\Payments\Gateways\TamaraGateway;

/**
 * =============================================================================
 * AUTO-CAPTURE IS ON BECAUSE THE OWNER SAID SO, AND OFF STAYS OFF
 * =============================================================================
 *
 * Lane TC built Tamara auto-capture and shipped it switched Off, which was
 * right — whether capture follows fulfilment is a commercial decision about
 * when this shop takes a customer's money. It was put to the owner on 28
 * September 2026 and he answered it: *"yes if tamara payment captured, The
 * order should be processing by default in our system."*
 *
 * ── WHY THIS IS A MIGRATION AND NOT A CHANGED DEFAULT IN autoCapture() ──────
 *
 * The payments screen stores a `bool` gateway field as the string '1' for On
 * and the EMPTY STRING for Off (resources/views/admin/app.blade.php, the
 * `f.type === 'bool'` branch). GatewayCredentials::get() also returns '' for a
 * key no config blob carries. So "never set" and "deliberately switched Off"
 * are indistinguishable by value, and the obvious code default —
 * `autoCapture()` returning true unless the value is '0' — would read an
 * owner's explicit Off as On and take a customer's money after he had said not
 * to. That is the defect this file exists to keep out, and it is why the two
 * cases below are worth more than the one.
 *
 * The migration writes the '1' ONCE, and only when the key is ABSENT
 * (array_key_exists, not a truthiness test). After it runs the key exists and
 * says On; an Off written over it is a real, distinguishable Off that nothing
 * puts back.
 */
function tamaraDefaultMigration(): object
{
    return require database_path('migrations/2027_03_21_000000_tamara_auto_capture_on_by_default.php');
}

function tamaraDefaultProvider(array $config): PaymentProvider
{
    app()->forgetInstance(GatewayCredentials::class);

    return PaymentProvider::query()->updateOrCreate(
        ['id' => 'tamara'],
        ['enabled' => true, 'config' => $config],
    );
}

function tamaraAutoCaptureNow(): bool
{
    // A fresh resolve, because GatewayCredentials memoises per gateway for the
    // life of the instance and this file writes the row underneath it.
    app()->forgetInstance(GatewayCredentials::class);

    $gateway = app(\App\Services\Payments\GatewayRegistry::class)->find('tamara');

    expect($gateway)->toBeInstanceOf(TamaraGateway::class);

    return $gateway->autoCapture();
}

it('turns auto-capture on for an install that has never set the switch', function () {
    /*
     * The state every existing install is in: Tamara configured, the key absent
     * from the config blob because nothing has ever written it.
     *
     * MUTATION NOTE, RUN: change the migration's write to `$config
     * ['auto_capture'] = '';` and this is red — autoCapture() reads '' as off,
     * which is the whole point of the string it writes.
     */
    tamaraDefaultProvider(['api_token' => 'tok', 'notification_token' => 'ntok']);

    expect(tamaraAutoCaptureNow())->toBeFalse('the fixture did not start from the un-set state');

    tamaraDefaultMigration()->up();

    expect(tamaraAutoCaptureNow())->toBeTrue();

    $config = PaymentProvider::query()->find('tamara')->config;

    expect($config['auto_capture'])->toBe('1')
        ->and($config['api_token'])->toBe('tok', 'the migration rewrote a credential it was not asked to touch');
});

it('leaves an owner who switched it off switched off', function () {
    /*
     * THE CASE THAT MAKES THIS A MIGRATION RATHER THAN A CODE DEFAULT.
     *
     * The screen writes '' for Off. If this ran again — a re-apply, a repair, a
     * `migrate` on a box where the migrations table was rebuilt — a default
     * expressed as "on unless the value is '0'" would take the money anyway.
     * array_key_exists() is what keeps Off meaning Off, and it is the line this
     * case pins.
     *
     * MUTATION NOTE, RUN: change the migration's guard to
     * `if (($config['auto_capture'] ?? '') !== '')` — which reads as the same
     * thing and is not — and this case is red: the owner's Off becomes On.
     */
    tamaraDefaultProvider(['api_token' => 'tok', 'auto_capture' => '']);

    tamaraDefaultMigration()->up();

    expect(tamaraAutoCaptureNow())->toBeFalse('an explicit Off was overwritten');

    expect(PaymentProvider::query()->find('tamara')->config)
        ->toHaveKey('auto_capture')
        ->and(PaymentProvider::query()->find('tamara')->config['auto_capture'])->toBe('');
});

it('does not switch on an install that has already switched it on', function () {
    tamaraDefaultProvider(['api_token' => 'tok', 'auto_capture' => '1']);

    tamaraDefaultMigration()->up();

    expect(tamaraAutoCaptureNow())->toBeTrue();
});

it('does nothing at all when Tamara has never been set up', function () {
    // No `tamara` row. The migration must not create one: a provider row with a
    // config blob and no credentials is a gateway the payments screen would
    // draw as half-configured.
    PaymentProvider::query()->where('id', 'tamara')->delete();

    tamaraDefaultMigration()->up();

    expect(PaymentProvider::query()->find('tamara'))->toBeNull();
});

it('captures nothing that has not shipped, whatever the switch says', function () {
    /*
     * The other half of the owner's answer, and it was already true: he asked
     * that a captured Tamara order read as `processing` in this system.
     * PaymentConfirmer::confirm() moves a Tamara order to `processing` the
     * moment the authorisation is confirmed (PaymentConfirmer.php:131), and the
     * sweep never touches a `processing` order — TamaraCaptureSweep::FULFILLED
     * is ['shipped', 'completed'] and the candidate query filters on it.
     *
     * Pinned here rather than taken on trust, because turning the switch on is
     * exactly the change that would make a widening of FULFILLED expensive: a
     * `processing` order has been packed by nobody, and capturing it charges a
     * customer for something still on the shelf.
     */
    expect(\App\Services\Payments\TamaraCaptureSweep::FULFILLED)->toBe(['shipped', 'completed']);
});
