<?php

/**
 * Tabby — the gaps between this port and the merchant's own plugin.
 *
 * This file is an audit turned into assertions. Every case below is a defect
 * that was live in App\Services\Payments\Gateways\TabbyGateway, or a capability
 * of the WooCommerce plugin that had no equivalent here at all, and each one
 * carries the mutation that turns it red again.
 *
 * WHAT COULD NOT BE TESTED, AND IS SAID SO RATHER THAN IMPLIED: egress is
 * blocked in this container, so nothing here has spoken to api.tabby.ai. Every
 * response is Http::fake() built to the shapes the plugin reads out of the real
 * API — `configuration.available_products.<product>[0].web_url`,
 * `payment.id`, `captures[]`, `refunds[]`, `errorType`, and `{url, is_test}` on
 * a webhook registration. The CONTRACT is verified; the handshake is not.
 *
 * Http::preventStrayRequests() is on throughout. A call this file did not fake
 * is an error, which is what makes "it did not go anywhere near Tabby" a real
 * assertion rather than a hopeful one.
 */

use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\Order;
use App\Models\PaymentEvent;
use App\Models\PaymentProvider;
use App\Services\Payments\GatewayCredentials;
use App\Services\Payments\GatewayRegistry;
use App\Services\Payments\Gateways\TabbyGateway;
use App\Services\Payments\PaymentVoider;
use App\Support\AdminCapabilities;
use Illuminate\Support\Facades\Http;
use Tests\Support\TabbyAdminRoutes;

beforeEach(function () {
    PaymentProvider::query()->delete();
    app(GatewayCredentials::class)->forget();
    Http::preventStrayRequests();
});

/* ------------------------------------------------------------------ fixtures */

function tg(array $config = []): TabbyGateway
{
    $row = PaymentProvider::create([
        'id' => 'tabby',
        'title' => 'Tabby',
        'enabled' => true,
        'mode' => 'test',
        'position' => 1,
    ]);

    $row->config = array_merge([
        'public_key' => 'pk_test_11111111-2222-3333-4444-555555555555',
        'secret_key' => 'sk_test_11111111-2222-3333-4444-555555555555',
        'merchant_code' => 'AE',
        'webhook_secret' => 'whsec-tabby-abcdefghijklmnopqrstuvwxyz012345',
    ], $config);

    $row->save();

    app(GatewayCredentials::class)->forget();

    /** @var TabbyGateway $gateway */
    $gateway = app(GatewayRegistry::class)->find('tabby');

    return $gateway;
}

function tgOrder(array $attributes = []): Order
{
    $order = Order::create(array_merge([
        'order_number' => 'TG-' . uniqid(),
        'email' => 'buyer@example.com',
        'status' => 'processing',
        'currency' => 'AED',
        'subtotal' => 25000,
        'shipping_total' => 0,
        'discount_total' => 0,
        'tax_total' => 0,
        'total' => 25000,
        'payment_method' => 'tabby',
    ], $attributes));

    $order->items()->create([
        'name' => 'Snail Mucin Essence',
        'sku' => 'COS-SNAIL-1',
        'quantity' => 2,
        'unit_price' => 15000,
        'subtotal' => 30000,
        // 250.00 charged for 300.00 of goods: a 50.00 line discount, which is
        // what makes the unit_price case below mean anything.
        'total' => 25000,
    ]);

    return $order->fresh();
}

/** The `POST /api/v2/checkout` answer, in the shape the plugin reads. */
function tgCheckoutBody(array $products = ['installments'], string $paymentId = 'pay_real_1'): array
{
    $available = [];

    foreach ($products as $product) {
        $available[$product] = [['web_url' => 'https://checkout.tabby.ai/' . $product . '/session']];
    }

    return [
        // The CHECKOUT SESSION's own id. Not the payment id, and that is the
        // whole point of the case that reads it.
        'id' => 'session_should_not_be_used',
        'status' => 'created',
        'payment' => ['id' => $paymentId],
        'configuration' => ['available_products' => $available],
    ];
}

function tgAdmin(string $tag, string $role = 'owner'): AdminUser
{
    return AdminUser::create([
        'name' => 'Tabby Admin',
        'email' => 'tabby-' . $tag . '-' . uniqid() . '@example.test',
        'password' => 'password-long-enough',
        'role' => $role,
    ]);
}

/* ======================================================================= */
/* THE PAYMENT ID                                                          */
/* ======================================================================= */

it('stores the payment id from the checkout response and not the session id', function () {
    /*
     * THE DEFECT, and it was the expensive one.
     *
     * `POST /api/v2/checkout` answers with a checkout SESSION. Its own id sits
     * at the top level; the id every /payments/{id} endpoint is keyed by sits in
     * the `payment` block. start() read `$result['id']` and wrote the SESSION id
     * into orders.transaction_id, so capture(), refund() and the release below
     * all called /payments/<a session id> — a 404.
     *
     * On the shop it looked like this: a Tabby order authorises, the buyer is
     * charged nothing yet, and the Capture button on the order screen answers
     * "Tabby could not be reached" forever. It was survivable ONLY on a shop
     * whose webhook was registered, because PaymentConfirmer overwrites the
     * column with the id the webhook carries — and registering the webhook was
     * itself impossible before this lane. The two defects hid each other.
     *
     * MUTATION: change start()'s id read back to
     * `(string) ($result['id'] ?? '')` and this is red.
     */
    $gateway = tg();
    $order = tgOrder();

    Http::fake(['api.tabby.ai/api/v2/checkout' => Http::response(tgCheckoutBody(), 200)]);

    $start = $gateway->start($order);

    expect($start->ok())->toBeTrue();
    expect($start->providerRef)->toBe('pay_real_1');
    expect($order->fresh()->transaction_id)->toBe('pay_real_1');
});

it('refuses to redirect when tabby returns no payment id at all', function () {
    // A response with no reference is a response nothing later can act on, so
    // the shopper is sent to another method rather than into a flow whose
    // capture will 404 a week later.
    $gateway = tg();
    $order = tgOrder();

    $body = tgCheckoutBody();
    unset($body['id'], $body['payment']);

    Http::fake(['api.tabby.ai/api/v2/checkout' => Http::response($body, 200)]);

    $start = $gateway->start($order);

    expect($start->ok())->toBeFalse();
    expect($order->fresh()->transaction_id)->toBeNull();
});

/* ======================================================================= */
/* THE PRODUCT                                                             */
/* ======================================================================= */

it('sends the shopper to the installments product and not to whatever came first', function () {
    /*
     * THE DEFECT. `available_products` is a MAP keyed by product, and start()
     * walked it taking the first `web_url` it found. The merchant's own plugin
     * ships payLater and creditCardInstallments with `is_available()` overridden
     * to a flat FALSE — that store offers `installments` and nothing else — so
     * Tabby offering payLater was enough to put a shopper into a pay-in-14-days
     * contract from a radio button that says pay in 4.
     *
     * MUTATION: restore the `foreach ($products as $offers)` loop that took
     * `$offers[0]['web_url']` from the first entry and this is red — PHP
     * preserves insertion order, so payLater is what it would find.
     */
    $gateway = tg();
    $order = tgOrder();

    Http::fake([
        'api.tabby.ai/api/v2/checkout' => Http::response(
            tgCheckoutBody(['payLater', 'installments', 'creditCardInstallments']),
            200,
        ),
    ]);

    $start = $gateway->start($order);

    expect($start->redirectUrl)->toBe('https://checkout.tabby.ai/installments/session');
});

it('treats a response without the installments product as a decline', function () {
    // Tabby's way of saying "not this buyer, not this basket". It is not an
    // outage, so the shopper is told to pick another method rather than retry.
    $gateway = tg();
    $order = tgOrder();

    Http::fake([
        'api.tabby.ai/api/v2/checkout' => Http::response(tgCheckoutBody(['payLater']), 200),
    ]);

    $start = $gateway->start($order);

    expect($start->ok())->toBeFalse();
    expect(str_contains((string) $start->message, 'not available for this order'))->toBeTrue();
    expect($order->fresh()->transaction_id)->toBeNull();
});

it('refuses a redirect url that is not an https tabby address', function () {
    /*
     * An API response is not a trusted source just because it was
     * authenticated. A compromised or mis-proxied answer carrying `javascript:`
     * or an attacker's host would otherwise be handed straight to the browser as
     * a redirect.
     *
     * MUTATION: delete the `! $this->isTabbyUrl($url)` clause from start() and
     * all three cases are red.
     *
     * AND THIS CASE WAS A FALSE PASS UNTIL tgAnswers() EXISTED. It called
     * Http::fake() once per iteration, which MERGES rather than replaces, so only
     * `javascript:alert(1)` was ever served — the two hostile https hosts were
     * never sent to and never tested. See tgAnswers()' own comment; the
     * assertion on the served body at the bottom of the loop is what keeps that
     * from happening again quietly.
     */
    $gateway = tg();
    $answer = tgAnswers();

    foreach ([
        'javascript:alert(1)',
        'http://checkout.tabby.ai/installments/session',
        'https://checkout.tabby.ai.evil.example/installments',
        'https://evil.example/checkout.tabby.ai/installments',
        '//checkout.tabby.ai/installments/session',
    ] as $hostile) {
        $order = tgOrder();

        $body = tgCheckoutBody();
        $body['configuration']['available_products']['installments'][0]['web_url'] = $hostile;

        $answer(fn () => Http::response($body, 200));

        $start = $gateway->start($order);

        expect($start->ok())->toBeFalse("{$hostile} was accepted as a redirect target");
        expect($order->fresh()->transaction_id)->toBeNull();

        /*
         * THIS CASE was the one served, not the first one. Without it the loop
         * cannot tell a real rejection from a stale stub, which is precisely how
         * the two https hosts above went untested.
         */
        $served = collect(Http::recorded())->last()[1]->json();
        expect($served['configuration']['available_products']['installments'][0]['web_url'])->toBe($hostile);
    }
});

