<?php

/**
 * The card fields on our own checkout — the server half.
 *
 * Every Stripe host is unreachable from CI and from the sandbox this was built
 * in, so everything here runs against Http::fake(). What that proves is the
 * shape of what we send, the shape of what we do with what comes back, and
 * every branch between the two. What it cannot prove is that Stripe agrees:
 * that needs one real test-mode payment, and docs/FU-STRIPE-CARD-FIELDS.md
 * says exactly which claims are waiting on it.
 */

use App\Models\Order;
use App\Models\PaymentProvider;
use App\Services\Payments\GatewayRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

const CARD_URL_SECRET = 'whsec-url-card-0123456789abcd';
const CARD_SIGNING = 'whsec_card_signing_secret_value';

beforeEach(function () {
    PaymentProvider::query()->delete();
    app(\App\Services\Payments\GatewayCredentials::class)->forget();
});

function cardProvider(array $config = []): void
{
    $row = PaymentProvider::create([
        'id' => 'stripe', 'title' => 'Card', 'enabled' => true, 'mode' => 'test', 'position' => 0,
    ]);

    $row->config = array_merge([
        'publishable_key' => 'pk_test_kbb_card',
        'secret_key' => 'sk_test_kbb_card',
        'webhook_signing_secret' => CARD_SIGNING,
        'webhook_secret' => CARD_URL_SECRET,
    ], $config);

    $row->save();

    app(\App\Services\Payments\GatewayCredentials::class)->forget();
}

function cardOrder(int $totalFils = 40000, array $extra = []): Order
{
    return Order::create(array_merge([
        'order_number' => 'CARD-' . uniqid(),
        'email' => 'buyer@example.com',
        'status' => 'pending',
        'currency' => 'AED',
        'subtotal' => $totalFils,
        'total' => $totalFils,
        'payment_method' => 'stripe',
    ], $extra));
}

function cardGateway(): \App\Services\Payments\Gateways\StripeGateway
{
    return app(GatewayRegistry::class)->find('stripe');
}

/** A signed delivery, built the way StripeWebhookTest builds one. */
function cardWebhook(array $event): Request
{
    $raw = json_encode($event);
    $ts = time();
    $mac = hash_hmac('sha256', $ts . '.' . $raw, CARD_SIGNING);

    $request = Request::create(
        '/api/payments/webhook/stripe/' . CARD_URL_SECRET,
        'POST', [], [], [],
        ['HTTP_STRIPE_SIGNATURE' => "t={$ts},v1={$mac}", 'CONTENT_TYPE' => 'application/json'],
        $raw,
    );

    $route = new \Illuminate\Routing\Route(['POST'], '/api/payments/webhook/{gateway}/{secret}', fn () => null);
    $route->bind($request);
    $request->setRouteResolver(fn () => $route);

    return $request;
}

/* =====================================================================
 | Opening a payment
 ===================================================================== */

it('opens a PaymentIntent rather than a hosted Checkout session', function () {
    cardProvider();

    Http::fake([
        'api.stripe.com/v1/payment_intents' => Http::response([
            'id' => 'pi_card_1',
            'client_secret' => 'pi_card_1_secret_abc',
            'status' => 'requires_payment_method',
        ], 200),
    ]);

    $order = cardOrder(40000);
    $start = cardGateway()->start($order);

    // The whole point of the change: something the page can confirm, and no
    // URL to send anybody to.
    expect($start->result)->toBe('confirm')
        ->and($start->clientSecret)->toBe('pi_card_1_secret_abc')
        ->and($start->redirectUrl)->toBeNull()
        ->and($start->providerRef)->toBe('pi_card_1');

    // Settlement reads this column and needs an intent in it.
    expect($order->fresh()->transaction_id)->toBe('pi_card_1');

    Http::assertSent(function (\Illuminate\Http\Client\Request $r) use ($order) {
        return $r->method() === 'POST'
            && parse_url($r->url(), PHP_URL_PATH) === '/v1/payment_intents'
            // Form-encoded, integer minor units, and pinned to cards.
            && str_contains($r->body(), 'amount=40000')
            && str_contains($r->body(), 'currency=aed')
            && str_contains($r->body(), 'payment_method_types%5B0%5D=card')
            // The stamp reconciliation matches a charge back to an order with.
            && str_contains($r->body(), 'metadata%5Border_number%5D=' . $order->order_number);
    });

    // And nothing at all was asked of the Checkout API.
    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/checkout/sessions'));
});

