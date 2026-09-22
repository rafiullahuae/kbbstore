<?php

declare(strict_types=1);

/**
 * Coupon redemptions — the counter that makes a usage limit mean something.
 *
 * CouponService::recordRedemption() existed from the first coupon package and
 * nothing ever called it. Both limits read state that was therefore never
 * written: usage_count stayed 0 for the life of a code, and coupon_redemptions
 * stayed empty, so `usage_count >= usage_limit` and the per-user count both
 * compared against a constant. Every promo code the store issued was unlimited,
 * for everyone, forever.
 *
 * These tests are deliberately BEHAVIOURAL. None of them asserts that
 * recordRedemption() was called, because a future change that called it outside
 * the order's transaction — or on one placement path and not the other — would
 * satisfy that assertion while putting the bug straight back. What they assert
 * is that the Nth order is refused, which is only true if the counter is real.
 *
 * Every money assertion is an exact integer in fils. The discount is carried
 * from CouponService::discountFor(), which already returns fils, and is never
 * routed through a float on the way to the redemption row.
 */

use App\Models\Cart;
use App\Models\Coupon;
use App\Models\CouponRedemption;
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

    PaymentProvider::create([
        'id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0,
    ]);
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

/** A fresh cart carrying one line, with $coupon applied to it. */
function redemptionCart(?Coupon $coupon = null, int $unitPriceFils = 20000): Cart
{
    $product = Product::create([
        'slug' => 'redeem-' . Str::random(12),
        'name' => 'Redemption Serum',
        'status' => 'publish',
        'is_visible' => true,
        'price' => $unitPriceFils / 100,
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
        'quantity' => 1,
        'unit_price' => $unitPriceFils,
    ]);

    return $cart;
}

function redemptionForm(array $overrides = []): array
{
    return array_merge([
        'billing_email' => 'buyer@example.com',
        'billing_phone' => '+971500000000',
        'billing_first_name' => 'Aisha',
        'billing_last_name' => 'Khan',
        'billing_address_1' => '12 Marina Walk',
        'billing_city' => 'Dubai',
        'billing_state' => 'Dubai',
        'billing_country' => 'AE',
        'payment_method' => 'cod',
    ], $overrides);
}

/** Place one website order against $coupon, as $email. */
function placeWith(?Coupon $coupon, string $email = 'buyer@example.com')
{
    $cart = redemptionCart($coupon);

    return test()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token)
        ->post('/checkout/place', redemptionForm(['billing_email' => $email]));
}

/* ------------------------------------------------------ the counter is real */

it('records a redemption when a website order is placed, in exact fils', function () {
    $coupon = Coupon::create(['code' => 'WELCOME10', 'type' => 'fixed_cart', 'amount' => 5000]);

    placeWith($coupon);

    $order = Order::latest('id')->first();
    expect($order)->not->toBeNull()
        ->and($order->coupon_code)->toBe('WELCOME10')
        ->and($order->discount_total)->toBe(5000);

    $redemption = CouponRedemption::where('coupon_id', $coupon->id)->first();

    expect($redemption)->not->toBeNull()
        // Exact fils, and an integer — not 50.0, not "5000".
        ->and($redemption->amount)->toBe(5000)
        ->and($redemption->order_id)->toBe($order->id)
        ->and($redemption->email)->toBe('buyer@example.com');

    expect($coupon->fresh()->usage_count)->toBe(1);
});

it('records the discount actually given, not the coupon face value', function () {
    // A fixed_cart coupon worth more than the basket is capped by discountFor()
    // at the eligible subtotal. The redemption must record what was given away.
    $coupon = Coupon::create(['code' => 'BIG', 'type' => 'fixed_cart', 'amount' => 999999]);

    placeWith($coupon);

    expect(CouponRedemption::where('coupon_id', $coupon->id)->first()->amount)->toBe(20000);
});