/* ======================================================================= */
/* CURRENCY AND LANGUAGE                                                   */
/* ======================================================================= */

it('refuses an order in a currency tabby does not settle in, without calling them', function () {
    /*
     * THE GAP. Tabby settles in AED, SAR, BHD, KWD and QAR — WC_Tabby_Config::
     * ALLOWED_CURRENCIES — and availableFor() checked only the country. A USD
     * order reached the checkout call and came back a 400, which the shopper read
     * as "we could not reach Tabby": true of nothing and actionable by nobody.
     *
     * preventStrayRequests with no fake at all is the assertion that nothing was
     * sent. MUTATION: delete the supportsCurrency() guard from start() and this
     * is red with a stray-request error.
     */
    $gateway = tg();
    $order = tgOrder(['currency' => 'USD']);

    $start = $gateway->start($order);

    expect($start->ok())->toBeFalse();
    expect(str_contains((string) $start->message, 'currency'))->toBeTrue();
});

it('accepts every currency tabby settles in', function () {
    foreach (['AED', 'SAR', 'BHD', 'KWD', 'QAR'] as $currency) {
        expect(tg()->supportsCurrency($currency))->toBeTrue($currency . ' was refused');
        PaymentProvider::query()->delete();
        app(GatewayCredentials::class)->forget();
    }

    foreach (['USD', 'EUR', 'GBP', 'OMR', '', 'aed '] as $currency) {
        $gateway = tg();

        expect($gateway->supportsCurrency($currency === 'aed ' ? 'aed ' : $currency))
            ->toBe($currency === 'aed ')
            ->and(true)->toBeTrue();

        PaymentProvider::query()->delete();
        app(GatewayCredentials::class)->forget();
    }
});

it('asks for the hosted checkout in arabic on an arabic page', function () {
    /*
     * THE GAP. `'lang' => 'en'` was hard-coded. WC_Tabby_Config::get_lang()
     * reads the locale and Tabby renders ar or en, so an Arabic shopper was sent
     * to an English hosted checkout from an Arabic page — and Tabby's hosted
     * page is where they enter their ID and agree to a repayment schedule.
     *
     * MUTATION: put `'lang' => 'en'` back in start() and the first expectation
     * is red.
     */
    $gateway = tg();

    Http::fake(['api.tabby.ai/api/v2/checkout' => Http::response(tgCheckoutBody(), 200)]);

    app()->setLocale('ar');
    $gateway->start(tgOrder());
    $arabic = json_decode(collect(Http::recorded())->last()[0]->body(), true);

    app()->setLocale('en');
    $gateway->start(tgOrder());
    $english = json_decode(collect(Http::recorded())->last()[0]->body(), true);

    expect($arabic['lang'])->toBe('ar');
    expect($english['lang'])->toBe('en');
});

/* ======================================================================= */
/* THE CHECKOUT PAYLOAD                                                    */
/* ======================================================================= */

it('prices each line at what was charged for it, not at the list price', function () {
    /*
     * THE DEFECT. `unit_price` was `order_items.unit_price` — the list price. On
     * a discounted basket the items then add up to more than the amount beside
     * them, and Tabby validates that items + shipping + tax - discount equals
     * the amount. The plugin sends `get_total() / get_quantity()`, which is the
     * discounted figure, and so does this now.
     *
     * The fixture line is 2 x 150.00 list, charged 250.00, so the list reading
     * is "150.00" and the correct one is "125.00".
     *
     * MUTATION: change items() back to `$this->toMajor((int) $item->unit_price)`
     * and this is red.
     */
    $gateway = tg();

    Http::fake(['api.tabby.ai/api/v2/checkout' => Http::response(tgCheckoutBody(), 200)]);

    $gateway->start(tgOrder());

    $sent = json_decode(collect(Http::recorded())->last()[0]->body(), true);
    $item = $sent['payment']['order']['items'][0];

    expect($item['unit_price'])->toBe('125.00');
    expect($item['quantity'])->toBe(2);
    expect($item['reference_id'])->toBe('COS-SNAIL-1');
});

it('tells tabby where the goods are going', function () {
    // A fraud signal the plugin sends and this did not. Shipping address with a
    // billing fallback, two fields and no more.
    $gateway = tg();

    Http::fake(['api.tabby.ai/api/v2/checkout' => Http::response(tgCheckoutBody(), 200)]);

    $gateway->start(tgOrder([
        'shipping_address' => ['address_1' => 'Villa 9', 'address_2' => 'Al Barsha 2', 'city' => 'Dubai'],
    ]));

    $sent = json_decode(collect(Http::recorded())->last()[0]->body(), true);

    expect($sent['payment']['shipping_address'])->toBe([
        'address' => 'Villa 9, Al Barsha 2',
        'city' => 'Dubai',
    ]);
});

it('sends aggregate buyer history for a registered customer and none for a guest', function () {
    /*
     * `buyer_history` is registered_since plus a count of finished orders — the
     * ordinary fraud signal, naming no order and no address — so it is sent
     * whatever the share_order_history switch says.
     *
     * NULL FOR A GUEST, deliberately. The tempting fallback is the order's own
     * timestamp, which would tell Tabby every guest registered moments ago: a
     * signal that is both false and adverse to the shopper.
     */
    $gateway = tg();

    Http::fake(['api.tabby.ai/api/v2/checkout' => Http::response(tgCheckoutBody(), 200)]);

    $customer = Customer::create([
        'name' => 'Repeat Buyer',
        'email' => 'repeat@example.com',
        'password' => 'password-long-enough',
    ]);

    Order::create([
        'order_number' => 'TG-PAST-' . uniqid(),
        'email' => 'repeat@example.com',
        'customer_id' => $customer->id,
        'status' => 'completed',
        'currency' => 'AED',
        'subtotal' => 10000,
        'total' => 10000,
        'payment_method' => 'tabby',
    ]);

    $gateway->start(tgOrder(['customer_id' => $customer->id, 'email' => 'repeat@example.com']));
    $withCustomer = json_decode(collect(Http::recorded())->last()[0]->body(), true);

    expect($withCustomer['payment']['buyer_history']['loyalty_level'])->toBe(1);
    expect(is_string($withCustomer['payment']['buyer_history']['registered_since']))->toBeTrue();

    $gateway->start(tgOrder());
    $guest = json_decode(collect(Http::recorded())->last()[0]->body(), true);

    expect($guest['payment']['buyer_history'])->toBeNull();
});

it('sends no past-order history until the owner switches it on', function () {
    /*
     * CLAUDE.md rule 1: a new setting ships at the value the page already has,
     * so applying the package moves nothing. And this one is not caution —
     * `order_history` describes up to ten of this buyer's PREVIOUS orders with
     * the name, phone, email, delivery address and items on each, and that is
     * somebody's purchase history leaving this server.
     *
     * MUTATION: make orderHistory() skip its credential check and the first half
     * is red.
     */
    Http::fake(['api.tabby.ai/api/v2/checkout' => Http::response(tgCheckoutBody(), 200)]);

    Order::create([
        'order_number' => 'TG-HIST-' . uniqid(),
        'email' => 'buyer@example.com',
        'status' => 'completed',
        'currency' => 'AED',
        'subtotal' => 8000,
        'total' => 8000,
        'payment_method' => 'tabby',
    ]);

    tg()->start(tgOrder());
    $off = json_decode(collect(Http::recorded())->last()[0]->body(), true);

    expect(array_key_exists('order_history', $off['payment']))->toBeFalse();

    PaymentProvider::query()->delete();
    app(GatewayCredentials::class)->forget();

    tg(['share_order_history' => '1'])->start(tgOrder());
    $on = json_decode(collect(Http::recorded())->last()[0]->body(), true);

    expect(count($on['payment']['order_history']))->toBe(1);
    expect($on['payment']['order_history'][0]['status'])->toBe('complete');
    expect($on['payment']['order_history'][0]['amount'])->toBe('80.00');
});

it('never puts a secret key in the checkout body', function () {
    // The public key is renderable and the secret key is not. Neither belongs in
    // a request body at all — the secret rides in the Authorization header and
    // the public key is not used server-side.
    $gateway = tg();

    Http::fake(['api.tabby.ai/api/v2/checkout' => Http::response(tgCheckoutBody(), 200)]);

    $gateway->start(tgOrder());

    $body = collect(Http::recorded())->last()[0]->body();

    expect(str_contains($body, 'sk_test_'))->toBeFalse();
    expect(str_contains($body, 'pk_test_'))->toBeFalse();
});

/* ======================================================================= */
/* CLOSED IS AMBIGUOUS — the three readings that were wrong                */
/* ======================================================================= */

it('does not mark an order paid when tabby closes a payment nobody captured', function () {
    /*
     * THE DEFECT, and the worst of the three.
     *
     * Tabby has no VOIDED status: a released authorisation and a successful
     * capture both end at CLOSED, and only `captures[]` separates them.
     * handleWebhook() confirmed on CLOSED flatly, so the close notice for a
     * RELEASED hold marked the order paid. On the shop: an order is cancelled,
     * the hold is released, Tabby delivers the close, and the order comes back to
     * `processing` with `paid_at` set — a live, paid, stock-committed order for
     * money that will never arrive.
     *
     * MUTATION: make hasCapture() `return true;` and this is red — the order
     * comes back paid.
     */
    $gateway = tg();
    $order = tgOrder(['status' => 'pending']);

    Http::fake([
        'api.tabby.ai/api/v2/payments/*' => Http::response([
            'id' => 'pay_void_1',
            'status' => 'CLOSED',
            'amount' => '250.00',
            'currency' => 'AED',
            'captures' => [],
            'order' => ['reference_id' => $order->order_number],
        ], 200),
    ]);

    $outcome = $gateway->handleWebhook(
        tgWebhookRequest('whsec-tabby-abcdefghijklmnopqrstuvwxyz012345', ['id' => 'pay_void_1']),
    );

    expect($outcome->accepted)->toBeTrue();
    expect($order->fresh()->paid_at)->toBeNull();
    expect($order->fresh()->status)->toBe('failed');

    // And it is on the record as a void rather than as a mystery.
    expect(PaymentEvent::where('type', 'voided')->exists())->toBeTrue();
});

