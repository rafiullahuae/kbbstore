<?php

/**
 * The card form as the owner asked for it — Lane FY.
 *
 * Three things he asked for after paying with the form that shipped in
 * 2.60.215, and one he did not ask for but which every one of them has to keep
 * true:
 *
 *   1. one short line with a padlock instead of the paragraph that stood above
 *      the fields;
 *   2. the card number on its own row, expiry and security code beside each
 *      other under it, each labelled, each in its own box;
 *   3. a tick to keep the card for next time;
 *   4. and STILL no input of ours anywhere near a card number. The fields are
 *      three Stripe-hosted iframes rather than one, which changes whose
 *      document draws the box and nothing whatever about whose document holds
 *      the value.
 *
 * Everything Stripe answers here is Http::fake(). What that proves is what this
 * application sends and what its pages contain; it cannot prove Stripe agrees
 * with any of it. What still needs one real test-mode payment is named in
 * docs/FY-CHECKOUT-CARD-AND-PREFILL.md.
 */

use App\Models\Cart;
use App\Models\Customer;
use App\Models\Order;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\CartService;
use Illuminate\Support\Facades\Http;
use Tests\Support\EnglishRenderWalk;

const FY_PK = 'pk_test_kbb_fy_key';

beforeEach(function () {
    PaymentProvider::query()->delete();
    app(\App\Services\Payments\GatewayCredentials::class)->forget();

    /*
     * The two card endpoints, registered here for the reason
     * CheckoutCardFormTest's own beforeEach gives: routes/web.php belongs to
     * the integrator, routes/checkout-card.php carries the definitions, and
     * until that one `require` lands both paths 404 in this application.
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

/** Stripe configured and on offer, with whatever extra config the case wants. */
function fyStripeOn(array $config = [], string $mode = 'test'): void
{
    $row = PaymentProvider::create([
        'id' => 'stripe', 'title' => 'Credit / Debit Card', 'enabled' => true, 'mode' => $mode, 'position' => 0,
    ]);

    $row->config = array_merge([
        'publishable_key' => FY_PK,
        'secret_key' => 'sk_test_kbb_fy',
        'webhook_signing_secret' => 'whsec_fy_signing',
        'webhook_secret' => 'whsec-url-fy-0123456789ab',
    ], $config);

    $row->save();

    app(\App\Services\Payments\GatewayCredentials::class)->forget();
}

function fyCart(): Cart
{
    $product = Product::create([
        'slug' => 'serum-' . uniqid(),
        'name' => 'Test Serum',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 200,
        'stock_status' => 'instock',
    ]);

    $cart = Cart::create([
        'token' => bin2hex(random_bytes(16)),
        'status' => 'active',
        'currency' => 'AED',
    ]);

    $cart->items()->create([
        'product_id' => $product->id,
        'quantity' => 1,
        'unit_price' => 20000,
    ]);

    return $cart->fresh('items');
}

/** A browser with this basket in its cookie jar. */
function fyShopper(Cart $cart)
{
    return test()
        ->withCredentials()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token);
}

/** The same, signed in as $customer — through the session, as a browser is. */
function fySignedIn(Cart $cart, Customer $customer)
{
    return fyShopper($cart)->withSession([EnglishRenderWalk::customerSessionKey() => $customer->id]);
}

function fyFields(array $overrides = []): array
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
        'payment_method' => 'stripe',
    ], $overrides);
}

/** Stripe answering both calls this lane can make. */
function fyStripeAnswers(string $intent = 'pi_fy_1', string $customer = 'cus_fy_1'): void
{
    Http::fake([
        'api.stripe.com/v1/customers' => Http::response(['id' => $customer], 200),
        'api.stripe.com/v1/payment_intents' => Http::response([
            'id' => $intent,
            'client_secret' => $intent . '_secret_value',
            'status' => 'requires_payment_method',
        ], 200),
    ]);
}

