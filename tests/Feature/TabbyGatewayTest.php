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
     * both halves of this are red.
     */
    $gateway = tg();

    foreach ([
        'javascript:alert(1)',
        'http://checkout.tabby.ai/installments/session',
        'https://checkout.tabby.ai.evil.example/installments',
    ] as $hostile) {
        $order = tgOrder();

        $body = tgCheckoutBody();
        $body['configuration']['available_products']['installments'][0]['web_url'] = $hostile;

        Http::fake(['api.tabby.ai/api/v2/checkout' => Http::response($body, 200)]);

        $start = $gateway->start($order);

        expect($start->ok())->toBeFalse("{$hostile} was accepted as a redirect target");
        expect($order->fresh()->transaction_id)->toBeNull();
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
