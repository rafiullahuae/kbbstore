<?php

/**
 * Coupon rules are re-checked when the ORDER IS CREATED, not only when the
 * shopper applied the code.
 *
 * THE DEFECT THESE PIN.
 *
 * `cart.coupon_id` is durable — the cart cookie lives 30 days and nothing
 * clears the coupon when the basket changes (CartService::updateQuantity() and
 * remove() touch neither coupon_id nor re-run validation). CouponService::
 * validate() therefore ran exactly once, at the moment the code was typed, in
 * CartController::coupon() and CheckoutController::couponUpdate().
 *
 * Store\CheckoutController::place() then priced the order through
 * CartService::totals() -> CouponService::discountFor(), which applies the
 * product/category rules but knows nothing about expiry, minimum spend,
 * maximum spend or the allowed-email list. Only usage_limit and
 * usage_limit_per_user were re-checked at placement, and only because
 * recordRedemption() re-reads the coupon row under a lock for the race.
 *
 * So four of the six rules were enforced on the cart page and nowhere else:
 *
 *   - apply a code at AED 500, empty the basket down to AED 50, place the
 *     order, and a minimum-spend of AED 500 is paid out against AED 50;
 *   - apply a code the evening it expires, come back the next day to the same
 *     cart, and an expired code still discounts;
 *   - the maximum-spend and allowed-emails rules fail the same way.
 *
 * The admin's own manual-order path already does this correctly —
 * ManualOrderBuilder::price() re-runs validate() before it prices anything,
 * with the comment "an expired or over-used code is refused here in its own
 * words". The storefront, which is where the real money is, did not.
 */

use App\Models\Cart;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\CartService;
use Illuminate\Support\Str;

beforeEach(function () {
    PaymentProvider::query()->delete();
    app(\App\Services\Payments\GatewayCredentials::class)->forget();
    PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0]);
    app(\App\Services\SettingsService::class)->set('cod_fee', 0);

    $zone = ShippingZone::create(['name' => 'UAE', 'position' => 0]);
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

/** A cart holding $qty of one product at $unit fils, with $coupon applied. */
function revalCart(int $unit, int $qty, ?Coupon $coupon = null): Cart
{
    $product = Product::create([
        'slug' => 'reval-' . Str::random(12),
        'name' => 'Revalidation Serum',
        'status' => 'publish',
        'is_visible' => true,
        'price' => $unit,
        'stock_status' => 'instock',
    ]);

    $cart = Cart::create([
        'token' => (string) Str::uuid(),
        'currency' => 'AED',
        'status' => 'active',
        'shipping_country' => 'AE',
        'coupon_id' => $coupon?->id,
        'last_activity_at' => now(),
    ]);

    $cart->items()->create([
        'product_id' => $product->id,
        'quantity' => $qty,
        'unit_price' => $unit,
    ]);

    return $cart;
}

function revalPlace(Cart $cart, array $overrides = [])
{
    return test()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token)
        ->post('/checkout/place', array_merge([
            'billing_email' => 'buyer@example.com',
            'billing_first_name' => 'Aisha',
            'billing_last_name' => 'Khan',
            'billing_address_1' => '12 Marina Walk',
            'billing_city' => 'Dubai',
            'billing_state' => 'Dubai',
            'billing_country' => 'AE',
            'payment_method' => 'cod',
        ], $overrides));
}

it('refuses a coupon whose minimum spend the basket no longer meets', function () {
    // WELCOME50: AED 50 off, only on baskets of AED 500 or more.
    $coupon = Coupon::create([
        'code' => 'WELCOME50',
        'type' => 'fixed_cart',
        'amount' => 5000,
        'minimum_amount' => 50000,
        'usage_count' => 0,
    ]);

    // The shopper qualified when they applied it (AED 500), then took items
    // out of the basket until it was worth AED 50. Nothing re-checked.
    $cart = revalCart(5000, 1, $coupon);

    revalPlace($cart);

    $order = Order::latest('id')->first();

    // Either no order at all, or an order that paid no discount. What must
    // never happen is an order carrying the AED 50 discount on an AED 50
    // basket — which is the whole basket, free, plus AED 20 delivery the shop
    // pays the courier for.
    $discount = (int) ($order->discount_total ?? 0);

    expect($discount)->toBe(0, 'minimum-spend was not re-checked when the order was created');
});

it('refuses a coupon that expired while it sat in the cart', function () {
    $coupon = Coupon::create([
        'code' => 'LASTNIGHT',
        'type' => 'percent',
        'amount' => 2000,              // 20.00% (stored as percent x 100)
        'expires_at' => now()->subDay(),
        'usage_count' => 0,
    ]);

    $cart = revalCart(20000, 2, $coupon);   // AED 400 basket

    revalPlace($cart);

    $order = Order::latest('id')->first();

    expect((int) ($order->discount_total ?? 0))
        ->toBe(0, 'an expired coupon was still honoured at order creation');
});

it('refuses a coupon restricted to other email addresses', function () {
    $coupon = Coupon::create([
        'code' => 'STAFFONLY',
        'type' => 'fixed_cart',
        'amount' => 10000,
        'allowed_emails' => ['someone.else@example.com'],
        'usage_count' => 0,
    ]);

    $cart = revalCart(20000, 2, $coupon);

    revalPlace($cart, ['billing_email' => 'buyer@example.com']);

    $order = Order::latest('id')->first();

    expect((int) ($order->discount_total ?? 0))
        ->toBe(0, 'an email-restricted coupon was honoured for an address not on its list');
});

it('still places a normal order when the coupon is genuinely valid', function () {
    // The guard must not break the ordinary case: a live, unrestricted code on
    // a qualifying basket still discounts, and the order still completes.
    $coupon = Coupon::create([
        'code' => 'GOOD10',
        'type' => 'percent',
        'amount' => 1000,              // 10.00%
        'minimum_amount' => 10000,
        'expires_at' => now()->addYear(),
        'usage_count' => 0,
    ]);

    $cart = revalCart(20000, 2, $coupon);   // AED 400

    revalPlace($cart)->assertRedirect();

    $order = Order::latest('id')->first();

    expect($order)->not->toBeNull()
        ->and($order->discount_total)->toBe(4000)      // 10% of AED 400
        ->and($order->coupon_code)->toBe('GOOD10')
        ->and($order->subtotal)->toBe(40000)
        ->and($order->shipping_total)->toBe(2000)
        ->and($order->total)->toBe(40000 - 4000 + 2000);
});
