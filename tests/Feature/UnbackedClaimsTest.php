<?php

declare(strict_types=1);

/**
 * Lane CM — every promise on the page is backed by something the shop records.
 *
 * Three claims were printed at shoppers with nothing behind them:
 *
 *   1. "Fast UAE delivery" and "Easy 14-day returns" on the product page, both
 *      literals in the Blade. No returns window is recorded anywhere in this
 *      application, and the delivery one is shown to a Gulf shopper as readily
 *      as to a UAE one — the very claim Lane CF removed from the checkout, and
 *      App\Mail\OrderStatusChanged removed from the dispatch email, for being
 *      the wrong promise.
 *   2. "4.8 · loved by 2,300+ UAE customers" as the DEFAULT of
 *      `reassure_rating_text`, printed at the moment of payment on a shop with
 *      no reviews at all. This is the same class of defect as the one
 *      RatingsTellTheTruthTest exists for: an invented rating figure speaking
 *      over the real review data.
 *   3. GLOW30 as the DEFAULT of `checkout_coupon`, offered as a clickable
 *      badge. A shop with no such coupon advertised a 30% discount that was
 *      refused the instant it was tapped.
 *
 * Nothing is invented to replace any of them — that is the same restraint the
 * Gulf delivery window got. Each is either driven by something real, or it is
 * not shown.
 *
 * THE CLASS-NAME TRAP. kbb-checkout.css is inlined into the checkout document,
 * so a bare search for `kr-line` or `hint` matches a stylesheet rule whether or
 * not any element carries the class. Every assertion here either matches an
 * ELEMENT with an anchored regex or looks for the words a shopper would read.
 */

use App\Models\Cart;
use App\Models\Coupon;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\Review;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\CartService;
use App\Services\SettingsService;
use Illuminate\Support\Str;

beforeEach(function () {
    PaymentProvider::query()->delete();
    app(\App\Services\Payments\GatewayCredentials::class)->forget();
    PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0]);

    $zone = ShippingZone::create(['name' => 'UAE', 'position' => 0]);
    ShippingZoneLocation::create(['shipping_zone_id' => $zone->id, 'type' => 'country', 'code' => 'AE']);
    ShippingMethod::create([
        'shipping_zone_id' => $zone->id, 'type' => 'flat_rate',
        'title' => 'Delivery Charges', 'cost' => 2000, 'enabled' => true, 'position' => 0,
    ]);
});

/** Settings only ever through the service — it holds a forever-cache AND a per-process memo. */
function clSet(string $key, $value): void
{
    app(SettingsService::class)->set($key, $value);
}

function clProduct(array $overrides = []): Product
{
    return Product::create(array_merge([
        'slug' => 'cl-' . Str::random(8),
        'name' => 'Rice Probiotics Toner',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 13000,
        'stock_status' => 'instock',
    ], $overrides));
}

function clCheckout()
{
    $cart = Cart::create([
        'token' => (string) Str::uuid(),
        'currency' => 'AED',
        'status' => 'active',
        'shipping_country' => 'AE',
        'last_activity_at' => now(),
    ]);

    $cart->items()->create([
        'product_id' => clProduct()->id,
        'quantity' => 1,
        'unit_price' => 13000,
    ]);

    return test()
        ->withCredentials()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token)
        ->get('/checkout/')->assertOk()->getContent();
}

/** N approved reviews, all at the same score. */
function clReviews(int $count, int $stars = 5): void
{
    for ($i = 0; $i < $count; $i++) {
        Review::create([
            'product_id' => null,
            'author_name' => 'Shopper ' . $i,
            'rating' => $stars,
            'content' => 'Genuine',
            'status' => 'approved',
        ]);
    }
}

/*
|------------------------------------------------------------------------------
| 1. The product page's trust row
|------------------------------------------------------------------------------
*/

it('does not promise a delivery speed or a returns window the shop has not recorded', function () {
    $product = clProduct();

    $html = test()->get($product->url())->assertOk()->getContent();

    expect(str_contains($html, 'Fast UAE delivery'))
        ->toBeFalse('The product page promises UAE delivery speed to every shopper, Gulf ones included, with nothing recording it.');

    expect(str_contains($html, '14-day returns'))
        ->toBeFalse('The product page promises a 14-day returns window that is recorded nowhere in this application.');
});

