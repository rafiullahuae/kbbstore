<?php

/**
 * One tap on Add in the checkout's Browsed tab.
 *
 * The bug class this pins is this codebase's signature one, and it has shipped
 * twice: a handler that is wired up, runs, and changes nothing on screen. The
 * previous incarnation here was literal — checkout.js looked for `.badd` with
 * `data-add`, the markup rendered `.baddbtn` with `data-kbb-add`, so the
 * selector matched nothing and what actually added the product was cart.js's
 * global listener, which opens the drawer.
 *
 * So these tests assert the CONTENT of what comes back, not that a 200 came
 * back: the summary lines, the totals, the payment options, the mobile bag
 * strip with its free-delivery bar, and the Browsed list with its count. Four
 * regions, one request, and if any of them stood still the assertion fails.
 */

use App\Models\Cart;
use App\Models\ModuleToggle;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\CartService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    PaymentProvider::query()->delete();
    app(\App\Services\Payments\GatewayCredentials::class)->forget();

    /*
     * The route lives in routes/checkout-browsed.php, which the integrator
     * wires into routes/web.php (lanes do not edit web.php directly). Register
     * it here when that line is not in place yet, so the endpoint is testable
     * either way and these tests keep passing once it is.
     */
    if (! Route::has('checkout.browsedAdd')) {
        Route::middleware('web')->group(base_path('routes/checkout-browsed.php'));
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

/**
 * products.price is FILS, cast to int — not a decimal number of dirhams.
 * It matters here in a way it does not in CheckoutPlacementTest, which writes
 * unit_price onto the line by hand: this endpoint goes through
 * CartService::add(), which prices the new line from the catalogue.
 */
function browsedProduct(string $name, int $priceFils, string $stock = 'instock', bool $visible = true): Product
{
    return Product::create([
        'slug' => Illuminate\Support\Str::slug($name) . '-' . uniqid(),
        'name' => $name,
        'status' => $visible ? 'publish' : 'draft',
        'is_visible' => $visible,
        'price' => $priceFils,
        'stock_status' => $stock,
    ]);
}

/** A checkout-ready cart: one line, so page() does not redirect to /cart/. */
function browsedCart(int $unitPriceFils = 20000): Cart
{
    $cart = Cart::create([
        'token' => (string) Illuminate\Support\Str::uuid(),
        'currency' => 'AED',
        'status' => 'active',
        'shipping_country' => 'AE',
        'last_activity_at' => now(),
    ]);

    $cart->items()->create([
        'product_id' => browsedProduct('In The Bag Already', $unitPriceFils)->id,
        'quantity' => 1,
        'unit_price' => $unitPriceFils,
    ]);

    return $cart;
}

/**
 * A browser carrying this cart's cookie and a viewed-products cookie.
 *
 * EncryptCookies is disabled for the same reason CheckoutPlacementTest
 * disables it: a plain token handed to that middleware is decrypted, fails and
 * is dropped, leaving the controller with no cart at all.
 */
function browsedShopper(Cart $cart, array $viewedIds = [])
{
    $call = test()
        /*
         * withCredentials() is not decoration. postJson() sends NO cookies at
         * all unless it is set — prepareCookiesForJsonRequest() returns [] —
         * because it models a cross-origin fetch. The real handler is a
         * same-origin fetch(), which sends them by default, so without this the
         * endpoint is tested against a browser nobody has: every call arrives
         * with no cart cookie and answers "your bag is empty", which looks
         * exactly like a broken endpoint.
         */
        ->withCredentials()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token);

    return $viewedIds === []
        ? $call
        : $call->withUnencryptedCookie('kbb_viewed', implode(',', $viewedIds));
}

function browsedAdd(Cart $cart, array $viewedIds, array $body)
{
    return browsedShopper($cart, $viewedIds)->postJson('/checkout/browsed-add', $body);
}