/** The body of the POST that opened a PaymentIntent, as Stripe received it. */
function fyIntentPayload(): array
{
    $found = [];

    foreach (Http::recorded() as [$request]) {
        if (str_ends_with($request->url(), '/v1/payment_intents') && $request->method() === 'POST') {
            $found = $request->data();
        }
    }

    return $found;
}

function fyCustomerCalls(): int
{
    $calls = 0;

    foreach (Http::recorded() as [$request]) {
        if (str_ends_with($request->url(), '/v1/customers') && $request->method() === 'POST') {
            $calls++;
        }
    }

    return $calls;
}

/** Just the card form, cut out of the rendered checkout. */
function fyCardMarkup(string $html): string
{
    $start = strpos($html, '<div class="kbb-card" data-kbb-card>');

    expect($start)->not->toBeFalse();

    $end = strpos($html, 'data-kbb-card-bail', $start);

    expect($end)->not->toBeFalse();

    return substr($html, $start, $end - $start);
}

/* =====================================================================
 | 1 & 2 — the wording above the fields, and the layout of them
 ===================================================================== */

it('draws the card number, the expiry and the security code in their own labelled boxes', function () {
    fyStripeOn();

    $html = fyShopper(fyCart())->get('/checkout')->assertOk()->getContent();

    /*
     * Each needle is its own expect(): toContain() is VARIADIC, so passing a
     * message as a second argument makes it a second needle and the assertion
     * silently stops being the one that was written — see
     * tests/Feature/ExpectationsThatCannotFailTest.php.
     */
    // Three mount boxes where there was one.
    expect($html)->toContain('data-kbb-card-el="number"');
    expect($html)->toContain('data-kbb-card-el="expiry"');
    expect($html)->toContain('data-kbb-card-el="cvc"');

    // Three Stripe Elements to go in them, and NOT the combined one.
    expect($html)->toContain("cardNumber");
    expect($html)->toContain("cardExpiry");
    expect($html)->toContain("cardCvc");

    // A label above each, keyed like the rest of the checkout.
    expect($html)->toContain('Card number');
    expect($html)->toContain('Expiration date');
    expect($html)->toContain('Security code');

    // And the expiry and the security code share one row: the pair wrapper is
    // what puts them beside each other, and it is the thing the reference
    // asked for that CSS alone could not have produced from one element.
    expect($html)->toContain('kbb-card-pair');
});

it('replaces the paragraph above the fields with one line and a padlock', function () {
    fyStripeOn();

    $html = fyShopper(fyCart())->get('/checkout')->assertOk()->getContent();

    // The line the owner asked for.
    expect($html)->toContain('100% secure &amp; encrypted — Use any card');

    // And the three sentences it replaced, which are gone from the page rather
    // than merely moved down it.
    expect($html)->not->toContain('Pay securely by card. Enter your card details below');
    expect($html)->not->toContain('Your bank may ask you to confirm the payment.');
    // The smaller note that used to sit under the fields went with them: two
    // security sentences round one form is the noise he asked to be rid of.
    expect($html)->not->toContain('never reach this site');

    // ABOVE the fields, not below them. A reassurance printed under a form is
    // read after the decision it was meant to inform.
    $line = strpos($html, 'kbb-card-secure');
    $number = strpos($html, 'data-kbb-card-el="number"');

    expect($line)->not->toBeFalse();
    expect($number)->toBeGreaterThan($line);
});

it('keeps the card option-s own box although the gateway no longer has a description', function () {
    fyStripeOn();

    $html = fyShopper(fyCart())->get('/checkout')->assertOk()->getContent();

    /*
     * THE ONE THAT BREAKS THE CHECKOUT IF IT IS GOT WRONG.
     *
     * The fields are revealed by kbb-checkout.css's `:has(input:checked)` rule
     * on .payment_box, and that box used to be rendered only for a gateway with
     * a description to put in it. StripeGateway::description() now returns
     * null, so a payment-methods partial left as it was would draw no box — and
     * the card fields live inside it, so they would vanish with the words.
     */
    expect($html)->toContain('payment_box payment_method_stripe');
    expect(strpos($html, 'data-kbb-card-el="number"'))
        ->toBeGreaterThan(strpos($html, 'payment_box payment_method_stripe'));
});

