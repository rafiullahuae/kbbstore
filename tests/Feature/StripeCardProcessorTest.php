<?php

declare(strict_types=1);

/*
 * "We could not reach our card processor. Please try another payment method."
 * — the owner, 8 October, extrabeauty.ae on 2.60.428, Stripe in TEST mode,
 * with 4000 0025 0000 3155 and then 4242 4242 4242 4242. (Lane ST.)
 *
 * THAT SENTENCE HAS ONE SOURCE: StripeGateway::openIntent(), when POST
 * /v1/payment_intents comes back without an id and a client secret. The card
 * number plays no part in it — the intent is created before Stripe.js has
 * been handed a card, so no test card, good or bad, can produce or avoid it.
 * What can is everything the SERVER sends: the key in the Authorization
 * header, the parameters, the Idempotency-Key, the customer.
 *
 * So each case below drives the owner's flow — Place order on /checkout/place,
 * Stripe chosen — against stFakeStripe(), which answers POST /v1/customers and
 * POST /v1/payment_intents the way Stripe's API does for that request: a key
 * that is not a secret key, a statement-descriptor suffix with no letter, an
 * Idempotency-Key reused with other parameters, a customer the account does
 * not have, an amount below the AED minimum. Each one, before this lane, ended
 * in exactly the owner's sentence. Where the shop can make the request valid
 * it now does; where only the owner can (a wrong key), the till stops offering
 * a card it cannot take and the admin says why in plain words.
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
use App\Services\Payments\GatewayCredentials;
use App\Services\Payments\GatewayRegistry;
use App\Services\Payments\PaymentLog;
use App\Services\Payments\StripeConnect;
use App\Services\Payments\StripeKeys;
use App\Services\Payments\StripePaymentText;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\EnglishRenderWalk;

const ST_SAID = 'We could not reach our card processor. Please try another payment method.';

beforeEach(function () {
    PaymentProvider::query()->delete();
    DB::table(PaymentLog::TABLE)->delete();
    app(GatewayCredentials::class)->forget();

    $zone = ShippingZone::create(['name' => 'UAE', 'position' => 0]);
    ShippingZoneLocation::create(['shipping_zone_id' => $zone->id, 'type' => 'country', 'code' => 'AE']);
    ShippingMethod::create([
        'shipping_zone_id' => $zone->id, 'type' => 'flat_rate', 'title' => 'Standard delivery',
        'cost' => 2000, 'enabled' => true, 'position' => 0,
    ]);
});

/** Stripe on, TEST mode, the test set in the Test boxes — as Store → Payments → Stripe saves it. */
function stStripeOn(array $config = [], string $mode = 'test'): void
{
    $row = PaymentProvider::query()->firstOrNew(['id' => 'stripe']);
    $row->fill(['title' => 'Credit / Debit Card', 'enabled' => true, 'mode' => $mode, 'position' => 0]);
    $row->config = array_merge([
        'publishable_key_test' => 'pk_test_51StAcct',
        'secret_key_test' => 'sk_test_51StAcct',
        'webhook_signing_secret_test' => 'whsec_st_test',
        'webhook_secret' => 'whsec-url-st-0123456789ab',
    ], $config);
    $row->save();

    app(GatewayCredentials::class)->forget();
}

function stCart(int $unitFils = 10000, int $shipping = 2000): Cart
{
    ShippingMethod::query()->update(['cost' => $shipping]);

    $product = Product::create([
        'slug' => 'st-' . uniqid(), 'name' => 'Glow Serum', 'status' => 'publish', 'is_visible' => true,
        'price' => $unitFils / 100, 'stock_status' => 'instock',
    ]);

    $cart = Cart::create([
        'token' => (string) Illuminate\Support\Str::uuid(), 'currency' => 'AED', 'status' => 'active',
        'shipping_country' => 'AE', 'last_activity_at' => now(),
    ]);
    $cart->items()->create(['product_id' => $product->id, 'quantity' => 1, 'unit_price' => $unitFils]);

    return $cart;
}