/**
 * A second, real gateway beside Cash on delivery.
 *
 * GatewayRegistry only offers a gateway that answers configured(), and Tabby
 * answers that by looking for its keys — so without a config the "COD was
 * withdrawn, here is what is selected instead" case has nothing to fall back
 * to and cannot be tested at all.
 */
function browsedTabbyProvider(): void
{
    PaymentProvider::create([
        'id' => 'tabby',
        'title' => 'Tabby',
        'enabled' => true,
        'mode' => 'test',
        'position' => 1,
        'config' => ['public_key' => 'pk_test_x', 'secret_key' => 'sk_test_x', 'merchant_code' => 'AE'],
    ]);

    app(\App\Services\Payments\GatewayCredentials::class)->forget();
}

/** Turn Payment & Shipping Rules on with a Cash-on-delivery ceiling. */
function codCeiling(int $maxFils): void
{
    ModuleToggle::updateOrCreate(['module' => 'pay_ship_rules'], ['enabled' => true]);
    app(SettingsService::class)->setModuleSetting('pay_ship_rules', 'cod_max', $maxFils);
    app(SettingsService::class)->flush();
    app()->forgetInstance(\App\Services\PayShipRules::class);
}

it('puts the browsed product in the cart and returns the re-rendered summary and totals', function () {
    $cart = browsedCart(20000);
    $serum = browsedProduct('Snail Mucin Serum', 5000);

    $before = $cart->items()->count();

    $response = browsedAdd($cart, [$serum->id], ['product_id' => $serum->id, 'country' => 'AE']);

    $response->assertOk()->assertJson(['ok' => true, 'productId' => $serum->id]);

    // The line is really in the cart, not merely reported as added.
    expect($cart->items()->count())->toBe($before + 1)
        ->and($cart->items()->where('product_id', $serum->id)->value('quantity'))->toBe(1);

    $json = $response->json();

    // The summary carries the new line...
    expect($json['itemsHtml'])->toContain('Snail Mucin Serum')
        ->and($json['count'])->toBe(2);

    // ...and the totals moved with it: 200.00 + 50.00 goods, 20.00 delivery.
    expect($json['orderHtml'])
        ->toContain(\App\Support\Money::format(25000))
        ->toContain(\App\Support\Money::format(27000));
});

it('increments the quantity when the same product is added twice, rather than duplicating the line', function () {
    $cart = browsedCart(20000);
    $serum = browsedProduct('Snail Mucin Serum', 5000);

    browsedAdd($cart, [$serum->id], ['product_id' => $serum->id])->assertOk();
    $second = browsedAdd($cart, [$serum->id], ['product_id' => $serum->id]);

    $second->assertOk()->assertJson(['ok' => true]);

    expect($cart->items()->where('product_id', $serum->id)->count())->toBe(1)
        ->and($cart->items()->where('product_id', $serum->id)->value('quantity'))->toBe(2)
        // Two units of 50.00 on top of the 200.00 already there.
        ->and($second->json('count'))->toBe(3);

    /*
     * Derived from the line the server actually wrote, not from 2 × the shelf
     * price. CartService::add() reprices the WHOLE line through BundleService
     * when the quantity moves, so a second unit can cost less than the first —
     * which is exactly why the browser is never allowed to do this sum. The
     * assertion is that the summary shows the server's figure.
     */
    $line = $cart->items()->where('product_id', $serum->id)->first();
    $subtotal = 20000 + ((int) $line->unit_price * 2);

    expect($second->json('orderHtml'))->toContain(\App\Support\Money::format($subtotal))
        // And it moved: the single-unit subtotal is no longer on the page.
        ->and($second->json('orderHtml'))->not->toContain(\App\Support\Money::format(25000));
});

it('refuses a sold-out product with a reason and leaves the row in the Browsed list', function () {
    $cart = browsedCart(20000);
    $gone = browsedProduct('Sold Out Essence', 5000, stock: 'outofstock');

    $response = browsedAdd($cart, [$gone->id], ['product_id' => $gone->id]);

    $response->assertStatus(422)
        ->assertJson(['ok' => false, 'error' => 'That product is sold out.']);

    // Nothing was added, so nothing may be removed from the list either. The
    // client only ever swaps the list on an ok:true, and there is none here.
    expect($cart->items()->where('product_id', $gone->id)->count())->toBe(0)
        ->and($response->json('browsedHtml'))->toBeNull();
});

