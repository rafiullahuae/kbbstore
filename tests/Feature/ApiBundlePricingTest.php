<?php

declare(strict_types=1);

/**
 * A 3-PACK COSTS THE SAME THROUGH EITHER DOOR.
 *
 * THE DEFECT THIS PINS.
 *
 * Quantity bundles are generated for every simple product from one tier table
 * (BundleService::DEFAULT_TIERS — 2 for 5% off, 3 for 10%), so the product page
 * offers "3-pack bundle, Best value" on the whole catalogue without anyone
 * setting it up per product. CartService::unitPriceFor() applies the tier, and
 * its own comment says why it, and not the client, decides: "Recomputed here
 * from the catalogue and the bundle tiers — never taken from the request."
 *
 * Api\CheckoutController::session() recomputed too — but from
 * Product::effectivePrice() alone, with no reference to quantity. So the same
 * three bottles cost one thing on the product page and a different, HIGHER
 * thing through the public endpoint:
 *
 *     AED 150 each, 3 ordered
 *       product page / storefront cart   AED 135.00 each   AED 405.00
 *       POST /api/checkout/session       AED 150.00 each   AED 450.00
 *
 * AED 45 over the advertised price, on an unauthenticated endpoint, with the
 * order written and the customer's card charged.
 *
 * WHY IT WAS LEFT AND WHY IT IS BEING FIXED. It errs towards the shop rather
 * than away from it, which is why nobody chased it. That is also exactly what
 * makes it the worse defect of the two directions: an undercharge is the
 * store's money, an overcharge is the customer's, and the figure they were
 * shown is the one they agreed to. Every other pricing rule on this endpoint —
 * the sale window, the shipping zones, the visibility filter — has already been
 * brought into line with the storefront for the same reason; the bundle tier is
 * the last one that was not.
 *
 * AND IT DEFERS TO CartService::unitPriceFor() rather than calling
 * BundleService here. One method decides what a line costs. That method also
 * knows that bundles apply to simple products only (a variant carries its own
 * price), and that the tiers can be switched off entirely under Store →
 * Modules — none of which this endpoint should be re-deciding for itself.
 */

use App\Models\Order;
use App\Models\Product;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\BundleService;
use App\Services\CartService;

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
});

function bundleProduct(int $priceFils = 15000): Product
{
    return Product::create([
        'slug' => 'bundle-serum-' . uniqid(),
        'name' => 'Bundle Serum',
        'status' => 'publish',
        'is_visible' => true,
        'price' => $priceFils,
        'stock_status' => 'instock',
    ]);
}

/** Place one basket through the public endpoint and hand back the order. */
function apiOrderFor(Product $product, int $qty): Order
{
    test()->postJson('/api/checkout/session', [
        'items' => [['slug' => $product->slug, 'qty' => $qty]],
        'customer' => [
            'name' => 'Noura S.',
            'email' => 'noura@example.com',
            'country' => 'AE',
        ],
        'method' => 'cod',
    ])->assertSuccessful();

    return Order::latest('id')->firstOrFail();
}

it('charges the bundle rate the product page advertises', function () {
    $p = bundleProduct(15000);

    // What the storefront offers for three: the 10% tier.
    $advertised = app(CartService::class)->unitPriceFor($p, null, 3);

    expect($advertised)->toBe(13500)          // AED 135.00
        ->and($advertised)->toBeLessThan($p->effectivePrice());

    $order = apiOrderFor($p, 3);
    $line = $order->items->firstOrFail();

    expect((int) $line->unit_price)->toBe(
        $advertised,
        "page advertises {$advertised} fils each for 3, endpoint charged {$line->unit_price}"
    );

    expect((int) $order->subtotal)->toBe(40500);   // AED 405.00, not AED 450.00
});

it('never charges more than the storefront would for the same basket', function () {
    /*
     * The general statement, over every tier boundary and one above the top
     * tier — buying five still gets the 3-pack rate, so a quantity past the
     * last tier must not fall back to full price.
     */
    $carts = app(CartService::class);

    foreach ([1, 2, 3, 5] as $qty) {
        $p = bundleProduct(15000);
        $storefront = $carts->unitPriceFor($p, null, $qty);

        $line = apiOrderFor($p, $qty)->items->firstOrFail();

        expect((int) $line->unit_price)->toBe(
            $storefront,
            "qty {$qty}: storefront {$storefront} fils, endpoint {$line->unit_price}"
        );
    }
});

it('applies the bundle tier on top of a live sale, not instead of it', function () {
    // The two discounts compose, exactly as unitPriceFor() composes them:
    // the tier applies to effectivePrice(), not to the pre-sale price.
    $p = bundleProduct(15000);
    $p->forceFill([
        'sale_price' => 10000,                 // AED 100.00, live
        'sale_starts_at' => now()->subDay(),
        'sale_ends_at' => now()->addDay(),
    ])->save();

    $line = apiOrderFor($p, 3)->items->firstOrFail();

    expect((int) $line->unit_price)->toBe(9000);   // AED 90.00 = 100 less 10%
});

it('ignores the sale price when the sale window has closed, tier and all', function () {
    $p = bundleProduct(15000);
    $p->forceFill([
        'sale_price' => 10000,
        'sale_starts_at' => now()->subMonth(),
        'sale_ends_at' => now()->subDay(),
    ])->save();

    $line = apiOrderFor($p, 3)->items->firstOrFail();

    // Full price, then the 3-pack tier on top of it.
    expect((int) $line->unit_price)->toBe(13500);
});

it('charges the plain price when bundles are switched off', function () {
    /*
     * The owner's switch has to reach this endpoint too, or turning bundles
     * off would leave the public door still giving them away.
     */
    app(\App\Services\SettingsService::class)->set('bundles_enabled', false);

    expect(app(BundleService::class)->enabled())->toBeFalse();

    $p = bundleProduct(15000);
    $line = apiOrderFor($p, 3)->items->firstOrFail();

    expect((int) $line->unit_price)->toBe(15000);
});
