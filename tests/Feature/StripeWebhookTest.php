<?php

/**
 * Phase 11 — Stripe webhook verification.
 *
 * Stripe's scheme has three things that are each easy to get wrong and each
 * load bearing, so there is a test per failure: the MAC must be over the raw
 * body, the comparison must be constant-time, and the timestamp must be
 * checked or a captured delivery can be replayed forever.
 */

use App\Models\Order;
use App\Models\PaymentProvider;
use App\Services\Payments\GatewayRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

const STRIPE_URL_SECRET = 'whsec-url-stripe-0123456789ab';
const STRIPE_SIGNING = 'whsec_test_signing_secret_value';

beforeEach(function () {
    PaymentProvider::query()->delete();
    app(\App\Services\Payments\GatewayCredentials::class)->forget();
});

function stripeProvider(array $config = []): void
{
    $row = PaymentProvider::create([
        'id' => 'stripe',
        'title' => 'Card',
        'enabled' => true,
        'mode' => 'test',
        'position' => 3,
    ]);

    $row->config = array_merge([
        'publishable_key' => 'pk_test_kbb',
        'secret_key' => 'sk_test_kbb',
        'webhook_signing_secret' => STRIPE_SIGNING,
        'webhook_secret' => STRIPE_URL_SECRET,
    ], $config);

    $row->save();

    app(\App\Services\Payments\GatewayCredentials::class)->forget();
}

function stripeOrder(int $totalFils = 40000): Order
{
    return Order::create([
        'order_number' => 'STR-' . uniqid(),
        'email' => 'buyer@example.com',
        'status' => 'pending',
        'currency' => 'AED',
        'subtotal' => $totalFils,
        'total' => $totalFils,
    ]);
}

/**
 * A Stripe delivery. $signWith / $timestamp are overridable so the tests can
 * forge them; by default it is a genuine, current signature.
 */
function stripeRequest(
    array $event,
    string $urlSecret = STRIPE_URL_SECRET,
    ?string $signWith = STRIPE_SIGNING,
    ?int $timestamp = null,
    ?string $rawOverride = null,
): Request {
    $raw = $rawOverride ?? json_encode($event);
    $ts = $timestamp ?? time();

    $server = [];

    if ($signWith !== null) {
        $mac = hash_hmac('sha256', $ts . '.' . $raw, $signWith);
        $server['HTTP_STRIPE_SIGNATURE'] = "t={$ts},v1={$mac}";
    }

    $request = Request::create(
        "/api/payments/webhook/stripe/{$urlSecret}",
        'POST', [], [], [], $server, $raw,
    );

    $request->headers->set('Content-Type', 'application/json');

    $route = new \Illuminate\Routing\Route(['POST'], '/api/payments/webhook/{gateway}/{secret}', fn () => null);
    $route->bind($request);
    $request->setRouteResolver(fn () => $route);

    return $request;
}

function completedEvent(Order $order, int $amountMinor, string $currency = 'aed'): array
{
    return [
        'id' => 'evt_' . uniqid(),
        'type' => 'checkout.session.completed',
        'data' => ['object' => [
            'id' => 'cs_test_123',
            'payment_intent' => 'pi_test_123',
            'client_reference_id' => $order->order_number,
            'payment_status' => 'paid',
            'amount_total' => $amountMinor,
            'currency' => $currency,
        ]],
    ];
}

/* ------------------------------------------------------- signature rejection */

it('rejects a stripe webhook with no signature header', function () {
    stripeProvider();
    $order = stripeOrder();

    $outcome = app(GatewayRegistry::class)->find('stripe')
        ->handleWebhook(stripeRequest(completedEvent($order, 40000), signWith: null));

    expect($outcome->status)->toBe(401);
    expect($order->fresh()->paid_at)->toBeNull();
});

it('rejects a stripe webhook signed with the wrong secret', function () {
    stripeProvider();
    $order = stripeOrder();

    $outcome = app(GatewayRegistry::class)->find('stripe')
        ->handleWebhook(stripeRequest(completedEvent($order, 40000), signWith: 'whsec_not_the_real_one'));

    expect($outcome->status)->toBe(401);
    expect($order->fresh()->paid_at)->toBeNull();
});

