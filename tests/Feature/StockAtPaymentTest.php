<?php

declare(strict_types=1);

/**
 * Lane CM — nothing sold twice, and nobody paid for something that is gone.
 *
 * WHAT WAS WRONG. Both places that put something in a basket check stock at
 * that moment — Store\CartController::add() and Store\CheckoutController::
 * browsedAdd() both refuse anything whose stock_status is not `instock`. The
 * last step, where the money is actually taken, checked nothing at all:
 * place() walked $cart->items and wrote order lines. A shopper who added a
 * product on Monday and paid on Friday bought it whether or not it still
 * existed, and nothing anywhere decremented a counted stock figure, so the
 * shop could sell the same single unit to as many people as reached checkout.
 *
 * THE DECISION, stated once here and implemented in CartService::claimStock():
 * the WHOLE ORDER IS REFUSED, in words, before the gateway is touched. The
 * alternatives were dropping the unavailable line (the shopper pays for a
 * basket they did not agree to) and letting it through with a flag (the shop
 * takes money for something it cannot ship). Both of those move money against
 * an order the customer never confirmed; a refusal costs the sale and leaves
 * the basket intact for them to fix. It is also exactly what the coupon path
 * already does when a code runs out mid-checkout.
 *
 * The race itself — two shoppers, one unit — is in StockRaceTest, which runs
 * two real OS processes for the reason CouponRaceTest's header gives.
 */

use App\Models\Cart;
use App\Models\Order;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\CartService;
use Illuminate\Support\Str;

beforeEach(function () {
    PaymentProvider::query()->delete();
    app(\App\Services\Payments\GatewayCredentials::class)->forget();
    PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0]);

    $zone = ShippingZone::create(['name' => 'UAE', 'position' => 0]);
    ShippingZoneLocation::create(['shipping_zone_id' => $zone->id, 'type' => 'country', 'code' => 'AE']);
    ShippingMethod::create([
        'shipping_zone_id' => $zone->id, 'type' => 'flat_rate',
        'title' => 'Standard delivery', 'cost' => 2000, 'enabled' => true, 'position' => 0,
    ]);
});

function cmProduct(array $overrides = []): Product
{
    return Product::create(array_merge([
        'slug' => 'cm-' . Str::random(8),
        'name' => 'Rice Probiotics Toner',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 13000,
        'stock_status' => 'instock',
    ], $overrides));
}

function cmCart(Product $product, int $qty = 1, ?ProductVariant $variant = null): Cart
{
    $cart = Cart::create([
        'token' => (string) Str::uuid(),
        'currency' => 'AED',
        'status' => 'active',
        'shipping_country' => 'AE',
        'last_activity_at' => now(),
    ]);

    $cart->items()->create([
        'product_id' => $product->id,
        'product_variant_id' => $variant?->id,
        'quantity' => $qty,
        'unit_price' => 13000,
    ]);

    return $cart;
}

function cmPlace(Cart $cart, array $overrides = [])
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

/** The first error sentence the shopper was actually shown. */
function cmError($response): string
{
    $errors = $response->baseResponse->getSession()->get('errors');

    return $errors ? (string) $errors->first() : '';
}

/*
|------------------------------------------------------------------------------
| 1. A product that sold out while the basket was sitting there
|------------------------------------------------------------------------------
*/

it('refuses the order when a line sold out between adding to the basket and paying', function () {
    $product = cmProduct();
    $cart = cmCart($product);

    // The owner marks it sold out while the shopper is on the checkout page.
    // This is the common shape in this shop: stock is not counted, the status
    // is flipped by hand in Store -> Products.
    $product->forceFill(['stock_status' => 'outofstock'])->save();

    $response = cmPlace($cart);

    expect(Order::count())->toBe(0, 'An order was written for a product that is sold out.');

    $message = cmError($response);

    expect(str_contains($message, 'Rice Probiotics Toner'))
        ->toBeTrue('The refusal does not name the product that is gone: ' . $message);

    expect(str_contains(strtolower($message), 'sold out'))
        ->toBeTrue('The shopper is not told, in words, why the order was refused: ' . $message);
});

it('leaves the basket intact so the shopper can remove the line and try again', function () {
    $product = cmProduct();
    $cart = cmCart($product);
    $product->forceFill(['stock_status' => 'outofstock'])->save();

    cmPlace($cart);

    $cart->refresh();

    expect($cart->status)->toBe('active', 'A refused placement converted the cart, so the basket is gone.');
    expect($cart->items()->count())->toBe(1, 'A refused placement emptied the basket.');
});

/*
|------------------------------------------------------------------------------
| 2. Counted stock — the number, not just the flag
|------------------------------------------------------------------------------
*/

it('refuses an order for more units than are actually left', function () {
    $product = cmProduct(['manage_stock' => true, 'stock' => 2]);
    $cart = cmCart($product, 3);

    $response = cmPlace($cart);

    expect(Order::count())->toBe(0, 'The shop sold three of a product it has two of.');

    $message = cmError($response);

    expect(str_contains($message, '2'))
        ->toBeTrue('The refusal does not say how many are left: ' . $message);
});