it('never sends the buyer to another site to type a card', function () {
    cardProvider();

    Http::fake([
        'api.stripe.com/v1/payment_intents' => Http::response([
            'id' => 'pi_card_2', 'client_secret' => 'pi_card_2_secret', 'status' => 'requires_payment_method',
        ], 200),
    ]);

    /*
     * The one requirement stated as an absence, because that is the shape of
     * the regression: a redirect URL coming back out of start() is all it
     * takes for CheckoutController to send the shopper away, and everything
     * else about this change would still look correct.
     */
    expect(cardGateway()->start(cardOrder())->redirectUrl)->toBeNull();
});

it('carries an idempotency key so a retried create cannot open a second intent', function () {
    cardProvider();

    Http::fake([
        'api.stripe.com/v1/payment_intents' => Http::response([
            'id' => 'pi_card_3', 'client_secret' => 'pi_card_3_secret', 'status' => 'requires_payment_method',
        ], 200),
    ]);

    $order = cardOrder();
    cardGateway()->start($order);

    // Keyed on the ORDER, so the retry of a request whose answer never arrived
    // gets the original intent back instead of a twin.
    Http::assertSent(fn (\Illuminate\Http\Client\Request $r) => $r->method() === 'POST'
        && $r->header('Idempotency-Key') === ['kbb-intent-' . $order->order_number]);
});

it('reuses a live intent instead of opening a second one for the same order', function () {
    cardProvider();

    Http::fake([
        'api.stripe.com/v1/payment_intents/pi_live_1' => Http::response([
            'id' => 'pi_live_1',
            'client_secret' => 'pi_live_1_secret',
            // What Stripe leaves behind after a declined card: still payable.
            'status' => 'requires_payment_method',
            'amount' => 40000,
            'currency' => 'aed',
        ], 200),
        'api.stripe.com/v1/payment_intents' => Http::response([
            'id' => 'pi_SHOULD_NOT_EXIST', 'client_secret' => 'nope', 'status' => 'requires_payment_method',
        ], 200),
    ]);

    $order = cardOrder(40000, ['transaction_id' => 'pi_live_1']);
    $start = cardGateway()->start($order);

    expect($start->clientSecret)->toBe('pi_live_1_secret')
        ->and($start->providerRef)->toBe('pi_live_1');

    Http::assertNotSent(fn (\Illuminate\Http\Client\Request $r) => $r->method() === 'POST'
        && parse_url($r->url(), PHP_URL_PATH) === '/v1/payment_intents');
});

it('opens a fresh intent when the one on the order has already been paid', function () {
    cardProvider();

    Http::fake([
        'api.stripe.com/v1/payment_intents/pi_done_1' => Http::response([
            'id' => 'pi_done_1', 'client_secret' => 'pi_done_1_secret',
            'status' => 'succeeded', 'amount' => 40000, 'currency' => 'aed',
        ], 200),
        'api.stripe.com/v1/payment_intents' => Http::response([
            'id' => 'pi_new_1', 'client_secret' => 'pi_new_1_secret', 'status' => 'requires_payment_method',
        ], 200),
    ]);

    // Handing a succeeded intent's client secret back to a card form is how a
    // shopper gets charged twice for one order.
    $start = cardGateway()->start(cardOrder(40000, ['transaction_id' => 'pi_done_1']));

    expect($start->providerRef)->toBe('pi_new_1');
});

