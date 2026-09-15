<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\DeliveryCountry;
use App\Models\Setting;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Services\Import\Money as ImportMoney;
use App\Services\SettingsService;
use App\Support\Money;

/**
 * Money bounds on the admin write endpoints, and the engine gap they hide in.
 *
 * EVERY money column in this schema is a signed 32-bit `integer` in fils, so
 * the largest amount any of them can hold is Money::MAX_FILS — AED 21,474,836.47.
 * Several write endpoints validated these with `integer|min:0` and no ceiling,
 * which is not a bound at all: Laravel's `integer` rule accepts 99,999,999,999
 * quite happily.
 *
 * What makes it worth a test rather than a shrug is that the two engines
 * disagree, in the direction that hides it:
 *
 *   MySQL, strict mode   ERROR 1264 "Out of range value for column" — the save
 *                        500s, and on the live host that is the whole screen.
 *   SQLite               stores the value and carries on.
 *
 * So the suite this repository actually runs on is the one that cannot see it.
 * These tests assert the REFUSAL, which is identical on both engines, rather
 * than asserting what the database does — the point is that the endpoint never
 * hands the driver a number it cannot take.
 */
beforeEach(function () {
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();
    Money::forgetConfig();
});

function boundsAdmin(): AdminUser
{
    $admin = AdminUser::create([
        'name' => 'Bounds Owner',
        'email' => 'bounds-' . uniqid() . '@example.test',
        'password' => 'password-long-enough',
        'role' => 'owner',
    ]);

    test()->actingAs($admin, 'admin');

    return $admin;
}

/** One zone with a flat method and a free-shipping method, as seeded live. */
function boundsZone(): ShippingZone
{
    $zone = ShippingZone::create(['name' => 'UAE', 'position' => 0]);

    ShippingMethod::create([
        'shipping_zone_id' => $zone->id,
        'type' => 'flat_rate',
        'title' => 'Standard',
        'enabled' => true,
        'cost' => 2000,
        'position' => 0,
    ]);

    ShippingMethod::create([
        'shipping_zone_id' => $zone->id,
        'type' => 'free_shipping',
        'title' => 'Free delivery',
        'enabled' => true,
        'cost' => 0,
        'min_amount' => 19900,
        'position' => 1,
    ]);

    return $zone->fresh('methods');
}

// ---------------------------------------------------------------------------
// Store -> Delivery & Shipping
// ---------------------------------------------------------------------------

it('refuses a delivery charge larger than the money column, instead of 500ing on MySQL', function () {
    $zone = boundsZone();
    $flat = $zone->methods->firstWhere('type', 'flat_rate');

    boundsAdmin();

    test()->postJson('/admin-api/shipping', ['methods' => [[
        'id' => $flat->id,
        'title' => 'Standard',
        'enabled' => true,
        'cost' => ImportMoney::MAX_FILS + 1,
        'min_amount' => null,
    ]]])->assertStatus(422);

    // Untouched, not half-saved.
    expect((int) $flat->fresh()->cost)->toBe(2000);
});

it('refuses a free-shipping threshold larger than the money column', function () {
    $zone = boundsZone();
    $free = $zone->methods->firstWhere('type', 'free_shipping');

    boundsAdmin();

    test()->postJson('/admin-api/shipping', ['methods' => [[
        'id' => $free->id,
        'title' => 'Free delivery',
        'enabled' => true,
        'cost' => 0,
        'min_amount' => ImportMoney::MAX_FILS + 1,
    ]]])->assertStatus(422);

    expect((int) $free->fresh()->min_amount)->toBe(19900);
});

it('still saves a delivery charge at the very top of the range', function () {
    $zone = boundsZone();
    $flat = $zone->methods->firstWhere('type', 'flat_rate');

    boundsAdmin();

    test()->postJson('/admin-api/shipping', ['methods' => [[
        'id' => $flat->id,
        'title' => 'Standard',
        'enabled' => true,
        'cost' => ImportMoney::MAX_FILS,
        'min_amount' => null,
    ]]])->assertOk();

    expect((int) $flat->fresh()->cost)->toBe(ImportMoney::MAX_FILS);
});

// ---------------------------------------------------------------------------
// Store -> Delivery & Shipping -> Extended
// ---------------------------------------------------------------------------

