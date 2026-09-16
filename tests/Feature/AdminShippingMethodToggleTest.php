<?php

declare(strict_types=1);

/**
 * Store → Delivery & Shipping: a method you switch off has to be switchable
 * back on.
 *
 * THE DEFECT. `ShippingZone::methods()` is not a plain hasMany — it carries
 * `->where('enabled', true)`, because the storefront asks that relation for
 * what to OFFER a shopper and must never offer a switched-off method. The admin
 * screen asked the SAME relation for what to EDIT. Those are different
 * questions, and the answer to the second one has to include the rows the first
 * one hides.
 *
 * So: the owner unticks Standard delivery and saves. save() writes
 * enabled = false. The next show() reads through the scoped relation and the
 * method is simply not in the payload, so the screen no longer draws a row for
 * it — and save() only ever updates ids the browser sends it, which are the ids
 * show() returned. The switch is one-way. The only way back is a database
 * client, on a host CLAUDE.md records as having no shell access.
 *
 * Worse at the end of it: disable both methods on the only zone that covers the
 * UAE and ShippingService::ratesFor() returns [] for every UAE address, which
 * Store\CheckoutController::place() answers with "We do not deliver to that
 * country yet." Every checkout refused, and no screen that can undo it.
 *
 * These tests assert the SCREEN's payload and the round trip, not the relation,
 * because a later change that re-scoped the query somewhere else would still
 * have to keep the owner able to turn a method back on.
 */

use App\Models\AdminUser;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;

function shippingAdmin(): AdminUser
{
    $admin = AdminUser::create([
        'name' => 'Shipping Owner',
        'email' => 'shipping-owner-' . uniqid() . '@example.test',
        'password' => 'password-long-enough',
        'role' => 'owner',
    ]);

    test()->actingAs($admin, 'admin');

    return $admin;
}

/** One zone covering the UAE, with a flat rate and a free-over threshold. */
function shippingZoneWithBothMethods(): array
{
    $zone = ShippingZone::create(['name' => 'All UAE', 'position' => 0]);
    ShippingZoneLocation::create(['shipping_zone_id' => $zone->id, 'type' => 'country', 'code' => 'AE']);

    $flat = ShippingMethod::create([
        'shipping_zone_id' => $zone->id,
        'type' => 'flat_rate',
        'title' => 'Standard delivery',
        'cost' => 2000,
        'enabled' => true,
        'position' => 0,
    ]);

    $free = ShippingMethod::create([
        'shipping_zone_id' => $zone->id,
        'type' => 'free_shipping',
        'title' => 'Free delivery',
        'cost' => 0,
        'min_amount' => 19900,
        'enabled' => true,
        'position' => 1,
    ]);

    return [$zone, $flat, $free];
}

/** Every method id the screen would draw a row for. */
function methodIdsOnScreen(): array
{
    $payload = test()->getJson('/admin-api/shipping')->assertOk()->json('zones');

    $ids = [];

    foreach ($payload as $zone) {
        foreach ($zone['methods'] as $method) {
            $ids[] = $method['id'];
        }
    }

    return $ids;
}

it('still draws a switched-off delivery method on the screen', function () {
    shippingAdmin();
    [, $flat, $free] = shippingZoneWithBothMethods();

    test()->postJson('/admin-api/shipping', ['methods' => [
        ['id' => $flat->id, 'title' => 'Standard delivery', 'enabled' => false, 'cost' => 2000, 'min_amount' => null],
        ['id' => $free->id, 'title' => 'Free delivery', 'enabled' => true, 'cost' => 0, 'min_amount' => 19900],
    ]])->assertOk();

    expect($flat->fresh()->enabled)->toBeFalse();

    // The row the owner just unticked must still be there, unticked, or there
    // is nothing on the page to tick again.
    expect(methodIdsOnScreen())->toContain($flat->id);
});

it('reports the stored enabled flag rather than assuming true', function () {
    shippingAdmin();
    [, $flat] = shippingZoneWithBothMethods();

    $flat->forceFill(['enabled' => false])->save();

    $rows = collect(test()->getJson('/admin-api/shipping')->assertOk()->json('zones.0.methods'));

    expect($rows->firstWhere('id', $flat->id))->not->toBeNull()
        ->and($rows->firstWhere('id', $flat->id)['enabled'])->toBeFalse();
});

it('lets the owner switch a delivery method back on', function () {
    shippingAdmin();
    [, $flat, $free] = shippingZoneWithBothMethods();

    $flat->forceFill(['enabled' => false])->save();

    // The browser can only post back what the screen gave it, so this posts
    // exactly the rows show() returns.
    $methods = collect(test()->getJson('/admin-api/shipping')->json('zones.0.methods'))
        ->map(fn (array $m) => [
            'id' => $m['id'],
            'title' => $m['title'],
            'enabled' => true,
            'cost' => $m['cost'],
            'min_amount' => $m['min_amount'],
        ])->all();

    test()->postJson('/admin-api/shipping', ['methods' => $methods])->assertOk();

    expect($flat->fresh()->enabled)->toBeTrue()
        ->and($free->fresh()->enabled)->toBeTrue();
});

it('keeps a switched-off method out of the rates a shopper is offered', function () {
    // The other half of the contract, so a fix to the screen cannot be made by
    // dropping the storefront's filter.
    [, $flat] = shippingZoneWithBothMethods();

    $flat->forceFill(['enabled' => false])->save();

    $rates = app(\App\Services\ShippingService::class)->ratesFor('AE', null, 100, false);

    expect(collect($rates)->pluck('id')->all())->not->toContain($flat->id);
});
