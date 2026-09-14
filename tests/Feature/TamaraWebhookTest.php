<?php

/**
 * Phase 11 — Tamara webhook verification.
 *
 * Tamara signs its notifications with an HS256 JWT, so on top of the checks
 * every gateway here shares (URL secret, amount, replay) this file attacks the
 * signature itself: the "alg":"none" forgery, a token signed with the wrong
 * key, and a token that is valid but points at somebody else's order.
 */

use App\Models\Order;
use App\Models\PaymentProvider;
use App\Services\Payments\GatewayRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

const TAMARA_URL_SECRET = 'whsec-tamara-abcdef0123456789';
const TAMARA_NOTIFY_KEY = 'tamara-notification-token-value';

beforeEach(function () {
    PaymentProvider::query()->delete();
    app(\App\Services\Payments\GatewayCredentials::class)->forget();
});

function tamaraProvider(array $config = []): void
{
    $row = PaymentProvider::create([
        'id' => 'tamara',
        'title' => 'Tamara',
        'enabled' => true,
        'mode' => 'test',
        'position' => 2,
    ]);

    $row->config = array_merge([
        'api_token' => 'tamara-api-token',
        'notification_token' => TAMARA_NOTIFY_KEY,
        'webhook_secret' => TAMARA_URL_SECRET,
    ], $config);

    $row->save();

    app(\App\Services\Payments\GatewayCredentials::class)->forget();
}

