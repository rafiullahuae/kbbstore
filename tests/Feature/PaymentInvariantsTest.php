<?php

/**
 * The money invariants, as properties rather than as happy paths.
 *
 * Phase 11 shipped four gateway classes, a confirmer, a capturer and a
 * refunder, each with its own tests. What was missing was the layer above
 * those: the handful of statements that must be true of EVERY gateway, written
 * once, asserted against each of them, and phrased as "this cannot happen"
 * rather than "this works".
 *
 * Most of what follows already held before this file existed, and each such
 * case says so. That is the point — a regression net is worth most on the
 * things that are already right, because those are what a later refactor
 * quietly breaks. Three of them did NOT hold, and are marked WAS BROKEN with
 * what went wrong.
 *
 * No test here makes a network call. Http::fake() is set up in beforeEach and
 * Http::assertNothingSent() is the assertion wherever "nothing happened" is
 * the property under test — an outbound request on an unverified callback
 * would itself be the defect.
 */

use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\PaymentProvider;
use App\Models\Refund;
use App\Services\Payments\GatewayCredentials;
use App\Services\Payments\GatewayRegistry;
use App\Services\Payments\PaymentCapturer;
use App\Services\Payments\PaymentConfirmer;
use App\Services\Payments\PaymentRefunder;
use App\Services\Payments\Signature;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

const INV_TABBY_SECRET = 'whsec-inv-tabby-0123456789abcd';
const INV_STRIPE_URL = 'whsec-inv-stripe-0123456789abc';
const INV_STRIPE_SIGN = 'whsec_inv_stripe_signing_value';
const INV_TAMARA_SECRET = 'whsec-inv-tamara-0123456789ab';
const INV_TAMARA_TOKEN = 'inv-tamara-notification-token';

beforeEach(function () {
    PaymentProvider::query()->delete();
    app(GatewayCredentials::class)->forget();

    /*
     * No live call may leave this suite, and nothing here may accidentally
     * pass because a stray request quietly succeeded.
     *
     * preventStrayRequests() rather than a bare Http::fake(): a catch-all fake
     * registered here would WIN over the per-gateway stubs invDeliver() sets
     * up, because Laravel matches stub callbacks in registration order and
     * answers with the first one that matches. Every Tabby and Tamara
     * delivery would then be answered with an empty 200, the gateway would
     * correctly refuse to verify it, and a file of tests about refusing things
     * would have passed for the wrong reason.
     */
    Http::preventStrayRequests();
});

/* ------------------------------------------------------------------ fixtures */

/** Every remote gateway configured, so a sweep can address all three. */
function invConfigureAll(): void
{
    $rows = [
        'tabby' => [
            'public_key' => 'pk_test_inv',
            'secret_key' => 'sk_test_inv',
            'merchant_code' => 'AE',
            'webhook_secret' => INV_TABBY_SECRET,
        ],
        'tamara' => [
            'api_token' => 'tamara-api-token-inv',
            'notification_token' => INV_TAMARA_TOKEN,
            'webhook_secret' => INV_TAMARA_SECRET,
        ],
        'stripe' => [
            'publishable_key' => 'pk_test_inv',
            'secret_key' => 'sk_test_inv',
            'webhook_signing_secret' => INV_STRIPE_SIGN,
            'webhook_secret' => INV_STRIPE_URL,
        ],
    ];

    $position = 1;

    foreach ($rows as $id => $config) {
        $row = PaymentProvider::create([
            'id' => $id,
            'enabled' => true,
            'mode' => 'test',
            'position' => $position++,
        ]);

        $row->config = $config;
        $row->save();
    }

    app(GatewayCredentials::class)->forget();
}

function invariantOrder(array $overrides = []): Order
{
    return Order::create(array_merge([
        'order_number' => 'INV-' . uniqid(),
        'email' => 'buyer@example.com',
        'status' => 'pending',
        'currency' => 'AED',
        'subtotal' => 30000,
        'total' => 30000,
    ], $overrides));
}