function stShopper(Cart $cart, ?Customer $customer = null)
{
    $client = test()
        ->withCredentials()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token);

    return $customer === null ? $client : $client->withSession([EnglishRenderWalk::customerSessionKey() => $customer->id]);
}

function stFields(array $overrides = []): array
{
    return array_merge([
        'billing_email' => 'buyer@example.com', 'billing_phone' => '+971500000000',
        'billing_first_name' => 'Aisha', 'billing_last_name' => 'Khan',
        'billing_address_1' => '12 Marina Walk', 'billing_city' => 'Dubai', 'billing_state' => 'Dubai',
        'billing_country' => 'AE', 'payment_method' => 'stripe',
    ], $overrides);
}

/** A fresh request, as on the host (see CheckoutCardRetryTest::ccrNextRequest). */
function stNextRequest(): void
{
    app()->forgetScopedInstances();

    foreach (app('router')->getRoutes() as $route) {
        $route->flushController();
    }

    app(GatewayCredentials::class)->forget();
}

/**
 * Stripe's API, for the two calls the Place-order press makes, answering each
 * request the way Stripe answers it. Every rule is Stripe's documented one:
 *
 *   - Authorization: a SECRET key (sk_/rk_) of this account. A publishable key
 *     is refused: "This API call cannot be made with a publishable API key.
 *     Please use a secret API key." A key the account does not have is
 *     "Invalid API Key provided". Both 401, type invalid_request_error.
 *   - statement_descriptor_suffix: Statement descriptors — "If you use a
 *     prefix and a suffix, both require at least one letter", and prefix +
 *     "* " + suffix is at most 22 characters. 400, param named.
 *   - amount: "The minimum amount is $0.50 US or equivalent in charge
 *     currency" — AED 2.00, 200 fils. 400 amount_too_small.
 *   - customer: one this account does not have is 400 resource_missing,
 *     param customer, "No such customer: 'cus_…'".
 *   - Idempotency-Key: "Keys for idempotent requests can only be used with the
 *     same parameters they were first used with." 400, type
 *     idempotency_error. The same key with the same parameters replays the
 *     first answer.
 */
