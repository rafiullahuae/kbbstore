<?php

/**
 * Phase 11 — webhook verification.
 *
 * This file is the reason the lane exists. An unverified webhook endpoint lets
 * anyone mark any order paid, so each case here is an attack rather than a
 * feature: a forged body, a wrong signature, a replayed delivery, an amount
 * that does not match the order.
 *
 * RefreshDatabase does not roll back the FIRST test in a process, so every
 * fixture below is uniquely named and each test clears the provider rows it
 * depends on.
 */

use App\Models\Order;
use App\Models\PaymentProvider;
use App\Services\Payments\GatewayRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

const TABBY_SECRET = 'whsec-tabby-0123456789abcdef';

beforeEach(function () {
    PaymentProvider::query()->delete();
    app(\App\Services\Payments\GatewayCredentials::class)->forget();
});

function tabbyProvider(array $config = []): PaymentProvider
{
    $row = PaymentProvider::create([
        'id' => 'tabby',
        'title' => 'Tabby',
        'enabled' => true,
        'mode' => 'test',
        'position' => 1,
    ]);

    $row->config = array_merge([
        'public_key' => 'pk_test_kbb',
        'secret_key' => 'sk_test_kbb',
        'merchant_code' => 'AE',
        'webhook_secret' => TABBY_SECRET,
    ], $config);

    $row->save();

    app(\App\Services\Payments\GatewayCredentials::class)->forget();

    return $row;
}

function payableOrder(int $totalFils = 25000, string $currency = 'AED'): Order
{
    return Order::create([
        'order_number' => 'WH-' . uniqid(),
        'email' => 'buyer@example.com',
        'status' => 'pending',
        'currency' => $currency,
        'subtotal' => $totalFils,
        'total' => $totalFils,
    ]);
}

/** A webhook POST with the secret bound as a route parameter, as the router does. */
function webhookRequest(string $gateway, string $secret, array $body = [], array $headers = []): Request
{
    $request = Request::create(
        "/api/payments/webhook/{$gateway}/{$secret}",
        'POST',
        [],
        [],
        [],
        collect($headers)->mapWithKeys(fn ($v, $k) => ['HTTP_' . strtoupper(str_replace('-', '_', $k)) => $v])->all(),
        json_encode($body),
    );

    $request->headers->set('Content-Type', 'application/json');

    $route = new \Illuminate\Routing\Route(['POST'], '/api/payments/webhook/{gateway}/{secret}', fn () => null);
    $route->bind($request);
    $request->setRouteResolver(fn () => $route);

    return $request;
}

/* ------------------------------------------------- a bad signature is refused */

it('rejects a tabby webhook with no secret at all', function () {
    tabbyProvider();
    Http::fake();   // any outbound call here would itself be a failure

    $outcome = app(GatewayRegistry::class)->find('tabby')
        ->handleWebhook(webhookRequest('tabby', 'x', ['id' => 'pay_forged']));

    expect($outcome->accepted)->toBeFalse()
        ->and($outcome->status)->toBe(401);

    // It must not have gone anywhere near Tabby's API on an unverified call.
    Http::assertNothingSent();
});

it('rejects a tabby webhook whose secret is wrong by one character', function () {
    tabbyProvider();
    Http::fake();

    $wrong = substr(TABBY_SECRET, 0, -1) . 'X';

    $outcome = app(GatewayRegistry::class)->find('tabby')
        ->handleWebhook(webhookRequest('tabby', $wrong, ['id' => 'pay_forged']));

    expect($outcome->accepted)->toBeFalse()
        ->and($outcome->status)->toBe(401);

    Http::assertNothingSent();
});

it('rejects a tabby webhook when no webhook secret is configured', function () {
    // An empty stored secret must never verify -- otherwise a gateway that is
    // half set up accepts everything.
    tabbyProvider(['webhook_secret' => '']);
    Http::fake();

    $outcome = app(GatewayRegistry::class)->find('tabby')
        ->handleWebhook(webhookRequest('tabby', 'anything-at-all-1234', ['id' => 'pay_x']));

    expect($outcome->accepted)->toBeFalse()
        ->and($outcome->status)->toBe(401);
});