it('refuses an extended-delivery charge larger than the money column', function () {
    boundsAdmin();

    test()->postJson('/admin-api/extended-delivery', [
        'on' => true,
        'detect' => false,
        'show_all' => false,
        'rows' => [[
            'code' => 'SA',
            'enabled' => true,
            'charge' => ImportMoney::MAX_FILS + 1,
            'free_from' => null,
            'eta' => '3-5 days',
        ]],
    ])->assertStatus(422);

    expect(DeliveryCountry::query()->where('code', 'SA')->exists())->toBeFalse();
});

// ---------------------------------------------------------------------------
// Store -> Ecommerce -> Checkout
// ---------------------------------------------------------------------------

/**
 * The same COD fee, written by a second screen with a different rule.
 *
 * EcommerceApiController::cast() had `is_numeric($raw) && (int) $raw >= 0`.
 * is_numeric('12.50') is true and `(int) '12.50'` is 12, so this screen turned
 * AED 12.50 into 12 fils — AED 0.12 — and answered "Saved". The settings screen
 * next door writes the same key; the two disagreeing about what a value means
 * is how a fee ends up wrong in a way nobody can reproduce.
 */
it('refuses a decimal COD fee on the Ecommerce screen rather than storing a hundredth of it', function () {
    app(SettingsService::class)->set('cod_fee', '1250');

    boundsAdmin();

    test()->postJson('/admin-api/ecommerce', ['settings' => ['cod_fee' => '12.50']])
        ->assertStatus(422);

    expect((int) app(SettingsService::class)->get('cod_fee', 0))->toBe(1250);
});

it('refuses a COD fee larger than the money column on the Ecommerce screen', function () {
    app(SettingsService::class)->set('cod_fee', '500');

    boundsAdmin();

    test()->postJson('/admin-api/ecommerce', ['settings' => ['cod_fee' => ImportMoney::MAX_FILS + 1]])
        ->assertStatus(422);

    expect((int) app(SettingsService::class)->get('cod_fee', 0))->toBe(500);
});

it('still saves an ordinary COD fee on the Ecommerce screen', function () {
    boundsAdmin();

    test()->postJson('/admin-api/ecommerce', ['settings' => ['cod_fee' => '750']])
        ->assertOk();

    expect((int) app(SettingsService::class)->get('cod_fee', 0))->toBe(750);
});

/**
 * `int` fields on that screen are counts, not money, so they get the bound their
 * own column and reader impose rather than the money one. dispatch_cutoff_hour
 * is an hour of the day; 30 is not one, and the countdown it drives just renders
 * nonsense rather than failing.
 */
it('refuses a cutoff hour that is not an hour', function () {
    boundsAdmin();

    test()->postJson('/admin-api/ecommerce', ['settings' => ['dispatch_cutoff_hour' => '30']])
        ->assertStatus(422);
});

// ---------------------------------------------------------------------------
// Appearance -> Quantity bundles: the worked example on the tier editor
// ---------------------------------------------------------------------------

/**
 * BundleApiController::preview() formatted with `number_format($fils / 100, 2)`.
 *
 * The float in that expression is NOT the bug, and saying so matters because
 * the two get conflated. Checked against exact integer arithmetic across the
 * whole 32-bit fils range, `$fils / 100` through number_format() agrees every
 * time: a two-decimal value is never a tie at two decimals, and the double's
 * error is ~1e-13 relative — nowhere near half a fil. It could not have
 * produced a wrong figure at any magnitude a money column can hold.
 *
 * The hard-coded `/ 100` and `2` were the bug. "Minor units are hundredths" is
 * true of AED and not of every currency this store can be configured to use:
 * Money::minorExponent() is a setting, and KWD is 3. On a KWD store the tier
 * editor's worked example — the one thing on that screen whose job is to show
 * the operator what a discount does before they commit it — was out by a factor
 * of ten.
 */
it('shows the bundle preview in the configured currency, not always hundredths', function () {
    boundsAdmin();

    $svc = app(SettingsService::class);
    $svc->set('currency', 'KWD');
    $svc->set('currency_decimals', '3');
    Money::forgetConfig();
    Setting::flushMap();

    $preview = test()->getJson('/admin-api/bundles')->assertOk()->json('preview');

    // 5500 minor units at three decimals is 5.500, not 55.00.
    expect($preview[0]['was'])->toBe('5.50');
});

it('leaves the bundle preview byte-for-byte unchanged on AED', function () {
    boundsAdmin();

    $preview = test()->getJson('/admin-api/bundles')->assertOk()->json('preview');

    expect($preview[0]['was'])->toBe('55.00');
});