function b64u(string $raw): string
{
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

/** A real HS256 JWT. */
function jwt(array $claims, string $key, string $alg = 'HS256'): string
{
    $head = b64u(json_encode(['typ' => 'JWT', 'alg' => $alg]));
    $body = b64u(json_encode($claims));

    $sig = $alg === 'none'
        ? ''
        : b64u(hash_hmac('sha256', $head . '.' . $body, $key, true));

    return $head . '.' . $body . '.' . $sig;
}

function tamaraOrder(int $totalFils = 30000): Order
{
    return Order::create([
        'order_number' => 'TAM-' . uniqid(),
        'email' => 'buyer@example.com',
        'status' => 'pending',
        'currency' => 'AED',
        'subtotal' => $totalFils,
        'total' => $totalFils,
    ]);
}

function tamaraRequest(string $urlSecret, ?string $token, array $body): Request
{
    $server = [];

    if ($token !== null) {
        $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
    }

    $request = Request::create(
        "/api/payments/webhook/tamara/{$urlSecret}",
        'POST', [], [], [], $server, json_encode($body),
    );

    $request->headers->set('Content-Type', 'application/json');

    $route = new \Illuminate\Routing\Route(['POST'], '/api/payments/webhook/{gateway}/{secret}', fn () => null);
    $route->bind($request);
    $request->setRouteResolver(fn () => $route);

    return $request;
}

/* ------------------------------------------------------- signature forgery */

it('rejects a tamara webhook with no token', function () {
    tamaraProvider();
    Http::fake();

    $outcome = app(GatewayRegistry::class)->find('tamara')->handleWebhook(
        tamaraRequest(TAMARA_URL_SECRET, null, ['order_reference_id' => 'x', 'order_status' => 'approved'])
    );

    expect($outcome->status)->toBe(401);
    Http::assertNothingSent();
});

it('rejects a tamara token signed with the wrong key', function () {
    tamaraProvider();
    Http::fake();

    $token = jwt(['sub' => 'notification'], 'not-the-notification-token');

    $outcome = app(GatewayRegistry::class)->find('tamara')->handleWebhook(
        tamaraRequest(TAMARA_URL_SECRET, $token, ['order_reference_id' => 'x', 'order_status' => 'approved'])
    );

    expect($outcome->status)->toBe(401);
    Http::assertNothingSent();
});

it('rejects the alg-none jwt forgery', function () {
    tamaraProvider();
    Http::fake();

    // The classic: strip the signature and declare the token unsigned. A
    // verifier that reads alg out of the header accepts this.
    $token = jwt(['sub' => 'notification'], '', 'none');

    $outcome = app(GatewayRegistry::class)->find('tamara')->handleWebhook(
        tamaraRequest(TAMARA_URL_SECRET, $token, ['order_reference_id' => 'x', 'order_status' => 'approved'])
    );

    expect($outcome->status)->toBe(401);
    Http::assertNothingSent();
});

it('rejects an expired tamara token', function () {
    tamaraProvider();
    Http::fake();

    $token = jwt(['exp' => time() - 3600], TAMARA_NOTIFY_KEY);

    $outcome = app(GatewayRegistry::class)->find('tamara')->handleWebhook(
        tamaraRequest(TAMARA_URL_SECRET, $token, ['order_reference_id' => 'x', 'order_status' => 'approved'])
    );

    expect($outcome->status)->toBe(401);
});

it('rejects a validly signed tamara token sent to the wrong url secret', function () {
    tamaraProvider();
    Http::fake();

    $token = jwt(['sub' => 'notification'], TAMARA_NOTIFY_KEY);

    $outcome = app(GatewayRegistry::class)->find('tamara')->handleWebhook(
        tamaraRequest('wrong-url-secret-here-1234', $token, ['order_reference_id' => 'x', 'order_status' => 'approved'])
    );

    expect($outcome->status)->toBe(401);
});

/* ------------------------------------------- a signed body is still not a total */

it('refuses a tamara order whose verified amount disagrees with ours', function () {
    tamaraProvider();
    $order = tamaraOrder(30000);          // د.إ300.00

    Http::fake([
        '*/merchants/orders/*' => Http::response([
            'order_id' => 'tam_1',
            'order_reference_id' => $order->order_number,
            'total_amount' => ['amount' => 3.00, 'currency' => 'AED'],
        ]),
        '*/authorise' => Http::response(['status' => 'authorised']),
    ]);

    $token = jwt(['sub' => 'notification'], TAMARA_NOTIFY_KEY);

    $outcome = app(GatewayRegistry::class)->find('tamara')->handleWebhook(
        tamaraRequest(TAMARA_URL_SECRET, $token, [
            'order_id' => 'tam_1',
            'order_reference_id' => $order->order_number,
            'order_status' => 'approved',
            // A perfectly signed lie. Ignored -- the figure comes from the GET.
            'total_amount' => ['amount' => 300.00, 'currency' => 'AED'],
        ])
    );

    expect($outcome->status)->toBe(422);
    expect($order->fresh()->paid_at)->toBeNull();
});

it('refuses when the fetched order points at a different reference', function () {
    tamaraProvider();
    $order = tamaraOrder(30000);

    Http::fake([
        '*/merchants/orders/*' => Http::response([
            'order_id' => 'tam_other',
            'order_reference_id' => 'SOMEBODY-ELSES-ORDER',
            'total_amount' => ['amount' => 300.00, 'currency' => 'AED'],
        ]),
    ]);

    $token = jwt(['sub' => 'notification'], TAMARA_NOTIFY_KEY);

    $outcome = app(GatewayRegistry::class)->find('tamara')->handleWebhook(
        tamaraRequest(TAMARA_URL_SECRET, $token, [
            'order_id' => 'tam_other',
            'order_reference_id' => $order->order_number,
            'order_status' => 'approved',
        ])
    );

    expect($outcome->status)->toBe(422);
    expect($order->fresh()->paid_at)->toBeNull();
});

/* -------------------------------------------------------- the happy path ---- */

it('marks an order paid on a valid tamara notification, once', function () {
    tamaraProvider();
    $order = tamaraOrder(30000);

    Http::fake([
        '*/merchants/orders/*' => Http::response([
            'order_id' => 'tam_ok',
            'order_reference_id' => $order->order_number,
            'total_amount' => ['amount' => 300.00, 'currency' => 'AED'],
        ]),
        '*/authorise' => Http::response(['status' => 'authorised']),
    ]);

    $token = jwt(['sub' => 'notification'], TAMARA_NOTIFY_KEY);
    $body = fn () => tamaraRequest(TAMARA_URL_SECRET, $token, [
        'order_id' => 'tam_ok',
        'order_reference_id' => $order->order_number,
        'order_status' => 'approved',
    ]);

    $gateway = app(GatewayRegistry::class)->find('tamara');

    $first = $gateway->handleWebhook($body());
    $second = $gateway->handleWebhook($body());

    expect($first->accepted)->toBeTrue()
        ->and($second->message)->toBe('already applied');

    $order->refresh();

    expect($order->paid_at)->not->toBeNull()
        ->and($order->status)->toBe('processing');

    expect(\App\Models\Payment::where('order_id', $order->id)->count())->toBe(1);
});

it('cancels an order on a tamara expiry event', function () {
    tamaraProvider();
    $order = tamaraOrder(30000);

    Http::fake();

    $token = jwt(['sub' => 'notification'], TAMARA_NOTIFY_KEY);

    app(GatewayRegistry::class)->find('tamara')->handleWebhook(
        tamaraRequest(TAMARA_URL_SECRET, $token, [
            'order_id' => 'tam_exp',
            'order_reference_id' => $order->order_number,
            'event_type' => 'order_expired',
        ])
    );

    $order->refresh();

    expect($order->status)->toBe('failed')
        ->and($order->paid_at)->toBeNull();
});

it('ignores a tamara event it does not act on', function () {
    tamaraProvider();
    $order = tamaraOrder(30000);

    Http::fake();

    $token = jwt(['sub' => 'notification'], TAMARA_NOTIFY_KEY);

    $outcome = app(GatewayRegistry::class)->find('tamara')->handleWebhook(
        tamaraRequest(TAMARA_URL_SECRET, $token, [
            'order_id' => 'tam_new',
            'order_reference_id' => $order->order_number,
            'order_status' => 'new',
        ])
    );

    // A 200 -- it is not an error, and a retry would say the same thing.
    expect($outcome->accepted)->toBeTrue()
        ->and($outcome->status)->toBe(200);

    expect($order->fresh()->status)->toBe('pending');
});
