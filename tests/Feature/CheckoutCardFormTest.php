<?php

/**
 * The card fields on /checkout/ — the page, the submit path and the two
 * reports the browser makes afterwards.
 *
 * Everything Stripe answers here is Http::fake(). What that proves is what
 * this application sends, what it does with what comes back, and what the page
 * contains; it cannot prove Stripe agrees with any of it. The one real
 * test-mode payment that would is named in docs/FU-STRIPE-CARD-FIELDS.md.
 */

use App\Models\Cart;
use App\Models\Order;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\CartService;
use Illuminate\Support\Facades\Http;

const FORM_PK = 'pk_test_kbb_form_key';

beforeEach(function () {
    PaymentProvider::query()->delete();
    app(\App\Services\Payments\GatewayCredentials::class)->forget();

    /*
     * THE TWO CARD ENDPOINTS ARE REGISTERED HERE, because routes/web.php is
     * owned by the integrator and this lane may not write to it.
     * routes/checkout-card.php carries the definitions and the instruction for
     * the one line that includes it; until that line lands, both paths 404 in
     * this application and these tests would be asserting about routes that do
     * not exist.
     *
     * `web`, matching the group the file asks to be required into, and for the
     * reason its header gives: both endpoints are authorised by the session
     * alone, so a group without session middleware would read an empty session
     * and refuse every call.
     *
     * If the integrator wires the file and this line stays, Laravel registers
     * the same two paths twice and the last definition wins — identical, so
     * harmless. If the integrator does NOT wire it, this beforeEach is what
     * makes the failure visible here rather than in production.
     */
    Illuminate\Support\Facades\Route::middleware('web')->group(base_path('routes/checkout-card.php'));

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

function formStripeOn(array $config = []): void
{
    $row = PaymentProvider::create([
        'id' => 'stripe', 'title' => 'Credit / Debit Card', 'enabled' => true, 'mode' => 'test', 'position' => 0,
    ]);

    $row->config = array_merge([
        'publishable_key' => FORM_PK,
        'secret_key' => 'sk_test_kbb_form',
        'webhook_signing_secret' => 'whsec_form_signing',
        'webhook_secret' => 'whsec-url-form-0123456789ab',
    ], $config);

    $row->save();

    app(\App\Services\Payments\GatewayCredentials::class)->forget();
}

function formCart(int $unitPriceFils = 20000): Cart
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

/**
 * A browser carrying this cart's cookie.
 *
 * EncryptCookies is disabled for the reason CheckoutPlacementTest gives: the
 * cart cookie is encrypted in production, and a plain token handed to that
 * middleware is decrypted, fails and is dropped — leaving the controller with
 * no cart and a refusal that looks exactly like a rejected payment method.
 *
 * withCredentials() IS NOT OPTIONAL HERE and cost an hour to find.
 * TestCase::postJson() calls prepareCookiesForJsonRequest(), which returns an
 * EMPTY cookie jar unless this is set — it models a cross-origin fetch. Every
 * endpoint in this file is identified by a cookie, so without it they all
 * answer "your bag is empty" and the failure reads as a broken checkout rather
 * than as a test that sent no cookies. The page's own fetch() is same-origin
 * and sends them by default; it says `credentials: 'same-origin'` anyway, for
 * the same reason this line is commented.
 */
function formShopper(Cart $cart)
{
    return test()
        ->withCredentials()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token);
}

function formFields(array $overrides = []): array
{
    return array_merge([
        'billing_email' => 'buyer@example.com',
        'billing_first_name' => 'Aisha',
        'billing_last_name' => 'Khan',
        'billing_address_1' => '12 Marina Walk',
        'billing_city' => 'Dubai',
        'billing_state' => 'Dubai',
        'billing_country' => 'AE',
        'payment_method' => 'stripe',
    ], $overrides);
}

/** Stripe answering "here is an intent you can confirm". */
function formIntentOpens(string $id = 'pi_form_1'): void
{
    Http::fake([
        'api.stripe.com/v1/payment_intents' => Http::response([
            'id' => $id,
            'client_secret' => $id . '_secret_value',
            'status' => 'requires_payment_method',
        ], 200),
    ]);
}

/* =====================================================================
 | The page
 ===================================================================== */

it('renders the card fields on the checkout page itself', function () {
    formStripeOn();

    $html = formShopper(formCart())->get('/checkout')->assertOk()->getContent();

    /*
     * The owner's complaint, as an assertion. Each needle is its own expect():
     * toContain() is VARIADIC, so passing a message as a second argument makes
     * it a second needle and the assertion silently stops being the one that
     * was written — see tests/Feature/ExpectationsThatCannotFailTest.php.
     */
    // The box Stripe.js mounts its iframe into.
    expect($html)->toContain('id="kbb-card-element"');
    // Stripe.js itself, and from Stripe's own domain — self-hosting it would
    // put the code that touches the card number inside our origin.
    expect($html)->toContain('https://js.stripe.com/v3');
    // The publishable key, which is public by design and is what boots it.
    expect($html)->toContain(FORM_PK);
    // Where a decline is printed.
    expect($html)->toContain('data-kbb-card-error');
});

it('puts the card fields inside the card option, not loose on the page', function () {
    formStripeOn();

    $html = formShopper(formCart())->get('/checkout')->assertOk()->getContent();

    /*
     * kbb-checkout.css reveals `.payment_box` only for the option whose radio
     * is checked. The fields being INSIDE that box is what makes them appear
     * under Credit / Debit Card and nowhere else, with no JavaScript deciding
     * visibility — so their position in the document is the behaviour.
     */
    $box = strpos($html, 'payment_box payment_method_stripe');
    $mount = strpos($html, 'id="kbb-card-element"');

    expect($box)->not->toBeFalse();
    expect($mount)->not->toBeFalse();
    expect($mount)->toBeGreaterThan($box);

    // And the next payment option's <li> starts after the mount, so the box
    // was not closed before it.
    expect(substr($html, $box, $mount - $box))->not->toContain('</ul>');
});

it('loads no Stripe script at all when no card gateway is configured', function () {
    PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0]);

    $html = formShopper(formCart())->get('/checkout')->assertOk()->getContent();

    expect($html)->not->toContain('js.stripe.com');
    expect($html)->not->toContain('kbb-card-element');
});

