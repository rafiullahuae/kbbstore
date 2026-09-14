<?php

/**
 * The coupon box on checkout, applied in place.
 *
 * Same defect as the quantity steppers, left behind when those were fixed:
 * /api/cart/coupon renders the mini-cart drawer and the cart page, neither of
 * which is on screen at checkout, so applying a code could only be shown by
 * reloading — which threw away every field the shopper had already typed.
 *
 * These assert the CONTENT that comes back, not that a 200 came back: a coupon
 * moves the line discounts, the totals and the free-delivery bar, and the page
 * cannot repaint unless the server actually sends those regions.
 */

use App\Models\Cart;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\CartService;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

beforeEach(function () {
    // Registered here too when the integrator's require is not in place, for
    // the same reason CheckoutLineUpdateTest does it.
    if (! Route::has('checkout.couponUpdate')) {
        Route::middleware('web')->group(base_path('routes/checkout-line.php'));
        app('router')->getRoutes()->refreshNameLookups();
    }

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

function couponCart(int $priceFils = 20000, int $qty = 2): Cart
{
    $product = Product::create([
        'slug' => 'coupon-test-' . uniqid(),
        'name' => 'Coupon Test Serum',
        'status' => 'publish',
        'is_visible' => true,
        'price' => $priceFils,
        'stock_status' => 'instock',
    ]);

    $cart = Cart::create([
        'token' => (string) Str::uuid(),
        'currency' => 'AED',
        'status' => 'active',
        'shipping_country' => 'AE',
        'last_activity_at' => now(),
    ]);

    $cart->items()->create([
        'product_id' => $product->id,
        'quantity' => $qty,
        'unit_price' => $priceFils,
    ]);

    return $cart;
}

function couponPost(Cart $cart, array $body)
{
    return test()
        ->withCredentials()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token)
        ->postJson('/checkout/coupon', $body);
}

it('applies a coupon and returns the checkout regions to repaint', function () {
    Coupon::create(['code' => 'GLOW10', 'type' => 'fixed_cart', 'amount' => 5000]);

    $cart = couponCart(20000, 2);   // AED 400 of goods

    $response = couponPost($cart, ['code' => 'GLOW10', 'country' => 'AE']);

    $response->assertOk()->assertJson(['ok' => true]);

    // The regions the page swaps in. Without orderHtml there is nothing to
    // repaint and a reload would be the only honest option.
    expect($response->json('orderHtml'))->toBeString()->not->toBe('')
        ->and($response->json('itemsHtml'))->toBeString();

    // The discount is really on the cart, not just in the wording.
    expect($cart->fresh()->coupon_id)->not->toBeNull();

    // And the returned totals reflect it: 40000 - 5000 + 2000 delivery.
    expect($response->json('orderHtml'))->toContain('370');
});

it('refuses an unknown code without touching the cart, and still returns fragments', function () {
    $cart = couponCart(20000, 2);

    $response = couponPost($cart, ['code' => 'NOPE-NOT-REAL', 'country' => 'AE']);

    $response->assertOk()->assertJson(['ok' => false]);
    expect($response->json('error'))->toBeString()->not->toBe('');

    // Unchanged cart...
    expect($cart->fresh()->coupon_id)->toBeNull();

    // ...but the page is still repainted, so the error appears beside figures
    // that are current rather than stale.
    expect($response->json('orderHtml'))->toBeString()->not->toBe('');
});

it('removes an applied coupon', function () {
    $coupon = Coupon::create(['code' => 'GLOW10', 'type' => 'fixed_cart', 'amount' => 5000]);

    $cart = couponCart(20000, 2);
    $cart->forceFill(['coupon_id' => $coupon->id])->save();

    $response = couponPost($cart, ['remove' => true, 'country' => 'AE']);

    $response->assertOk()->assertJson(['ok' => true]);
    expect($cart->fresh()->coupon_id)->toBeNull()
        ->and($response->json('orderHtml'))->toContain('420');   // 400 + 20 delivery
});

it('never takes a discount amount from the request', function () {
    Coupon::create(['code' => 'GLOW10', 'type' => 'fixed_cart', 'amount' => 5000]);

    $cart = couponCart(20000, 2);

    // A browser claiming a far larger discount than the coupon carries.
    $response = couponPost($cart, [
        'code' => 'GLOW10',
        'amount' => 39000,
        'discount' => 39000,
        'country' => 'AE',
    ]);

    $response->assertOk()->assertJson(['ok' => true]);

    // Still the coupon's own AED 50, not the AED 390 the browser asked for.
    expect($response->json('orderHtml'))->toContain('370')
        ->and($response->json('orderHtml'))->not->toContain('10.00');
});

it('refuses to apply a coupon to an empty bag', function () {
    $cart = Cart::create([
        'token' => (string) Str::uuid(),
        'currency' => 'AED',
        'status' => 'active',
        'shipping_country' => 'AE',
        'last_activity_at' => now(),
    ]);

    couponPost($cart, ['code' => 'GLOW10', 'country' => 'AE'])->assertStatus(422);
});