it('has no input of ours that could hold a card number', function () {
    fyStripeOn();

    $html = fyShopper(fyCart())->get('/checkout')->assertOk()->getContent();
    $card = fyCardMarkup($html);

    /*
     * The property that made the on-site form acceptable in the first place,
     * and the one a rebuild of its layout is most likely to lose: somebody
     * laying out three boxes reaches for three <input>s. There is exactly one
     * input inside the card form and it is the save-card tick.
     */
    expect(substr_count($card, '<input'))->toBe(1);
    expect($card)->toContain('name="save_card"');

    // Nothing anywhere on the checkout asks a browser to autofill a card into
    // it, which is the other shape this mistake takes.
    expect($html)->not->toContain('cc-number');
    expect($html)->not->toContain('cc-exp');
    expect($html)->not->toContain('cc-csc');
});

/* =====================================================================
 | 4 — Stripe Link, off unless the shop says otherwise
 ===================================================================== */

it('switches Stripe Link off unless the shop has switched it on', function () {
    fyStripeOn();

    $html = fyShopper(fyCart())->get('/checkout')->assertOk()->getContent();

    // The flag the card number element is created with. False here means the
    // page asks Stripe NOT to draw the Link prompt.
    expect($html)->toContain('var LINK = false;');
});

it('offers Stripe Link when the shop switches it on, without an edit to any file', function () {
    fyStripeOn(['link_enabled' => '1']);

    $html = fyShopper(fyCart())->get('/checkout')->assertOk()->getContent();

    expect($html)->toContain('var LINK = true;');
});

it('does not report the Link switch as a credential still to be pasted in', function () {
    fyStripeOn();

    $report = app(\App\Services\Payments\GatewayPreflight::class)->inspect('stripe');

    /*
     * "Check this setup" exists to list what is still missing. An empty switch
     * is not missing — it is off, which is what the owner asked for — and
     * listing it would mean the only way to clear that screen was to switch on
     * a thing he wanted switched off.
     */
    expect(array_column($report['missing_fields'], 'key'))->toBe([]);
    expect($report['blocked_by'])->toBe([]);
});

/* =====================================================================
 | 3 — "save this card", and who it is offered to
 ===================================================================== */

it('offers to keep the card to a customer who is signed in', function () {
    fyStripeOn();

    $customer = Customer::create(['email' => 'saver@example.com', 'name' => 'Saver', 'password' => 'secret-secret']);

    $html = fySignedIn(fyCart(), $customer)->get('/checkout')->assertOk()->getContent();
    $card = fyCardMarkup($html);

    expect($card)->toContain('name="save_card"');
    // Shown, not merely present: `hidden` is what the guest case carries.
    expect($card)->not->toContain('data-kbb-card-save-row hidden');
});

it('hides the tick from a guest who is not making an account', function () {
    fyStripeOn();

    $html = fyShopper(fyCart())->get('/checkout')->assertOk()->getContent();
    $card = fyCardMarkup($html);

    /*
     * A saved card is worth nothing to somebody who can never be recognised
     * again, and a tick that cannot mean anything is the defect this project
     * has a phase about. The row is in the markup — the script reveals it the
     * moment "create an account" is ticked — and it is hidden until then.
     */
    expect($card)->toContain('data-kbb-card-save-row hidden');
});