function stFakeStripe(array $state = []): object
{
    $stripe = new class {
        /** Secret keys this account answers to. */
        public array $keys = ['sk_test_51StAcct'];

        /** Customers that exist in this account. */
        public array $customers = [];

        /** Idempotency-Key => [params hash, response]. */
        public array $replay = [];

        public ?int $prefixLength = 7;          // e.g. "KBEAUTY"

        public bool $down = false;

        public int $intents = 0;

        public int $customerCalls = 0;

        /** @var list<array> every intent body sent */
        public array $sent = [];
    };

    foreach ($state as $k => $v) {
        $stripe->{$k} = $v;
    }

    Http::fake(function (ClientRequest $request) use ($stripe) {
        if ($stripe->down) {
            throw new Illuminate\Http\Client\ConnectionException('cURL error 28: Connection timed out after 20001 milliseconds');
        }

        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        $auth = (string) ($request->header('Authorization')[0] ?? '');
        $key = trim((string) preg_replace('/^Bearer\s*/', '', $auth));

        $error = fn (int $status, array $e) => Http::response(['error' => $e + ['type' => 'invalid_request_error']], $status);

        if ($key === '') {
            return $error(401, ['message' => 'You did not provide an API key.']);
        }

        if (str_starts_with($key, 'pk_')) {
            return $error(401, ['message' => 'This API call cannot be made with a publishable API key. Please use a secret API key.']);
        }

        if (! in_array($key, $stripe->keys, true)) {
            return $error(401, ['message' => 'Invalid API Key provided: ' . substr($key, 0, 8) . '****' . substr($key, -4)]);
        }

        parse_str($request->body(), $body);

        $idem = $request->header('Idempotency-Key')[0] ?? null;
        $hash = sha1($path . json_encode($body));

        if ($idem !== null && isset($stripe->replay[$key . $idem])) {
            [$was, $answer] = $stripe->replay[$key . $idem];

            if ($was !== $hash) {
                return Http::response(['error' => [
                    'type' => 'idempotency_error',
                    'message' => "Keys for idempotent requests can only be used with the same parameters they were first used with. Try using a key other than '{$idem}' if you meant to execute a different request.",
                ]], 400);
            }

            return Http::response($answer[0], $answer[1]);
        }

        $answer = (function () use ($stripe, $path, $body, $request, $error) {
            if ($request->method() === 'POST' && $path === '/v1/customers') {
                $stripe->customerCalls++;
                $id = 'cus_st_' . (count($stripe->customers) + 1);
                $stripe->customers[] = $id;

                return [['id' => $id, 'object' => 'customer'], 200];
            }

            if ($request->method() === 'POST' && $path === '/v1/payment_intents') {
                $stripe->sent[] = $body;

                if ((int) ($body['amount'] ?? 0) < 200) {
                    return [['error' => ['type' => 'invalid_request_error', 'code' => 'amount_too_small', 'param' => 'amount',
                        'message' => 'Amount must be at least د.إ2.00 aed']], 400];
                }

                if (isset($body['customer']) && ! in_array($body['customer'], $stripe->customers, true)) {
                    return [['error' => ['type' => 'invalid_request_error', 'code' => 'resource_missing', 'param' => 'customer',
                        'message' => "No such customer: '{$body['customer']}'"]], 400];
                }

                if (isset($body['statement_descriptor_suffix'])) {
                    $suffix = (string) $body['statement_descriptor_suffix'];

                    if (! preg_match('/[A-Za-z]/', $suffix)
                        || ($stripe->prefixLength ?? 10) + 2 + strlen($suffix) > 22) {
                        return [['error' => ['type' => 'invalid_request_error', 'param' => 'statement_descriptor_suffix',
                            'message' => 'Invalid statement_descriptor_suffix: the statement descriptor must contain at least one letter and be at most 22 characters with your prefix.']], 400];
                    }
                }

                $stripe->intents++;
                $id = 'pi_st_' . $stripe->intents;

                return [['id' => $id, 'object' => 'payment_intent', 'client_secret' => $id . '_secret_x',
                    'status' => 'requires_payment_method', 'amount' => (int) $body['amount'], 'currency' => 'aed'], 200];
            }

            return [['error' => ['type' => 'invalid_request_error', 'message' => 'Unrecognized request URL']], 404];
        })();

        // Stripe keeps the result of any request that started executing,
        // errors included, against its key.
        if ($idem !== null) {
            $stripe->replay[$key . $idem] = [$hash, $answer];
        }

        return Http::response($answer[0], $answer[1]);
    });

    return $stripe;
}

function stLog(string $event): ?array
{
    return PaymentLog::latest(StripeConnect::GATEWAY, $event);
}

/* =====================================================================
 | Control: the owner's flow with nothing wrong opens the payment
 ===================================================================== */

it('opens the payment for the owner\'s flow when every setting is valid', function () {
    stStripeOn();
    $stripe = stFakeStripe();

    stShopper(stCart())->postJson('/checkout/place', stFields())
        ->assertOk()
        ->assertJsonPath('action', 'confirm')
        ->assertJsonPath('client_secret', 'pi_st_1_secret_x');

    expect($stripe->intents)->toBe(1);
});

/* =====================================================================
 | (b) "Add the order number to card statements" — a suffix with no letter
 ===================================================================== */

it('puts a letter in the statement suffix when it carries only the order number', function () {
    /*
     * PROVEN, OURS. The switch "Add the order number to card statements" with
     * the suffix text left empty sent statement_descriptor_suffix = "10234":
     * digits only. Stripe's statement-descriptor rules: "If you use a prefix
     * and a suffix, both require at least one letter." The shop's OWN save
     * validation already enforced that rule on the typed suffix
     * (StripePaymentText::suffixError) — the order-number path skipped it.
     * Every card attempt with the switch on ended in the owner's sentence.
     *
     * MUTATION: drop the lettered() step from StripePaymentText::suffix() →
     * the place answers 422 ST_SAID and the suffix sent is digits only.
     */
    stStripeOn(['statement_descriptor_order_number' => '1']);
    $stripe = stFakeStripe();

    stShopper(stCart())->postJson('/checkout/place', stFields())
        ->assertOk()
        ->assertJsonPath('action', 'confirm');

    $suffix = (string) ($stripe->sent[0]['statement_descriptor_suffix'] ?? '');
    $number = (string) Order::latest('id')->value('order_number');

    expect($suffix)->toMatch('/[A-Za-z]/')
        ->and($suffix)->toContain($number)
        ->and(strlen($suffix))->toBeLessThanOrEqual(22 - 2 - StripePaymentText::PREFIX_MAX);
});