it('still marks an order paid when tabby closes a payment it did capture', function () {
    // The other side of the same branch: a capture really does end at CLOSED,
    // and a shop that stopped believing those would never mark anything paid.
    $gateway = tg();
    $order = tgOrder(['status' => 'pending']);

    Http::fake([
        'api.tabby.ai/api/v2/payments/*' => Http::response([
            'id' => 'pay_closed_ok',
            'status' => 'CLOSED',
            'amount' => '250.00',
            'currency' => 'AED',
            'captures' => [['id' => 'cap_1', 'amount' => '250.00']],
            'order' => ['reference_id' => $order->order_number],
        ], 200),
    ]);

    $outcome = $gateway->handleWebhook(
        tgWebhookRequest('whsec-tabby-abcdefghijklmnopqrstuvwxyz012345', ['id' => 'pay_closed_ok']),
    );

    expect($outcome->accepted)->toBeTrue();
    expect($order->fresh()->paid_at)->not->toBeNull();
});

it('refuses to report a voided payment as captured', function () {
    /*
     * THE SECOND READING. capture() answered ok('already_captured') on any
     * CLOSED payment. PaymentCapturer treats an ok() as done: it keeps the
     * `captured_at` it claimed before the call and writes `captured_total` = the
     * whole order with `capture_ref` = this method's reference, which on a void
     * is NULL. The order then read as fully captured with no transaction behind
     * it — and `captured_total` is the ceiling PaymentRefunder measures a refund
     * against, so the next refund was authorised against money the shop had
     * never received.
     *
     * MUTATION: put the unconditional branch back — delete the `$existing ===
     * null || ! $this->hasCapture($payment)` guard so every CLOSED payment
     * returns `SettlementResult::ok('already_captured', $existing, ...)` — and
     * this is red. (Mutating hasCapture() alone does NOT turn it red, and the
     * note here used to claim it did: `lastId([])` is null on a void, so the
     * first half of that guard catches this shape on its own. The two clauses
     * are equivalent for every response Tabby has been seen to send and the
     * second is the one that carries the meaning, which is why both are there —
     * but only the first is what this case exercises. hasCapture() IS
     * load-bearing on its own in handleWebhook() and voidAuthorisation(), and
     * those two cases name it.)
     */
    $gateway = tg();
    $order = tgOrder(['transaction_id' => 'pay_void_2', 'paid_at' => now()]);

    Http::fake([
        'api.tabby.ai/api/v2/payments/*' => Http::response([
            'id' => 'pay_void_2',
            'status' => 'CLOSED',
            'captures' => [],
        ], 200),
    ]);

    $result = $gateway->capture($order, 25000);

    expect($result->ok)->toBeFalse();
    expect($result->code)->toBe('voided');
    expect($result->reference)->toBeNull();
});

it('reports a released authorisation as dead money in reconciliation, not as settled', function () {
    /*
     * THE THIRD READING, and the quietest. listRemotePayments() mapped CLOSED to
     * SETTLED, so a voided authorisation arrived in reconciliation as money that
     * had moved — matched against the order it belonged to and reported as an
     * agreeing pair. Not a discrepancy anybody could chase: a clean bill of
     * health over money that does not exist.
     *
     * MUTATION: change the match arm back to `'CLOSED' => RemoteTxn::SETTLED`
     * and the first expectation is red.
     */
    $gateway = tg();

    Http::fake([
        'api.tabby.ai/api/v2/payments?*' => Http::response([
            'payments' => [
                ['id' => 'pay_a', 'status' => 'CLOSED', 'amount' => '10.00', 'currency' => 'AED', 'captures' => []],
                ['id' => 'pay_b', 'status' => 'CLOSED', 'amount' => '20.00', 'currency' => 'AED', 'captures' => [['id' => 'cap_b']]],
                ['id' => 'pay_c', 'status' => 'AUTHORIZED', 'amount' => '30.00', 'currency' => 'AED'],
            ],
        ], 200),
    ]);

    $window = \App\Services\Payments\Reconciliation\ReconcileWindow::lastDays(7);

    $page = $gateway->listRemotePayments($window, null, 50);

    $states = collect($page->items)->mapWithKeys(fn ($t) => [$t->remoteId => $t->state])->all();

    expect($states['pay_a'])->toBe(\App\Services\Payments\Reconciliation\RemoteTxn::DEAD);
    expect($states['pay_b'])->toBe(\App\Services\Payments\Reconciliation\RemoteTxn::SETTLED);
    expect($states['pay_c'])->toBe(\App\Services\Payments\Reconciliation\RemoteTxn::AUTHORISED);
});

it('does not count a capture entry that carries no id', function () {
    // `captures: [[]]` would otherwise report captured money with no transaction
    // to point at, which is the exact state capture() was writing on a void,
    // reached by a different route.
    $gateway = tg();
    $order = tgOrder(['transaction_id' => 'pay_empty', 'paid_at' => now()]);

    Http::fake([
        'api.tabby.ai/api/v2/payments/*' => Http::response([
            'id' => 'pay_empty',
            'status' => 'CLOSED',
            'captures' => [[]],
        ], 200),
    ]);

    expect($gateway->capture($order, 25000)->code)->toBe('voided');
});

/* ======================================================================= */
/* CAPTURE                                                                 */
/* ======================================================================= */

it('sends no line breakdown on a partial capture', function () {
    /*
     * Tabby checks that a capture's amount agrees with the tax, shipping and
     * items sent beside it. A partial capture was being sent with the WHOLE
     * order's breakdown — arithmetic that cannot balance, and a rejection whose
     * message would be about items rather than the amount that caused it.
     *
     * MUTATION: drop the `$amountFils === (int) $order->total` condition in
     * capture() and the first three expectations are red.
     */
    $gateway = tg();
    $order = tgOrder(['transaction_id' => 'pay_part', 'paid_at' => now(), 'tax_total' => 500, 'shipping_total' => 1500]);

    Http::fake([
        'api.tabby.ai/api/v2/payments/*' => Http::response(['id' => 'pay_part', 'status' => 'AUTHORIZED'], 200),
        'api.tabby.ai/api/v1/payments/*/captures' => Http::response([
            'id' => 'pay_part',
            'captures' => [['id' => 'cap_part']],
        ], 200),
    ]);

    $gateway->capture($order, 10000);

    $partial = json_decode(collect(Http::recorded())->last()[0]->body(), true);

    expect($partial['amount'])->toBe('100.00');
    expect(array_key_exists('items', $partial))->toBeFalse();
    expect(array_key_exists('tax_amount', $partial))->toBeFalse();

    // A full capture still carries it, because that is the shape Tabby's own
    // plugin sends and it is what reconciles against the order.
    $gateway->capture($order->fresh(), (int) $order->total);

    $full = json_decode(collect(Http::recorded())->last()[0]->body(), true);

    expect($full['amount'])->toBe('250.00');
    expect(count($full['items']))->toBe(1);
    expect($full['tax_amount'])->toBe('5.00');
    expect($full['shipping_amount'])->toBe('15.00');
});

/* ======================================================================= */
/* RELEASING AN AUTHORISATION                                              */
/* ======================================================================= */

it('releases an authorised payment through the close endpoint', function () {
    /*
     * THE GAP. There was no way to release a hold at all — SettlesPayments covers
     * taking money and giving it back, and neither describes a cancelled order
     * whose authorisation is still live against the customer's Tabby credit
     * limit. `POST /api/v1/payments/{id}/close` is Tabby's only word for it
     * (WC_Gateway_Tabby_Checkout_Base::cancel), and the plugin fires it on
     * `woocommerce_order_status_cancelled`.
     *
     * v1, not v2, which is the same version split capture and refund sit on.
     */
    $gateway = tg();
    $order = tgOrder(['transaction_id' => 'pay_hold', 'status' => 'cancelled']);

    Http::fake([
        'api.tabby.ai/api/v2/payments/*' => Http::response(['id' => 'pay_hold', 'status' => 'AUTHORIZED'], 200),
        'api.tabby.ai/api/v1/payments/*/close' => Http::response(['id' => 'pay_hold', 'status' => 'CLOSED'], 200),
    ]);

    $result = $gateway->voidAuthorisation($order);

    expect($result->ok)->toBeTrue();
    expect($result->code)->toBe('voided');

    $urls = collect(Http::recorded())->map(fn ($pair) => $pair[0]->url())->all();

    expect(in_array('https://api.tabby.ai/api/v1/payments/pay_hold/close', $urls, true))->toBeTrue();
});

it('refuses to release a payment whose money has already been captured', function () {
    /*
     * The case worth writing down. What the caller is asking for is a refund, and
     * doing it as a close would either be rejected or — worse, if Tabby ever
     * accepted it — reverse a capture through a path with no refund row, no
     * ledger entry and no `refunded_total` behind it.
     *
     * MUTATION: make hasCapture() `return false;` and this is red — a captured
     * payment is reported as already released.
     */
    $gateway = tg();
    $order = tgOrder(['transaction_id' => 'pay_taken']);

    Http::fake([
        'api.tabby.ai/api/v2/payments/*' => Http::response([
            'id' => 'pay_taken',
            'status' => 'CLOSED',
            'captures' => [['id' => 'cap_taken']],
        ], 200),
    ]);

    $result = $gateway->voidAuthorisation($order);

    expect($result->ok)->toBeFalse();
    expect($result->code)->toBe('already_captured');
    expect(str_contains((string) $result->message, 'Refund it instead'))->toBeTrue();
});

it('reports an already-released hold as a success so a retry settles', function () {
    $gateway = tg();
    $order = tgOrder(['transaction_id' => 'pay_gone']);

    Http::fake([
        'api.tabby.ai/api/v2/payments/*' => Http::response([
            'id' => 'pay_gone',
            'status' => 'CLOSED',
            'captures' => [],
        ], 200),
    ]);

    expect($gateway->voidAuthorisation($order)->code)->toBe('already_voided');
});