it('keeps the card when a signed-in customer asks for it', function () {
    fyStripeOn();
    fyStripeAnswers('pi_save_1', 'cus_save_1');

    $customer = Customer::create(['email' => 'saver@example.com', 'name' => 'Saver', 'password' => 'secret-secret']);

    fySignedIn(fyCart(), $customer)
        ->postJson('/checkout/place', fyFields(['billing_email' => 'saver@example.com', 'save_card' => '1']))
        ->assertOk()
        ->assertJsonPath('action', 'confirm');

    $payload = fyIntentPayload();

    // A Customer to hang the card off, and the instruction to keep it.
    expect($payload['customer'] ?? null)->toBe('cus_save_1');
    /*
     * on_session, not off_session. The tick says "save this card for future
     * purchases" — purchases this shopper makes, at a checkout they are looking
     * at. off_session would claim the right to charge the card while they are
     * away and would ask the issuer for the authentication that goes with that
     * claim: a larger promise than the one on the page.
     */
    expect($payload['setup_future_usage'] ?? null)->toBe('on_session');

    // And the Stripe Customer was remembered, keyed by the mode it was made in.
    expect($customer->fresh()->stripeCustomerId('test'))->toBe('cus_save_1');
    expect($customer->fresh()->stripeCustomerId('live'))->toBeNull();
});

it('keeps the card for a guest who creates an account in the same checkout', function () {
    fyStripeOn();
    fyStripeAnswers('pi_save_2', 'cus_save_2');

    fyShopper(fyCart())
        ->postJson('/checkout/place', fyFields([
            'billing_email' => 'newcomer@example.com',
            'billing_phone' => '+971500000000',
            'create_account' => '1',
            'account_password' => 'a-good-password',
            'save_card' => '1',
        ]))
        ->assertOk();

    expect(fyIntentPayload()['setup_future_usage'] ?? null)->toBe('on_session');

    // The account that was made in the same breath is the one it hangs off.
    $customer = Customer::where('email', 'newcomer@example.com')->first();

    expect($customer)->not->toBeNull();
    expect($customer->stripeCustomerId('test'))->toBe('cus_save_2');
});

it('will not hang a card off an account the shopper has not proved is theirs', function () {
    fyStripeOn();
    fyStripeAnswers('pi_save_3', 'cus_save_3');

    /*
     * THE ATTACK THIS GUARD IS FOR, and it is not hypothetical — it falls out
     * of how place() already works. A guest's order is attached to an EXISTING
     * customer row whenever the email address matches one (firstOrCreate),
     * which is right for the order history. So somebody who knows an address
     * can type it, tick a box the page did not show them, and — if the tick
     * alone were honoured — attach their card to a stranger's account, to be
     * offered back to that stranger at their next checkout.
     */
    Customer::create(['email' => 'victim@example.com', 'name' => 'Victim', 'password' => 'secret-secret']);

    fyShopper(fyCart())
        ->postJson('/checkout/place', fyFields(['billing_email' => 'victim@example.com', 'save_card' => '1']))
        ->assertOk();

    $payload = fyIntentPayload();

    expect($payload['setup_future_usage'] ?? null)->toBeNull();
    expect($payload['customer'] ?? null)->toBeNull();
    // Not even asked for: no Stripe Customer was created for somebody else.
    expect(fyCustomerCalls())->toBe(0);
    expect(Customer::where('email', 'victim@example.com')->first()->stripeCustomerId('test'))->toBeNull();
});

it('takes the payment anyway when Stripe will not make a customer', function () {
    fyStripeOn();

    Http::fake([
        'api.stripe.com/v1/customers' => Http::response(['error' => ['code' => 'api_error']], 500),
        'api.stripe.com/v1/payment_intents' => Http::response([
            'id' => 'pi_nocust', 'client_secret' => 'pi_nocust_secret', 'status' => 'requires_payment_method',
        ], 200),
    ]);

    $customer = Customer::create(['email' => 'saver@example.com', 'name' => 'Saver', 'password' => 'secret-secret']);

    fySignedIn(fyCart(), $customer)
        ->postJson('/checkout/place', fyFields(['billing_email' => 'saver@example.com', 'save_card' => '1']))
        ->assertOk()
        ->assertJsonPath('action', 'confirm');

    /*
     * Losing the sale because a convenience failed would be much the worse of
     * the two outcomes. The payment goes through exactly as it would have
     * without the tick, nothing is saved, and the order is real.
     */
    expect(fyIntentPayload()['setup_future_usage'] ?? null)->toBeNull();
    expect(Order::latest('id')->first()->status)->toBe('pending');
    expect($customer->fresh()->stripeCustomerId('test'))->toBeNull();
});

