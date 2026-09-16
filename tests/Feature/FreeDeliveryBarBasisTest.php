<?php

declare(strict_types=1);

/**
 * THE FREE-DELIVERY BAR IS MEASURED ON THE SUBTOTAL THAT ACTUALLY QUALIFIES.
 *
 * THE DEFECT THIS PINS.
 *
 * Two figures decided one thing, and they disagreed.
 *
 * What is CHARGED comes from ShippingService::ratesFor(), and every caller —
 * Store\CheckoutController::place(), its rateContext(), Api\CheckoutController
 * and ManualOrderBuilder::price() — hands it the GROSS subtotal, before any
 * coupon. A "free over AED 199" method therefore qualifies on the pre-discount
 * basket, and the rate comes back at zero.
 *
 * What is SAID came from CartService::totals(), which measured
 * `free_shipping_remaining` and `free_shipping_percent` on the DISCOUNTED
 * subtotal. So with a coupon applied:
 *
 *     basket AED 220, coupon -AED 40, threshold AED 199
 *       charged   : ratesFor(22000 fils) -> qualified -> AED 0 delivery
 *       displayed : "You're AED 19.00 away from free delivery", bar at 90%
 *
 * The shopper is told to spend AED 19 more for something the shop has already
 * given them. The bar and the line item on the same screen contradict each
 * other, and the one that is wrong is the one asking for more money.
 *
 * WHY THE LABEL MOVES AND THE CHARGE DOES NOT.
 *
 * The split is deliberate and written down — ManualOrderBuilder::price() says
 * "Rates are asked for on the pre-discount subtotal and the free-shipping bar
 * is measured on the discounted one. That split is the checkout's, reproduced
 * by calling the same two things in the same order." Qualifying on the gross
 * subtotal is the customer-favouring half: a coupon cannot push a basket back
 * under the threshold and re-impose a delivery charge the shopper had already
 * earned. That behaviour is correct and is left exactly as it is — the test
 * below pins it so this fix cannot quietly become a charge change.
 *
 * The bar's old comment claimed the same motive — "a coupon should not push a
 * customer back below the threshold" — and then measured the one basis that
 * CAN push them below it. Measuring on the gross subtotal serves that intent
 * and agrees with the charge; there is no basket on which it is less generous,
 * because gross is never below net.
 */

use App\Models\Cart;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\CartService;
use App\Services\ShippingService;

/** "All UAE": AED 20 flat, free over AED 199 — the live zone. */
beforeEach(function () {
    $zone = ShippingZone::create(['name' => 'All UAE', 'position' => 0]);
    ShippingZoneLocation::create(['shipping_zone_id' => $zone->id, 'type' => 'country', 'code' => 'AE']);
    ShippingMethod::create([
        'shipping_zone_id' => $zone->id,
        'type' => 'flat_rate',
        'title' => 'Standard delivery',
        'cost' => 2000,
        'enabled' => true,
        'position' => 0,
    ]);
    ShippingMethod::create([
        'shipping_zone_id' => $zone->id,
        'type' => 'free_shipping',
        'title' => 'Free delivery',
        'cost' => 0,
        'min_amount' => 19900,     // AED 199.00
        'enabled' => true,
        'position' => 1,
    ]);
});

/**
 * A cart holding one product at $priceFils, with $code applied if given.
 * Bundles are off so the quantity of one line cannot move the subtotal.
 */
function barCart(int $priceFils, ?string $code = null): Cart
{
    app(\App\Services\SettingsService::class)->set('bundles_enabled', false);

    $product = Product::create([
        'slug' => 'bar-serum-' . uniqid(),
        'name' => 'Bar Serum',
        'status' => 'publish',
        'is_visible' => true,
        'price' => $priceFils,
        'stock_status' => 'instock',
    ]);

    $carts = app(CartService::class);
    $cart = $carts->create();
    $carts->add($cart, $product, 1);

    $cart->forceFill(['shipping_country' => 'AE'])->save();

    if ($code !== null) {
        $coupon = Coupon::whereRaw('LOWER(code) = ?', [mb_strtolower($code)])->firstOrFail();
        $cart->forceFill(['coupon_id' => $coupon->id])->save();
    }

    return $cart->fresh(['items.product', 'items.variant', 'coupon']);
}