it('builds a suffix Stripe accepts for every prefix length and order number', function () {
    // By construction: a letter, Stripe's characters, and PREFIX* SUFFIX <= 22.
    foreach ([null, 2, 5, 7, 10, 12, 18] as $prefix) {
        foreach (['7', '10234', '1023456', '123456789012'] as $number) {
            foreach (['', 'KBB', '12345', 'Extra Beauty Shop'] as $text) {
                $suffix = StripePaymentText::suffix($text, true, $number, $prefix);
                $room = 22 - 2 - ($prefix ?? StripePaymentText::PREFIX_MAX);

                if ($suffix === null) {
                    expect($room)->toBeLessThan(2, "no suffix for prefix {$prefix} / {$number}");

                    continue;
                }

                expect($suffix)->toMatch('/[A-Za-z]/', "'{$suffix}' has no letter")
                    ->and(strlen($suffix))->toBeLessThanOrEqual($room)
                    ->and(StripePaymentText::suffixError($suffix, $prefix ?? StripePaymentText::PREFIX_MAX))->toBeNull();
            }
        }
    }
});

/* =====================================================================
 | (c) The Idempotency-Key used before with other parameters
 ===================================================================== */

it('retries once with a fresh key when Stripe has seen this order\'s key with other parameters', function () {
    /*
     * PROVEN, OURS. The key is 'kbb-intent-<order number>'. Order numbers are
     * unique inside one install, but an Idempotency-Key is scoped to the
     * Stripe ACCOUNT: a second copy of this shop (the old staging box) on the
     * same test keys, whose order 10234 was a different basket, has already
     * used it. Stripe answers 400 idempotency_error to every attempt for
     * that order number for 24 hours. The request never ran at Stripe, so a
     * fresh key that carries the payload hash is safe and opens the payment.
     *
     * MUTATION: remove the idempotency_error retry in openIntent() → 422
     * ST_SAID and one intent request, not two.
     */
    stStripeOn();
    $stripe = stFakeStripe();

    // The next order number this install will hand out, already spent at
    // Stripe by another install with another amount.
    $next = app(\App\Services\Orders\OrderNumbers::class)->allocate();
    DB::table(\App\Services\Orders\OrderNumbers::TABLE)->update(['next_number' => (int) $next]);
    $stripe->replay['sk_test_51StAcct' . 'kbb-intent-' . $next] = ['someone-else', [['id' => 'pi_other_install'], 200]];

    stShopper(stCart())->postJson('/checkout/place', stFields())
        ->assertOk()
        ->assertJsonPath('action', 'confirm')
        ->assertJsonPath('client_secret', 'pi_st_1_secret_x');

    expect((string) Order::latest('id')->value('order_number'))->toBe($next)
        ->and(Order::latest('id')->value('transaction_id'))->toBe('pi_st_1');
});

/* =====================================================================
 | (d) A saved Stripe customer the connected account does not have
 ===================================================================== */