it('opens a fresh intent when the basket changed under the old one', function () {
    cardProvider();

    Http::fake([
        'api.stripe.com/v1/payment_intents/pi_stale_1' => Http::response([
            'id' => 'pi_stale_1', 'client_secret' => 'pi_stale_1_secret',
            'status' => 'requires_payment_method',
            // Opened for a smaller basket.
            'amount' => 25000, 'currency' => 'aed',
        ], 200),
        'api.stripe.com/v1/payment_intents' => Http::response([
            'id' => 'pi_fresh_1', 'client_secret' => 'pi_fresh_1_secret', 'status' => 'requires_payment_method',
        ], 200),
    ]);

    $start = cardGateway()->start(cardOrder(40000, ['transaction_id' => 'pi_stale_1']));

    expect($start->providerRef)->toBe('pi_fresh_1');
});

it('refuses the card option when the publishable key is missing, and stays refundable', function () {
    cardProvider(['publishable_key' => '']);

    $gateway = cardGateway();

    // Not on the checkout: without the key Stripe.js cannot boot and the
    // fields would never appear.
    expect($gateway->availableFor(40000, 'AE'))->toBeFalse();

    // But still able to reach Stripe, because refunding money already taken
    // has nothing to do with drawing a card form.
    expect($gateway->configured())->toBeTrue();
});

/* =====================================================================
 | The webhook — still the authority
 ===================================================================== */

it('marks an order paid on payment_intent.succeeded', function () {
    cardProvider();
    $order = cardOrder(40000, ['transaction_id' => 'pi_hook_1']);

    $outcome = cardGateway()->handleWebhook(cardWebhook([
        'id' => 'evt_1',
        'type' => 'payment_intent.succeeded',
        'data' => ['object' => [
            'id' => 'pi_hook_1',
            'status' => 'succeeded',
            'amount' => 40000,
            'amount_received' => 40000,
            'currency' => 'aed',
            'metadata' => ['order_number' => $order->order_number],
        ]],
    ]));

    expect($outcome->status)->toBe(200);

    $order->refresh();

    expect($order->paid_at)->not->toBeNull()
        ->and($order->status)->toBe('processing')
        // The reference settlement and reconciliation both key off.
        ->and($order->transaction_id)->toBe('pi_hook_1');
});

it('checks the amount on payment_intent.succeeded against the order', function () {
    cardProvider();
    $order = cardOrder(40000);

    $outcome = cardGateway()->handleWebhook(cardWebhook([
        'id' => 'evt_2',
        'type' => 'payment_intent.succeeded',
        'data' => ['object' => [
            'id' => 'pi_hook_2', 'status' => 'succeeded',
            // What the intent ASKED for is right; what was TAKEN is not. Read
            // the wrong one of these two and a partial capture is accepted as
            // full payment.
            'amount' => 40000,
            'amount_received' => 1,
            'currency' => 'aed',
            'metadata' => ['order_number' => $order->order_number],
        ]],
    ]));

    expect($outcome->status)->toBe(422);
    expect($order->fresh()->paid_at)->toBeNull();
});

it('applies a payment_intent.succeeded delivery at most once', function () {
    cardProvider();
    $order = cardOrder(40000);

    $event = [
        'id' => 'evt_3',
        'type' => 'payment_intent.succeeded',
        'data' => ['object' => [
            'id' => 'pi_hook_3', 'status' => 'succeeded',
            'amount' => 40000, 'amount_received' => 40000, 'currency' => 'aed',
            'metadata' => ['order_number' => $order->order_number],
        ]],
    ];

    expect(cardGateway()->handleWebhook(cardWebhook($event))->message)->toBe('payment applied');
    expect(cardGateway()->handleWebhook(cardWebhook($event))->message)->toBe('already applied');

    expect(\App\Models\Payment::where('provider_ref', 'pi_hook_3')->count())->toBe(1);
});