it('does not report a release that tabby accepted and did not apply', function () {
    /*
     * Trusting a 200 alone would report a release that had not happened on any
     * future response shape where the call is accepted and ignored — and the
     * merchant would stop looking for a hold that is still open.
     *
     * MUTATION: delete the status_after check in voidAuthorisation() and this is
     * red.
     */
    $gateway = tg();
    $order = tgOrder(['transaction_id' => 'pay_stuck']);

    Http::fake([
        'api.tabby.ai/api/v2/payments/*' => Http::response(['id' => 'pay_stuck', 'status' => 'AUTHORIZED'], 200),
        'api.tabby.ai/api/v1/payments/*/close' => Http::response(['id' => 'pay_stuck', 'status' => 'AUTHORIZED'], 200),
    ]);

    $result = $gateway->voidAuthorisation($order);

    expect($result->ok)->toBeFalse();
    expect($result->code)->toBe('void_not_applied');
});

it('puts the release claim back when tabby refuses, and writes the failure down', function () {
    /*
     * PaymentVoider claims `voided_at` before it calls out, exactly as
     * PaymentCapturer claims `captured_at`. An order flagged released against a
     * hold that is still open is the one lie this is shaped around — the merchant
     * would stop looking for it.
     *
     * MUTATION: delete the `voided_at => null` restore in PaymentVoider and the
     * second expectation is red.
     */
    $order = tgOrder(['transaction_id' => 'pay_refuse', 'status' => 'cancelled']);
    tg();

    Http::fake([
        'api.tabby.ai/api/v2/payments/*' => Http::response(['id' => 'pay_refuse', 'status' => 'AUTHORIZED'], 200),
        'api.tabby.ai/api/v1/payments/*/close' => Http::response(['errorType' => 'not_allowed'], 409),
    ]);

    $result = app(PaymentVoider::class)->void($order, 'Tester');

    expect($result->ok)->toBeFalse();
    expect($order->fresh()->voided_at)->toBeNull();
    expect(PaymentEvent::where('type', 'void_failed')->exists())->toBeTrue();
});

it('releases once when the button is clicked twice', function () {
    $order = tgOrder(['transaction_id' => 'pay_twice', 'status' => 'cancelled']);
    tg();

    Http::fake([
        'api.tabby.ai/api/v2/payments/*' => Http::response(['id' => 'pay_twice', 'status' => 'AUTHORIZED'], 200),
        'api.tabby.ai/api/v1/payments/*/close' => Http::response(['id' => 'pay_twice', 'status' => 'CLOSED'], 200),
    ]);

    $voider = app(PaymentVoider::class);

    expect($voider->void($order)->code)->toBe('voided');
    expect($voider->void($order->fresh())->code)->toBe('already_voided');

    $closes = collect(Http::recorded())
        ->filter(fn ($pair) => str_contains($pair[0]->url(), '/close'))
        ->count();

    expect($closes)->toBe(1);
});

it('never releases a hold on an order that has been captured', function () {
    /*
     * Checked from our OWN column, before the gateway is asked, as well as from
     * the provider's live status inside it — the two have to agree. Nothing is
     * sent, which preventStrayRequests proves.
     *
     * MUTATION: delete the captured_at guard in PaymentVoider and this is red
     * with a stray-request error.
     */
    $order = tgOrder(['transaction_id' => 'pay_done', 'captured_at' => now(), 'captured_total' => 25000]);
    tg();

    $result = app(PaymentVoider::class)->void($order);

    expect($result->ok)->toBeFalse();
    expect($result->code)->toBe('already_captured');
});

/* ======================================================================= */
/* WEBHOOK REGISTRATION — the gap that made everything else moot           */
/* ======================================================================= */

it('registers this shops webhook with tabby, per country, over the v1 api', function () {
    /*
     * THE GAP THAT MADE THE REST MOOT. Tabby does not take a callback URL from a
     * dashboard field the way Stripe and Tamara do — WC_Tabby_Webhook::register()
     * POSTs it to `/api/v1/webhooks` with `{url, is_test}` and an
     * X-Merchant-Code per country. Until that call is made Tabby never contacts
     * this shop, so handleWebhook() was complete, correct and unreachable: every
     * Tabby order sat `pending` with the money authorised until it lapsed.
     *
     * `webhooks` is also the ONE GET that is v1 — everything else reads from v2
     * and this collection 404s there. The plugin encodes the exception literally
     * and so does this.
     */
    $gateway = tg();

    Http::fake([
        'api.tabby.ai/api/v1/webhooks' => Http::sequence()
            ->push([], 200)->push(['id' => 'wh_ae'], 200)      // AE: empty, then created
            ->push(['errorType' => 'not_authorized'], 403)      // SA
            ->push(['errorType' => 'not_authorized'], 403)      // BH
            ->push(['errorType' => 'not_authorized'], 403)      // KW
            ->push(['errorType' => 'not_authorized'], 403),     // QA
    ]);

    $report = $gateway->syncWebhooks();

    expect($report['ok'])->toBeTrue();
    expect($report['registered_anywhere'])->toBeTrue();

    $states = collect($report['countries'])->mapWithKeys(fn ($r) => [$r['country'] => $r['state']])->all();

    expect($states['AE'])->toBe('registered');
    expect($states['SA'])->toBe('not_authorised');

    $writes = collect(Http::recorded())
        ->filter(fn ($pair) => $pair[0]->method() === 'POST')
        ->values();

    expect($writes->count())->toBe(1);
    expect($writes[0][0]->url())->toBe('https://api.tabby.ai/api/v1/webhooks');

    $sent = json_decode($writes[0][0]->body(), true);

    // The URL it registers is the one the payments screen shows, secret and all.
    expect($sent['url'])->toBe($gateway->ourWebhookUrl());
    // is_test from the SECRET KEY's prefix, which is what Tabby's own flag has
    // to agree with — not from the mode column, which Tabby has never seen.
    expect($sent['is_test'])->toBeTrue();
});

it('changes nothing when tabby is already registered correctly', function () {
    /*
     * A sync is a button somebody may press twice, and a second registration on
     * the same URL would make Tabby deliver every event twice.
     *
     * MUTATION: make matchingHook() `return null;` and this is red — a second
     * POST is sent.
     */
    $gateway = tg();
    $url = $gateway->ourWebhookUrl();

    Http::fake([
        'api.tabby.ai/api/v1/webhooks' => Http::sequence()
            ->push([['id' => 'wh_ae', 'url' => $url, 'is_test' => true]], 200)
            ->push(['errorType' => 'not_authorized'], 403)
            ->push(['errorType' => 'not_authorized'], 403)
            ->push(['errorType' => 'not_authorized'], 403)
            ->push(['errorType' => 'not_authorized'], 403),
    ]);

    $report = $gateway->syncWebhooks();

    expect(collect($report['countries'])->firstWhere('country', 'AE')['state'])->toBe('current');

    $writes = collect(Http::recorded())->filter(fn ($pair) => $pair[0]->method() !== 'GET')->count();

    expect($writes)->toBe(0);
});

it('moves a registration that is flagged for the wrong environment', function () {
    /*
     * Tabby delivers TEST events to a test webhook and live events to a live one,
     * so a hook left flagged test after the keys went live is a hook that is
     * never called about a real payment — a shop taking real money and hearing
     * nothing. A PUT, not a second POST.
     */
    $gateway = tg([
        'public_key' => 'pk_11111111-2222-3333-4444-555555555555',
        'secret_key' => 'sk_11111111-2222-3333-4444-555555555555',
    ]);
    $url = $gateway->ourWebhookUrl();

    Http::fake([
        'api.tabby.ai/api/v1/webhooks/wh_ae' => Http::response(['id' => 'wh_ae'], 200),
        'api.tabby.ai/api/v1/webhooks' => Http::sequence()
            ->push([['id' => 'wh_ae', 'url' => $url, 'is_test' => true]], 200)
            ->push(['errorType' => 'not_authorized'], 403)
            ->push(['errorType' => 'not_authorized'], 403)
            ->push(['errorType' => 'not_authorized'], 403)
            ->push(['errorType' => 'not_authorized'], 403),
    ]);

    $report = $gateway->syncWebhooks();

    expect(collect($report['countries'])->firstWhere('country', 'AE')['state'])->toBe('updated');

    $put = collect(Http::recorded())->first(fn ($pair) => $pair[0]->method() === 'PUT');

    expect($put)->not->toBeNull();
    expect($put[0]->url())->toBe('https://api.tabby.ai/api/v1/webhooks/wh_ae');
    expect(json_decode($put[0]->body(), true)['is_test'])->toBeFalse();
});

it('deletes a stale registration of ours and leaves everybody elses alone', function () {
    /*
     * The URL ends in a 32-character secret, so REGENERATING it makes a new URL.
     * Without a prune Tabby keeps both, delivers every event twice, and the
     * stale delivery is answered 401 forever by a gateway that is working
     * perfectly.
     *
     * Safe precisely BECAUSE the path is ours: a candidate has to match this
     * install's host, base path and `/api/payments/webhook/tabby/` prefix before
     * it is a candidate at all, so a hook the merchant registered for anything
     * else is never listed and never touched.
     *
     * MUTATION: drop the `str_starts_with($candidate, $prefix)` test in
     * staleHooks() and the last expectation is red — somebody else's endpoint is
     * deleted.
     */
    $gateway = tg();
    $url = (string) $gateway->ourWebhookUrl();
    $old = str_replace('abcdefghijklmnopqrstuvwxyz012345', '000000000000000000000000000000ff', $url);

    Http::fake([
        'api.tabby.ai/api/v1/webhooks/*' => Http::response([], 200),
        'api.tabby.ai/api/v1/webhooks' => Http::sequence()
            ->push([
                ['id' => 'wh_old', 'url' => $old, 'is_test' => true],
                ['id' => 'wh_theirs', 'url' => 'https://someone-else.example/tabby-hook', 'is_test' => true],
                ['id' => 'wh_now', 'url' => $url, 'is_test' => true],
            ], 200)
            ->push(['errorType' => 'not_authorized'], 403)
            ->push(['errorType' => 'not_authorized'], 403)
            ->push(['errorType' => 'not_authorized'], 403)
            ->push(['errorType' => 'not_authorized'], 403),
    ]);

    $report = $gateway->syncWebhooks();
    $ae = collect($report['countries'])->firstWhere('country', 'AE');

    expect($ae['state'])->toBe('current');
    expect($ae['pruned'])->toBe(1);

    $deletes = collect(Http::recorded())
        ->filter(fn ($pair) => $pair[0]->method() === 'DELETE')
        ->map(fn ($pair) => $pair[0]->url())
        // values(): filter preserves keys, and an array with a key of 1 is not
        // identical to one with a key of 0 however alike they read.
        ->values()
        ->all();

    expect($deletes)->toBe(['https://api.tabby.ai/api/v1/webhooks/wh_old']);
});