it('shows a delivery and returns promise the owner has actually written', function () {
    /*
     * LANE CT MOVED THE DELIVERY HALF OF THIS, and the reason is worth keeping
     * here beside the case it changes.
     *
     * `trust_delivery_text` fixed "nothing records this claim" and left "shown
     * to the wrong country" exactly where it was: one global string with no
     * country check, printed at every visitor on earth the moment the owner
     * typed a UAE sentence into it. A single global string cannot be made
     * country-aware — it can only ever be true of one country and the shop
     * cannot know which — so the chip now reads the per-country wording through
     * App\Support\DeliveryLine, the same one reader the home page and the
     * checkout use, and the global setting is gone.
     *
     * What this case asserts is unchanged: a delivery promise the owner
     * actually wrote reaches the page. Only the screen he writes it on moved,
     * from Store → Ecommerce to Store → Delivery & Shipping → Delivery lines.
     * The country-aware half is pinned in ProductPagePromisesTest.
     */
    clSet(\App\Support\DeliveryLine::SETTING, [['country' => 'AE', 'text' => '1–3 day delivery in the UAE']]);
    clSet('trust_returns_text', 'Easy 14-day returns');

    $html = test()->withHeader('CF-IPCountry', 'AE')->get(clProduct()->url())->assertOk()->getContent();

    expect(str_contains($html, '1–3 day delivery in the UAE'))
        ->toBeTrue('The owner wrote a delivery promise and the page did not show it.');

    expect(str_contains($html, 'Easy 14-day returns'))
        ->toBeTrue('The owner wrote a returns promise and the page did not show it.');
});

it('keeps the claims the shop does back', function () {
    // Authenticity and the pay-later methods are not delivery promises: the
    // first is what this shop is, the second is read off the gateways. Removing
    // the whole row would have been its own defect.
    $html = test()->get(clProduct()->url())->assertOk()->getContent();

    expect(str_contains($html, '100% authentic'))->toBeTrue('The authenticity claim went with the unbacked ones.');
});

/*
|------------------------------------------------------------------------------
| 2. The rating line at the moment of payment
|------------------------------------------------------------------------------
*/

it('does not invent a rating at checkout when the shop has no reviews', function () {
    $html = clCheckout();

    expect(str_contains($html, '2,300+'))
        ->toBeFalse('The checkout still prints the invented "loved by 2,300+ UAE customers" line.');

    expect((bool) preg_match('/<div[^>]*class="[^"]*\bkr-line\b[^"]*"[^>]*>\s*<span[^>]*class="[^"]*\bkr-stars\b/', $html))
        ->toBeFalse('A star rating rendered at the payment step with no reviews behind it.');
});

it('does not print a rating built on a handful of reviews', function () {
    clReviews(2, 5);

    $html = clCheckout();

    expect((bool) preg_match('/<div[^>]*class="[^"]*\bkr-line\b[^"]*"[^>]*>\s*<span[^>]*class="[^"]*\bkr-stars\b/', $html))
        ->toBeFalse('Two reviews were presented as a shop-wide score at the payment step.');
});

it('prints the real average and the real count once there are enough reviews', function () {
    // Eight fives and two fours: 4.8, which is what the invented line claimed
    // and is now true.
    clReviews(8, 5);
    clReviews(2, 4);

    $html = clCheckout();

    expect(str_contains($html, '4.8 from 10 reviews'))
        ->toBeTrue('The real shop-wide average and count are not what the payment step shows.');

    expect((bool) preg_match('/<div[^>]*class="[^"]*\bkr-line\b[^"]*"[^>]*>\s*<span[^>]*class="[^"]*\bkr-stars\b/', $html))
        ->toBeTrue('The stars capsule did not render for a shop with real reviews.');
});

it('counts only approved reviews in the checkout rating', function () {
    clReviews(10, 5);

    Review::query()->limit(6)->update(['status' => 'pending']);

    $html = clCheckout();

    expect((bool) preg_match('/<div[^>]*class="[^"]*\bkr-line\b[^"]*"[^>]*>\s*<span[^>]*class="[^"]*\bkr-stars\b/', $html))
        ->toBeFalse('Unapproved reviews were counted towards the figure shown at the payment step.');
});

/*
|------------------------------------------------------------------------------
| 3. The discount code the checkout offers
|------------------------------------------------------------------------------
*/

it('does not advertise a discount code the shop does not have', function () {
    $html = clCheckout();

    expect(str_contains($html, 'GLOW30'))
        ->toBeFalse('The checkout still advertises GLOW30, a code this shop has never had.');

    // `class="hint"` exactly. `\bhint\b` inside a class list also matches
    // `mnav-hint`, which partials/drawers.blade.php renders on every page — the
    // class-name trap one turn further in than usual.
    expect((bool) preg_match('/<div[^>]*class="hint"/', $html))
        ->toBeFalse('The coupon hint rendered with no coupon behind it.');
});