it('rejects a stripe webhook whose body was altered after signing', function () {
    stripeProvider();
    $order = stripeOrder(40000);

    // Signed honestly for 400.00, then the body is swapped for one claiming a
    // different order. This is the case that fails if the MAC is computed over
    // re-encoded JSON rather than the raw bytes.
    $signed = json_encode(completedEvent($order, 40000));
    $tampered = str_replace('"amount_total":40000', '"amount_total":1', $signed);

    $ts = time();
    $mac = hash_hmac('sha256', $ts . '.' . $signed, STRIPE_SIGNING);

    $request = Request::create(
        '/api/payments/webhook/stripe/' . STRIPE_URL_SECRET,
        'POST', [], [], [],
        ['HTTP_STRIPE_SIGNATURE' => "t={$ts},v1={$mac}", 'CONTENT_TYPE' => 'application/json'],
        $tampered,
    );

    $route = new \Illuminate\Routing\Route(['POST'], '/api/payments/webhook/{gateway}/{secret}', fn () => null);
    $route->bind($request);
    $request->setRouteResolver(fn () => $route);

    $outcome = app(GatewayRegistry::class)->find('stripe')->handleWebhook($request);

    expect($outcome->status)->toBe(401);
    expect($order->fresh()->paid_at)->toBeNull();
});

it('rejects a replayed stripe delivery outside the timestamp window', function () {
    stripeProvider();
    $order = stripeOrder();

    // A genuine signature, captured an hour ago. Valid forever without a
    // timestamp check, because the MAC itself does not expire.
    $outcome = app(GatewayRegistry::class)->find('stripe')
        ->handleWebhook(stripeRequest(completedEvent($order, 40000), timestamp: time() - 3600));

    expect($outcome->status)->toBe(401);
    expect($order->fresh()->paid_at)->toBeNull();
});

it('rejects a stripe webhook when no signing secret is configured', function () {
    stripeProvider(['webhook_signing_secret' => '']);
    $order = stripeOrder();

    $outcome = app(GatewayRegistry::class)->find('stripe')
        ->handleWebhook(stripeRequest(completedEvent($order, 40000), signWith: ''));

    expect($outcome->status)->toBe(401);
});

it('rejects a correctly signed stripe webhook sent to the wrong url secret', function () {
    stripeProvider();
    $order = stripeOrder();

    $outcome = app(GatewayRegistry::class)->find('stripe')
        ->handleWebhook(stripeRequest(completedEvent($order, 40000), urlSecret: 'wrong-url-secret-1234567'));

    expect($outcome->status)->toBe(401);
});

/* ----------------------------------------------------------- amount checks */

it('refuses a stripe payment whose amount disagrees with the order', function () {
    stripeProvider();
    $order = stripeOrder(40000);        // د.إ400.00

    // Properly signed, and still wrong.
    $outcome = app(GatewayRegistry::class)->find('stripe')
        ->handleWebhook(stripeRequest(completedEvent($order, 100)));

    expect($outcome->status)->toBe(422);
    expect($order->fresh()->paid_at)->toBeNull();
});

it('refuses a stripe payment in the wrong currency', function () {
    stripeProvider();
    $order = stripeOrder(40000);

    $outcome = app(GatewayRegistry::class)->find('stripe')
        ->handleWebhook(stripeRequest(completedEvent($order, 40000, 'usd')));

    expect($outcome->status)->toBe(422);
    expect($order->fresh()->paid_at)->toBeNull();
});

/* ------------------------------------------------------------- happy path */

it('marks an order paid on a valid stripe webhook, once', function () {
    stripeProvider();
    $order = stripeOrder(40000);

    $gateway = app(GatewayRegistry::class)->find('stripe');
    $event = completedEvent($order, 40000);

    $first = $gateway->handleWebhook(stripeRequest($event));
    $second = $gateway->handleWebhook(stripeRequest($event));

    expect($first->accepted)->toBeTrue()
        ->and($second->message)->toBe('already applied');

    $order->refresh();

    expect($order->paid_at)->not->toBeNull()
        ->and($order->status)->toBe('processing')
        ->and($order->transaction_id)->toBe('pi_test_123');

    expect(\App\Models\Payment::where('order_id', $order->id)->count())->toBe(1);
});