it('sends a delete as a delete and not as a post', function () {
    /*
     * RemoteGateway's method match fell through to POST for anything that was
     * not GET or PUT, so a DELETE was silently a POST — and on a webhook
     * collection the shape of "POST /webhooks/{id}" is a second registration
     * rather than a removal.
     *
     * MUTATION: remove the 'DELETE' arm from RemoteGateway::attempt()'s match and
     * this is red.
     */
    $gateway = tg();
    $url = (string) $gateway->ourWebhookUrl();
    $old = str_replace('abcdefghijklmnopqrstuvwxyz012345', '000000000000000000000000000000ff', $url);

    Http::fake([
        'api.tabby.ai/api/v1/webhooks/*' => Http::response([], 200),
        'api.tabby.ai/api/v1/webhooks' => Http::sequence()
            ->push([['id' => 'wh_old', 'url' => $old, 'is_test' => true], ['id' => 'wh_now', 'url' => $url, 'is_test' => true]], 200)
            ->push(['errorType' => 'not_authorized'], 403)
            ->push(['errorType' => 'not_authorized'], 403)
            ->push(['errorType' => 'not_authorized'], 403)
            ->push(['errorType' => 'not_authorized'], 403),
    ]);

    $gateway->syncWebhooks();

    $methods = collect(Http::recorded())
        ->filter(fn ($pair) => str_contains($pair[0]->url(), '/webhooks/wh_old'))
        ->map(fn ($pair) => $pair[0]->method())
        ->values()
        ->all();

    expect($methods)->toBe(['DELETE']);
});

it('aims each country at that countrys merchant account and restores the header afterwards', function () {
    /*
     * Tabby answers a different list of registered hooks per X-Merchant-Code.
     * forCountry() overrides the header for one call and restores it in a
     * finally — an override left set would silently aim every later call in the
     * request, including a capture, at the wrong country's account.
     */
    $gateway = tg();

    Http::fake([
        'api.tabby.ai/api/v1/webhooks' => Http::sequence()
            ->push(['errorType' => 'not_authorized'], 403)
            ->push(['errorType' => 'not_authorized'], 403)
            ->push(['errorType' => 'not_authorized'], 403)
            ->push(['errorType' => 'not_authorized'], 403)
            ->push(['errorType' => 'not_authorized'], 403),
        'api.tabby.ai/api/v2/checkout' => Http::response(tgCheckoutBody(), 200),
    ]);

    $gateway->webhookStatus();

    $codes = collect(Http::recorded())
        ->filter(fn ($pair) => str_contains($pair[0]->url(), '/webhooks'))
        ->map(fn ($pair) => $pair[0]->header('X-Merchant-Code')[0] ?? '')
        ->all();

    expect($codes)->toBe(['AE', 'SA', 'BH', 'KW', 'QA']);

    // And the next ordinary call is back on the stored code.
    $gateway->start(tgOrder());

    $after = collect(Http::recorded())->last()[0]->header('X-Merchant-Code')[0] ?? '';

    expect($after)->toBe('AE');
});

it('says so rather than registering nothing when no country is authorised', function () {
    // Zero authorised countries means the keys are wrong or the account is not
    // live yet. A sync that reported plain success having registered nothing
    // anywhere is the report that would stop the owner looking.
    $gateway = tg();

    Http::fake([
        'api.tabby.ai/api/v1/webhooks' => Http::response(['errorType' => 'not_authorized'], 403),
    ]);

    $report = $gateway->syncWebhooks();

    expect($report['registered_anywhere'])->toBeFalse();
});

it('will not try to register before there is a webhook secret to register', function () {
    $gateway = tg(['webhook_secret' => '']);

    $report = $gateway->syncWebhooks();

    expect($report['ok'])->toBeFalse();
    expect($report['error'])->toBe('no_webhook_secret');
});

it('notices that the public and secret keys are from different environments', function () {
    // Server-to-server calls succeed, the hosted page loads, and the shopper is
    // declined for reasons nobody can see. Reported rather than enforced, so a
    // prefix change at Tabby cannot take a working shop dark.
    expect(tg()->keysDisagree())->toBeFalse();

    PaymentProvider::query()->delete();
    app(GatewayCredentials::class)->forget();

    expect(tg(['secret_key' => 'sk_11111111-2222-3333-4444-555555555555'])->keysDisagree())->toBeTrue();
});

/* ======================================================================= */
/* THE ADMIN ENDPOINTS                                                     */
/* ======================================================================= */

it('refuses an unauthenticated caller on every tabby endpoint', function () {
    TabbyAdminRoutes::wire($this->app);

    $order = tgOrder();

    $this->getJson('/admin-api/payments/tabby/webhooks')->assertUnauthorized();
    $this->postJson('/admin-api/payments/tabby/webhooks')->assertUnauthorized();
    $this->getJson('/admin-api/orders/' . $order->id . '/void')->assertUnauthorized();
    $this->postJson('/admin-api/orders/' . $order->id . '/void')->assertUnauthorized();
});

it('maps every route this lane adds to a capability, and to the right one', function () {
    /*
     * The FINISHED state, per CLAUDE.md: each of these is mapped, and to the
     * capability the file's header says. Zero would be "built, never guarded" —
     * which the closed default makes safe and invisible, and invisible is how it
     * stays wrong.
     */
    TabbyAdminRoutes::wire($this->app);

    $mapped = [];

    foreach (TabbyAdminRoutes::registered() as $route) {
        foreach (array_diff($route->methods(), ['HEAD', 'OPTIONS']) as $method) {
            $mapped[$method . ' ' . $route->uri()] = AdminCapabilities::for($route);
        }
    }

    expect($mapped['GET admin-api/payments/tabby/webhooks'])->toBe('payments.manage');
    expect($mapped['POST admin-api/payments/tabby/webhooks'])->toBe('payments.manage');
    expect($mapped['GET admin-api/orders/{id}/void'])->toBe('orders.money');
    expect($mapped['POST admin-api/orders/{id}/void'])->toBe('orders.money');
});

it('never returns the webhook url from the registration endpoint', function () {
    /*
     * The random tail of that URL is what makes the endpoint unguessable, and an
     * endpoint that echoed it would be a second place it can leak from. The
     * payments screen already has it from /admin-api/payments and is the one
     * place that shows it.
     *
     * MUTATION: add `'url' => $report['url']` to TabbyWebhookController::safe()
     * and this is red.
     */
    TabbyAdminRoutes::wire($this->app);

    $gateway = tg();
    $secret = 'abcdefghijklmnopqrstuvwxyz012345';

    Http::fake([
        'api.tabby.ai/api/v1/webhooks' => Http::response(['errorType' => 'not_authorized'], 403),
    ]);

    $this->actingAs(tgAdmin('hook'), 'admin');

    $body = $this->getJson('/admin-api/payments/tabby/webhooks')->assertOk()->content();

    expect(str_contains($body, $secret))->toBeFalse();
    expect(str_contains($body, 'sk_test_'))->toBeFalse();
    // It still says whether there IS one, which is what the screen needs.
    expect(json_decode($body, true)['webhook_url_ready'])->toBeTrue();
    expect($gateway->ourWebhookUrl())->not->toBeNull();
});

it('releases a hold from the order screen and refuses a captured one', function () {
    TabbyAdminRoutes::wire($this->app);

    tg();
    $order = tgOrder(['transaction_id' => 'pay_screen', 'status' => 'cancelled']);

    Http::fake([
        'api.tabby.ai/api/v2/payments/*' => Http::response(['id' => 'pay_screen', 'status' => 'AUTHORIZED'], 200),
        'api.tabby.ai/api/v1/payments/*/close' => Http::response(['id' => 'pay_screen', 'status' => 'CLOSED'], 200),
    ]);

    $this->actingAs(tgAdmin('void'), 'admin');

    $this->getJson('/admin-api/orders/' . $order->id . '/void')
        ->assertOk()
        ->assertJson(['void_supported' => true, 'voidable' => true, 'voided' => false]);

    $this->postJson('/admin-api/orders/' . $order->id . '/void')
        ->assertOk()
        ->assertJson(['ok' => true, 'code' => 'voided']);

    expect($order->fresh()->voided_at)->not->toBeNull();

    // A second press is a 200 and a no-op, not a second close.
    $this->postJson('/admin-api/orders/' . $order->id . '/void')
        ->assertOk()
        ->assertJson(['ok' => true, 'code' => 'already_voided']);
});

it('offers no release on an order paid by a gateway that cannot release one', function () {
    TabbyAdminRoutes::wire($this->app);

    $order = tgOrder(['payment_method' => 'cod', 'transaction_id' => null]);

    $this->actingAs(tgAdmin('cod'), 'admin');

    $this->getJson('/admin-api/orders/' . $order->id . '/void')
        ->assertOk()
        ->assertJson(['void_supported' => false, 'voidable' => false]);

    $this->postJson('/admin-api/orders/' . $order->id . '/void')
        ->assertStatus(502)
        ->assertJson(['ok' => false, 'code' => 'unsupported']);
});

