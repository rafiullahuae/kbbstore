<?php

declare(strict_types=1);

use App\Models\Cart;
use App\Models\Customer;
use App\Models\Order;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\CartPage;
use App\Services\CartService;
use App\Services\CheckoutPage;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Route;
use Tests\Support\EnglishRenderWalk;

/**
 * =============================================================================
 * THE ADDRESS ROW IS OFF ON THE CART AND THE CHECKOUT (Lane CK, 6 October)
 * =============================================================================
 *
 * The owner, with a phone screenshot of the cart's docked bar: "turn off the
 * address row completely, from cart and checkout pages, and bring the manual
 * fields under address section on checkout page." And then: "checkout row will
 * be there, only the address row will be gone."
 *
 * So, by default:
 *   - the squeezed cart's docked bar draws the "N items / AED … / Proceed to
 *     Checkout" row and NOT "Please choose your delivery address  + Address",
 *     and reserves no height for the row it no longer draws;
 *   - the checkout's Shipping address section is the four typed fields
 *     (address, emirate, city, country), filled for a returning customer;
 *   - the /cart/address endpoints answer 404, because nothing can open them.
 *
 * Appearance → Checkout page → Fields & attention → "Address picker row on cart
 * and checkout" brings all of it back exactly; CartPageSqueezeTest and
 * CheckoutAddressPickerTest switch it on and test that mode in full.
 *
 * WHAT MUST NOT MOVE is the money path: place() validates the same four
 * fields, the order's shipping_address keeps its shape, and the emirate is
 * still what prices the delivery.
 */
beforeEach(function () {
    PaymentProvider::query()->delete();
    PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0]);
    app(SettingsService::class)->set('cod_fee', 0);

    // Two zones, so the emirate visibly decides the charge: Dubai has its own
    // AED 10 rate ahead of the country's AED 25.
    $dubai = ShippingZone::create(['name' => 'Dubai', 'position' => 0]);
    ShippingZoneLocation::create(['shipping_zone_id' => $dubai->id, 'type' => 'state', 'code' => 'AE:Dubai']);
    ShippingMethod::create([
        'shipping_zone_id' => $dubai->id, 'type' => 'flat_rate', 'title' => 'Dubai delivery',
        'cost' => 1000, 'enabled' => true, 'position' => 0,
    ]);

    $uae = ShippingZone::create(['name' => 'UAE', 'position' => 1]);
    ShippingZoneLocation::create(['shipping_zone_id' => $uae->id, 'type' => 'country', 'code' => 'AE']);
    ShippingMethod::create([
        'shipping_zone_id' => $uae->id, 'type' => 'flat_rate', 'title' => 'UAE delivery',
        'cost' => 2500, 'enabled' => true, 'position' => 0,
    ]);
});