it('does not fail the order when a card is declined but the intent is still payable', function () {
    cardProvider();
    $order = cardOrder(40000);

    /*
     * THE ONE THE MOVE TO ON-SITE FIELDS MADE DANGEROUS.
     *
     * Stripe puts the intent back to `requires_payment_method` after a
     * decline, and the shopper is still sitting in front of our card form with
     * another card. Failing the order here would release its stock and its
     * coupon; the second card would then succeed against an order in
     * PaymentConfirmer::VOID, which refuses the confirmation. Money taken,
     * nothing sold, and the shopper told the payment went through.
     */
    $outcome = cardGateway()->handleWebhook(cardWebhook([
        'id' => 'evt_4',
        'type' => 'payment_intent.payment_failed',
        'data' => ['object' => [
            'id' => 'pi_hook_4',
            'status' => 'requires_payment_method',
            'metadata' => ['order_number' => $order->order_number],
        ]],
    ]));

    expect($outcome->status)->toBe(200);

    $order->refresh();

    expect($order->status)->toBe('pending')
        ->and($order->paid_at)->toBeNull();
});

it('fails the order when the intent is dead rather than merely declined', function () {
    cardProvider();
    $order = cardOrder(40000);

    $outcome = cardGateway()->handleWebhook(cardWebhook([
        'id' => 'evt_5',
        'type' => 'payment_intent.canceled',
        'data' => ['object' => [
            'id' => 'pi_hook_5',
            'status' => 'canceled',
            'metadata' => ['order_number' => $order->order_number],
        ]],
    ]));

    expect($outcome->status)->toBe(200);
    expect($order->fresh()->status)->toBe('failed');
});

it('still applies a Checkout session webhook for an order placed the old way', function () {
    cardProvider();
    $order = cardOrder(40000, ['transaction_id' => 'cs_old_1']);

    /*
     * Orders placed before this package carry a Checkout session, and their
     * deliveries are still in flight and still being retried when the update
     * lands. Dropping this arm would strand every payment that was in progress
     * at the moment of the upgrade.
     */
    $outcome = cardGateway()->handleWebhook(cardWebhook([
        'id' => 'evt_6',
        'type' => 'checkout.session.completed',
        'data' => ['object' => [
            'id' => 'cs_old_1',
            'payment_intent' => 'pi_old_1',
            'client_reference_id' => $order->order_number,
            'payment_status' => 'paid',
            'amount_total' => 40000,
            'currency' => 'aed',
        ]],
    ]));

    expect($outcome->status)->toBe(200);
    expect($order->fresh()->paid_at)->not->toBeNull();
});

/* =====================================================================
 | The browser's report, verified rather than believed
 ===================================================================== */

it('reads the payment back from Stripe instead of believing the browser', function () {
    cardProvider();
    $order = cardOrder(40000, ['transaction_id' => 'pi_browser_1']);

    Http::fake([
        'api.stripe.com/v1/payment_intents/pi_browser_1' => Http::response([
            'id' => 'pi_browser_1', 'status' => 'succeeded',
            'amount' => 40000, 'amount_received' => 40000, 'currency' => 'aed',
        ], 200),
    ]);

    expect(cardGateway()->confirmFromBrowser($order)->status)->toBe(200);
    expect($order->fresh()->paid_at)->not->toBeNull();
});

it('does not mark an order paid because the browser said so', function () {
    cardProvider();
    $order = cardOrder(40000, ['transaction_id' => 'pi_browser_2']);

    Http::fake([
        // Stripe's answer, which is the only one that counts.
        'api.stripe.com/v1/payment_intents/pi_browser_2' => Http::response([
            'id' => 'pi_browser_2', 'status' => 'requires_payment_method',
            'amount' => 40000, 'currency' => 'aed',
        ], 200),
    ]);

    cardGateway()->confirmFromBrowser($order);

    expect($order->fresh()->paid_at)->toBeNull();
});