it('advertises a code the owner has set that really exists', function () {
    Coupon::create(['code' => 'GLOW30', 'type' => 'percent', 'amount' => 3000, 'usage_count' => 0]);
    clSet('checkout_coupon', 'GLOW30');

    $html = clCheckout();

    expect((bool) preg_match('/<b[^>]*data-code="GLOW30"/', $html))
        ->toBeTrue('A real, usable code the owner configured is not offered.');

    expect(str_contains($html, '30% off'))
        ->toBeTrue("The hint does not say what the code is actually worth.");
});

it('says what the code is really worth rather than a figure from the template', function () {
    // The default wording said "30% off" whatever the coupon was set to, so a
    // 10% code advertised three times its value.
    Coupon::create(['code' => 'GLOW10', 'type' => 'percent', 'amount' => 1000, 'usage_count' => 0]);
    clSet('checkout_coupon', 'GLOW10');

    $html = clCheckout();

    expect(str_contains($html, '10% off'))->toBeTrue('The hint misstates the discount.');
    expect(str_contains($html, '30% off'))->toBeFalse('The hint advertises 30% off a 10% code.');
});

it('stops advertising a code that has expired', function () {
    Coupon::create([
        'code' => 'GONE30', 'type' => 'percent', 'amount' => 3000,
        'usage_count' => 0, 'expires_at' => now()->subDay(),
    ]);
    clSet('checkout_coupon', 'GONE30');

    $html = clCheckout();

    expect(str_contains($html, 'GONE30'))
        ->toBeFalse('An expired code is still offered at the payment step.');
});

it('stops advertising a code that has been fully redeemed', function () {
    Coupon::create([
        'code' => 'USEDUP', 'type' => 'percent', 'amount' => 3000,
        'usage_limit' => 5, 'usage_count' => 5,
    ]);
    clSet('checkout_coupon', 'USEDUP');

    $html = clCheckout();

    expect(str_contains($html, 'USEDUP'))
        ->toBeFalse('A fully redeemed code is still offered at the payment step.');
});

it('stops advertising a code that has not started yet', function () {
    Coupon::create([
        'code' => 'SOON30', 'type' => 'percent', 'amount' => 3000,
        'usage_count' => 0, 'starts_at' => now()->addWeek(),
    ]);
    clSet('checkout_coupon', 'SOON30');

    $html = clCheckout();

    expect(str_contains($html, 'SOON30'))
        ->toBeFalse('A code that is not active yet is offered at the payment step.');
});

it('still lets the owner write their own wording for a real code', function () {
    Coupon::create(['code' => 'GLOW30', 'type' => 'percent', 'amount' => 3000, 'usage_count' => 0]);
    clSet('checkout_coupon', 'GLOW30');
    clSet('checkout_coupon_text', 'Add {code} for a treat ✨');

    $html = clCheckout();

    expect(str_contains($html, 'Add'))->toBeTrue('The owner’s own hint wording was ignored.');
    expect((bool) preg_match('/<b[^>]*data-code="GLOW30"/', $html))->toBeTrue();
});

/*
|------------------------------------------------------------------------------
| 3b. The same code, on the cart page and in the drawer every page carries
|------------------------------------------------------------------------------
*/

/**
 * The cart page, with one line in the bag and the discount field switched on.
 *
 * `cart_coupon_field` is off by default (CartPageLayoutTest pins that), and the
 * hint lives inside it — so without this the assertions below would pass
 * against a page that renders no coupon block at all, which is a green light
 * that means nothing.
 */
function clCart()
{
    app(SettingsService::class)->setModule('cart_coupon_field', true);

    $cart = Cart::create([
        'token' => (string) Str::uuid(),
        'currency' => 'AED',
        'status' => 'active',
        'shipping_country' => 'AE',
        'last_activity_at' => now(),
    ]);

    $cart->items()->create([
        'product_id' => clProduct()->id,
        'quantity' => 1,
        'unit_price' => 13000,
    ]);

    return test()
        ->withCredentials()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token)
        ->get('/cart/')->assertOk()->getContent();
}

it('does not advertise a code the shop does not have on the cart page either', function () {
    // CartController::payload() carried its own copy of the same literal, and
    // it feeds the mini-cart drawer the layout renders on every page.
    $html = clCart();

    expect(str_contains($html, 'GLOW30'))
        ->toBeFalse('The cart still advertises GLOW30, a code this shop has never had.');

    expect(str_contains($html, 'extra 30% off'))
        ->toBeFalse('The cart advertises a 30% discount with no coupon behind it.');
});

