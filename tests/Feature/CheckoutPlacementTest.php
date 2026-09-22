<?php

/**
 * Phase 11 — placing an order through the storefront checkout.
 *
 * Store\CheckoutController::place() changed shape in this phase: the
 * posted-method check was generalised from COD to every gateway, the surcharge
 * is now asked of the gateway rather than inferred from its id, and the order
 * is handed to PaymentGateway::start() at the end. Order placement is the last
 * thing that should break quietly, so the whole path is exercised here rather
 * than trusted.
 */

use App\Models\Cart;
use App\Models\Order;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use Illuminate\Support\Facades\DB;
use App\Services\CartService;

beforeEach(function () {
    PaymentProvider::query()->delete();
    app(\App\Services\Payments\GatewayCredentials::class)->forget();

    // One zone covering the UAE with a flat rate, so ratesFor() returns
    // something and checkout does not bail out before the payment step.
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

/** A cart with one line, and the cookie that addresses it. */
function cartWithItem(int $unitPriceFils = 20000): Cart
{
    $product = Product::create([
        'slug' => 'serum-' . uniqid(),
        'name' => 'Test Serum',
        'status' => 'publish',
        'is_visible' => true,
        'price' => $unitPriceFils / 100,
        'stock_status' => 'instock',
    ]);

    $cart = Cart::create([
        'token' => (string) Illuminate\Support\Str::uuid(),
        'currency' => 'AED',
        'status' => 'active',
        'shipping_country' => 'AE',
        'last_activity_at' => now(),
    ]);

    $cart->items()->create([
        'product_id' => $product->id,
        'quantity' => 1,
        'unit_price' => $unitPriceFils,
    ]);

    return $cart;
}

function checkoutForm(array $overrides = []): array
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

/**
 * A browser carrying this cart's cookie.
 *
 * EncryptCookies is disabled for these calls: the cart cookie is encrypted in
 * production, and a plain token handed to that middleware is decrypted, fails,
 * and is dropped — leaving the controller with no cart and a redirect to
 * /cart/ that looks exactly like a rejected payment method. Encrypting the
 * token by hand here would test Laravel's cookie encryption rather than the
 * checkout.
 */
function asShopper(Cart $cart)
{
    return test()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token);
}

function place(Cart $cart, array $form)
{
    return asShopper($cart)->post('/checkout/place', $form);
}

function visitCheckout(Cart $cart)
{
    return asShopper($cart)->get('/checkout');
}

it('places a cash on delivery order through the gateway', function () {
    PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0]);
    app(\App\Services\SettingsService::class)->set('cod_fee', 1500);

    $cart = cartWithItem(20000);

    $response = place($cart, checkoutForm());

    $order = \App\Models\Order::latest('id')->first();

    expect($order)->not->toBeNull();

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('order=' . $order->order_number);

    expect($order->payment_method)->toBe('cod')
        // 200.00 goods + 20.00 delivery + 15.00 COD fee
        ->and($order->fee_total)->toBe(1500)
        ->and($order->total)->toBe(22000 + 1500)
        // CashOnDelivery::start() moved it on, and did NOT mark it paid.
        ->and($order->status)->toBe('processing')
        ->and($order->paid_at)->toBeNull();
});

it('charges no surcharge for a gateway that has none', function () {
    PaymentProvider::create(['id' => 'cod', 'title' => 'COD', 'enabled' => true, 'mode' => 'test', 'position' => 0]);
    app(\App\Services\SettingsService::class)->set('cod_fee', 0);

    place(cartWithItem(20000), checkoutForm());

    expect(\App\Models\Order::latest('id')->first()->fee_total)->toBe(0);
});

it('refuses a payment method that was never offered', function () {
    // Only COD is enabled, so a posted `stripe` is a method this shopper was
    // never shown. Before Phase 11 only COD was re-checked on submit.
    PaymentProvider::create(['id' => 'cod', 'title' => 'COD', 'enabled' => true, 'mode' => 'test', 'position' => 0]);

    $cart = cartWithItem(20000);
    $before = \App\Models\Order::count();

    place($cart, checkoutForm(['payment_method' => 'stripe']))
        ->assertSessionHasErrors();

    expect(\App\Models\Order::count())->toBe($before);
});

it('refuses an enabled gateway that has no credentials', function () {
    // Enabled, but never configured -- exactly how this ships.
    PaymentProvider::create(['id' => 'stripe', 'title' => 'Card', 'enabled' => true, 'mode' => 'test', 'position' => 0]);

    $cart = cartWithItem(20000);
    $before = \App\Models\Order::count();

    place($cart, checkoutForm(['payment_method' => 'stripe']))
        ->assertSessionHasErrors();

    expect(\App\Models\Order::count())->toBe($before);
});

it('refuses a gateway id this build has no code for', function () {
    PaymentProvider::create(['id' => 'cod', 'title' => 'COD', 'enabled' => true, 'mode' => 'test', 'position' => 0]);
    PaymentProvider::create(['id' => 'paypal', 'title' => 'PayPal', 'enabled' => true, 'mode' => 'test', 'position' => 1]);

    $cart = cartWithItem(20000);
    $before = \App\Models\Order::count();

    place($cart, checkoutForm(['payment_method' => 'paypal']))
        ->assertSessionHasErrors();

    expect(\App\Models\Order::count())->toBe($before);
});

