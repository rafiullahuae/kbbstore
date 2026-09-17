<?php

declare(strict_types=1);

/**
 * A PROGRESS BAR MAY NOT READ FULL WHILE MONEY IS STILL OWED — Lane EZ.
 *
 * CartService::totals() filled the free-delivery bar with
 *
 *     min(100, (int) round($subtotal / $threshold * 100))
 *
 * and a basket 30 fils short of the AED 199 threshold is 19870/19900 = 99.85%,
 * which rounds UP. The bar filled completely while `free_shipping_unlocked`
 * stayed false, the "is-unlocked" styling did not fire, and the caption beside
 * it read "You're AED 0.30 away from free delivery" — a full bar arguing with
 * the sentence printed on top of it. A shopper who reads the bar rather than
 * the sentence checks out expecting free delivery and is charged AED 20.
 *
 * 100 is now read off $toFree, the same value `free_shipping_unlocked` is read
 * off, so the two cannot disagree: reaching 100 and unlocking are one fact.
 *
 * WHAT IS ASSERTED. Not "the number is 99" — that would pin an arbitrary
 * rendering. The INVARIANT: the bar reads 100 if and only if delivery is
 * actually free. It is checked across the last fil on either side of the
 * threshold, and separately as a property over a spread of baskets, so it holds
 * for any threshold and any rounding a later lane might choose.
 *
 * FreeDeliveryBarBasisTest already pins the basis the bar is computed ON (gross
 * subtotal, not the discounted figure) and its 75% and 100% cases still pass
 * unchanged — nothing below the ceiling moved.
 */

use App\Models\Cart;
use App\Models\Product;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\CartService;

/** "All UAE": AED 20 flat, free over AED 199 — the live zone. */
beforeEach(function () {
    $zone = ShippingZone::create(['name' => 'All UAE', 'position' => 0]);
    ShippingZoneLocation::create(['shipping_zone_id' => $zone->id, 'type' => 'country', 'code' => 'AE']);
    ShippingMethod::create([
        'shipping_zone_id' => $zone->id, 'type' => 'flat_rate', 'title' => 'Standard delivery',
        'cost' => 2000, 'enabled' => true, 'position' => 0,
    ]);
    ShippingMethod::create([
        'shipping_zone_id' => $zone->id, 'type' => 'free_shipping', 'title' => 'Free delivery',
        'cost' => 0, 'min_amount' => 19900, 'enabled' => true, 'position' => 1,
    ]);
});

function nearlyFreeCart(int $priceFils): Cart
{
    app(\App\Services\SettingsService::class)->set('bundles_enabled', false);

    $product = Product::create([
        'slug' => 'ceiling-serum-' . uniqid(),
        'name' => 'Ceiling Serum',
        'status' => 'publish',
        'is_visible' => true,
        'price' => $priceFils,
        'stock_status' => 'instock',
    ]);

    $carts = app(CartService::class);
    $cart = $carts->create();
    $carts->add($cart, $product, 1);
    $cart->forceFill(['shipping_country' => 'AE'])->save();

    return $cart->fresh(['items.product', 'items.variant', 'coupon']);
}

it('does not fill the bar on a basket thirty fils short of free delivery', function () {
    // 19870 of 19900. 99.849…%, which round() takes to 100.
    $totals = app(CartService::class)->totals(nearlyFreeCart(19870), 'AE', null, 2000);

    // The premise: this basket really is short, and really does round up.
    expect($totals['free_shipping_remaining'])->toBe(30)
        ->and($totals['free_shipping_unlocked'])->toBeFalse()
        ->and((int) round(19870 / 19900 * 100))->toBe(100, 'the fixture no longer rounds up, so it proves nothing');

    expect($totals['free_shipping_percent'])->toBeLessThan(
        100,
        'the bar reads full while AED 0.30 is still owed'
    );
});

it('fills the bar the moment delivery is actually free, and not before', function () {
    $carts = app(CartService::class);

    // One fil short, exactly on, and over.
    foreach ([[19899, false], [19900, true], [25000, true]] as [$price, $shouldBeFull]) {
        $totals = $carts->totals(nearlyFreeCart($price), 'AE', null, 2000);

        expect($totals['free_shipping_percent'] === 100)->toBe(
            $shouldBeFull,
            'basket of ' . $price . ' fils: bar reads ' . $totals['free_shipping_percent']
                . '% but unlocked is ' . var_export($totals['free_shipping_unlocked'], true)
        );
    }
});

it('reads 100 if and only if the basket has unlocked free delivery', function () {
    $carts = app(CartService::class);

    // Across the whole range, including both sides of every rounding boundary
    // the old expression could trip over.
    foreach ([1, 5000, 9949, 9950, 19849, 19850, 19870, 19899, 19900, 19901, 40000] as $price) {
        $totals = $carts->totals(nearlyFreeCart($price), 'AE', null, 2000);

        expect($totals['free_shipping_percent'] === 100)->toBe(
            (bool) $totals['free_shipping_unlocked'],
            'basket of ' . $price . ' fils: the bar and the unlock flag disagree'
        );

        // And the bar stays a percentage.
        expect($totals['free_shipping_percent'])->toBeGreaterThanOrEqual(0)
            ->and($totals['free_shipping_percent'])->toBeLessThanOrEqual(100);
    }
});
