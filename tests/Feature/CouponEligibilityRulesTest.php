<?php

declare(strict_types=1);

/**
 * A coupon's eligibility rules, on the paths the storefront actually uses.
 *
 * THE DEFECT. Three call sites eager-load the cart's coupon as
 * `coupon:id,code,type,amount` — CartController::loadCart(),
 * Store\CheckoutController::loadCart() and CartDrawerComposer — and hand that
 * instance straight to CouponService::discountFor(). Its eligibility filter
 * reads `product_ids`, `excluded_product_ids`, `category_ids`,
 * `excluded_category_ids` and `exclude_sale_items`, none of which are in that
 * column list, so on that instance every one of them reads null and every line
 * in the basket passes. A code restricted to one product discounted the whole
 * basket, and `exclude_sale_items` did nothing at all.
 *
 * CouponService::lockForRedemption() already documents this exact failure for
 * the usage limits and re-reads the row to defeat it; discountFor() had no such
 * guard, and it is the one that decides how much money leaves the store.
 *
 * These tests go through the checkout, so what they assert is the discount
 * written to `orders.discount_total` — the number the customer actually pays
 * less — rather than the return value of a method called with a row a test
 * hydrated itself.
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

function eligibilityProduct(string $name, int $priceFils, ?int $saleFils = null): Product
{
    return Product::create([
        'slug' => Str::slug($name) . '-' . Str::random(8),
        'name' => $name,
        'status' => 'publish',
        'is_visible' => true,
        'price' => $priceFils,
        'sale_price' => $saleFils,
        'stock_status' => 'instock',
    ]);
}

/** A cart carrying one line per product, at the product's effective price. */
function eligibilityCart(Coupon $coupon, array $products): Cart
{
    $cart = Cart::create([
        'token' => (string) Str::uuid(),
        'currency' => 'AED',
        'status' => 'active',
        'shipping_country' => 'AE',
        'coupon_id' => $coupon->id,
        'last_activity_at' => now(),
    ]);

    foreach ($products as $product) {
        $cart->items()->create([
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => $product->effectivePrice(),
        ]);
    }

    return $cart;
}

function placeEligibilityOrder(Cart $cart)
{
    return test()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token)
        ->post('/checkout/place', [
            'billing_email' => 'buyer@example.com',
            'billing_phone' => '+971500000000',
            'billing_first_name' => 'Aisha',
            'billing_last_name' => 'Khan',
            'billing_address_1' => '12 Marina Walk',
            'billing_city' => 'Dubai',
            'billing_state' => 'Dubai',
            'billing_country' => 'AE',
            'payment_method' => 'cod',
        ]);
}

it('keeps a product-restricted percentage coupon off the rest of the basket', function () {
    $eligible = eligibilityProduct('Eligible Serum', 10000);   // AED 100
    $other = eligibilityProduct('Other Cream', 40000);         // AED 400

    $coupon = Coupon::create([
        'code' => 'SERUM10',
        'type' => 'percent',
        'amount' => 1000,                       // 10%, stored as percent x 100
        'product_ids' => [$eligible->id],
    ]);

    placeEligibilityOrder(eligibilityCart($coupon, [$eligible, $other]));

    // 10% of the AED 100 line only. Ten percent of the whole AED 500 basket
    // would be 5000 fils — AED 40 the store never agreed to give away.
    expect(Order::latest('id')->first()->discount_total)->toBe(1000);
});

it('caps a product-restricted fixed_cart coupon at the eligible lines', function () {
    $eligible = eligibilityProduct('Eligible Balm', 2000);     // AED 20
    $other = eligibilityProduct('Other Mask', 30000);          // AED 300

    $coupon = Coupon::create([
        'code' => 'BALM50',
        'type' => 'fixed_cart',
        'amount' => 5000,                       // AED 50 off
        'product_ids' => [$eligible->id],
    ]);

    placeEligibilityOrder(eligibilityCart($coupon, [$eligible, $other]));

    // discountFor() caps a fixed_cart discount at the ELIGIBLE subtotal, which
    // is the AED 20 line. Capped at the whole basket it would hand over 5000.
    expect(Order::latest('id')->first()->discount_total)->toBe(2000);
});

it('honours excluded_product_ids at checkout', function () {
    $excluded = eligibilityProduct('Excluded Toner', 20000);
    $allowed = eligibilityProduct('Allowed Oil', 10000);

    $coupon = Coupon::create([
        'code' => 'NOTONER',
        'type' => 'percent',
        'amount' => 1000,
        'excluded_product_ids' => [$excluded->id],
    ]);

    placeEligibilityOrder(eligibilityCart($coupon, [$excluded, $allowed]));

    expect(Order::latest('id')->first()->discount_total)->toBe(1000);
});

it('honours exclude_sale_items at checkout', function () {
    $onSale = eligibilityProduct('Clearance Cleanser', 20000, 10000);
    $fullPrice = eligibilityProduct('Full Price Serum', 10000);

    $coupon = Coupon::create([
        'code' => 'NOSALE',
        'type' => 'percent',
        'amount' => 1000,
        'exclude_sale_items' => true,
    ]);

    placeEligibilityOrder(eligibilityCart($coupon, [$onSale, $fullPrice]));

    // Only the full-price AED 100 line is eligible: 1000 fils.
    expect(Order::latest('id')->first()->discount_total)->toBe(1000);
});