it('recreates a saved Stripe customer the account does not know, and takes the payment', function () {
    /*
     * PROVEN, OURS. `customers.stripe_customer_ids` keeps one cus_ per MODE,
     * not per account. Keys from another Stripe account or sandbox — the
     * owner re-entered his after 2.60.425 — leave the stored test id pointing
     * at a customer this account never had. A signed-in shopper who ticks
     * "Save this card" sends it, Stripe answers 400 resource_missing (param
     * customer), and the payment never opens.
     *
     * MUTATION: remove the resource_missing branch in openIntent() → 422
     * ST_SAID, and the stale cus_ is still stored.
     */
    stStripeOn();
    $stripe = stFakeStripe();

    $customer = Customer::create(['email' => 'saver@example.com', 'name' => 'Saver', 'password' => 'secret-secret']);
    $customer->rememberStripeCustomerId('test', 'cus_from_another_account');

    stShopper(stCart(), $customer)
        ->postJson('/checkout/place', stFields(['billing_email' => 'saver@example.com', 'save_card' => '1']))
        ->assertOk()
        ->assertJsonPath('action', 'confirm');

    $last = end($stripe->sent);

    expect($last['customer'] ?? null)->toBe('cus_st_1')
        ->and($last['setup_future_usage'] ?? null)->toBe('on_session')
        ->and($customer->fresh()->stripeCustomerId('test'))->toBe('cus_st_1');
});

/* =====================================================================
 | (a) Keys: a publishable key in the secret box, keys of the other mode
 ===================================================================== */

it('refuses a publishable key typed into a secret-key box, and a secret key in a publishable box', function () {
    /*
     * PROVEN, OURS. validateConfig() checked a key's MODE but not its KIND:
     * pk_test_… in "Test secret key" is "test", so it was saved. The card
     * fields then render (the publishable box is right) and every Place
     * order sends "Authorization: Bearer pk_test_…", which Stripe refuses
     * with 401 — the owner's sentence. The reverse is worse: an sk_ in the
     * publishable box is printed into every checkout page.
     *
     * MUTATION: drop the keyKind check from validateConfig() → both saves
     * succeed (200).
     */
    stStripeOn();
    $gateway = (new GatewayRegistry())->find('stripe');

    $errors = $gateway->validateConfig(['secret_key_test' => 'pk_test_51StAcct', 'publishable_key_test' => 'sk_test_51StAcct']);

    expect($errors['secret_key_test'] ?? '')->toContain('publishable key')
        ->and($errors['publishable_key_test'] ?? '')->toContain('SECRET key');
});

it('does not offer cards when the secret key in force is not a secret key, and says why', function () {
    /*
     * The same mistake already saved (before the check above existed). The
     * till must not offer a card form the server cannot open a payment for,
     * and the Stripe status block names the box.
     *
     * MUTATION: drop the kind check from StripeKeys::get() → availableFor()
     * is true and the place answers 422 ST_SAID.
     */
    stStripeOn(['secret_key_test' => 'pk_test_51StAcct']);
    $stripe = stFakeStripe();
    $gateway = (new GatewayRegistry())->find('stripe');

    expect($gateway->availableFor(12000))->toBeFalse()
        ->and($gateway->configured())->toBeFalse();

    stShopper(stCart())->postJson('/checkout/place', stFields())->assertStatus(422);
    expect($stripe->sent)->toBe([], 'a payment was attempted with a publishable key as the secret');

    $warnings = implode(' ', app(StripeConnect::class)->settingsStatus()['warnings']);
    expect($warnings)->toContain('Test secret key')->and($warnings)->toContain('PUBLISHABLE key');
});

it('never pairs a key of one mode with a key of the other', function () {
    /*
     * PROVEN, OURS. A single-set shop holding sk_test_ in "secret_key" and
     * pk_live_ in "publishable_key" (the live pk is accepted in the Live
     * box) was resolved by StripeKeys::get() as sk_test + pk_live in TEST
     * mode, and normalise() then MOVED the pk_live_ into the Test box.
     * Stripe.js boots on a live key while the server opens test intents.
     *
     * MUTATION: drop the mode check from StripeKeys::get() → the first
     * expectation reads 'pk_live_51StAcct'.
     */
    $config = ['secret_key' => 'sk_test_51StAcct', 'publishable_key' => 'pk_live_51StAcct'];

    expect(StripeKeys::get($config, 'test', 'publishable_key'))->toBe('')
        ->and(StripeKeys::get($config, 'test', 'secret_key'))->toBe('sk_test_51StAcct')
        ->and(StripeKeys::normalise($config))->not->toHaveKey('publishable_key_test')
        ->and(StripeKeys::normalise($config))->not->toHaveKey('publishable_key');
});