it('never puts the secret key on the checkout page', function () {
    formStripeOn(['secret_key' => 'sk_live_KBBFORMCANARY0000001']);

    $html = formShopper(formCart())->get('/checkout')->assertOk()->getContent();

    // The publishable key belongs there; its partner never does, and this page
    // is the one place the two sit closest together in the code.
    expect($html)->toContain(FORM_PK);
    expect($html)->not->toContain('sk_live_KBBFORMCANARY0000001');
    expect($html)->not->toContain('whsec_form_signing');
});

it('tells the shopper the card is entered here, not on another site', function () {
    formStripeOn();

    $html = formShopper(formCart())->get('/checkout')->assertOk()->getContent();

    // The description the owner read as "the fields are broken". It must not
    // send anybody looking for a step that no longer happens.
    expect($html)->not->toContain('after you press Place order, so they never reach this site');
});

/* =====================================================================
 | Placing — the JSON handshake
 ===================================================================== */

it('answers Place order with an intent to confirm rather than a redirect', function () {
    formStripeOn();
    formIntentOpens('pi_place_1');

    $response = formShopper(formCart())
        ->postJson('/checkout/place', formFields())
        ->assertOk();

    $order = Order::latest('id')->first();

    expect($order)->not->toBeNull()
        // NOT `processing`. Nothing has been paid: the card has not been typed
        // yet, let alone approved.
        ->and($order->status)->toBe('pending')
        ->and($order->paid_at)->toBeNull()
        // Settlement and reconciliation both key off this.
        ->and($order->transaction_id)->toBe('pi_place_1');

    $response->assertJsonPath('ok', true)
        ->assertJsonPath('action', 'confirm')
        ->assertJsonPath('client_secret', 'pi_place_1_secret_value')
        ->assertJsonPath('order', $order->order_number);

    // The one thing this whole change exists to remove.
    expect($response->json())->not->toHaveKey('url');
});

it('hands the client secret only to the browser that placed the order', function () {
    formStripeOn();
    formIntentOpens('pi_place_2');

    $body = formShopper(formCart())->postJson('/checkout/place', formFields())->json();

    expect($body['client_secret'])->toBe('pi_place_2_secret_value');

    /*
     * A client secret lets its holder confirm that intent AND read its amount
     * and status, so it is minted in the response to the POST that created the
     * order and is never readable again. There is no endpoint that will hand
     * one out — including the two this package adds.
     */
    $order = Order::latest('id')->first();

    $leak = test()->postJson('/checkout/card/paid', ['order' => $order->order_number]);

    expect(json_encode($leak->json()))->not->toContain('pi_place_2_secret_value');
});

it('refuses a card order from a browser that cannot run the card form', function () {
    formStripeOn();
    formIntentOpens('pi_place_3');

    /*
     * An ordinary form POST with the card option chosen means scripting is
     * off: there were no fields to type into, because they are Stripe's
     * iframes and Stripe.js mounts them. Falling through to the order-received
     * page would thank the shopper for a payment that cannot be made.
     */
    $response = formShopper(formCart())->post('/checkout/place', formFields());

    $order = Order::latest('id')->first();

    expect($order->status)->toBe('failed');

    $response->assertRedirect();
    expect(session('errors')->first())->toContain('JavaScript');
});