it('advertises a real code on the cart page, worth what it is worth', function () {
    Coupon::create(['code' => 'GLOW15', 'type' => 'percent', 'amount' => 1500, 'usage_count' => 0]);
    clSet('checkout_coupon', 'GLOW15');

    $html = clCart();

    expect((bool) preg_match('/<b[^>]*data-code="GLOW15"/', $html))
        ->toBeTrue('A real, usable code is not offered on the cart page.');

    expect(str_contains($html, '15% off'))->toBeTrue('The cart misstates what the code is worth.');
});

/*
|------------------------------------------------------------------------------
| 4. The order-received page says when the parcel arrives
|------------------------------------------------------------------------------
|
| It showed the RATE NAME — "Free delivery" — and nothing about when. The shop
| already records a window per country in `delivery_texts`, with
| `delivery_default_text` for the store's own country, and the checkout has been
| showing it under Place order all along. Showing the recorded line for the
| order's own destination invents nothing: a country with no line recorded gets
| no line, exactly as at checkout.
*/

function clOrder(array $overrides = [])
{
    $order = \App\Models\Order::create(array_merge([
        'order_number' => '91001',
        'email' => 'buyer@example.com',
        'status' => 'processing',
        'currency' => 'AED',
        'billing_address' => ['first_name' => 'Layla', 'last_name' => 'Hassan', 'line1' => '12 Marina Walk', 'city' => 'Dubai', 'state' => 'Dubai', 'country' => 'AE'],
        'shipping_address' => ['first_name' => 'Layla', 'last_name' => 'Hassan', 'line1' => '12 Marina Walk', 'city' => 'Dubai', 'state' => 'Dubai', 'country' => 'AE'],
        'subtotal' => 13000, 'discount_total' => 0, 'shipping_total' => 0,
        'fee_total' => 0, 'tax_total' => 0, 'total' => 13000,
        'shipping_method' => 'Free delivery',
        'payment_method' => 'cod',
        'payment_method_title' => 'Cash on delivery',
    ], $overrides));

    return test()
        ->withSession(['kbb_orders_viewable' => [$order->order_number]])
        ->get('/checkout/success?order=' . $order->order_number)
        ->assertOk()->getContent();
}

it('tells a UAE customer when their parcel arrives, not just what the rate is called', function () {
    $html = clOrder();

    expect(str_contains($html, 'Free delivery'))->toBeTrue('fixture lost its rate name');

    expect(str_contains($html, '1–3 days fast delivery all over UAE'))
        ->toBeTrue('The confirmation names the rate and never says when the parcel arrives.');
});

it('uses the window the owner recorded for the destination country', function () {
    clSet('delivery_texts', [['country' => 'SA', 'text' => '5-8 days to Saudi Arabia']]);

    $html = clOrder([
        'order_number' => '91002',
        'shipping_address' => ['first_name' => 'Noura', 'last_name' => 'Al Otaibi', 'line1' => '4 King Fahd Rd', 'city' => 'Riyadh', 'state' => 'Riyadh', 'country' => 'SA'],
    ]);

    expect(str_contains($html, '5-8 days to Saudi Arabia'))
        ->toBeTrue('The recorded window for the destination was not used.');
});

it('does not promise a UAE window to a Gulf customer on the confirmation either', function () {
    // The same restraint deliveryText() already applies at checkout: no Gulf
    // window has been measured, so nothing is said.
    $html = clOrder([
        'order_number' => '91003',
        'shipping_address' => ['first_name' => 'Noura', 'last_name' => 'Al Otaibi', 'line1' => '4 King Fahd Rd', 'city' => 'Riyadh', 'state' => 'Riyadh', 'country' => 'SA'],
    ]);

    expect(str_contains($html, 'all over UAE'))
        ->toBeFalse('The confirmation promises a UAE delivery window to a customer in Saudi Arabia.');
});

it('does not print the owner wording when the code behind it has gone', function () {
    // The wording is about the code. With no usable coupon there is nothing for
    // it to be about, and the sentence on its own is still an advertised
    // discount.
    clSet('checkout_coupon', 'GLOW30');
    clSet('checkout_coupon_text', 'Need more discount? Try {code} for 30% off ✨');

    $html = clCheckout();

    expect(str_contains($html, 'Need more discount'))
        ->toBeFalse('The hint sentence survives the coupon it advertises.');
});