/* ----------------------------------- the body is never trusted for the amount */

it('refuses a tabby payment whose amount disagrees with the order', function () {
    tabbyProvider();
    $order = payableOrder(25000);          // د.إ250.00

    // Tabby's own API says 5.00 was paid. The forged body claiming 250 is
    // irrelevant: the verified GET is what counts.
    Http::fake([
        '*/payments/pay_short' => Http::response([
            'id' => 'pay_short',
            'status' => 'AUTHORIZED',
            'amount' => '5.00',
            'currency' => 'AED',
            'order' => ['reference_id' => $order->order_number],
        ]),
    ]);

    $outcome = app(GatewayRegistry::class)->find('tabby')->handleWebhook(
        webhookRequest('tabby', TABBY_SECRET, [
            'id' => 'pay_short',
            'amount' => '250.00',           // the lie
            'status' => 'AUTHORIZED',
        ])
    );

    expect($outcome->accepted)->toBeFalse()
        ->and($outcome->status)->toBe(422);

    expect($order->fresh()->paid_at)->toBeNull()
        ->and($order->fresh()->status)->toBe('pending');
});

it('refuses a tabby payment in the wrong currency', function () {
    tabbyProvider();
    $order = payableOrder(25000, 'AED');

    Http::fake([
        '*/payments/pay_sar' => Http::response([
            'id' => 'pay_sar',
            'status' => 'AUTHORIZED',
            'amount' => '250.00',
            'currency' => 'SAR',            // same number, different money
            'order' => ['reference_id' => $order->order_number],
        ]),
    ]);

    $outcome = app(GatewayRegistry::class)->find('tabby')
        ->handleWebhook(webhookRequest('tabby', TABBY_SECRET, ['id' => 'pay_sar']));

    expect($outcome->status)->toBe(422);
    expect($order->fresh()->paid_at)->toBeNull();
});

it('refuses a tabby webhook for a reference that is not ours', function () {
    tabbyProvider();

    Http::fake([
        '*/payments/pay_ghost' => Http::response([
            'id' => 'pay_ghost',
            'status' => 'AUTHORIZED',
            'amount' => '10.00',
            'currency' => 'AED',
            'order' => ['reference_id' => 'NOT-AN-ORDER-9999'],
        ]),
    ]);

    $outcome = app(GatewayRegistry::class)->find('tabby')
        ->handleWebhook(webhookRequest('tabby', TABBY_SECRET, ['id' => 'pay_ghost']));

    expect($outcome->status)->toBe(422);
});

/* ------------------------------------------------------ the happy path, once */

it('marks an order paid on a valid tabby webhook', function () {
    tabbyProvider();
    $order = payableOrder(25000);

    Http::fake([
        '*/payments/pay_good' => Http::response([
            'id' => 'pay_good',
            'status' => 'AUTHORIZED',
            'amount' => '250.00',
            'currency' => 'AED',
            'order' => ['reference_id' => $order->order_number],
        ]),
    ]);

    $outcome = app(GatewayRegistry::class)->find('tabby')
        ->handleWebhook(webhookRequest('tabby', TABBY_SECRET, ['id' => 'pay_good']));

    expect($outcome->accepted)->toBeTrue()
        ->and($outcome->status)->toBe(200);

    $order->refresh();

    expect($order->paid_at)->not->toBeNull()
        ->and($order->status)->toBe('processing')
        ->and($order->transaction_id)->toBe('pay_good');
});