it('refuses a product that is no longer visible', function () {
    $cart = browsedCart(20000);
    $hidden = browsedProduct('Discontinued Toner', 5000, visible: false);

    browsedAdd($cart, [$hidden->id], ['product_id' => $hidden->id])
        ->assertStatus(404)
        ->assertJson(['ok' => false, 'error' => 'That product is no longer available.']);

    expect($cart->items()->where('product_id', $hidden->id)->count())->toBe(0);
});

it('withdraws cash on delivery when the add carries the total past the window, and says so', function () {
    PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0]);
    browsedTabbyProvider();

    // 200.00 goods + 20.00 delivery = 220.00, inside a 300.00 ceiling. One more
    // 150.00 product takes the order to 370.00, outside it.
    codCeiling(30000);

    $cart = browsedCart(20000);
    $big = browsedProduct('Big Ticket Cream', 15000);

    // Before: the checkout page itself offers Cash on delivery.
    browsedShopper($cart, [$big->id])->get('/checkout')
        ->assertOk()
        ->assertSee('payment_method_cod', false);

    $response = browsedAdd($cart, [$big->id], [
        'product_id' => $big->id,
        'country' => 'AE',
        'payment_method' => 'cod',
    ]);

    $response->assertOk();
    $json = $response->json();

    // The list that comes back no longer offers it...
    expect($json['paymentHtml'])->not->toContain('value="cod"')
        // ...it says why, in the merchant's own sentence...
        ->and($json['paymentHtml'])->toContain('Cash on delivery is not available on orders over')
        // ...and it says what is selected instead, rather than moving the
        // radio under the shopper without a word.
        ->and($json['payNotice'])->toContain('no longer available')
        ->and($json['payNotice'])->toContain('Tabby')
        ->and($json['paymentHtml'])->toContain('value="tabby" checked');
});

it('keeps the chosen payment method checked when the new total still allows it', function () {
    PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0]);
    browsedTabbyProvider();

    $cart = browsedCart(20000);
    $serum = browsedProduct('Snail Mucin Serum', 5000);

    $json = browsedAdd($cart, [$serum->id], [
        'product_id' => $serum->id,
        'payment_method' => 'tabby',
    ])->assertOk()->json();

    // Not the first option in the list — the one they picked.
    expect($json['paymentHtml'])->toContain('value="tabby" checked')
        ->and($json['paymentHtml'])->not->toContain('value="cod" checked')
        ->and($json['payNotice'])->toBeNull();
});

it('moves the summary, the mobile bag strip and the free-delivery bar together', function () {
    // Free delivery at 400.00, so a cart of 200.00 sits at 50% and one more
    // 150.00 product takes it to 350.00 — 88%, still short.
    $zone = ShippingZone::first();
    ShippingMethod::create([
        'shipping_zone_id' => $zone->id,
        'type' => 'free_shipping',
        'title' => 'Free delivery',
        'cost' => 0,
        'min_amount' => 40000,
        'enabled' => true,
        'position' => 1,
    ]);

    $cart = browsedCart(20000);
    $cream = browsedProduct('Barrier Cream', 15000);

    $json = browsedAdd($cart, [$cream->id], ['product_id' => $cream->id, 'country' => 'AE'])
        ->assertOk()->json();

    // 1. the summary lines
    expect($json['itemsHtml'])->toContain('Barrier Cream');

    // 2. the mobile bag strip: a thumbnail per line, and the item count in words
    expect(substr_count($json['thumbsHtml'], 'class="kthumb"'))->toBe(2)
        ->and($json['thumbsHtml'])->toContain('2 items');

    // 3. the free-delivery bar, which opens the order block, has advanced off
    //    the 50% the page loaded with. This is the one most likely to be
    //    missed and the most visible when wrong.
    expect($json['orderHtml'])->toContain('width:88%')
        ->and($json['orderHtml'])->not->toContain('width:50%')
        ->and($json['orderHtml'])->toContain(\App\Support\Money::format(5000)); // 50.00 to go

    // 4. the cart count
    expect($json['count'])->toBe(2);
});