/* ======================================================================= */
/* NO TELEMETRY                                                            */
/* ======================================================================= */

it('talks to nobody but tabby', function () {
    /*
     * The plugin ships every logged event to `logs.browser-intake-datadoghq.eu`
     * under a hard-coded API key, carrying the store hostname, the full request
     * URL, the request BODY (buyer name, email, phone, address) and the response
     * body — and its product feed POSTs the merchant's SECRET KEY to
     * `plugins-api.tabby.ai`. Neither is ported, and this is the assertion that
     * says so rather than a paragraph claiming it.
     *
     * Every host a Tabby path can reach, walked end to end, with only
     * api.tabby.ai faked: preventStrayRequests turns a call to anywhere else
     * into a failure.
     */
    $gateway = tg();

    Http::fake([
        'api.tabby.ai/*' => Http::response(tgCheckoutBody(), 200),
    ]);

    $order = tgOrder();
    $gateway->start($order);

    $hosts = collect(Http::recorded())
        ->map(fn ($pair) => parse_url($pair[0]->url(), PHP_URL_HOST))
        ->unique()
        ->values()
        ->all();

    expect($hosts)->toBe(['api.tabby.ai']);

    // And the source carries no trace of either endpoint.
    $source = file_get_contents(base_path('app/Services/Payments/Gateways/TabbyGateway.php'));

    // A QUOTED url, not the word: the class comment names both endpoints in
    // prose precisely so the next reader knows they were considered and
    // refused, and a test that forbade the word would forbid saying so.
    expect(preg_match('/[\'"]https?:\/\/[^\'"]*datadoghq/', $source))->toBe(0);
    expect(preg_match('/[\'"]https?:\/\/[^\'"]*plugins-api/', $source))->toBe(0);
    expect(preg_match('/[\'"]DD-API-KEY[\'"]/', $source))->toBe(0);
});

/* ------------------------------------------------------------------ helpers */

/** A webhook POST with the secret bound as a route parameter, as the router does. */
function tgWebhookRequest(string $secret, array $body): \Illuminate\Http\Request
{
    $request = \Illuminate\Http\Request::create(
        "/api/payments/webhook/tabby/{$secret}",
        'POST',
        [],
        [],
        [],
        [],
        json_encode($body),
    );

    $request->headers->set('Content-Type', 'application/json');

    $route = new \Illuminate\Routing\Route(['POST'], '/api/payments/webhook/{gateway}/{secret}', fn () => null);
    $route->bind($request);
    $request->setRouteResolver(fn () => $route);

    return $request;
}

/* ======================================================================= */
/* WEBHOOK AUTHENTICITY — the part the plugin does not have at all         */
/* ======================================================================= */

/*
 * The merchant's plugin registers its webhook route with
 * `'permission_callback' => '__return_true'`: the endpoint is COMPLETELY
 * unauthenticated, and the only thing standing between a stranger's POST and an
 * order being marked paid is that the plugin re-fetches the payment before
 * believing it. That instinct is right and this port keeps it — but on its own
 * it makes the endpoint a free oracle, so there is a shared secret in the URL as
 * well and BOTH have to pass. None of that was driven. These are the cases.
 */

it('rejects a webhook whose url secret is wrong, and asks tabby nothing', function () {
    /*
     * The first gate. A wrong secret is a 401 before the body is parsed, before
     * the database is touched and before any outbound call — otherwise anyone who
     * can reach this shop can make it call Tabby, which is a free amplifier and
     * an oracle for which payment ids exist.
     *
     * MUTATION: drop the `! Signature::equals(...)` clause from handleWebhook()
     * and this is red twice over — a 200 instead of a 401, and a request sent to
     * api.tabby.ai that preventStrayRequests turns into an error.
     */
    $gateway = tg();
    $order = tgOrder(['status' => 'pending']);

    Http::fake();

    $outcome = $gateway->handleWebhook(
        tgWebhookRequest('whsec-tabby-WRONGWRONGWRONGWRONGWRONGWRONG12', ['id' => 'pay_forged']),
    );

    expect($outcome->accepted)->toBeFalse();
    expect($outcome->status)->toBe(401);
    expect($order->fresh()->paid_at)->toBeNull();
    Http::assertNothingSent();
});

it('rejects a webhook when this shop has no webhook secret stored at all', function () {
    /*
     * The empty-string trap, and the reason `$expected === ''` is checked
     * SEPARATELY from the comparison. An unconfigured shop has '' stored; a
     * caller who sends '' would otherwise be comparing '' with '' — equal — and
     * every webhook would verify on a shop that had never generated a secret.
     *
     * Signature::equals() refuses an empty side of its own accord, so this is
     * belt and braces; it is pinned because the belt and the braces are one line
     * apart and either could be tidied away.
     *
     * MUTATION: replace the guard with `if (! hash_equals($expected, (string)
     * $request->route('secret')))` and this is red — '' === '' verifies.
     */
    $gateway = tg(['webhook_secret' => '']);
    $order = tgOrder(['status' => 'pending']);

    Http::fake();

    foreach (['', 'anything'] as $given) {
        $outcome = $gateway->handleWebhook(tgWebhookRequest($given, ['id' => 'pay_x']));

        expect($outcome->status)->toBe(401);
    }

    expect($order->fresh()->paid_at)->toBeNull();
    Http::assertNothingSent();
});

it('ignores every figure in the webhook body and believes only tabby', function () {
    /*
     * "Never trust an amount, a currency or a status from the client." The body
     * here is a forgery by somebody who has the URL: it claims a 5.00 AED payment
     * is AUTHORIZED, and it claims a DIFFERENT order reference. Every one of
     * those fields is ignored — the id is the only thing read out of it — and the
     * authenticated GET decides.
     *
     * MUTATION: read the amount from the request (`$this->toFils($request->
     * input('amount'))`) instead of from `$payment` and this is red: the order is
     * refused for an amount mismatch instead of paid, because 5.00 is not 250.00.
     */
    $gateway = tg();
    $order = tgOrder(['status' => 'pending']);
    $other = tgOrder(['status' => 'pending']);

    Http::fake([
        'api.tabby.ai/api/v2/payments/*' => Http::response([
            'id' => 'pay_truth',
            'status' => 'AUTHORIZED',
            'amount' => '250.00',
            'currency' => 'AED',
            'captures' => [],
            'order' => ['reference_id' => $order->order_number],
        ], 200),
    ]);

    $outcome = $gateway->handleWebhook(tgWebhookRequest(
        'whsec-tabby-abcdefghijklmnopqrstuvwxyz012345',
        [
            'id' => 'pay_truth',
            // All lies, all ignored.
            'status' => 'CLOSED',
            'amount' => '5.00',
            'currency' => 'USD',
            'captures' => [['id' => 'cap_forged']],
            'order' => ['reference_id' => $other->order_number],
        ],
    ));

    expect($outcome->accepted)->toBeTrue();
    // The order the API named is the one that moved.
    expect($order->fresh()->paid_at)->not->toBeNull();
    // The one the BODY named did not.
    expect($other->fresh()->paid_at)->toBeNull();

    // And the full amount was recorded, not the body's 5.00.
    $paid = \App\Models\Payment::where('order_id', $order->id)->first();
    expect((int) $paid->amount)->toBe(25000);
});

it('applies a replayed webhook exactly once', function () {
    /*
     * Tabby retries a delivery it did not get a 2xx for, and a provider retrying
     * a delivery it DID get is ordinary too. The same notice arriving twice must
     * not produce two payment rows, two `paid_at` writes or two stock movements.
     *
     * The guard is PaymentConfirmer's conditional UPDATE against a null
     * `paid_at`, which is shared by all three gateways — what is pinned here is
     * that the Tabby path actually reaches it and that the second delivery is a
     * 200 rather than a 503 (a 4xx/5xx would make Tabby retry the same notice
     * forever).
     *
     * MUTATION: change PaymentConfirmer's claim to an unconditional
     * `$order->forceFill(['paid_at' => now()])->save()` and this is red — two
     * `payments` rows for one payment.
     */
    $gateway = tg();
    $order = tgOrder(['status' => 'pending']);

    Http::fake([
        'api.tabby.ai/api/v2/payments/*' => Http::response([
            'id' => 'pay_replay',
            'status' => 'AUTHORIZED',
            'amount' => '250.00',
            'currency' => 'AED',
            'captures' => [],
            'order' => ['reference_id' => $order->order_number],
        ], 200),
    ]);

    $request = fn () => tgWebhookRequest('whsec-tabby-abcdefghijklmnopqrstuvwxyz012345', ['id' => 'pay_replay']);

    $first = $gateway->handleWebhook($request());
    $paidAt = $order->fresh()->paid_at;

    $second = $gateway->handleWebhook($request());
    $third = $gateway->handleWebhook($request());

    expect($first->accepted)->toBeTrue();
    expect($second->accepted)->toBeTrue();
    expect($second->status)->toBe(200);
    expect($third->status)->toBe(200);

    // One payment, one timestamp, and the timestamp did not move.
    expect(\App\Models\Payment::where('order_id', $order->id)->count())->toBe(1);
    expect($order->fresh()->paid_at->eq($paidAt))->toBeTrue();
});