it('applies the same tabby webhook only once', function () {
    tabbyProvider();
    $order = payableOrder(25000);

    Http::fake([
        '*/payments/pay_twice' => Http::response([
            'id' => 'pay_twice',
            'status' => 'AUTHORIZED',
            'amount' => '250.00',
            'currency' => 'AED',
            'order' => ['reference_id' => $order->order_number],
        ]),
    ]);

    $gateway = app(GatewayRegistry::class)->find('tabby');
    $request = fn () => webhookRequest('tabby', TABBY_SECRET, ['id' => 'pay_twice']);

    $first = $gateway->handleWebhook($request());
    $paidAt = $order->fresh()->paid_at;

    $second = $gateway->handleWebhook($request());
    $third = $gateway->handleWebhook($request());

    // Both later deliveries are accepted -- they are not errors -- but nothing
    // is applied a second time.
    expect($first->accepted)->toBeTrue()
        ->and($second->accepted)->toBeTrue()
        ->and($second->message)->toBe('already applied')
        ->and($third->message)->toBe('already applied');

    $order->refresh();

    // The timestamp did not move, and there is exactly one payment row.
    expect($order->paid_at->toString())->toBe($paidAt->toString());

    expect(\App\Models\Payment::where('order_id', $order->id)->count())->toBe(1);
});

it('records a declined tabby payment without marking it paid', function () {
    tabbyProvider();
    $order = payableOrder(25000);

    Http::fake([
        '*/payments/pay_no' => Http::response([
            'id' => 'pay_no',
            'status' => 'REJECTED',
            'amount' => '250.00',
            'currency' => 'AED',
            'order' => ['reference_id' => $order->order_number],
        ]),
    ]);

    app(GatewayRegistry::class)->find('tabby')
        ->handleWebhook(webhookRequest('tabby', TABBY_SECRET, ['id' => 'pay_no']));

    $order->refresh();

    expect($order->paid_at)->toBeNull()
        ->and($order->status)->toBe('failed');
});

it('never lets a late failure notice cancel an order that is already paid', function () {
    tabbyProvider();
    $order = payableOrder(25000);

    Http::fake([
        '*/payments/pay_race' => Http::sequence()
            ->push([
                'id' => 'pay_race', 'status' => 'AUTHORIZED', 'amount' => '250.00',
                'currency' => 'AED', 'order' => ['reference_id' => $order->order_number],
            ])
            ->push([
                'id' => 'pay_race', 'status' => 'EXPIRED', 'amount' => '250.00',
                'currency' => 'AED', 'order' => ['reference_id' => $order->order_number],
            ]),
    ]);

    $gateway = app(GatewayRegistry::class)->find('tabby');

    $gateway->handleWebhook(webhookRequest('tabby', TABBY_SECRET, ['id' => 'pay_race']));
    $gateway->handleWebhook(webhookRequest('tabby', TABBY_SECRET, ['id' => 'pay_race']));

    $order->refresh();

    expect($order->paid_at)->not->toBeNull()
        ->and($order->status)->toBe('processing');
});

it('asks tabby to retry when their api cannot be reached', function () {
    tabbyProvider();
    $order = payableOrder(25000);

    Http::fake(['*' => Http::response('gateway timeout', 504)]);

    $outcome = app(GatewayRegistry::class)->find('tabby')
        ->handleWebhook(webhookRequest('tabby', TABBY_SECRET, ['id' => 'pay_unknown']));

    // 503, not 200 -- guessing would be worse than being asked again.
    expect($outcome->status)->toBe(503);
    expect($order->fresh()->paid_at)->toBeNull();
});

/* --------------------------------------------------------- the controller ---- */

it('has no webhook endpoint for cash on delivery', function () {
    PaymentProvider::create(['id' => 'cod', 'title' => 'COD', 'enabled' => true, 'mode' => 'test', 'position' => 0]);

    $response = app(\App\Http\Controllers\Payments\WebhookController::class)
        ->handle(webhookRequest('cod', TABBY_SECRET, []), 'cod');

    // A gateway with no verification must not have a reachable endpoint.
    expect($response->getStatusCode())->toBe(404);
});

it('tells an unverified caller nothing beyond "rejected"', function () {
    tabbyProvider();
    Http::fake();

    $response = app(\App\Http\Controllers\Payments\WebhookController::class)
        ->handle(webhookRequest('tabby', 'wrong-secret-value-here', ['id' => 'x']), 'tabby');

    expect($response->getStatusCode())->toBe(401)
        ->and($response->getData(true))->toBe(['result' => 'rejected']);
});