/** A POST at the real webhook route, with the secret bound as the router binds it. */
function invRequest(string $gateway, string $secret, string $raw, array $server = []): Request
{
    $request = Request::create(
        "/api/payments/webhook/{$gateway}/{$secret}",
        'POST', [], [], [], $server, $raw,
    );

    $request->headers->set('Content-Type', 'application/json');

    $route = new \Illuminate\Routing\Route(['POST'], '/api/payments/webhook/{gateway}/{secret}', fn () => null);
    $route->bind($request);
    $request->setRouteResolver(fn () => $route);

    return $request;
}

/**
 * One genuinely-verified delivery per gateway, for an order and an amount.
 *
 * Each returns the WebhookOutcome. The three schemes are completely different
 * — Tabby re-fetches over its own API, Stripe signs the raw body, Tamara sends
 * an HS256 JWT — so the shapes below are not interchangeable and the point of
 * collecting them here is that the PROPERTIES asserted against them are.
 */
function invDeliver(string $gateway, Order $order, int $amountFils, string $currency = 'AED'): \App\Services\Payments\WebhookOutcome
{
    $registry = app(GatewayRegistry::class);

    if ($gateway === 'tabby') {
        Http::fake(['api.tabby.ai/*' => Http::response([
            'id' => 'pay_inv',
            'status' => 'AUTHORIZED',
            'amount' => number_format($amountFils / 100, 2, '.', ''),
            'currency' => $currency,
            'order' => ['reference_id' => $order->order_number],
        ])]);

        return $registry->find('tabby')->handleWebhook(
            invRequest('tabby', INV_TABBY_SECRET, json_encode(['id' => 'pay_inv'])),
        );
    }

    if ($gateway === 'stripe') {
        $raw = json_encode([
            'id' => 'evt_inv',
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_inv',
                'payment_intent' => 'pi_inv',
                'client_reference_id' => $order->order_number,
                'payment_status' => 'paid',
                'amount_total' => $amountFils,
                'currency' => strtolower($currency),
            ]],
        ]);

        $ts = time();
        $mac = hash_hmac('sha256', $ts . '.' . $raw, INV_STRIPE_SIGN);

        return $registry->find('stripe')->handleWebhook(
            invRequest('stripe', INV_STRIPE_URL, $raw, ['HTTP_STRIPE_SIGNATURE' => "t={$ts},v1={$mac}"]),
        );
    }

    // Two calls, in this order: the fetch that decides the amount, then the
    // authorise that makes Tamara actually hold it. Both have to be faked or
    // the gateway refuses on the second, which is the correct behaviour and
    // would make this helper test the wrong thing.
    Http::fake([
        'api-sandbox.tamara.co/merchants/orders/*' => Http::response([
            'order_id' => 'tam_inv',
            'order_reference_id' => $order->order_number,
            'total_amount' => ['amount' => number_format($amountFils / 100, 2, '.', ''), 'currency' => $currency],
        ]),
        'api-sandbox.tamara.co/orders/*' => Http::response(['order_id' => 'tam_inv', 'status' => 'authorised']),
    ]);

    $body = [
        'order_id' => 'tam_inv',
        'order_reference_id' => $order->order_number,
        'order_status' => 'approved',
    ];

    return $registry->find('tamara')->handleWebhook(
        invRequest('tamara', INV_TAMARA_SECRET, json_encode($body), [
            'HTTP_AUTHORIZATION' => 'Bearer ' . invTamaraJwt($body),
        ]),
    );
}

function invTamaraJwt(array $claims, string $key = INV_TAMARA_TOKEN): string
{
    $b64 = fn (array $part) => rtrim(strtr(base64_encode(json_encode($part)), '+/', '-_'), '=');

    $head = $b64(['alg' => 'HS256', 'typ' => 'JWT']);
    $body = $b64($claims + ['exp' => time() + 600]);
    $sig = rtrim(strtr(base64_encode(hash_hmac('sha256', $head . '.' . $body, $key, true)), '+/', '-_'), '=');

    return "{$head}.{$body}.{$sig}";
}

/** Everything a callback could possibly have written, as one comparable snapshot. */
function invFootprint(Order $order): array
{
    return [
        'status' => (string) $order->fresh()->status,
        'paid_at' => (string) $order->fresh()->paid_at,
        'transaction_id' => (string) $order->fresh()->transaction_id,
        'payments' => Payment::query()->where('order_id', $order->getKey())->count(),
        'events' => PaymentEvent::query()->count(),
        'notes' => $order->notes()->count(),
    ];
}