it('still redirects a hosted gateway and still places a cash order', function () {
    // place() answers for every gateway, and the card path must not have
    // changed what the other two do.
    PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0]);

    $response = formShopper(formCart())
        ->post('/checkout/place', formFields(['payment_method' => 'cod']));

    $order = Order::latest('id')->first();

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('order=' . $order->order_number);
    expect($order->status)->toBe('processing');
});

it('says why it refused, in the same words, through the JSON door', function () {
    formStripeOn();

    // A method that was never on offer. The form POST has always answered this
    // with the sentence below above the repopulated form.
    formShopper(formCart())
        ->postJson('/checkout/place', formFields(['payment_method' => 'tabby']))
        ->assertStatus(422)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('error', 'That payment method is not available.');

    expect(Order::count())->toBe(0);
});

it('cannot place a second order out of a basket that has already been bought', function () {
    formStripeOn();
    formIntentOpens('pi_twice_1');

    $cart = formCart();

    formShopper($cart)->postJson('/checkout/place', formFields())->assertOk();

    expect(Order::count())->toBe(1);

    /*
     * THE DOUBLE-SUBMIT GUARD, AND WHY IT IS ASSERTED HERE RATHER THAN BY
     * POSTING TWICE.
     *
     * The guard is not the disabled button — anything that can be pressed
     * twice can be posted twice by something that is not the page. It is that
     * placing an order marks the cart `converted`, and CartService::resolve()
     * only ever finds an `active` one, so a second POST arrives with no
     * basket and place() turns it away before it can mint an order.
     *
     * Posting twice through the test client does NOT show that, and shows the
     * opposite: CartService is bound `scoped`, it memoises the cart it
     * resolved, and a scoped binding is released when a REQUEST ends. Laravel's
     * test client reuses one booted application across calls without ending
     * one, so the second call reads the first call's memo and is handed the
     * cart it already converted. That is an artefact of the harness — on the
     * shared host every request is its own process — but it means the
     * two-POST version of this test passes for a reason that is not true, and
     * would go on passing if the guard were deleted.
     *
     * So the two facts the guard is made of are asserted directly: the basket
     * is converted, and a service resolving it fresh, with the same cookie the
     * browser still holds, finds nothing.
     */
    expect($cart->fresh()->status)->toBe('converted');

    app()->forgetScopedInstances();

    $second = Illuminate\Http\Request::create('/checkout/place', 'POST');
    $second->cookies->set(CartService::COOKIE, $cart->token);

    expect(app(CartService::class)->current($second, create: false))->toBeNull();

    // And that is exactly what place() answers to.
    expect(Cart::where('token', $cart->token)->where('status', 'active')->count())->toBe(0);
});

it('does not open a second intent when one order reaches the gateway twice', function () {
    formStripeOn();

    Http::fake([
        // The intent this order already has, still confirmable — what Stripe
        // leaves behind after a declined card.
        'api.stripe.com/v1/payment_intents/pi_once_1' => Http::response([
            'id' => 'pi_once_1', 'client_secret' => 'pi_once_1_secret',
            'status' => 'requires_payment_method', 'amount' => 22000, 'currency' => 'aed',
        ], 200),
        'api.stripe.com/v1/payment_intents' => Http::response([
            'id' => 'pi_SECOND', 'client_secret' => 'pi_SECOND_secret', 'status' => 'requires_payment_method',
        ], 200),
    ]);

    $order = Order::create([
        'order_number' => 'TWICE-' . uniqid(),
        'email' => 'buyer@example.com',
        'status' => 'pending',
        'currency' => 'AED',
        'subtotal' => 22000,
        'total' => 22000,
        'payment_method' => 'stripe',
        'transaction_id' => 'pi_once_1',
    ]);

    /*
     * The other half of the guard, and the half that is about Stripe. Two live
     * authorisations against one order is money only one of which
     * `transaction_id` can name; the other is money nobody is watching.
     */
    $start = app(\App\Services\Payments\GatewayRegistry::class)->find('stripe')->start($order);

    expect($start->providerRef)->toBe('pi_once_1');
    expect($order->fresh()->transaction_id)->toBe('pi_once_1');

    Http::assertNotSent(fn (\Illuminate\Http\Client\Request $r) => $r->method() === 'POST'
        && parse_url($r->url(), PHP_URL_PATH) === '/v1/payment_intents');
});

/* =====================================================================
 | The browser's reports
 ===================================================================== */