it('does not double-apply when the browser and the webhook both report the same payment', function () {
    cardProvider();
    $order = cardOrder(40000, ['transaction_id' => 'pi_both_1']);

    Http::fake([
        'api.stripe.com/v1/payment_intents/pi_both_1' => Http::response([
            'id' => 'pi_both_1', 'status' => 'succeeded',
            'amount' => 40000, 'amount_received' => 40000, 'currency' => 'aed',
        ], 200),
    ]);

    cardGateway()->confirmFromBrowser($order);

    $again = cardGateway()->handleWebhook(cardWebhook([
        'id' => 'evt_7',
        'type' => 'payment_intent.succeeded',
        'data' => ['object' => [
            'id' => 'pi_both_1', 'status' => 'succeeded',
            'amount' => 40000, 'amount_received' => 40000, 'currency' => 'aed',
            'metadata' => ['order_number' => $order->order_number],
        ]],
    ]));

    expect($again->message)->toBe('already applied');
    expect(\App\Models\Payment::where('provider_ref', 'pi_both_1')->count())->toBe(1);
});

/* =====================================================================
 | Giving up
 ===================================================================== */

it('cancels the intent at Stripe before anything is released', function () {
    cardProvider();
    $order = cardOrder(40000, ['transaction_id' => 'pi_bail_1']);

    Http::fake([
        'api.stripe.com/v1/payment_intents/pi_bail_1/cancel' => Http::response([
            'id' => 'pi_bail_1', 'status' => 'canceled',
        ], 200),
        'api.stripe.com/v1/payment_intents/pi_bail_1' => Http::response([
            'id' => 'pi_bail_1', 'status' => 'requires_payment_method',
            'amount' => 40000, 'currency' => 'aed',
        ], 200),
    ]);

    expect(cardGateway()->abandonIntent($order))->toBeTrue();

    Http::assertSent(fn (\Illuminate\Http\Client\Request $r) => $r->method() === 'POST'
        && str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/pi_bail_1/cancel'));
});

it('refuses to abandon a payment that has already gone through', function () {
    cardProvider();
    $order = cardOrder(40000, ['transaction_id' => 'pi_bail_2']);

    Http::fake([
        /*
         * THE CANCEL IS FAKED TOO, although this test asserts it is never
         * called, and that is the point rather than an oversight corrected.
         *
         * Without a stub the call falls through to the real network, which is
         * unreachable here — so stripeAttempt() catches the transport failure,
         * abandonIntent() returns false for the wrong reason, and no request is
         * recorded for assertNotSent() to see. The test then passes with the
         * guard deleted. Measured: removing the guard below left this green
         * until this stub existed.
         */
        'api.stripe.com/v1/payment_intents/pi_bail_2/cancel' => Http::response([
            'id' => 'pi_bail_2', 'status' => 'canceled',
        ], 200),
        'api.stripe.com/v1/payment_intents/pi_bail_2' => Http::response([
            'id' => 'pi_bail_2', 'status' => 'succeeded',
            'amount' => 40000, 'currency' => 'aed',
        ], 200),
    ]);

    /*
     * The caller releases the order's stock and coupon on a true and on
     * nothing else. Money that has moved is a refund, and a decision for the
     * merchant — not something a browser gets to trigger by pressing a link.
     */
    expect(cardGateway()->abandonIntent($order))->toBeFalse();

    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/cancel'));
});

it('refuses to abandon when Stripe cannot be reached at all', function () {
    cardProvider();
    $order = cardOrder(40000, ['transaction_id' => 'pi_bail_3']);

    Http::fake(['api.stripe.com/*' => Http::response(['error' => ['code' => 'nope']], 503)]);

    // An intent we could not read is an intent that may still be confirmable,
    // and releasing this order's stock against one is how a paying customer
    // ends up with no product.
    expect(cardGateway()->abandonIntent($order))->toBeFalse();
});