it('does not claim more is needed once the basket already ships free', function () {
    /*
     * The defect, stated as the shopper meets it. AED 220 basket, AED 40 off,
     * threshold AED 199. The gross basket qualifies, so delivery is free —
     * and the bar used to ask for another AED 19.00 anyway.
     */
    Coupon::create([
        'code' => 'SAVE40',
        'type' => 'fixed_cart',
        'amount' => 4000,
    ]);

    $cart = barCart(22000, 'SAVE40');

    // The charge: the gross basket qualifies for the free method.
    $rates = app(ShippingService::class)->ratesFor('AE', null, 22000, true);
    expect(collect($rates)->firstWhere('type', 'free_shipping'))->not->toBeNull();

    $totals = app(CartService::class)->totals($cart, 'AE', null, 0);

    expect($totals['discount'])->toBe(4000)
        ->and($totals['subtotal'])->toBe(22000);

    expect($totals['free_shipping_remaining'])->toBe(
        0,
        'bar asked for another ' . $totals['free_shipping_remaining'] . ' fils on a basket already shipping free'
    );
    expect($totals['free_shipping_unlocked'])->toBeTrue(
        'basket ships free but the bar says it has not unlocked'
    );
    expect($totals['free_shipping_percent'])->toBe(100);
});

it('agrees with the charge on every basket, coupon or not', function () {
    /*
     * The general statement: whatever the bar says about unlocking is true of
     * what the shop actually charges. One rule, checked either side of the
     * threshold and either side of the coupon.
     */
    Coupon::create([
        'code' => 'SAVE40',
        'type' => 'fixed_cart',
        'amount' => 4000,
    ]);

    $shipping = app(ShippingService::class);
    $carts = app(CartService::class);

    foreach ([[15000, null], [15000, 'SAVE40'], [22000, null], [22000, 'SAVE40'], [19900, 'SAVE40']] as [$price, $code]) {
        $cart = barCart($price, $code);
        $totals = $carts->totals($cart, 'AE', null, 0);

        // What the shop charges: is a free method actually offered?
        $rates = $shipping->ratesFor('AE', null, $totals['subtotal'], true);
        $shipsFree = collect($rates)->contains(fn ($r) => $r['type'] === 'free_shipping' && $r['cost'] === 0);

        $label = 'AED ' . number_format($price / 100, 2) . ($code ? " with {$code}" : ' with no coupon');

        expect($totals['free_shipping_unlocked'])->toBe(
            $shipsFree,
            "{$label}: bar says unlocked=" . var_export($totals['free_shipping_unlocked'], true)
                . ' but the shop ships free=' . var_export($shipsFree, true)
        );
    }
});

it('still tells a basket under the threshold how much more it needs', function () {
    // The bar has to keep working. AED 150 of a AED 199 threshold, no coupon.
    $totals = app(CartService::class)->totals(barCart(15000), 'AE', null, 2000);

    expect($totals['free_shipping_remaining'])->toBe(4900)   // AED 49.00
        ->and($totals['free_shipping_unlocked'])->toBeFalse()
        ->and($totals['free_shipping_percent'])->toBe(75);
});

it('leaves the charge exactly where it was: a coupon never re-imposes delivery', function () {
    /*
     * The half that was already right, pinned so that "fix the label" cannot
     * drift into "fix the charge". The rate is chosen on the GROSS subtotal,
     * so a coupon that takes the basket under the threshold does not hand the
     * shopper a delivery charge they had already earned.
     */
    Coupon::create([
        'code' => 'BIGCUT',
        'type' => 'fixed_cart',
        'amount' => 5000,             // AED 50 off: 220 -> 170, under AED 199
    ]);

    $cart = barCart(22000, 'BIGCUT');
    $totals = app(CartService::class)->totals($cart, 'AE', null, 0);

    expect($totals['subtotal'] - $totals['discount'])->toBe(17000);

    // The rate is still asked for on the gross subtotal, and still free.
    $rates = app(ShippingService::class)->ratesFor('AE', null, $totals['subtotal'], true);
    expect(collect($rates)->contains(fn ($r) => $r['type'] === 'free_shipping' && $r['cost'] === 0))->toBeTrue();

    // And the bar agrees rather than contradicting it.
    expect($totals['free_shipping_unlocked'])->toBeTrue();
    expect($totals['free_shipping_remaining'])->toBe(0);
});

it('says nothing at all where no free-delivery method exists', function () {
    ShippingMethod::where('type', 'free_shipping')->delete();

    $totals = app(CartService::class)->totals(barCart(22000), 'AE', null, 2000);

    expect($totals['free_shipping_threshold'])->toBeNull()
        ->and($totals['free_shipping_remaining'])->toBeNull()
        ->and($totals['free_shipping_unlocked'])->toBeFalse()
        ->and($totals['free_shipping_percent'])->toBeNull();
});