it('takes the units off the shelf when the order is placed', function () {
    $product = cmProduct(['manage_stock' => true, 'stock' => 5]);

    cmPlace(cmCart($product, 2));

    expect(Order::count())->toBe(1, 'A placeable order was refused.');
    expect((int) $product->fresh()->stock)->toBe(3, 'Stock was not reduced by the order — the same units can be sold again.');
});

it('marks a counted product sold out when the last unit goes', function () {
    // Otherwise the shop goes on advertising it as in stock and every later
    // checkout fails at the very last step, which is the sale-losing churn
    // this whole change exists to stop.
    $product = cmProduct(['manage_stock' => true, 'stock' => 1]);

    cmPlace(cmCart($product, 1));

    $product->refresh();

    expect((int) $product->stock)->toBe(0);
    expect($product->stock_status)->toBe('outofstock', 'The shelf is empty and the storefront still says in stock.');
});

it('counts the same product across two basket lines as one demand on the shelf', function () {
    // Two lines can hold the same product — a variant line and a plain one, or
    // the same product added through two different paths. Checking each line
    // against the shelf separately lets a basket of 1 + 1 pass against a single
    // remaining unit.
    $product = cmProduct(['manage_stock' => true, 'stock' => 1]);
    $cart = cmCart($product, 1);

    $cart->items()->create([
        'product_id' => $product->id,
        'product_variant_id' => null,
        'quantity' => 1,
        'unit_price' => 13000,
    ]);

    cmPlace($cart);

    expect(Order::count())->toBe(0, 'Two lines of one unit each bought the same single unit twice.');
});

/*
|------------------------------------------------------------------------------
| 3. Products that do not count stock are unaffected
|------------------------------------------------------------------------------
*/

it('sells a product that does not count stock exactly as before', function () {
    $product = cmProduct(['manage_stock' => false, 'stock' => null]);

    cmPlace(cmCart($product, 4));

    expect(Order::count())->toBe(1, 'A product with no counted stock was refused.');
    expect($product->fresh()->stock)->toBeNull('An uncounted product had a stock figure invented for it.');
    expect($product->fresh()->stock_status)->toBe('instock');
});

it('does not drive an uncounted product negative or sold out', function () {
    // manage_stock off with a leftover number in the column — the WooCommerce
    // import leaves plenty of these. The number means nothing and must not be
    // touched.
    $product = cmProduct(['manage_stock' => false, 'stock' => 1]);

    cmPlace(cmCart($product, 9));

    expect(Order::count())->toBe(1);
    expect((int) $product->fresh()->stock)->toBe(1, 'A stock figure that is not being counted was decremented anyway.');
    expect($product->fresh()->stock_status)->toBe('instock');
});

/*
|------------------------------------------------------------------------------
| 4. Variants carry their own shelf
|------------------------------------------------------------------------------
*/

it('refuses a variant line whose own stock has run out', function () {
    $product = cmProduct(['type' => 'variable', 'manage_stock' => false]);

    $variant = ProductVariant::create([
        'product_id' => $product->id, 'sku' => 'S-50', 'price' => 13000,
        'stock_status' => 'instock', 'manage_stock' => true, 'stock' => 1, 'position' => 0,
    ]);

    cmPlace(cmCart($product, 2, $variant));

    expect(Order::count())->toBe(0, 'Two of a size the shop has one of were sold.');
});

it('takes a variant sale off the variant shelf, not the parent', function () {
    $product = cmProduct(['type' => 'variable', 'manage_stock' => true, 'stock' => 10]);

    $variant = ProductVariant::create([
        'product_id' => $product->id, 'sku' => 'S-50', 'price' => 13000,
        'stock_status' => 'instock', 'manage_stock' => true, 'stock' => 4, 'position' => 0,
    ]);

    cmPlace(cmCart($product, 2, $variant));

    expect(Order::count())->toBe(1);
    expect((int) $variant->fresh()->stock)->toBe(2, 'The variant shelf was not reduced.');
    expect((int) $product->fresh()->stock)->toBe(10, 'A variant sale was taken off the parent product as well — double counted.');
});

/*
|------------------------------------------------------------------------------
| 5. The refusal happens before any money moves
|------------------------------------------------------------------------------
*/

it('does not spend the shopper coupon use on an order it is about to refuse', function () {
    $coupon = \App\Models\Coupon::create([
        'code' => 'LASTONE', 'type' => 'fixed_cart', 'amount' => 500, 'usage_limit' => 1, 'usage_count' => 0,
    ]);

    $product = cmProduct(['manage_stock' => true, 'stock' => 0, 'stock_status' => 'instock']);
    $cart = cmCart($product, 1);
    $cart->forceFill(['coupon_id' => $coupon->id])->save();

    cmPlace($cart);

    expect(Order::count())->toBe(0);
    expect((int) $coupon->fresh()->usage_count)->toBe(0, 'The refused order burned the shopper\'s one use of the code.');
});

it('never reaches the payment gateway when a line is gone', function () {
    // A COD order that got as far as the gateway would have been emailed and
    // handed to the courier. The order row is the proof: there is none.
    $product = cmProduct(['stock_status' => 'outofstock']);

    cmPlace(cmCart($product, 1));

    expect(Order::count())->toBe(0);
});