function ckRowCart(): Cart
{
    $product = Product::create([
        'slug' => 'ck-' . uniqid(), 'name' => 'Row Serum', 'status' => 'publish',
        'is_visible' => true, 'price' => 200, 'stock_status' => 'instock',
    ]);

    $cart = Cart::create(['token' => bin2hex(random_bytes(16)), 'status' => 'active', 'currency' => 'AED', 'shipping_country' => 'AE']);
    $cart->items()->create(['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 20000]);

    return $cart->fresh('items');
}

function ckRowBrowser(Cart $cart, ?Customer $customer = null)
{
    $browser = test()
        ->withCredentials()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token);

    return $customer === null
        ? $browser
        : $browser->withSession([EnglishRenderWalk::customerSessionKey() => $customer->id]);
}

function ckRowPicker(bool $on): void
{
    app(CheckoutPage::class)->save(['addr_picker' => $on]);
    SettingsService::forgetMemo();
}

/** The `value` one named input was rendered with, or null if there is no such input. */
function ckRowValue(string $html, string $name): ?string
{
    if (! preg_match('/<input[^>]*name="' . preg_quote($name, '/') . '"[^>]*>/', $html, $tag)) {
        return null;
    }

    return preg_match('/value="([^"]*)"/', $tag[0], $v) ? html_entity_decode($v[1]) : '';
}

/** The Shipping address section: from its heading to the Delivery one. */
function ckRowSection(string $html): string
{
    $from = strpos($html, '<!-- 2 · Shipping address -->');
    $to = strpos($html, '<!-- 3 · Delivery -->');

    expect($from)->not->toBeFalse()->and($to)->not->toBeFalse();

    return substr($html, $from, $to - $from);
}

function ckRowPlace(Cart $cart, array $over = [])
{
    return ckRowBrowser($cart)->post('/checkout/place', array_merge([
        'billing_email' => 'row@example.com',
        'billing_phone' => '+971500000000',
        'billing_first_name' => 'Aisha Khan',
        'billing_address_1' => 'Villa 12 - Al Wasl Road',
        'billing_city' => 'Jumeirah',
        'billing_state' => 'Dubai',
        'billing_country' => 'AE',
        'payment_method' => 'cod',
    ], $over));
}

/* ------------------------------------------------------------------------
 | 1. The switch
 |------------------------------------------------------------------------*/

it('ships the picker row off, on the checkout screen, as asked', function () {
    // MUTATION: default `addr_picker` to true. RED here and in every test below.
    expect(CheckoutPage::SCHEMA['addr_picker'][0])->toBe('bool')
        ->and(CheckoutPage::SCHEMA['addr_picker'][1])->toBe('Address picker row on cart and checkout')
        ->and(CheckoutPage::SCHEMA['addr_picker'][2])->toBeFalse()
        ->and(app(CheckoutPage::class)->addressPickerRow())->toBeFalse()
        // Where the owner will find it: Appearance → Checkout page → Fields & attention.
        ->and(CheckoutPage::TABS['cues'][0])->toBe('Fields & attention')
        ->and(CheckoutPage::TABS['cues'][2])->toContain('addr_picker');
});

/* ------------------------------------------------------------------------
 | 2. The cart page
 |------------------------------------------------------------------------*/

it('draws the checkout row and not the address row on the cart by default', function () {
    /*
     * The reported page: the docked bar carried "Please choose your delivery
     * address  + Address" above "5 items / AED 1,798 / Proceed to Checkout".
     * The first row goes; the second stays exactly as it was.
     *
     * MUTATION: put `@if ($kbbCpg['addr_on'])` back in cart-inner. RED.
     */
    app(CartPage::class)->save(['layout' => 'squeeze']);

    $html = ckRowBrowser(ckRowCart())->get('/cart')->assertOk()->getContent();

    expect($html)->toContain('<div class="cpg-docked">')
        ->and($html)->toContain('<div class="cpg-cobar">')
        ->and($html)->toContain('Proceed to Checkout')
        // The markup, not the stylesheet: cart-squeeze's rules for the row
        // still ship, and are inert without it.
        ->and($html)->not->toContain('<div class="cpg-addrbar')
        ->and($html)->not->toContain('id="cpgAddrBtn"')
        // The wording is still in the sheet script's config; the row that
        // prints it is not.
        ->and($html)->not->toContain('id="cpgAddrHead"');

    // The bottom padding the docked bar is measured against reserves no room
    // for the row it no longer draws -- otherwise 40px of blank white sits
    // under the last basket line. MUTATION: drop the addressRowOn() ternary
    // in CartPage::cssVariables(). RED.
    expect(app(CartPage::class)->cssVariables())->toContain('--cpg-addr-h:0px');
});

it('brings the cart address row back exactly with the switch on', function () {
    app(CartPage::class)->save(['layout' => 'squeeze']);
    ckRowPicker(true);

    $html = ckRowBrowser(ckRowCart())->get('/cart')->assertOk()->getContent();

    expect($html)->toContain('<div class="cpg-addrbar">')
        ->and($html)->toContain('id="cpgAddrBtn"')
        ->and($html)->toContain('Please choose your delivery address')
        ->and($html)->toContain('<div class="cpg-cobar">')
        ->and(app(CartPage::class)->cssVariables())->toContain('--cpg-addr-h:40px');

    // And the cart's own "Delivery address row" still governs it on top.
    app(CartPage::class)->save(['addr_on' => false]);
    SettingsService::forgetMemo();

    expect(ckRowBrowser(ckRowCart())->get('/cart')->getContent())->not->toContain('<div class="cpg-addrbar');
});

it('puts no saved address into the cart page while nothing can open the sheet', function () {
    /*
     * The sheet is still included -- its script drives the recommendation
     * rail's arrows -- but its seed is the shopper's address book, and with no
     * row to open it that is a home address in the HTML for no reason.
     * MUTATION: drop the addressPickerOn() guard on $kbbSheetSeed. RED.
     */
    app(CartPage::class)->save(['layout' => 'squeeze']);

    $customer = Customer::create(['email' => 'seed@example.com', 'name' => 'Seed Shopper', 'password' => 'secret-secret']);
    $customer->addresses()->create([
        'type' => 'shipping', 'is_default' => true, 'label' => 'home',
        'line1' => 'Tower 9 Flat 1203', 'city' => 'Dubai', 'country' => 'AE',
    ]);

    $html = ckRowBrowser(ckRowCart(), $customer)->get('/cart')->assertOk()->getContent();

    expect($html)->toContain('id="cpgSheet"')
        ->and($html)->toContain('var SEED = null;')
        ->and($html)->not->toContain('Tower 9 Flat 1203');
});

it('closes the address endpoints while the picker is off, and opens them with it on', function () {
    // MUTATION: make CartPage::addressPickerOn() return true again. RED.
    if (! app('router')->getRoutes()->hasNamedRoute('cart.address')) {
        Route::middleware('web')->group(base_path('routes/cart-address.php'));
        app('router')->getRoutes()->refreshNameLookups();
    }

    test()->getJson('/cart/address')->assertNotFound();
    test()->postJson('/cart/address', ['area' => 'Al Quoz', 'city' => 'Dubai', 'country' => 'AE', 'tag' => 'home'])
        ->assertNotFound();

    ckRowPicker(true);

    test()->getJson('/cart/address')->assertOk();
});

/* ------------------------------------------------------------------------
 | 3. The checkout page
 |------------------------------------------------------------------------*/

it('puts the typed address fields under the Shipping address heading by default', function () {
    /*
     * MUTATION: include partials.checkout-address unconditionally. RED -- the
     * section is the picker row again and the four inputs are hidden.
     */
    $html = ckRowBrowser(ckRowCart())->get('/checkout/')->assertOk()->getContent();
    $section = ckRowSection($html);

    expect($section)->toContain('Shipping address')
        ->and($section)->not->toContain('id="cka"')
        ->and($section)->not->toContain('cpgAddrBtn')
        ->and($section)->not->toContain('Please choose your delivery address')
        ->and($section)->not->toContain('type="hidden"');

    // Four boxes a shopper types into, each REQUIRED in the browser as well as
    // in place(), in the order the pre-picker page drew them.
    foreach (['billing_address_1', 'billing_state', 'billing_city'] as $name) {
        expect($section)->toMatch('/<input[^>]*name="' . $name . '"[^>]*required/');
    }

    expect($section)->toMatch('/<select[^>]*name="billing_country"[^>]*required/')
        ->and(strpos($section, 'billing_address_1'))->toBeLessThan(strpos($section, 'billing_state'))
        ->and(strpos($section, 'billing_state'))->toBeLessThan(strpos($section, 'billing_city'))
        ->and(strpos($section, 'billing_city'))->toBeLessThan(strpos($section, 'name="billing_country"'));

    // Nothing in the page can open the sheet, so it is not shipped.
    expect($html)->not->toContain('id="cpgSheet"');
});

it('brings the picker row back on the checkout with the switch on', function () {
    ckRowPicker(true);

    $html = ckRowBrowser(ckRowCart())->get('/checkout/')->assertOk()->getContent();
    $section = ckRowSection($html);

    expect($section)->toContain('id="cka"')
        ->and($section)->toContain('id="cpgAddrBtn"')
        ->and($section)->toContain('<input type="hidden" name="billing_address_1" id="billing_address_1"')
        ->and($html)->toContain('id="cpgSheet"');
});

it('fills the typed fields from a returning customer\'s saved address', function () {
    /*
     * An address saved through the cart's popup keeps the apartment in line1,
     * the area in line2 and the emirate in city, with `state` empty. Read
     * naively, the box would lose the area and the emirate would be blank,
     * and the customer would retype both. MUTATION: drop line2 from the join
     * in typedAddress(). RED. Drop the city fallback for state. RED.
     */
    $customer = Customer::create(['email' => 'back@example.com', 'name' => 'Back Again', 'password' => 'secret-secret']);
    $customer->addresses()->create([
        'type' => 'shipping', 'is_default' => true, 'label' => 'home',
        'line1' => 'Building 1-10, G-04', 'line2' => 'Al Quoz Industrial Area 2',
        'city' => 'Dubai', 'country' => 'AE',
    ]);

    $html = ckRowBrowser(ckRowCart(), $customer)->get('/checkout/')->assertOk()->getContent();

    expect(ckRowValue($html, 'billing_address_1'))->toBe('Building 1-10, G-04 - Al Quoz Industrial Area 2')
        ->and(ckRowValue($html, 'billing_city'))->toBe('Dubai')
        ->and(ckRowValue($html, 'billing_state'))->toBe('Dubai')
        ->and($html)->toMatch('/<option value="AE" selected/');

    // And the delivery list is priced on that emirate -- the AED 10 Dubai rate,
    // not the country's AED 25 -- so the rate shown is the rate place() will
    // charge for what the form posts. MUTATION: price on `$address?->state`
    // (empty) as the picker mode does. RED: "UAE delivery" is drawn instead.
    expect($html)->toContain('Dubai delivery')
        ->and($html)->not->toContain('UAE delivery');
});

it('fills the typed fields from the latest order when the customer has no saved address', function () {
    /*
     * Accounts made at the checkout have no address-book row -- place() never
     * writes one -- so "a returning customer doesn't retype" needs the last
     * order. MUTATION: return null from typedAddress() when $address is null.
     * RED.
     */
    $customer = Customer::create(['email' => 'repeat@example.com', 'name' => 'Repeat Buyer', 'password' => 'secret-secret']);
    Order::create([
        'order_number' => 'CK-1', 'customer_id' => $customer->id, 'email' => 'repeat@example.com',
        'status' => 'completed', 'currency' => 'AED', 'subtotal' => 100, 'total' => 100,
        'shipping_address' => ['first_name' => 'Repeat', 'last_name' => 'Buyer', 'line1' => 'Villa 7, Street 21',
            'city' => 'Al Barsha', 'state' => 'Dubai', 'country' => 'AE', 'phone' => '0500000000'],
    ]);

    $html = ckRowBrowser(ckRowCart(), $customer)->get('/checkout/')->assertOk()->getContent();

    expect(ckRowValue($html, 'billing_address_1'))->toBe('Villa 7, Street 21')
        ->and(ckRowValue($html, 'billing_city'))->toBe('Al Barsha')
        ->and(ckRowValue($html, 'billing_state'))->toBe('Dubai');
});

it('leaves a guest\'s address boxes empty and the default country chosen', function () {
    $html = ckRowBrowser(ckRowCart())->get('/checkout/')->assertOk()->getContent();

    expect(ckRowValue($html, 'billing_address_1'))->toBe('')
        ->and(ckRowValue($html, 'billing_city'))->toBe('')
        ->and(ckRowValue($html, 'billing_state'))->toBe('')
        ->and($html)->toMatch('/<option value="AE" selected/');
});

it('re-prices the delivery when the emirate changes, through the one pricing path', function () {
    /*
     * The emirate is a typed box now, and the rates on the page were fetched
     * for the emirate it held at load. checkout.js re-prices on a `change` of
     * #billing_country and sends #billing_state with it, so the emirate hands
     * its change to the country. `change`, not `input`: one request when the
     * shopper leaves the box. MUTATION: delete the listener. RED.
     */
    $html = ckRowBrowser(ckRowCart())->get('/checkout/')->assertOk()->getContent();

    expect($html)->toContain("state.addEventListener('change', function () {\n    country.dispatchEvent(new Event('change', { bubbles: true }));")
        ->and($html)->not->toContain("state.addEventListener('input'");

    // And the endpoint that listener reaches prices on the emirate it is sent.
    ckRowBrowser(ckRowCart());
    $dubai = test()->postJson('/api/checkout/rates', ['country' => 'AE', 'state' => 'Dubai'])->assertOk()->json();
    $sharjah = test()->postJson('/api/checkout/rates', ['country' => 'AE', 'state' => 'Sharjah'])->assertOk()->json();

    expect($dubai['ok'] ?? null)->toBeTrue()
        ->and($dubai['deliveryHtml'])->toContain('Dubai delivery')
        ->and($sharjah['deliveryHtml'])->toContain('UAE delivery');
});

/* ------------------------------------------------------------------------
 | 4. The order -- the part that takes money
 |------------------------------------------------------------------------*/

it('stores a guest order\'s address in the same shape as before, priced on its emirate', function () {
    $cart = ckRowCart();

    $response = ckRowBrowser($cart)->post('/checkout/place', [
        'billing_email' => 'guest@example.com', 'billing_phone' => '+971500000000',
        'billing_first_name' => 'Aisha Khan',
        'billing_address_1' => 'Villa 12 - Al Wasl Road', 'billing_city' => 'Jumeirah',
        'billing_state' => 'Dubai', 'billing_country' => 'AE', 'payment_method' => 'cod',
    ]);

    expect($response->getStatusCode())->toBe(302);

    $order = Order::query()->latest('id')->first();

    expect($order)->not->toBeNull()
        ->and($order->shipping_address)->toBe([
            'first_name' => 'Aisha', 'last_name' => 'Khan',
            'line1' => 'Villa 12 - Al Wasl Road', 'city' => 'Jumeirah',
            'state' => 'Dubai', 'country' => 'AE', 'phone' => '+971500000000',
        ])
        ->and($order->billing_address)->toBe($order->shipping_address)
        // The Dubai zone, because the emirate is Dubai.
        ->and((int) $order->shipping_total)->toBe(1000);
});

it('stores a signed-in order\'s address in the same shape, and another emirate prices differently', function () {
    $customer = Customer::create(['email' => 'member@example.com', 'name' => 'Member Shopper', 'password' => 'secret-secret']);
    $cart = ckRowCart();

    $response = ckRowBrowser($cart, $customer)->post('/checkout/place', [
        'billing_email' => 'member@example.com', 'billing_phone' => '+971511111111',
        'billing_first_name' => 'Member Shopper',
        'billing_address_1' => 'Flat 4, Al Majaz 3', 'billing_city' => 'Al Majaz',
        'billing_state' => 'Sharjah', 'billing_country' => 'AE', 'payment_method' => 'cod',
    ]);

    expect($response->getStatusCode())->toBe(302);

    $order = Order::query()->latest('id')->first();

    expect($order->customer_id)->toBe($customer->id)
        ->and(array_keys($order->shipping_address))->toBe(['first_name', 'last_name', 'line1', 'city', 'state', 'country', 'phone'])
        ->and($order->shipping_address['state'])->toBe('Sharjah')
        // No Sharjah zone: the country's rate, exactly as before the change.
        ->and((int) $order->shipping_total)->toBe(2500);
});

it('still refuses an order with the address boxes left empty', function () {
    $response = ckRowPlace(ckRowCart(), ['billing_address_1' => '', 'billing_city' => '', 'billing_state' => '']);

    $response->assertSessionHasErrors(['billing_address_1', 'billing_city', 'billing_state']);
    expect(Order::query()->count())->toBe(0);
});

it('keeps what a rejected submission typed in the boxes', function () {
    // old() wins over the saved address, so a typo fixed and refused for
    // another reason does not snap back to the account's copy.
    $html = ckRowBrowser(ckRowCart())
        ->withSession(['_old_input' => ['billing_address_1' => 'Typed by hand', 'billing_state' => 'Ajman', 'billing_city' => 'Al Nuaimiya']])
        ->get('/checkout/')->assertOk()->getContent();

    expect(ckRowValue($html, 'billing_address_1'))->toBe('Typed by hand')
        ->and(ckRowValue($html, 'billing_state'))->toBe('Ajman')
        ->and(ckRowValue($html, 'billing_city'))->toBe('Al Nuaimiya');
});