it('does not make a second Stripe customer for a shopper who already has one', function () {
    fyStripeOn();
    fyStripeAnswers('pi_again', 'cus_again');

    $customer = Customer::create(['email' => 'saver@example.com', 'name' => 'Saver', 'password' => 'secret-secret']);
    $customer->rememberStripeCustomerId('test', 'cus_from_last_time');

    fySignedIn(fyCart(), $customer)
        ->postJson('/checkout/place', fyFields(['billing_email' => 'saver@example.com', 'save_card' => '1']))
        ->assertOk();

    expect(fyCustomerCalls())->toBe(0);
    expect(fyIntentPayload()['customer'] ?? null)->toBe('cus_from_last_time');
});

it('does not send a test-mode customer to a shop that has gone live', function () {
    fyStripeOn(mode: 'live');
    fyStripeAnswers('pi_live', 'cus_live_new');

    $customer = Customer::create(['email' => 'saver@example.com', 'name' => 'Saver', 'password' => 'secret-secret']);
    $customer->rememberStripeCustomerId('test', 'cus_from_the_sandbox');

    fySignedIn(fyCart(), $customer)
        ->postJson('/checkout/place', fyFields(['billing_email' => 'saver@example.com', 'save_card' => '1']))
        ->assertOk();

    /*
     * A `cus_...` made with test keys does not exist to an account using live
     * ones, and Stripe refuses the whole PaymentIntent rather than merely
     * declining to save the card — so sending the sandbox's customer here would
     * not be a lost convenience, it would be a shopper who cannot pay at all.
     */
    expect(fyIntentPayload()['customer'] ?? null)->toBe('cus_live_new');
    expect($customer->fresh()->stripeCustomerId('test'))->toBe('cus_from_the_sandbox');
    expect($customer->fresh()->stripeCustomerId('live'))->toBe('cus_live_new');
});

it('opens an ordinary intent when nobody ticked anything', function () {
    fyStripeOn();
    fyStripeAnswers('pi_plain', 'cus_plain');

    $customer = Customer::create(['email' => 'saver@example.com', 'name' => 'Saver', 'password' => 'secret-secret']);

    fySignedIn(fyCart(), $customer)
        ->postJson('/checkout/place', fyFields(['billing_email' => 'saver@example.com']))
        ->assertOk();

    // The default is untouched: no customer, no setup_future_usage, and no call
    // to /v1/customers at all.
    expect(fyIntentPayload()['setup_future_usage'] ?? null)->toBeNull();
    expect(fyCustomerCalls())->toBe(0);
});

/* =====================================================================
 | Reuse — an intent already open against this order
 ===================================================================== */

/** An order sitting `pending` with an intent already against it. */
function fyOrderWithIntent(Customer $customer, string $intentId): Order
{
    return Order::create([
        'order_number' => 'FY-' . uniqid(),
        'customer_id' => $customer->id,
        'email' => $customer->email,
        'status' => 'pending',
        'currency' => 'AED',
        'subtotal' => 40000,
        'total' => 40000,
        'payment_method' => 'stripe',
        'transaction_id' => $intentId,
    ]);
}

function fyGateway(): \App\Services\Payments\Gateways\StripeGateway
{
    return app(\App\Services\Payments\GatewayRegistry::class)->find('stripe');
}