/*
|------------------------------------------------------------------------------
| INVARIANT 1 — no order is marked paid without a VERIFIED provider callback,
| and an unverified one changes nothing at all.
|------------------------------------------------------------------------------
|
| ALREADY HELD. Each gateway verifies before it parses, and the webhook
| controller will not route to a gateway that implements no verification at
| all. Pinned here across all three at once, and asserting the absence of a
| whole footprint rather than only of `paid_at`: a rejected callback that still
| wrote an audit row would be an unauthenticated stranger writing to our
| database.
*/

it('marks no order paid on an unverified callback, and writes nothing at all', function (string $gateway, string $secret) {
    // Recording on, so "nothing was sent" is an assertion and not an absence
    // of instrumentation. Nothing below is supposed to match it.
    Http::fake();
    invConfigureAll();

    $order = invariantOrder();
    $before = invFootprint($order);

    // A perfectly well-formed body. The ONLY thing wrong with it is that it is
    // not signed -- the URL secret is wrong by its last character.
    $forged = json_encode([
        'id' => 'pay_forged',
        'order_id' => 'tam_forged',
        'order_reference_id' => $order->order_number,
        'event_type' => 'order_approved',
    ]);

    $outcome = app(GatewayRegistry::class)->find($gateway)
        ->handleWebhook(invRequest($gateway, substr($secret, 0, -1) . 'X', $forged));

    expect($outcome->accepted)->toBeFalse()
        ->and($outcome->status)->toBe(401)
        ->and(invFootprint($order))->toBe($before);

    // And it never reached the provider's API either, which matters for Tabby
    // and Tamara: both re-fetch, and a re-fetch on an unverified call is an
    // unauthenticated stranger making us spend our own credentials.
    Http::assertNothingSent();
})->with([
    'tabby' => ['tabby', INV_TABBY_SECRET],
    'tamara' => ['tamara', INV_TAMARA_SECRET],
    'stripe' => ['stripe', INV_STRIPE_URL],
]);

it('verifies with a constant-time compare that an empty secret can never satisfy', function () {
    // The bare mechanics, because every gateway above is built on them. An
    // unset secret must not verify an unset signature into a match -- that is
    // how a half-configured gateway ends up accepting everything.
    expect(Signature::equals('', ''))->toBeFalse()
        ->and(Signature::equals(null, null))->toBeFalse()
        ->and(Signature::equals('abc', ''))->toBeFalse()
        ->and(Signature::equals('', 'abc'))->toBeFalse()
        ->and(Signature::hmacMatches('sha256', 'body', '', hash_hmac('sha256', 'body', '')))->toBeFalse()
        ->and(Signature::verifyJwtHs256(invTamaraJwt(['a' => 1], ''), ''))->toBeNull();

    // A real match still matches, so the guard above is not simply "no".
    expect(Signature::equals('abc', 'abc'))->toBeTrue()
        ->and(Signature::hmacMatches('sha256', 'body', 'key', hash_hmac('sha256', 'body', 'key')))->toBeTrue();
});

it('has no unverifiable door: every gateway the webhook route reaches implements verification', function () {
    $registry = app(GatewayRegistry::class);

    foreach ($registry->all() as $gateway) {
        $handles = $gateway instanceof \App\Services\Payments\HandlesWebhooks;

        // cod is the one that must NOT, and the controller 404s it. Everything
        // else must, or it is an endpoint nobody is checking.
        expect($handles)->toBe($gateway->id() !== 'cod', $gateway->id());
    }
});

/*
|------------------------------------------------------------------------------
| INVARIANT 2 — a replayed callback cannot charge, capture or refund twice.
|------------------------------------------------------------------------------
|
| ALREADY HELD, by three different mechanisms, and the mechanism matters as
| much as the outcome. Confirmation claims `orders.paid_at` against a row read
| under SELECT ... FOR UPDATE inside the transaction that writes it; capture
| claims `orders.captured_at` with one conditional UPDATE; refunds are claimed
| by a UNIQUE index on `refunds.idempotency_key`. Two of those three are
| database constraints rather than checks, and the third is a lock, so none of
| them can be raced between two PHP processes.
|
| The last test in this block goes under the application entirely and asserts
| the constraints exist on whatever engine is running, because a unique index
| that quietly failed to migrate is invisible until the day it is needed.
*/