it('ignores an expiry notice for a payment attempt the order has moved past', function () {
    /*
     * THE DEFECT. One order can carry more than one Tabby payment: a shopper who
     * abandons the Tabby page and picks Tabby again gets a NEW payment and
     * `orders.transaction_id` is overwritten with it. The abandoned one then
     * expires on Tabby's timer and Tabby delivers an EXPIRED notice for it.
     *
     * That notice was applied to the order. PaymentConfirmer::fail() moved it to
     * `failed`, put the stock back and released the coupon — and because webhook
     * delivery is not ordered, the AUTHORIZED notice for the payment that DID
     * succeed could arrive second, find the order in `failed` (which is in
     * PaymentConfirmer's VOID list) and be refused as "order is no longer live".
     * Money authorised on Tabby, stock back on the shelf, order dead. The
     * merchant's own plugin guards it by comparing the order's stored payment id
     * with the notice's before acting.
     *
     * MUTATION: delete the `if ($superseded)` branch from the REJECTED/EXPIRED
     * arm of handleWebhook() and this is red — the order goes to `failed` and the
     * later authorisation is refused.
     */
    $gateway = tg();

    // The order is waiting on attempt TWO.
    $order = tgOrder(['status' => 'pending', 'transaction_id' => 'pay_attempt_2']);

    Http::fake([
        // Attempt ONE, long abandoned, now expired.
        'api.tabby.ai/api/v2/payments/pay_attempt_1' => Http::response([
            'id' => 'pay_attempt_1',
            'status' => 'EXPIRED',
            'amount' => '250.00',
            'currency' => 'AED',
            'order' => ['reference_id' => $order->order_number],
        ], 200),
        // Attempt TWO, which is the one that succeeded.
        'api.tabby.ai/api/v2/payments/pay_attempt_2' => Http::response([
            'id' => 'pay_attempt_2',
            'status' => 'AUTHORIZED',
            'amount' => '250.00',
            'currency' => 'AED',
            'captures' => [],
            'order' => ['reference_id' => $order->order_number],
        ], 200),
    ]);

    $secret = 'whsec-tabby-abcdefghijklmnopqrstuvwxyz012345';

    // The stale notice lands first, as it does on the shop.
    $stale = $gateway->handleWebhook(tgWebhookRequest($secret, ['id' => 'pay_attempt_1']));

    expect($stale->accepted)->toBeTrue();
    expect($stale->status)->toBe(200);
    // Untouched: still pending, not failed.
    expect($order->fresh()->status)->toBe('pending');
    expect($order->fresh()->paid_at)->toBeNull();

    // And the real one is still able to land.
    $live = $gateway->handleWebhook(tgWebhookRequest($secret, ['id' => 'pay_attempt_2']));

    expect($live->accepted)->toBeTrue();
    expect($order->fresh()->paid_at)->not->toBeNull();
});

it('still believes a success notice for an id the order was not expecting', function () {
    /*
     * The other side of that guard, and the reason it covers only the failure
     * branches. A SUCCESS notice for an unexpected payment id is money the
     * customer really has committed — two tabs, and the FIRST one is the one they
     * finished. Dropping it would be the same silence in the other direction, so
     * it falls through to confirm(), which checks the amount and the currency
     * against the order before believing anything.
     *
     * MUTATION: move the `if ($superseded)` guard above the status branches so it
     * covers the success path too, and this is red — the order never gets paid.
     */
    $gateway = tg();
    $order = tgOrder(['status' => 'pending', 'transaction_id' => 'pay_attempt_2']);

    Http::fake([
        'api.tabby.ai/api/v2/payments/*' => Http::response([
            'id' => 'pay_attempt_1',
            'status' => 'AUTHORIZED',
            'amount' => '250.00',
            'currency' => 'AED',
            'captures' => [],
            'order' => ['reference_id' => $order->order_number],
        ], 200),
    ]);

    $outcome = $gateway->handleWebhook(
        tgWebhookRequest('whsec-tabby-abcdefghijklmnopqrstuvwxyz012345', ['id' => 'pay_attempt_1']),
    );

    expect($outcome->accepted)->toBeTrue();
    expect($order->fresh()->paid_at)->not->toBeNull();

    // And the reference recorded is the one Tabby's own answer carried.
    expect(\App\Models\Payment::where('order_id', $order->id)->first()->provider_ref)
        ->toBe('pay_attempt_1');
});

it('asks tabby to try again rather than guessing when the verification call fails', function () {
    /*
     * Three ways the verifying GET can fail, and all three must end at a 503 with
     * the order untouched — never a 200, which stops Tabby retrying and loses the
     * notice for good, and never a guess from the body.
     *
     *   a transport timeout        the shop's egress, Tabby's edge, anything
     *   a 5xx from Tabby          their side
     *   a body that is not JSON    an HTML error page from a proxy, which the
     *                              plugin has actually seen ("API html response
     *                              detected")
     *
     * MUTATION: make handleWebhook() fall back to the request body when
     * `$payment === null` and this is red — the order is marked paid off an
     * unverified POST.
     */
    $gateway = tg();
    $secret = 'whsec-tabby-abcdefghijklmnopqrstuvwxyz012345';

    $body = fn (Order $order) => [
        'id' => 'pay_unverifiable',
        'status' => 'AUTHORIZED',
        'amount' => '250.00',
        'currency' => 'AED',
        'order' => ['reference_id' => $order->order_number],
    ];

    // ONE stub, switched per case. Http::fake() merges, so faking per iteration
    // would serve the timeout to all three — see tgAnswers().
    $answer = tgAnswers();

    $cases = [
        'timeout' => fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: timed out'),
        'server error' => fn () => Http::response(['errorType' => 'internal'], 502),
        'html instead of json' => fn () => Http::response('<!DOCTYPE html><html><body>nope</body></html>', 200, ['Content-Type' => 'text/html']),
    ];

    foreach ($cases as $name => $response) {
        $order = tgOrder(['status' => 'pending']);
        $answer($response);

        $outcome = $gateway->handleWebhook(tgWebhookRequest($secret, $body($order)));

        expect($outcome->accepted)->toBeFalse("[$name] must not be accepted");
        expect($outcome->status)->toBe(503);
        expect($order->fresh()->paid_at)->toBeNull();
        expect($order->fresh()->status)->toBe('pending');
    }
});

it('refuses a webhook with no payment id in it, without calling tabby', function () {
    // An empty POST, a body that is not JSON, and a body with every field but
    // the one that matters. 422 and no outbound call in each case: there is
    // nothing to ask about.
    $gateway = tg();

    Http::fake();

    foreach ([[], ['order' => ['reference_id' => 'TG-nope']], ['id' => '']] as $body) {
        $outcome = $gateway->handleWebhook(
            tgWebhookRequest('whsec-tabby-abcdefghijklmnopqrstuvwxyz012345', $body),
        );

        expect($outcome->accepted)->toBeFalse();
        expect($outcome->status)->toBe(422);
    }

    Http::assertNothingSent();
});

/* ======================================================================= */
/* REFUNDS                                                                 */
/* ======================================================================= */

it('refunds against the capture id and not against the payment id', function () {
    /*
     * Tabby refunds a CAPTURE, not a payment, so `capture_id` in the body has to
     * be the capture's id. PaymentRefunder passes `capture_ref ?: transaction_id`
     * into this method, and the fallback half of that is the PAYMENT id — which
     * names nothing at Tabby. refund() therefore reads the order's own
     * `capture_ref` and ignores the parameter.
     *
     * Driven as a PARTIAL refund (100.00 of a 250.00 order), which is also the
     * shape that proves the amount sent is the one asked for rather than the
     * order total.
     *
     * MUTATION: send `$captureRef` instead of `$order->capture_ref` and this is
     * red — the body carries `pay_ref_1`, the payment id, instead of `cap_ref_1`.
     */
    $gateway = tg();
    $order = tgOrder([
        'transaction_id' => 'pay_ref_1',
        'capture_ref' => 'cap_ref_1',
        'captured_at' => now(),
        'captured_total' => 25000,
    ]);

    Http::fake([
        'api.tabby.ai/api/v1/payments/*/refunds' => Http::response([
            'id' => 'pay_ref_1',
            'status' => 'CLOSED',
            'refunds' => [['id' => 'ref_1', 'amount' => '100.00']],
        ], 200),
    ]);

    // The payment id is deliberately passed as $captureRef, which is exactly what
    // PaymentRefunder's fallback does and exactly what must not be sent.
    $result = $gateway->refund($order, 10000, 'Damaged in transit', 'pay_ref_1');

    expect($result->ok)->toBeTrue();
    expect($result->code)->toBe('refunded');
    expect($result->reference)->toBe('ref_1');

    $sent = Http::recorded()[0][0];

    expect($sent->url())->toBe('https://api.tabby.ai/api/v1/payments/pay_ref_1/refunds');
    expect($sent->data()['capture_id'])->toBe('cap_ref_1');
    // Major-unit decimal string, and the PARTIAL amount.
    expect($sent->data()['amount'])->toBe('100.00');
    expect($sent->data()['reason'])->toBe('Damaged in transit');
});

it('refuses to refund an authorisation nobody captured, and tells you to capture it', function () {
    /*
     * A refund against an uncaptured authorisation has nothing to point at, so it
     * is refused HERE rather than sent and rejected — with its own code, because
     * the fix is "capture it first" and no generic failure message says so.
     * Nothing is sent: the order carries no `capture_ref` and there is nothing to
     * ask Tabby about.
     *
     * MUTATION: delete the `$captureId === ''` guard and this is red — a request
     * goes out with an empty `capture_id`, which preventStrayRequests reports
     * because this test fakes nothing.
     */
    $gateway = tg();
    $order = tgOrder(['transaction_id' => 'pay_nocap', 'capture_ref' => null]);

    Http::fake();

    $result = $gateway->refund($order, 5000, null, null);

    expect($result->ok)->toBeFalse();
    expect($result->code)->toBe('not_captured');
    expect(str_contains(strtolower((string) $result->message), 'capture it first'))->toBeTrue();
    Http::assertNothingSent();
});