it('rules out an empty secret key as the source of the sentence: it is a different sentence', function () {
    // RULED OUT. configured() is checked first, so no key at all never
    // reaches Stripe and never says "could not reach".
    stStripeOn(['secret_key_test' => null]);
    $gateway = (new GatewayRegistry())->find('stripe');

    expect($gateway->start(Order::create([
        'order_number' => '55501', 'email' => 'a@example.com', 'status' => 'pending',
        'currency' => 'AED', 'subtotal' => 12000, 'total' => 12000, 'payment_method' => 'stripe',
    ]))->message)->toBe('Card payment is not available right now.');
});

/* =====================================================================
 | (e) Below Stripe's AED minimum
 ===================================================================== */

it('does not offer a card for a total below Stripe\'s AED 2.00 minimum', function () {
    /*
     * PROVEN, OURS (edge). Stripe refuses a charge below its minimum — AED
     * 2.00 — with amount_too_small. The till offered the card for any total
     * above zero, so a 1.50 basket got the card form and then the sentence.
     *
     * MUTATION: drop MIN_FILS from availableFor() → true for 150.
     */
    stStripeOn();
    $gateway = (new GatewayRegistry())->find('stripe');

    expect($gateway->availableFor(150))->toBeFalse()
        ->and($gateway->availableFor(200))->toBeTrue();
});

/* =====================================================================
 | (f) Transport, and what the owner reads
 ===================================================================== */

it('keeps the shopper\'s sentence generic and writes the plain reason in the payment log', function () {
    /*
     * The shopper never sees Stripe's words. The owner does: Store →
     * Payments → Stripe → Payment log, "What happened" column, and the same
     * sentence at the top of the Stripe card until a payment opens again.
     *
     * MUTATION: put back the fixed "Stripe did not open a payment for this
     * order." message in openIntent() → the reason expectations go red.
     */
    stStripeOn(['secret_key_test' => 'sk_test_51RolledKey']);
    stFakeStripe();

    stShopper(stCart())->postJson('/checkout/place', stFields())
        ->assertStatus(422)
        ->assertJsonPath('error', ST_SAID);

    $failed = stLog('intent.failed');

    expect($failed['message'])->toContain('Stripe refused the secret key')
        ->and($failed['context']['http_status'])->toBe(401)
        ->and($failed['context']['error_message'])->toContain('Invalid API Key provided')
        ->and(json_encode($failed))->not->toContain('sk_test_51RolledKey');

    $status = app(StripeConnect::class)->settingsStatus();

    expect($status['last_failure']['message'] ?? '')->toContain('Stripe refused the secret key');
});

it('tells the owner the server could not reach Stripe at all when the connection fails', function () {
    stStripeOn();
    stFakeStripe(['down' => true]);

    stShopper(stCart())->postJson('/checkout/place', stFields())
        ->assertStatus(422)
        ->assertJsonPath('error', ST_SAID);

    expect(stLog('intent.failed')['message'])->toContain('could not reach Stripe');
});

it('clears the last-failure line once a payment opens again', function () {
    stStripeOn(['secret_key_test' => 'sk_test_51RolledKey']);
    $stripe = stFakeStripe();
    $cart = stCart();

    stShopper($cart)->postJson('/checkout/place', stFields())->assertStatus(422);
    expect(app(StripeConnect::class)->settingsStatus()['last_failure'])->not->toBeNull();

    stNextRequest();
    stStripeOn();

    stShopper($cart)->postJson('/checkout/place', stFields())->assertOk();
    expect(app(StripeConnect::class)->settingsStatus()['last_failure'])->toBeNull();
});

it('draws the last failure at the top of the Stripe card on Store → Payments', function () {
    // MUTATION: drop the s.last_failure block from stripe-settings-panel →
    // red. The status endpoint already carries it (test above).
    $panel = file_get_contents(resource_path('views/admin/partials/stripe-settings-panel.blade.php'));

    expect(substr_count($panel, 'id="srs-last-failure"'))->toBe(1)
        ->and($panel)->toContain('Last card payment that could not start')
        ->and($panel)->toContain('esc(f.message)');
});