it('does not mark a completed but unpaid stripe session as paid', function () {
    stripeProvider();
    $order = stripeOrder(40000);

    $event = completedEvent($order, 40000);
    $event['data']['object']['payment_status'] = 'unpaid';

    $outcome = app(GatewayRegistry::class)->find('stripe')->handleWebhook(stripeRequest($event));

    expect($outcome->accepted)->toBeTrue()      // not an error
        ->and($outcome->status)->toBe(200);

    expect($order->fresh()->paid_at)->toBeNull();
});

it('marks an order failed when a stripe session expires', function () {
    stripeProvider();
    $order = stripeOrder(40000);

    $event = completedEvent($order, 40000);
    $event['type'] = 'checkout.session.expired';

    app(GatewayRegistry::class)->find('stripe')->handleWebhook(stripeRequest($event));

    $order->refresh();

    expect($order->status)->toBe('failed')
        ->and($order->paid_at)->toBeNull();
});

it('accepts any one of several v1 signatures during a secret rollover', function () {
    stripeProvider();
    $order = stripeOrder(40000);

    $event = completedEvent($order, 40000);
    $raw = json_encode($event);
    $ts = time();

    $good = hash_hmac('sha256', $ts . '.' . $raw, STRIPE_SIGNING);

    $request = Request::create(
        '/api/payments/webhook/stripe/' . STRIPE_URL_SECRET,
        'POST', [], [], [],
        // The old secret's signature first, the current one second.
        ['HTTP_STRIPE_SIGNATURE' => "t={$ts},v1=deadbeef,v1={$good}", 'CONTENT_TYPE' => 'application/json'],
        $raw,
    );

    $route = new \Illuminate\Routing\Route(['POST'], '/api/payments/webhook/{gateway}/{secret}', fn () => null);
    $route->bind($request);
    $request->setRouteResolver(fn () => $route);

    expect(app(GatewayRegistry::class)->find('stripe')->handleWebhook($request)->accepted)->toBeTrue();
    expect($order->fresh()->paid_at)->not->toBeNull();
});

/* -------------------------------------------------- unconfigured behaviour */

it('reports every remote gateway unavailable with no credentials, without fatalling', function () {
    // The state this ships in: rows enabled, config empty.
    foreach (['stripe', 'tabby', 'tamara'] as $id) {
        PaymentProvider::create([
            'id' => $id, 'title' => ucfirst($id), 'enabled' => true, 'mode' => 'test', 'position' => 1,
        ]);
    }

    app(\App\Services\Payments\GatewayCredentials::class)->forget();

    $registry = app(GatewayRegistry::class);

    foreach (['stripe', 'tabby', 'tamara'] as $id) {
        // That the call returns at all is half the assertion.
        expect($registry->find($id)->configured())->toBeFalse();
    }

    expect($registry->availableFor(40000))->toBeEmpty();
    expect($registry->checkoutList(40000))->toBe([]);
});

it('refuses to start a payment on an unconfigured gateway instead of throwing', function () {
    PaymentProvider::create(['id' => 'stripe', 'title' => 'Card', 'enabled' => true, 'mode' => 'test', 'position' => 1]);
    app(\App\Services\Payments\GatewayCredentials::class)->forget();

    Http::fake();

    $start = app(GatewayRegistry::class)->find('stripe')->start(stripeOrder());

    expect($start->ok())->toBeFalse()
        ->and($start->message)->toBeString();

    // It must not have tried to call Stripe with no key.
    Http::assertNothingSent();
});

it('does not fatal when a config blob cannot be decrypted', function () {
    stripeProvider();

    // Raw write past the encrypted cast, as an app-key rotation would leave it.
    \Illuminate\Support\Facades\DB::table('payment_providers')
        ->where('id', 'stripe')->update(['config' => 'not-encrypted-at-all']);

    app(\App\Services\Payments\GatewayCredentials::class)->forget();

    expect(app(GatewayRegistry::class)->find('stripe')->configured())->toBeFalse();
});