it('marks the order paid when Stripe confirms what the browser reported', function () {
    formStripeOn();
    formIntentOpens('pi_paid_1');

    $shopper = formShopper(formCart());
    $shopper->postJson('/checkout/place', formFields())->assertOk();

    $order = Order::latest('id')->first();

    Http::fake([
        'api.stripe.com/v1/payment_intents/pi_paid_1' => Http::response([
            'id' => 'pi_paid_1', 'status' => 'succeeded',
            'amount' => (int) $order->total,
            'amount_received' => (int) $order->total,
            'currency' => 'aed',
        ], 200),
    ]);

    $shopper->postJson('/checkout/card/paid', ['order' => $order->order_number])
        ->assertOk()->assertJsonPath('ok', true);

    $order->refresh();

    expect($order->paid_at)->not->toBeNull()
        ->and($order->status)->toBe('processing');
});

it('will not confirm somebody else\'s order', function () {
    formStripeOn();
    formIntentOpens('pi_other_1');

    formShopper(formCart())->postJson('/checkout/place', formFields())->assertOk();

    $order = Order::latest('id')->first();

    Http::fake([
        'api.stripe.com/v1/payment_intents/pi_other_1' => Http::response([
            'id' => 'pi_other_1', 'status' => 'succeeded',
            'amount' => (int) $order->total, 'amount_received' => (int) $order->total, 'currency' => 'aed',
        ], 200),
    ]);

    /*
     * A browser that placed SOME order asking about a different one. Order
     * numbers are sequential (CheckoutController::nextOrderNumber), so
     * guessing a neighbour's is trivial; `kbb_last_order` is the marker that
     * says which one is this browser's, and it is compared with hash_equals.
     */
    $neighbour = (string) (((int) $order->order_number) - 1);

    formShopper(formCart())
        ->postJson('/checkout/card/paid', ['order' => $neighbour])
        ->assertStatus(404);

    /*
     * And a browser holding no marker at all gets the same 404 — the answer a
     * wholly made-up number gets, so neither call can be used to find out
     * which order numbers exist.
     */
    test()->withSession(['kbb_last_order' => null])
        ->postJson('/checkout/card/paid', ['order' => $order->order_number])
        ->assertStatus(404);

    expect($order->fresh()->paid_at)->toBeNull();
});

it('gives the basket back when the shopper gives up on the card', function () {
    formStripeOn();
    formIntentOpens('pi_bail_form_1');

    $cart = formCart();
    $shopper = formShopper($cart);

    $shopper->postJson('/checkout/place', formFields())->assertOk();

    $order = Order::latest('id')->first();

    expect($cart->fresh()->status)->toBe('converted');

    Http::fake([
        'api.stripe.com/v1/payment_intents/pi_bail_form_1/cancel' => Http::response([
            'id' => 'pi_bail_form_1', 'status' => 'canceled',
        ], 200),
        'api.stripe.com/v1/payment_intents/pi_bail_form_1' => Http::response([
            'id' => 'pi_bail_form_1', 'status' => 'requires_payment_method',
            'amount' => (int) $order->total, 'currency' => 'aed',
        ], 200),
    ]);

    $shopper->postJson('/checkout/card/abandon', ['order' => $order->order_number])
        ->assertOk()->assertJsonPath('ok', true);

    // The order is released — which is what puts the stock back on the shelf
    // and hands the coupon use back, through OrderStatus.
    expect($order->fresh()->status)->toBe('failed');

    // And the basket exists again. `status` is the single field
    // CartService::resolve() decides on, so this is the whole of it.
    expect($cart->fresh()->status)->toBe('active');

    // Provably, rather than by inspection: the checkout renders instead of
    // bouncing an empty bag to /cart/.
    formShopper($cart)->get('/checkout')->assertOk();
});

it('refuses to give the basket back once the card has gone through', function () {
    formStripeOn();
    formIntentOpens('pi_nobail_1');

    $cart = formCart();
    $shopper = formShopper($cart);
    $shopper->postJson('/checkout/place', formFields())->assertOk();

    $order = Order::latest('id')->first();

    Http::fake([
        // Faked although it must never be called — an unfaked URL falls
        // through to a network that is not there, which fails the call for the
        // wrong reason and records nothing for assertNotSent() to catch. See
        // the same note in StripeCardFieldsTest.
        'api.stripe.com/v1/payment_intents/pi_nobail_1/cancel' => Http::response([
            'id' => 'pi_nobail_1', 'status' => 'canceled',
        ], 200),
        'api.stripe.com/v1/payment_intents/pi_nobail_1' => Http::response([
            'id' => 'pi_nobail_1', 'status' => 'succeeded',
            'amount' => (int) $order->total, 'currency' => 'aed',
        ], 200),
    ]);

    /*
     * Money that has moved is a refund, and a decision for the merchant.
     * Releasing this order's stock here would sell its units to somebody else
     * while the shopper's card statement says they bought them.
     */
    $shopper->postJson('/checkout/card/abandon', ['order' => $order->order_number])
        ->assertStatus(409);

    expect($order->fresh()->status)->toBe('pending');
    expect($cart->fresh()->status)->toBe('converted');

    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/cancel'));
});