it('applies the same verified callback only once, on every gateway', function (string $gateway) {
    invConfigureAll();

    $order = invariantOrder();

    $first = invDeliver($gateway, $order, 30000);
    $after = invFootprint($order);

    $second = invDeliver($gateway, $order, 30000);

    expect($first->accepted)->toBeTrue()
        ->and($first->status)->toBe(200)
        // 200, not an error: a replay is normal and the provider must stop.
        ->and($second->accepted)->toBeTrue()
        ->and($second->status)->toBe(200)
        // Nothing moved the second time. Not the status, not `paid_at`, not
        // the payment row, and not the note -- two "payment confirmed" notes
        // on one order read as two payments.
        ->and(invFootprint($order))->toBe($after)
        ->and($order->fresh()->paid_at)->not->toBeNull();
})->with(['tabby', 'stripe', 'tamara']);

it('captures once however many times the button is pressed', function () {
    PaymentProvider::create(['id' => 'cod', 'enabled' => true, 'mode' => 'test', 'position' => 0]);

    $order = invariantOrder(['status' => 'processing', 'payment_method' => 'cod']);

    $first = app(PaymentCapturer::class)->capture($order, 'Admin');
    $second = app(PaymentCapturer::class)->capture($order, 'Admin');
    $third = app(PaymentCapturer::class)->capture($order, 'Admin');

    expect($first->code)->toBe('captured')
        ->and($second->code)->toBe('already_captured')
        ->and($third->code)->toBe('already_captured')
        // One capture's worth of money, not three.
        ->and((int) $order->fresh()->captured_total)->toBe(30000)
        ->and(PaymentEvent::query()->where('type', 'capture')->count())->toBe(1);
});

it('makes one refund out of a replayed refund call', function () {
    PaymentProvider::create(['id' => 'cod', 'enabled' => true, 'mode' => 'test', 'position' => 0]);

    $order = invariantOrder(['status' => 'processing', 'payment_method' => 'cod']);
    app(PaymentCapturer::class)->capture($order, 'Admin');

    $key = 'idem-' . uniqid();

    $first = app(PaymentRefunder::class)->refund($order->fresh(), 10000, 'damaged', 'Admin', $key);
    $second = app(PaymentRefunder::class)->refund($order->fresh(), 10000, 'damaged', 'Admin', $key);

    expect(Refund::query()->where('order_id', $order->getKey())->count())->toBe(1)
        ->and($second->code)->toBe('duplicate')
        ->and((int) app(PaymentRefunder::class)->refundedFils($order->fresh()))->toBe(10000)
        ->and($first->refund?->getKey())->toBe($second->refund?->getKey());
});