it('says free delivery is unlocked in every region at once when the add crosses the threshold', function () {
    $zone = ShippingZone::first();
    ShippingMethod::create([
        'shipping_zone_id' => $zone->id,
        'type' => 'free_shipping',
        'title' => 'Free delivery',
        'cost' => 0,
        'min_amount' => 25000,
        'enabled' => true,
        'position' => 1,
    ]);

    $cart = browsedCart(20000);
    $serum = browsedProduct('Snail Mucin Serum', 6000);

    $json = browsedAdd($cart, [$serum->id], ['product_id' => $serum->id, 'country' => 'AE'])
        ->assertOk()->json();

    // A bar left at 80% while the summary says Free is worse than one that
    // never moved, so both halves are asserted from the same response.
    expect($json['orderHtml'])
        ->toContain('is-unlocked')
        ->toContain("You've unlocked free delivery")
        ->toContain('width:100%')
        ->toContain('Free');
});

it('drops the product from the Browsed list and its count badge', function () {
    $cart = browsedCart(20000);
    $one = browsedProduct('First Browsed', 5000);
    $two = browsedProduct('Second Browsed', 5000);

    $json = browsedAdd($cart, [$one->id, $two->id], ['product_id' => $one->id])
        ->assertOk()->json();

    expect($json['browsedCount'])->toBe(1)
        ->and($json['browsedHtml'])->not->toContain('First Browsed')
        ->and($json['browsedHtml'])->toContain('Second Browsed')
        // The row that remains still carries the hook the handler binds.
        ->and($json['browsedHtml'])->toContain('data-kbb-checkout-add="' . $two->id . '"');
});

it('shows an empty state instead of an empty box when the last browsed product is added', function () {
    $cart = browsedCart(20000);
    $only = browsedProduct('Last One Left', 5000);

    $json = browsedAdd($cart, [$only->id], ['product_id' => $only->id])->assertOk()->json();

    expect($json['browsedCount'])->toBe(0)
        ->and($json['browsedHtml'])->toContain('already in your bag')
        ->and($json['browsedHtml'])->not->toContain('baddbtn');
});

it('refuses to add anything when the bag is empty', function () {
    $cart = Cart::create([
        'token' => (string) Illuminate\Support\Str::uuid(),
        'currency' => 'AED',
        'status' => 'active',
        'shipping_country' => 'AE',
        'last_activity_at' => now(),
    ]);

    $serum = browsedProduct('Snail Mucin Serum', 5000);

    browsedAdd($cart, [$serum->id], ['product_id' => $serum->id])
        ->assertStatus(422)
        ->assertJson(['ok' => false]);

    expect($cart->items()->count())->toBe(0);
});

it('renders the checkout Browsed rows against the handler that is actually bound', function () {
    $cart = browsedCart(20000);
    $serum = browsedProduct('Snail Mucin Serum', 5000);

    $page = browsedShopper($cart, [$serum->id])->get('/checkout')->assertOk();

    // The three halves that have to agree: the attribute checkout.js binds,
    // the endpoint it posts to, and the live region the confirmation writes
    // into. Any one of them missing is a handler that runs and does nothing.
    $page->assertSee('data-kbb-checkout-add="' . $serum->id . '"', false)
        ->assertSee('id="kbbBrowsedList" data-add-url=', false)
        ->assertSee('id="kbbBrowsedNote"', false)
        // The regions the response swaps have to exist to be swapped into.
        ->assertSee('class="kbb-order-slot"', false)
        ->assertSee('class="kbb-thumbs-slot"', false);
});