it('reports a refund tabby refused as a refund that did not happen', function () {
    /*
     * A declined refund must not read as a success: PaymentRefunder writes
     * `refunded_total` off an ok(), and a shop that believed a refused refund
     * would show the customer's money as returned and stop chasing it.
     *
     * Two shapes. Tabby's own refusal carries `errorType`, which
     * RemoteGateway::errorCode() reduces to an identifier — and a 200 with an
     * empty `refunds[]`, which is the shape that would otherwise slip through as
     * a success with a null reference.
     *
     * MUTATION: drop `|| $refundId === null` from the failure condition and the
     * second case is red — ok() with no reference.
     */
    $gateway = tg();
    $answer = tgAnswers('api.tabby.ai/api/v1/payments/*/refunds');

    $cases = [
        'refused' => [fn () => Http::response(['errorType' => 'refund_amount_too_high'], 400), 'refund_amount_too_high'],
        'accepted and empty' => [fn () => Http::response(['id' => 'p', 'refunds' => []], 200), 'refund_rejected'],
    ];

    foreach ($cases as $name => [$response, $expectedCode]) {
        $order = tgOrder([
            'transaction_id' => 'pay_ref_bad',
            'capture_ref' => 'cap_ref_bad',
            'captured_at' => now(),
            'captured_total' => 25000,
        ]);

        $answer($response);

        $result = $gateway->refund($order, 25000, null, 'cap_ref_bad');

        expect($result->ok)->toBeFalse("[$name]");
        expect($result->code)->toBe($expectedCode);
        expect(str_contains((string) $result->message, 'Nothing has been returned'))->toBeTrue();
    }
});

/* ======================================================================= */
/* THE PROVIDER BEING DOWN IS NOT A 500 AT THE TILL                        */
/* ======================================================================= */

it('sends the shopper to another payment method when tabby times out', function () {
    /*
     * A BNPL provider having a bad morning must never become a 500 on this
     * shop's checkout. The transport exception is caught in RemoteGateway::call()
     * and every caller here turns null into a message — this drives the checkout
     * path, the capture path and the release path through a real
     * ConnectionException and asserts that none of them throws.
     *
     * MUTATION: remove the try/catch from RemoteGateway::call() and this test
     * errors out with an uncaught ConnectionException instead of failing.
     */
    $gateway = tg();
    $order = tgOrder(['transaction_id' => 'pay_timeout']);

    Http::fake([
        'api.tabby.ai/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error 28'),
    ]);

    $start = $gateway->start($order);

    expect($start->ok())->toBeFalse();
    expect($start->redirectUrl)->toBeNull();
    expect(str_contains((string) $start->message, 'another payment method'))->toBeTrue();

    expect($gateway->capture($order, 25000)->code)->toBe('unreachable');
    expect($gateway->voidAuthorisation($order)->code)->toBe('unreachable');

    // Nothing was written to the order on any of the three.
    expect($order->fresh()->paid_at)->toBeNull();
    expect($order->fresh()->captured_at)->toBeNull();
    expect($order->fresh()->voided_at)->toBeNull();
});

it('treats a checkout answer that is not json as a failure and not as a redirect', function () {
    /*
     * The plugin has met this one in production and named it — "API html response
     * detected", a proxy or a WAF answering 200 with an error page. A gateway that
     * read `web_url` out of that would hand the shopper `null` as a redirect.
     *
     * MUTATION: have start() return `PaymentStart::redirect((string) $url)`
     * without the `is_string($url)` check and this is red.
     */
    $gateway = tg();
    $order = tgOrder();
    $answer = tgAnswers('api.tabby.ai/api/v2/checkout');

    foreach ([
        'html' => fn () => Http::response('<!DOCTYPE html><html>no</html>', 200, ['Content-Type' => 'text/html']),
        'json that is not an object' => fn () => Http::response('"created"', 200),
        'created but empty' => fn () => Http::response(['status' => 'created'], 200),
        'no payment id' => fn () => Http::response([
            'status' => 'created',
            'configuration' => ['available_products' => ['installments' => [['web_url' => 'https://checkout.tabby.ai/x']]]],
        ], 200),
        'no web_url on the product' => fn () => Http::response([
            'status' => 'created',
            'payment' => ['id' => 'p1'],
            'configuration' => ['available_products' => ['installments' => [[]]]],
        ], 200),
        'a status that is not created' => fn () => Http::response([
            'status' => 'rejected',
            'payment' => ['id' => 'p1'],
            'configuration' => ['available_products' => ['installments' => [['web_url' => 'https://checkout.tabby.ai/x']]]],
        ], 200),
    ] as $name => $response) {
        $answer($response);

        $start = $gateway->start($order->fresh());

        expect($start->ok())->toBeFalse("[$name] must not redirect");
        expect($start->redirectUrl)->toBeNull();
        // And nothing was written to the order on any of them.
        expect($order->fresh()->transaction_id)->toBeNull();
    }
});

/* ======================================================================= */
/* COST — MEASURED, AND THE SLOPE IS WHAT MATTERS                          */
/* ======================================================================= */

it('costs the same to build a checkout body however many past orders the buyer has', function () {
    /*
     * CLAUDE.md rule 4, measured at 1, 2, 5 and 10 rather than asserted. The
     * past-order history is the one part of the checkout body whose size moves
     * with the customer's record, and the tempting implementation — walk the
     * orders, load each one's items — is a textbook N+1 on the checkout POST,
     * which is the slowest request in the shop and the one a shopper is waiting
     * on.
     *
     * The history is capped at ten and eager-loads its items in one query, so the
     * COUNT IS FLAT: the slope across 1 → 10 previous orders must be zero.
     *
     * MUTATION: drop the `->with(['items:...'])` from orderHistory() and this is
     * red — the count climbs by one per previous order (2, 3, 6, 11 extra).
     */
    $gateway = tg(['share_order_history' => '1']);
    $answer = tgAnswers('api.tabby.ai/api/v2/checkout');
    $answer(fn () => Http::response(tgCheckoutBody(), 200));

    /*
     * A throwaway call first, and the reason is the measurement rather than
     * convenience. GatewayCredentials memoises the decrypted provider row on
     * first read, so the very first start() in a process spends ONE query that no
     * later one does — measured, it reads 5, 4, 4, 4 across 1/2/5/10. That is a
     * one-off constant, not a slope, and leaving it in would have made the first
     * sample the outlier and hidden the number that matters.
     */
    $gateway->start(tgOrder(['email' => 'warmup@example.com']));

    $counts = [];

    foreach ([1, 2, 5, 10] as $n) {
        // A fresh buyer per sample, so the n-th sample really has n past orders.
        $email = "slope-{$n}@example.com";

        for ($i = 0; $i < $n; $i++) {
            $past = tgOrder(['email' => $email, 'status' => 'completed']);
            $past->items()->create([
                'name' => 'Second line',
                'sku' => 'COS-2',
                'quantity' => 1,
                'unit_price' => 1000,
                'subtotal' => 1000,
                'total' => 1000,
            ]);
        }

        $order = tgOrder(['email' => $email]);

        \Illuminate\Support\Facades\DB::flushQueryLog();
        \Illuminate\Support\Facades\DB::enableQueryLog();

        $gateway->start($order);

        $counts[$n] = count(\Illuminate\Support\Facades\DB::getQueryLog());
        \Illuminate\Support\Facades\DB::disableQueryLog();
    }

    // Flat. Reported as the whole shape rather than as a single bound, so a
    // regression names the slope instead of a threshold somebody can raise.
    expect($counts[10] - $counts[1])->toBe(0, 'slope 1 -> 10: ' . json_encode($counts));
    expect($counts[5] - $counts[2])->toBe(0, 'slope 2 -> 5: ' . json_encode($counts));
    // All four identical, stated as one assertion so the failure prints the shape.
    expect(count(array_unique($counts)))->toBe(1, 'counts: ' . json_encode($counts));
    // And the absolute figure, so a new query added to this path is visible too.
    expect($counts[1])->toBeLessThanOrEqual(8, json_encode($counts));
});

it('sends at most ten past orders however many there are', function () {
    /*
     * The cap, driven. Tabby's schema takes ten and the plugin slices to ten; an
     * uncapped list on a long-standing customer would be a request body of
     * hundreds of orders, each with its items, on the checkout POST.
     *
     * MUTATION: raise or delete the `->limit(self::HISTORY_LIMIT)` and this is
     * red — 14 entries instead of 10.
     */
    $gateway = tg(['share_order_history' => '1']);

    for ($i = 0; $i < 14; $i++) {
        tgOrder(['email' => 'many@example.com', 'status' => 'completed']);
    }

    $order = tgOrder(['email' => 'many@example.com']);

    Http::fake(['api.tabby.ai/api/v2/checkout' => Http::response(tgCheckoutBody(), 200)]);

    $gateway->start($order);

    $body = Http::recorded()[0][0]->data();

    expect(count($body['payment']['order_history']))->toBe(10);
    // And the order being placed is never in its own history.
    $references = array_column($body['payment']['order_history'], 'reference_id');
    expect(in_array($order->order_number, $references, true))->toBeFalse();
});

/**
 * A stub whose answer can be CHANGED between the cases of one test.
 *
 * THE TRAP THIS EXISTS FOR, because it produced a test that passed for the wrong
 * reason in this very file. `Http::fake([$url => $response])` MERGES its stubs
 * into the ones already registered and the FIRST matching stub wins, so calling
 * it a second time for the same URL inside one test is a NO-OP: every later case
 * in the loop is served the FIRST case's response. A four-case loop asserts one
 * case four times and reports four passes.
 *
 * `it refuses a redirect url that is not an https tabby address` was doing
 * exactly that — only `javascript:alert(1)` was ever served, and the two hostile
 * https hosts beneath it were never sent, never rejected and never tested.
 *
 * So the stub is registered ONCE and answers by calling whatever closure the
 * returned setter was last handed. A case that forgets to set one gets an
 * exception rather than a stale answer, which is the other half of the point.
 */
function tgAnswers(string $pattern = 'api.tabby.ai/*'): Closure
{
    $box = new stdClass();
    $box->answer = null;

    Http::fake([$pattern => function () use ($box) {
        if ($box->answer === null) {
            throw new RuntimeException('tgAnswers(): this case set no response.');
        }

        return ($box->answer)();
    }]);

    return function (Closure $answer) use ($box): void {
        $box->answer = $answer;
    };
}