it('records a percentage discount in exact fils', function () {
    // 10% of AED 200.00 = AED 20.00 = 2000 fils. amount is percent x 100.
    $coupon = Coupon::create(['code' => 'GLOW10', 'type' => 'percent', 'amount' => 1000]);

    placeWith($coupon);

    expect(CouponRedemption::where('coupon_id', $coupon->id)->first()->amount)->toBe(2000);
});

it('writes no redemption for an order placed without a coupon', function () {
    placeWith(null);

    expect(Order::latest('id')->first())->not->toBeNull()
        ->and(CouponRedemption::count())->toBe(0);
});

/* ------------------------------------------------------------ global limit */

it('refuses the order that would exceed a global usage limit', function () {
    $coupon = Coupon::create([
        'code' => 'LIMIT2', 'type' => 'fixed_cart', 'amount' => 5000, 'usage_limit' => 2,
    ]);

    // Three different shoppers, so only the GLOBAL limit is in play.
    placeWith($coupon, 'one@example.com')->assertRedirect();
    placeWith($coupon, 'two@example.com')->assertRedirect();

    expect($coupon->fresh()->usage_count)->toBe(2);

    $ordersBefore = Order::count();

    placeWith($coupon, 'three@example.com')->assertSessionHasErrors();

    // The third order does not exist, and the counter did not move.
    expect(Order::count())->toBe($ordersBefore)
        ->and($coupon->fresh()->usage_count)->toBe(2)
        ->and(CouponRedemption::where('coupon_id', $coupon->id)->count())->toBe(2);
});

/* ---------------------------------------------------------- per-user limit */

it('refuses a second order from the same shopper when the per-user limit is one', function () {
    $coupon = Coupon::create([
        'code' => 'ONEEACH', 'type' => 'fixed_cart', 'amount' => 5000, 'usage_limit_per_user' => 1,
    ]);

    placeWith($coupon, 'repeat@example.com')->assertRedirect();

    $ordersBefore = Order::count();

    placeWith($coupon, 'repeat@example.com')->assertSessionHasErrors();

    expect(Order::count())->toBe($ordersBefore)
        ->and(CouponRedemption::where('coupon_id', $coupon->id)->count())->toBe(1);
});

it('lets a different shopper use a code that is per-user limited', function () {
    $coupon = Coupon::create([
        'code' => 'ONEEACH', 'type' => 'fixed_cart', 'amount' => 5000, 'usage_limit_per_user' => 1,
    ]);

    placeWith($coupon, 'first@example.com')->assertRedirect();
    placeWith($coupon, 'second@example.com')->assertRedirect();

    expect($coupon->fresh()->usage_count)->toBe(2);
});

it('matches an address case-insensitively for the per-user limit', function () {
    $coupon = Coupon::create([
        'code' => 'ONEEACH', 'type' => 'fixed_cart', 'amount' => 5000, 'usage_limit_per_user' => 1,
    ]);

    placeWith($coupon, 'Mixed.Case@Example.com')->assertRedirect();

    $ordersBefore = Order::count();

    placeWith($coupon, 'mixed.case@example.com')->assertSessionHasErrors();

    expect(Order::count())->toBe($ordersBefore);
});

/* ------------------------------------------------- the redemption is atomic */

it('leaves no redemption behind when the payment could not be started', function () {
    // A gateway that is enabled but has no credentials fails at start(), after
    // the order transaction has committed. The order stays as a `failed` row
    // for support; the coupon use must not have been consumed by it.
    PaymentProvider::create([
        'id' => 'stripe', 'title' => 'Card', 'enabled' => true, 'mode' => 'test', 'position' => 1,
    ]);

    $coupon = Coupon::create(['code' => 'WELCOME10', 'type' => 'fixed_cart', 'amount' => 5000]);

    $cart = redemptionCart($coupon);

    test()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token)
        ->post('/checkout/place', redemptionForm(['payment_method' => 'stripe']))
        ->assertSessionHasErrors();

    expect(CouponRedemption::where('coupon_id', $coupon->id)->count())->toBe(0)
        ->and($coupon->fresh()->usage_count)->toBe(0);
});