it('will not reuse an intent that cannot keep the card', function () {
    fyStripeOn();

    Http::fake([
        'api.stripe.com/v1/payment_intents/pi_plainish' => Http::response([
            'id' => 'pi_plainish', 'client_secret' => 'pi_plainish_secret',
            'status' => 'requires_payment_method', 'amount' => 40000, 'currency' => 'aed',
            // Opened without it, because nothing was ticked the first time.
            'setup_future_usage' => null,
        ], 200),
        'api.stripe.com/v1/customers' => Http::response(['id' => 'cus_reuse'], 200),
        'api.stripe.com/v1/payment_intents' => Http::response([
            'id' => 'pi_saving', 'client_secret' => 'pi_saving_secret', 'status' => 'requires_payment_method',
        ], 200),
    ]);

    $customer = Customer::create(['email' => 'saver@example.com', 'name' => 'Saver', 'password' => 'secret-secret']);

    $start = fyGateway()->startAndSaveCard(fyOrderWithIntent($customer, 'pi_plainish'));

    /*
     * `setup_future_usage` is fixed when an intent is created. Reusing one that
     * was opened without it would take the money, leave the tick on the page,
     * and save nothing — the tick meaning nothing is the whole defect this
     * feature was warned about.
     */
    expect($start->providerRef)->toBe('pi_saving');
    expect(fyIntentPayload()['setup_future_usage'] ?? null)->toBe('on_session');
});

it('will not reuse a saving intent for a shopper who has taken the tick back off', function () {
    fyStripeOn();

    Http::fake([
        'api.stripe.com/v1/payment_intents/pi_wassaving' => Http::response([
            'id' => 'pi_wassaving', 'client_secret' => 'pi_wassaving_secret',
            'status' => 'requires_payment_method', 'amount' => 40000, 'currency' => 'aed',
            'setup_future_usage' => 'on_session', 'customer' => 'cus_old',
        ], 200),
        'api.stripe.com/v1/payment_intents' => Http::response([
            'id' => 'pi_plain_again', 'client_secret' => 'pi_plain_again_secret', 'status' => 'requires_payment_method',
        ], 200),
    ]);

    $customer = Customer::create(['email' => 'saver@example.com', 'name' => 'Saver', 'password' => 'secret-secret']);

    // The other direction, and it matters just as much: confirming this intent
    // would keep a card the shopper has asked us not to keep.
    $start = fyGateway()->start(fyOrderWithIntent($customer, 'pi_wassaving'));

    expect($start->providerRef)->toBe('pi_plain_again');
    expect(fyIntentPayload()['setup_future_usage'] ?? null)->toBeNull();
});

it('will not reuse a saving intent that belongs to another Stripe customer', function () {
    fyStripeOn();

    Http::fake([
        'api.stripe.com/v1/payment_intents/pi_someone_else' => Http::response([
            'id' => 'pi_someone_else', 'client_secret' => 'pi_someone_else_secret',
            'status' => 'requires_payment_method', 'amount' => 40000, 'currency' => 'aed',
            'setup_future_usage' => 'on_session',
            // Saving, and saving to somebody who is not this shopper.
            'customer' => 'cus_not_this_one',
        ], 200),
        'api.stripe.com/v1/payment_intents' => Http::response([
            'id' => 'pi_mine', 'client_secret' => 'pi_mine_secret', 'status' => 'requires_payment_method',
        ], 200),
    ]);

    $customer = Customer::create(['email' => 'saver@example.com', 'name' => 'Saver', 'password' => 'secret-secret']);
    $customer->rememberStripeCustomerId('test', 'cus_mine');

    /*
     * Confirming this one would file the card under a Stripe Customer that is
     * not this shopper's — which is the thing the guard in place() exists to
     * stop happening at the other end of the same path. The intent id is
     * whatever `orders.transaction_id` says, and that column has been written
     * by an earlier attempt, a mode switch or a retry; it is not a statement
     * about who the intent belongs to.
     */
    $start = fyGateway()->startAndSaveCard(fyOrderWithIntent($customer, 'pi_someone_else'));

    expect($start->providerRef)->toBe('pi_mine');
    expect(fyIntentPayload()['customer'] ?? null)->toBe('cus_mine');
});
