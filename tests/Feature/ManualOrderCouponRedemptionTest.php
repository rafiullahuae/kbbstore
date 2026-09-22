<?php

declare(strict_types=1);

/**
 * Coupon redemptions on the back-office placement path.
 *
 * ManualOrderBuilder's own docblock said it did not record a redemption, and
 * gave the reason: Store\CheckoutController::place() did not either, so
 * matching it was the lesser evil. That reasoning was right while the website
 * path was broken. Now that the website path records, this one must too —
 * otherwise a back-office order spends a coupon a web order does not, which is
 * exactly the divergence that comment was written to avoid.
 *
 * The last test here is the one that matters most: the two paths share one
 * counter, so a limit is a limit however the order was keyed in.
 */

use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Order;
use Tests\ManualOrders;

beforeEach(function () {
    ManualOrders::registerRoutes();
    ManualOrders::shop();
    $this->admin = ManualOrders::admin();
});

function mocPost(array $payload)
{
    return test()->actingAs(test()->admin, 'admin')
        ->postJson('/admin-api/manual-orders', $payload);
}

/** One manual order for $customer against $code. */
function manualOrderWith(string $code, ?\App\Models\Customer $customer = null)
{
    $customer ??= ManualOrders::customer();
    $item = ManualOrders::product('Toner ' . uniqid(), 8900);

    return mocPost(ManualOrders::payload([
        'customer_id' => $customer->id,
        'items' => [['product_id' => $item->id, 'quantity' => 1]],
        'coupon_code' => $code,
    ]));
}

it('records a redemption for a manual order, in exact fils', function () {
    $coupon = ManualOrders::coupon('WELCOME10', 'fixed_cart', 5000);
    $customer = ManualOrders::customer();

    manualOrderWith('WELCOME10', $customer)->assertCreated();

    $order = Order::latest('id')->first();
    expect($order->coupon_code)->toBe('WELCOME10')
        ->and($order->discount_total)->toBe(5000);

    $redemption = CouponRedemption::where('coupon_id', $coupon->id)->first();

    expect($redemption)->not->toBeNull()
        ->and($redemption->amount)->toBe(5000)
        ->and($redemption->order_id)->toBe($order->id)
        ->and($redemption->customer_id)->toBe($customer->id)
        ->and($redemption->email)->toBe('layla@example.ae');

    expect($coupon->fresh()->usage_count)->toBe(1);
});

it('refuses the manual order that would exceed a global usage limit', function () {
    $coupon = ManualOrders::coupon('LIMIT1', 'fixed_cart', 5000, ['usage_limit' => 1]);

    manualOrderWith('LIMIT1', ManualOrders::customer(['email' => 'a@example.ae']))
        ->assertCreated();

    $ordersBefore = Order::count();

    // The operator is told why, and no order is written.
    manualOrderWith('LIMIT1', ManualOrders::customer(['email' => 'b@example.ae']))
        ->assertStatus(422);

    expect(Order::count())->toBe($ordersBefore)
        ->and($coupon->fresh()->usage_count)->toBe(1)
        ->and(CouponRedemption::where('coupon_id', $coupon->id)->count())->toBe(1);
});

it('refuses a second manual order for the same customer under a per-user limit', function () {
    $coupon = ManualOrders::coupon('ONEEACH', 'fixed_cart', 5000, ['usage_limit_per_user' => 1]);
    $customer = ManualOrders::customer();

    manualOrderWith('ONEEACH', $customer)->assertCreated();

    $ordersBefore = Order::count();

    manualOrderWith('ONEEACH', $customer)->assertStatus(422);

    expect(Order::count())->toBe($ordersBefore)
        ->and(CouponRedemption::where('coupon_id', $coupon->id)->count())->toBe(1);
});

it('rolls the redemption back with the order when a manual placement fails', function () {
    // A quote is priced inside a transaction that always rolls back. Nothing
    // it does may leave a redemption behind, or previewing an order would
    // spend the coupon.
    $coupon = ManualOrders::coupon('WELCOME10', 'fixed_cart', 5000);
    $customer = ManualOrders::customer();
    $item = ManualOrders::product('Serum', 8900);

    test()->actingAs(test()->admin, 'admin')
        ->postJson('/admin-api/manual-orders/quote', ManualOrders::payload([
            'customer_id' => $customer->id,
            'items' => [['product_id' => $item->id, 'quantity' => 1]],
            'coupon_code' => 'WELCOME10',
        ]))->assertOk();

    expect(CouponRedemption::count())->toBe(0)
        ->and($coupon->fresh()->usage_count)->toBe(0);
});

/* ------------------------------------------------------- one shared counter */

it('counts website and back-office orders against the same limit', function () {
    // usage_limit 1, spent by a manual order. The website must then refuse it.
    $coupon = ManualOrders::coupon('SHARED', 'fixed_cart', 5000, ['usage_limit' => 1]);

    manualOrderWith('SHARED', ManualOrders::customer(['email' => 'ops@example.ae']))
        ->assertCreated();

    expect($coupon->fresh()->usage_count)->toBe(1);

    // Now the storefront, with its own cart and cookie.
    $product = \App\Models\Product::create([
        'slug' => 'shared-' . uniqid(),
        'name' => 'Shared Serum',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 200,
        'stock_status' => 'instock',
    ]);

    $cart = \App\Models\Cart::create([
        'token' => (string) \Illuminate\Support\Str::uuid(),
        'currency' => 'AED',
        'status' => 'active',
        'shipping_country' => 'AE',
        'coupon_id' => $coupon->id,
        'last_activity_at' => now(),
    ]);

    $cart->items()->create([
        'product_id' => $product->id,
        'quantity' => 1,
        'unit_price' => 20000,
    ]);

    $ordersBefore = Order::count();

    test()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(\App\Services\CartService::COOKIE, $cart->token)
        ->post('/checkout/place', [
            'billing_email' => 'shopper@example.com',
            'billing_phone' => '+971500000000',
            'billing_first_name' => 'Aisha',
            'billing_last_name' => 'Khan',
            'billing_address_1' => '12 Marina Walk',
            'billing_city' => 'Dubai',
            'billing_state' => 'Dubai',
            'billing_country' => 'AE',
            'payment_method' => 'cod',
        ])
        ->assertSessionHasErrors();

    expect(Order::count())->toBe($ordersBefore)
        ->and($coupon->fresh()->usage_count)->toBe(1);
});