it('refuses cod outside the Payment & Shipping Rules window, with its own wording', function () {
    PaymentProvider::create(['id' => 'cod', 'title' => 'COD', 'enabled' => true, 'mode' => 'test', 'position' => 0]);

    $settings = app(\App\Services\SettingsService::class);
    $settings->setModule('pay_ship_rules', true);
    $settings->setModuleSetting('pay_ship_rules', 'cod_max', 5000);   // د.إ50 ceiling

    $cart = cartWithItem(20000);                                      // د.إ200 basket
    $before = \App\Models\Order::count();

    $response = place($cart, checkoutForm());

    $response->assertSessionHasErrors();

    // The window's specific sentence, not the generic refusal -- that branch
    // exists precisely so the shopper is told why.
    $errors = session('errors')->all();
    expect(implode(' ', $errors))->toContain('Cash on delivery is not available on orders over');

    expect(\App\Models\Order::count())->toBe($before);
});

it('still renders the checkout page with a cart present', function () {
    PaymentProvider::create(['id' => 'cod', 'title' => 'Pay the courier', 'enabled' => true, 'mode' => 'test', 'position' => 0]);

    $cart = cartWithItem(20000);

    visitCheckout($cart)
        ->assertOk()
        // The radio the blade renders per gateway, which is what place() then
        // validates the posted value against.
        ->assertSee('value="cod"', false)
        // And the merchant's own title from the provider row, not the class
        // default -- the registry prefers it.
        ->assertSee('Pay the courier', false);
});

it('renders the checkout page when every gateway is unconfigured', function () {
    foreach (['stripe', 'tabby', 'tamara'] as $i => $id) {
        PaymentProvider::create(['id' => $id, 'title' => ucfirst($id), 'enabled' => true, 'mode' => 'test', 'position' => $i]);
    }

    app(\App\Services\Payments\GatewayCredentials::class)->forget();

    $cart = cartWithItem(20000);

    // No payment option can be offered, but the page must render rather than
    // 500 -- which is the state this ships in before the owner fills in keys.
    visitCheckout($cart)->assertOk();
});

/*
 * The live 500. nextOrderNumber() was `10000 + max(id) + 1`, which assumes
 * order numbers march in step with primary keys. The live table had orders for
 * which that was false, so the generator returned a number that already
 * existed, order_number is NOT NULL UNIQUE, and the insert raised
 * SQLSTATE[23000] inside the transaction -- a 500 on every checkout attempt,
 * with no order written.
 *
 * Not caught before because every test here started from an empty orders table,
 * where the old formula happens to be right.
 */
it('places an order when an existing number collides with the old formula', function () {
    // id 1 holding the exact number `10000 + max(id) + 1` would produce.
    DB::table('orders')->insert([
        'id' => 1, 'order_number' => '10002', 'email' => 'prior@example.com',
        'status' => 'pending', 'currency' => 'AED', 'subtotal' => 100,
        'discount_total' => 0, 'shipping_total' => 0, 'fee_total' => 0,
        'tax_total' => 0, 'total' => 100,
    ]);

    $cart = cartWithItem(20000);
    $response = place($cart, checkoutForm(['payment_method' => 'cod']));

    expect($response->status())->not->toBe(500);
    expect(Order::where('email', 'buyer@example.com')->exists())->toBeTrue();
});

it('places an order when the numbers are far ahead of the ids', function () {
    // An import: one row, a number nowhere near 10000 + id.
    DB::table('orders')->insert([
        'id' => 1, 'order_number' => '48231', 'email' => 'imported@example.com',
        'status' => 'pending', 'currency' => 'AED', 'subtotal' => 100,
        'discount_total' => 0, 'shipping_total' => 0, 'fee_total' => 0,
        'tax_total' => 0, 'total' => 100,
    ]);

    $cart = cartWithItem(20000);
    place($cart, checkoutForm(['payment_method' => 'cod']));

    $new = Order::where('email', 'buyer@example.com')->first();

    expect($new)->not->toBeNull()
        ->and((int) $new->order_number)->toBeGreaterThan(48231);
});

/**
 * The order pages print the payment method at the customer. Nothing in the app
 * ever wrote payment_method_title except the demo seeder, so every real order
 * fell back to the raw id and the account order page read "cod".
 */
it('snapshots the payment wording the shopper saw onto the order', function () {
    PaymentProvider::create(['id' => 'cod', 'title' => 'Pay the courier in cash', 'enabled' => true, 'mode' => 'test', 'position' => 0]);

    place(cartWithItem(20000), checkoutForm());

    $order = Order::latest('id')->first();

    // The merchant's own wording, not the gateway default and not the id.
    expect($order->payment_method)->toBe('cod')
        ->and($order->payment_method_title)->toBe('Pay the courier in cash')
        ->and($order->paymentLabel())->toBe('Pay the courier in cash');
});

it('renames nothing on an order already placed when the merchant retitles the gateway', function () {
    PaymentProvider::create(['id' => 'cod', 'title' => 'Pay the courier in cash', 'enabled' => true, 'mode' => 'test', 'position' => 0]);

    place(cartWithItem(20000), checkoutForm());
    $order = Order::latest('id')->first();

    PaymentProvider::find('cod')->update(['title' => 'Cash only']);

    expect($order->fresh()->paymentLabel())->toBe('Pay the courier in cash');
});

/** Orders placed before the snapshot existed carry a null title. */
it('falls back to the gateway title rather than the raw id on an older order', function () {
    PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0]);

    place(cartWithItem(20000), checkoutForm());

    $order = Order::latest('id')->first();
    $order->forceFill(['payment_method_title' => null])->save();

    expect($order->fresh()->paymentLabel())->toBe('Cash on delivery');
});

it('humanises a gateway id this build no longer carries code for', function () {
    PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0]);

    place(cartWithItem(20000), checkoutForm());

    $order = Order::latest('id')->first();
    $order->forceFill(['payment_method_title' => null, 'payment_method' => 'old_wallet'])->save();

    expect($order->fresh()->paymentLabel())->toBe('Old wallet');
});