it('enforces its idempotency in the database, not only in PHP', function () {
    $order = invariantOrder();

    // One `payments` row per (provider, provider_ref). Inserted underneath the
    // application, so what is being tested is the INDEX and not the model.
    DB::table('payments')->insert([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'order_id' => $order->getKey(),
        'provider' => 'tabby', 'provider_ref' => 'dup_ref',
        'amount' => 30000, 'currency' => 'AED', 'status' => 'paid',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(fn () => DB::table('payments')->insert([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'order_id' => $order->getKey(),
        'provider' => 'tabby', 'provider_ref' => 'dup_ref',
        'amount' => 30000, 'currency' => 'AED', 'status' => 'paid',
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    // And one refund per idempotency key.
    DB::table('refunds')->insert([
        'order_id' => $order->getKey(), 'amount' => 100, 'status' => 'pending',
        'idempotency_key' => 'dup_key', 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(fn () => DB::table('refunds')->insert([
        'order_id' => $order->getKey(), 'amount' => 100, 'status' => 'pending',
        'idempotency_key' => 'dup_key', 'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    // NULL keys do not collide, which is what lets a failed refund release its
    // key and still leave the row behind as the audit record. True on SQLite
    // and on MySQL alike, and the suite runs on both.
    DB::table('refunds')->insert([
        'order_id' => $order->getKey(), 'amount' => 100, 'status' => 'failed',
        'idempotency_key' => null, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('refunds')->insert([
        'order_id' => $order->getKey(), 'amount' => 100, 'status' => 'failed',
        'idempotency_key' => null, 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(Refund::query()->where('order_id', $order->getKey())->count())->toBe(3);
});

/*
|------------------------------------------------------------------------------
| INVARIANT 3 — the amount the provider confirms equals the amount the order
| records, to the fil, and a mismatch refuses rather than reconciling itself.
|------------------------------------------------------------------------------
|
| ALREADY HELD, in PaymentConfirmer, for all three. Pinned at one fil in both
| directions, which is the case a rounding bug produces: the provider APIs take
| major units as decimal strings and the conversion back is where a float would
| cost exactly this much.
*/

it('refuses a confirmation that is out by a single fil, in either direction', function (string $gateway, int $amountFils) {
    invConfigureAll();

    $order = invariantOrder(['total' => 30000]);

    $outcome = invDeliver($gateway, $order, $amountFils);

    expect($outcome->accepted)->toBeFalse()
        ->and($outcome->status)->toBe(422)
        ->and($outcome->message)->toContain('amount')
        // Refused, not reconciled: the order's own figure is untouched and it
        // is not paid.
        ->and((int) $order->fresh()->total)->toBe(30000)
        ->and($order->fresh()->paid_at)->toBeNull();
})->with([
    'tabby one fil short' => ['tabby', 29999],
    'tabby one fil over' => ['tabby', 30001],
    'stripe one fil short' => ['stripe', 29999],
    'stripe one fil over' => ['stripe', 30001],
    'tamara one fil short' => ['tamara', 29999],
    'tamara one fil over' => ['tamara', 30001],
]);

it('records the mismatch instead of discarding it, so the merchant can find the money', function () {
    invConfigureAll();

    $order = invariantOrder(['total' => 30000]);
    invDeliver('stripe', $order, 29999);

    $payment = Payment::query()->where('order_id', $order->getKey())->first();

    expect($payment?->status)->toBe('amount_mismatch')
        // What the PROVIDER said, kept as evidence beside the order's own
        // figure rather than overwriting it.
        ->and((int) $payment?->amount)->toBe(29999)
        ->and((int) $order->fresh()->total)->toBe(30000);
});

/*
|------------------------------------------------------------------------------
| INVARIANT 4 — a refund can never exceed what was captured, in total across
| partial refunds.
|------------------------------------------------------------------------------
|
| ALREADY HELD, in PaymentRefunder, and the ceiling is recomputed from the
| database inside the locked transaction every time. Pinned here as a sweep
| over a sequence of partials rather than as one over-refund, because the way
| this breaks in practice is the fifth small refund rather than one large one.
*/

it('never lets a run of partial refunds add up past what was captured', function () {
    PaymentProvider::create(['id' => 'cod', 'enabled' => true, 'mode' => 'test', 'position' => 0]);

    $order = invariantOrder(['status' => 'processing', 'payment_method' => 'cod']);
    app(PaymentCapturer::class)->capture($order, 'Admin');

    $accepted = 0;

    // Eight attempts at 5000 against a 30000 capture. Six fit, two must not,
    // and each carries its own key so nothing is collapsed as a double click.
    foreach (range(1, 8) as $n) {
        $outcome = app(PaymentRefunder::class)
            ->refund($order->fresh(), 5000, 'partial ' . $n, 'Admin', 'run-' . $n . '-' . uniqid());

        if ($outcome->ok) {
            $accepted++;
        }
    }

    expect($accepted)->toBe(6)
        ->and((int) app(PaymentRefunder::class)->refundedFils($order->fresh()))->toBe(30000)
        // Never above the ceiling, whatever the sequence.
        ->and((int) app(PaymentRefunder::class)->refundedFils($order->fresh()))
        ->toBeLessThanOrEqual((int) app(PaymentRefunder::class)->capturedFils($order->fresh()));
});

it('refuses to refund an order that never had anything captured', function () {
    PaymentProvider::create(['id' => 'cod', 'enabled' => true, 'mode' => 'test', 'position' => 0]);

    $order = invariantOrder(['status' => 'processing', 'payment_method' => 'cod']);

    $outcome = app(PaymentRefunder::class)->refund($order, 1, null, 'Admin', 'never-' . uniqid());

    expect($outcome->ok)->toBeFalse()
        ->and($outcome->code)->toBe('nothing_captured')
        ->and(Refund::query()->where('order_id', $order->getKey())->count())->toBe(0);
});

/*
|------------------------------------------------------------------------------
| INVARIANT 5 — the currency is checked, not assumed.
|------------------------------------------------------------------------------
|
| ALREADY HELD, and checked BEFORE the amount, which is the right order: 300.00
| SAR against a 300.00 AED order matches on the number and is not the same
| money, so an amount check alone would pass it.
*/

it('refuses a confirmation in a currency that is not the order currency', function (string $gateway) {
    invConfigureAll();

    $order = invariantOrder(['total' => 30000, 'currency' => 'AED']);

    // The same NUMBER, a different currency. This is the case an amount-only
    // check waves through.
    $outcome = invDeliver($gateway, $order, 30000, 'SAR');

    expect($outcome->accepted)->toBeFalse()
        ->and($outcome->status)->toBe(422)
        ->and($outcome->message)->toContain('currency')
        ->and($order->fresh()->paid_at)->toBeNull();
})->with(['tabby', 'stripe', 'tamara']);

/*
|------------------------------------------------------------------------------
| INVARIANT 6 — a gateway that is not configured is invisible at checkout,
| rather than failing when it is chosen.
|------------------------------------------------------------------------------
|
| ALREADY HELD. GatewayRegistry::availableFor() asks configured() before it
| offers anything, and configured() is contractually forbidden to throw or to
| touch the network. Pinned with the row ENABLED and the credentials absent,
| which is the state a half-finished setup actually leaves behind -- the
| merchant switches the gateway on, means to paste the keys, and is
| interrupted.
*/

it('offers no gateway whose credentials are missing, however enabled the row is', function (string $gateway) {
    Http::fake();

    // Enabled, positioned, titled -- and with an empty config.
    PaymentProvider::create(['id' => $gateway, 'enabled' => true, 'mode' => 'test', 'position' => 1]);
    app(GatewayCredentials::class)->forget();

    $registry = app(GatewayRegistry::class);
    $offered = collect($registry->checkoutList(30000, 'AE'))->pluck('id');

    expect($offered)->not->toContain($gateway)
        // And asking it directly does not throw. A checkout page that fatals
        // because one gateway is half set up takes the whole shop down.
        ->and($registry->find($gateway)->configured())->toBeFalse();

    // Chosen anyway -- by a stale page, or by hand -- it refuses in words
    // rather than starting a payment or raising.
    $start = $registry->find($gateway)->start(invariantOrder());

    expect($start->ok())->toBeFalse()
        ->and($start->message)->toBeString()->not->toBe('');

    Http::assertNothingSent();
})->with(['tabby', 'tamara', 'stripe']);

/*
|------------------------------------------------------------------------------
| INVARIANT 7 — a callback never resurrects an order whose sale is off.
|------------------------------------------------------------------------------
|
| WAS BROKEN. Until this lane, a verified callback for a `cancelled`, `failed`
| or `refunded` order moved it to `processing` and set `paid_at`. All three of
| those statuses have already handed the coupon use back, and two of them have
| put the units back on the shelf, where they have since been sold to somebody
| else -- and NOTHING re-took either. The warehouse got a live, paid order for
| stock the shop no longer had.
|
| It is reachable on all three gateways: a shopper abandons the redirect, the
| merchant cancels the order, and the provider retries the delivery. Or, since
| webhook delivery is not ordered, an `expired` notice overtakes the
| `completed` one -- and `expired` is what writes `failed` in the first place.
*/

it('refuses to mark paid an order whose sale is already off', function (string $gateway, string $status) {
    invConfigureAll();

    $order = invariantOrder(['status' => $status]);

    $outcome = invDeliver($gateway, $order, 30000);

    expect($outcome->accepted)->toBeFalse()
        // 422 and not 503: there is nothing a further delivery could change,
        // and a human has to give this money back.
        ->and($outcome->status)->toBe(422)
        ->and($outcome->message)->toContain('no longer live')
        // The order is exactly where it was.
        ->and((string) $order->fresh()->status)->toBe($status)
        ->and($order->fresh()->paid_at)->toBeNull();
})->with(['tabby', 'stripe', 'tamara'])->with(['cancelled', 'failed', 'refunded']);

it('tells the merchant about money that arrived for an order that is gone', function () {
    invConfigureAll();

    $order = invariantOrder(['status' => 'cancelled']);
    invDeliver('stripe', $order, 30000);

    // Refusing quietly would leave this discoverable only by reconciling
    // Stripe's dashboard against this database by hand.
    $note = (string) $order->notes()->first()?->content;

    expect($note)->toContain('ACTION NEEDED')
        ->and($note)->toContain('300.00')
        ->and($note)->toContain('cancelled')
        ->and($note)->toContain('NOT been marked paid');

    // And it is in the ledger, under its own status, with the provider's
    // reference on it.
    $payment = Payment::query()->where('order_id', $order->getKey())->first();

    expect($payment?->status)->toBe('late_confirmation')
        ->and((int) $payment?->amount)->toBe(30000);
});

it('says it once, however many times the provider retries', function () {
    invConfigureAll();

    $order = invariantOrder(['status' => 'cancelled']);

    invDeliver('stripe', $order, 30000);
    invDeliver('stripe', $order, 30000);
    invDeliver('stripe', $order, 30000);

    // Three deliveries, one note. A note per retry would bury the order's own
    // history under the same sentence.
    expect($order->notes()->count())->toBe(1);
});

/*
|------------------------------------------------------------------------------
| INVARIANT 8 — a callback never winds a dispatched order backwards.
|------------------------------------------------------------------------------
|
| WAS BROKEN. PaymentConfirmer was handed the literal 'processing', so a
| perfectly valid but late callback for an order already out with the courier
| reverted it to `processing`. The payment still has to be recorded; the status
| is simply already further along than a payment confirmation should set it.
*/

it('records a late payment against a dispatched order without moving it back', function (string $status) {
    invConfigureAll();

    $order = invariantOrder(['status' => $status]);

    $outcome = invDeliver('stripe', $order, 30000);

    expect($outcome->accepted)->toBeTrue()
        // The payment IS recorded -- this is real money and a real callback.
        ->and($order->fresh()->paid_at)->not->toBeNull()
        ->and((string) $order->fresh()->transaction_id)->toBe('pi_inv')
        // The status is not wound back.
        ->and((string) $order->fresh()->status)->toBe($status);
})->with(['shipped', 'completed']);

/*
|------------------------------------------------------------------------------
| INVARIANT 9 — money is never taken for an order whose sale is off.
|------------------------------------------------------------------------------
|
| WAS BROKEN, and this is the confirmer's defect wearing the other hat. The
| Capture button worked perfectly on a cancelled order. On Tabby or Tamara the
| authorisation stays capturable for weeks after a cancellation, so that is the
| customer being charged for an order the shop cancelled and restocked. On cash
| on delivery it is quieter and no better: capture writes `captured_total`, and
| `captured_total` is the ceiling a refund is measured against, so a cancelled
| order that never saw a fil became refundable for its full value.
*/

it('refuses to capture an order whose sale is already off', function (string $status) {
    PaymentProvider::create(['id' => 'cod', 'enabled' => true, 'mode' => 'test', 'position' => 0]);

    $order = invariantOrder(['status' => $status, 'payment_method' => 'cod']);

    $result = app(PaymentCapturer::class)->capture($order, 'Admin');

    expect($result->ok)->toBeFalse()
        ->and($result->code)->toBe('order_not_live')
        ->and((int) $order->fresh()->captured_total)->toBe(0)
        ->and($order->fresh()->captured_at)->toBeNull();

    // And nothing became refundable on the strength of it.
    expect((int) app(PaymentRefunder::class)->capturedFils($order->fresh()))->toBe(0);
})->with(['cancelled', 'failed', 'refunded']);

it('does not offer the capture button on an order it would refuse to capture', function () {
    PaymentProvider::create(['id' => 'cod', 'enabled' => true, 'mode' => 'test', 'position' => 0]);

    $live = invariantOrder(['status' => 'processing', 'payment_method' => 'cod']);
    $off = invariantOrder(['status' => 'cancelled', 'payment_method' => 'cod']);

    // A button that is offered and then refused reads as a bug in the screen
    // rather than as the rule it is.
    expect(app(PaymentCapturer::class)->status($live)['capturable'])->toBeTrue()
        ->and(app(PaymentCapturer::class)->status($off)['capturable'])->toBeFalse();
});
