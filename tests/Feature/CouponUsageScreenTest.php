<?php

declare(strict_types=1);

/**
 * Store → Coupons, the screen that makes a code's usage visible.
 *
 * Two things are being pinned here, and the second matters more than the first.
 *
 * The first is that the numbers are right: usage_count beside the limit, the
 * remaining count, and the redemption list in exact fils.
 *
 * The second is the GUARD. /admin-api/coupons/{id} returns the email address of
 * every shopper who redeemed that code. There is no per-route authorisation in
 * CouponUsageApiController — it relies entirely on being mounted inside the
 * admin-api group — so the tests at the bottom drive every route this lane
 * registers, unauthenticated and as a non-admin, and expect to be refused. They
 * read the route list off the router rather than from a list kept by hand, so a
 * route added later without a guard is caught by them rather than by nobody.
 */

use App\Models\AdminUser;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Customer;
use App\Models\Order;
use Tests\Support\CouponsAdminRoutes;

beforeEach(function () {
    CouponsAdminRoutes::wire(app());

    $this->admin = AdminUser::create([
        'name' => 'Owner',
        'email' => 'owner@kbeautybliss.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
});

function couponGet(string $path)
{
    return test()->actingAs(test()->admin, 'admin')->getJson($path);
}

/** A coupon with $count redemptions already recorded against it. */
function couponWithUsage(array $attributes, int $count = 0): Coupon
{
    $coupon = Coupon::create($attributes + ['code' => 'GLOW10', 'type' => 'percent', 'amount' => 1000]);

    for ($i = 0; $i < $count; $i++) {
        $customer = Customer::create([
            'name' => "Shopper {$i}",
            'email' => "shopper{$i}@example.ae",
        ]);

        $order = Order::create([
            'order_number' => 'C' . $coupon->id . '-' . $i,
            'customer_id' => $customer->id,
            'email' => $customer->email,
            'status' => 'processing',
            'currency' => 'AED',
            'subtotal' => 20000,
            'discount_total' => 2000,
            'shipping_total' => 0,
            'fee_total' => 0,
            'tax_total' => 0,
            'total' => 18000,
            'payment_method' => 'cod',
            'coupon_code' => $coupon->code,
        ]);

        CouponRedemption::create([
            'coupon_id' => $coupon->id,
            'customer_id' => $customer->id,
            'order_id' => $order->id,
            'email' => $customer->email,
            'amount' => 2000,
        ]);
    }

    $coupon->forceFill(['usage_count' => $count])->save();

    return $coupon->refresh();
}

/* --------------------------------------------------------------- the list */

it('shows a coupon usage count beside the limit governing it', function () {
    couponWithUsage(['usage_limit' => 50], 12);

    $response = couponGet('/admin-api/coupons')->assertOk();

    $response->assertJsonPath('ok', true)
        ->assertJsonPath('coupons.0.code', 'GLOW10')
        ->assertJsonPath('coupons.0.usage_count', 12)
        ->assertJsonPath('coupons.0.usage_limit', 50)
        ->assertJsonPath('coupons.0.remaining', 38)
        ->assertJsonPath('coupons.0.exhausted', false)
        // percent x 100, shown as a percentage rather than as money.
        ->assertJsonPath('coupons.0.amount_display', '10%');
});

it('marks a code that has been fully redeemed', function () {
    couponWithUsage(['usage_limit' => 3], 3);

    couponGet('/admin-api/coupons')->assertOk()
        ->assertJsonPath('coupons.0.remaining', 0)
        ->assertJsonPath('coupons.0.exhausted', true)
        ->assertJsonPath('summary.exhausted', 1);
});

it('says unlimited rather than inventing a remaining count', function () {
    couponWithUsage([], 7);

    couponGet('/admin-api/coupons')->assertOk()
        ->assertJsonPath('coupons.0.usage_limit', null)
        ->assertJsonPath('coupons.0.remaining', null)
        ->assertJsonPath('coupons.0.exhausted', false)
        ->assertJsonPath('coupons.0.usage_count', 7);
});

it('totals redemptions across every coupon', function () {
    couponWithUsage(['usage_limit' => 10], 4);
    Coupon::create(['code' => 'WELCOME10', 'type' => 'fixed_cart', 'amount' => 5000, 'usage_count' => 6]);

    couponGet('/admin-api/coupons')->assertOk()
        ->assertJsonPath('summary.coupons', 2)
        ->assertJsonPath('summary.redemptions', 10);
});

it('finds a coupon by code', function () {
    couponWithUsage(['usage_limit' => 10], 1);
    Coupon::create(['code' => 'WELCOME10', 'type' => 'fixed_cart', 'amount' => 5000]);

    couponGet('/admin-api/coupons?q=welcome')->assertOk()
        ->assertJsonPath('summary.coupons', 1)
        ->assertJsonPath('coupons.0.code', 'WELCOME10');
});

/* -------------------------------------------------------- one coupon's use */

it('lists who redeemed a code, in exact fils', function () {
    $coupon = couponWithUsage(['usage_limit' => 10], 2);

    $response = couponGet("/admin-api/coupons/{$coupon->id}")->assertOk();

    $response->assertJsonPath('coupon.code', 'GLOW10')
        ->assertJsonPath('redemptions_total', 2)
        // Newest first.
        ->assertJsonPath('redemptions.0.email', 'shopper1@example.ae')
        ->assertJsonPath('redemptions.0.amount_fils', 2000)
        ->assertJsonPath('redemptions.1.email', 'shopper0@example.ae');

    // The order is reachable from the redemption, which is what makes the
    // screen actionable rather than merely informative.
    expect($response->json('redemptions.0.order_number'))->not->toBeNull();
    expect($response->json('redemptions.0.order_status'))->toBe('processing');
});

it('answers 404 for a coupon that does not exist', function () {
    couponGet('/admin-api/coupons/999999')->assertStatus(404);
});

/* ------------------------------------------------------------- the guard */

it('refuses every coupon route to a caller who is not signed in', function () {
    expect(CouponsAdminRoutes::paths())->not->toBeEmpty();

    foreach (CouponsAdminRoutes::paths() as [$method, $path]) {
        $response = test()->json($method, $path);

        expect($response->getStatusCode())
            ->toBeIn([401, 403, 419, 302], "{$method} {$path} was not refused");
    }
});

it('refuses every coupon route to a signed-in customer', function () {
    $customer = Customer::create(['name' => 'Shopper', 'email' => 'shopper@example.ae']);

    foreach (CouponsAdminRoutes::paths() as [$method, $path]) {
        $response = test()->actingAs($customer, 'customer')->json($method, $path);

        expect($response->getStatusCode())
            ->toBeIn([401, 403, 419, 302], "{$method} {$path} was not refused for a customer");
    }
});

it('keeps every coupon route behind the admin-api guard stack', function () {
    expect(CouponsAdminRoutes::registered())->not->toBeEmpty();

    foreach (CouponsAdminRoutes::registered() as $route) {
        // toContain() takes the expected values as varargs — a second argument
        // is another needle, not a failure message — so the route is named by
        // asserting on a labelled pair instead.
        $middleware = $route->gatherMiddleware();

        expect([$route->uri(), in_array('auth:admin', $middleware, true)])
            ->toBe([$route->uri(), true]);

        expect([$route->uri(), in_array('web', $middleware, true)])
            ->toBe([$route->uri(), true]);
    }
});

it('never exposes a redemption email outside the admin guard', function () {
    // The reason the guard tests above exist, stated as a fact about the
    // payload: this endpoint really does return customer addresses.
    $coupon = couponWithUsage(['usage_limit' => 10], 1);

    $body = couponGet("/admin-api/coupons/{$coupon->id}")->assertOk()->json();

    expect(json_encode($body))->toContain('shopper0@example.ae');
});
