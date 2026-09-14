<?php

/**
 * Capture and refund — the half of payments that moves money the other way.
 *
 * Every case here is a way the merchant loses money or the customer is charged
 * twice, not a feature:
 *
 *   - an authorisation that is never captured is auto-voided by Tabby and
 *     Tamara, so "capture" has to work and has to be reachable;
 *   - a capture that runs twice takes the money twice;
 *   - a refund over the captured amount is the store paying out more than it
 *     took, and the figure it is checked against must come from our database
 *     rather than from the browser that asked;
 *   - two partial refunds that each pass on their own and together do not are
 *     the same bug with an extra step;
 *   - a refund that failed at the gateway and was recorded as done is money
 *     the merchant believes has gone back and has not;
 *   - and every one of these endpoints unauthenticated is a stranger with the
 *     store's card terminal.
 *
 * The routes under test ship UNMOUNTED (CLAUDE.md forbids editing web.php), so
 * each test registers routes/payments-settlement.php into the same group the
 * file's header tells the integrator to mount it in — `auth:admin`,
 * NoStoreAdminApi, prefix `admin-api`. That is deliberate: it tests the real
 * file in the real guard rather than a hand-written copy of it that could stop
 * resembling what actually ships. POST /admin-api/orders/{id}/refund is
 * already mounted in web.php and is hit directly.
 *
 * Http::preventStrayRequests() is on throughout. Any call a gateway makes that
 * a test did not explicitly fake is an error, which is what lets "COD captures
 * without any HTTP call" be a real assertion rather than a hopeful one.
 */

use App\Models\AdminUser;
use App\Models\Order;
use App\Models\PaymentEvent;
use App\Models\PaymentProvider;
use App\Models\Refund;
use App\Services\Payments\GatewayCredentials;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    PaymentProvider::query()->delete();
    Http::preventStrayRequests();

    // Exactly the mounting routes/payments-settlement.php asks the integrator
    // for: the existing admin-api group, guard included.
    // One middleware() call, not two: RouteRegistrar::middleware() REPLACES
    // the attribute rather than appending to it, so a second call here would
    // silently drop `auth:admin` and this file would test an unguarded group.
    // The real mounting nests groups, which merges, so this is a harness
    // detail — but a harness detail that would have made the guard test pass
    // against no guard at all.
    Route::middleware(['web', 'auth:admin', \App\Http\Middleware\NoStoreAdminApi::class])
        ->prefix('admin-api')
        ->group(base_path('routes/payments-settlement.php'));
});

function settlementAdmin(string $tag): AdminUser
{
    return AdminUser::create([
        'name' => 'Settle Admin',
        'email' => 'settle-' . $tag . '-' . uniqid() . '@example.test',
        'password' => 'password-long-enough',
        'role' => 'owner',
    ]);
}

function settlementOrder(array $attributes = []): Order
{
    return Order::create(array_merge([
        'order_number' => 'ST-' . uniqid(),
        'email' => 'buyer@example.com',
        'status' => 'processing',
        'currency' => 'AED',
        'subtotal' => 25000,
        'total' => 25000,          // 250.00 AED in fils
        'payment_method' => 'cod',
    ], $attributes));
}

function tabbyConfigured(): void
{
    $row = PaymentProvider::create([
        'id' => 'tabby', 'title' => 'Tabby', 'enabled' => true, 'mode' => 'test', 'position' => 1,
    ]);

    $row->config = [
        'public_key' => 'pk_test_kbb',
        'secret_key' => 'sk_test_kbb',
        'merchant_code' => 'AE',
        'webhook_secret' => 'whsec-settlement-0123456789',
    ];

    $row->save();

    app(GatewayCredentials::class)->forget();
}

/** A Tabby order that has been authorised and captured, ready to refund. */
function capturedTabbyOrder(): Order
{
    tabbyConfigured();

    return settlementOrder([
        'payment_method' => 'tabby',
        'transaction_id' => 'pay_kbb_1',
        'paid_at' => now(),
        'captured_at' => now(),
        'captured_total' => 25000,
        'capture_ref' => 'cap_kbb_1',
    ]);
}

/* ============================================================== the guard === */

it('rejects an unauthenticated caller on every settlement endpoint', function () {
    $order = settlementOrder();

    // Signed out. Not one of these may do anything.
    $this->postJson('/admin-api/orders/' . $order->id . '/capture')->assertUnauthorized();
    $this->getJson('/admin-api/orders/' . $order->id . '/settlement')->assertUnauthorized();
    $this->postJson('/admin-api/orders/' . $order->id . '/refund', ['amount_aed' => 10])
        ->assertUnauthorized();

    $order->refresh();

    // And nothing was written on the way to being refused, which is the part a
    // status-code-only assertion would miss.
    expect($order->captured_at)->toBeNull()
        ->and(Refund::where('order_id', $order->id)->count())->toBe(0)
        ->and(PaymentEvent::count())->toBe(0);

    Http::assertNothingSent();
});

/* ================================================================ capture === */

it('captures a cash-on-delivery order without making any HTTP call at all', function () {
    $this->actingAs(settlementAdmin('cod'), 'admin');

    // COD is never confirmed — `paid_at` stays null by design, the courier has
    // the cash — so capture must not require it.
    $order = settlementOrder(['payment_method' => 'cod', 'paid_at' => null]);

    $this->postJson('/admin-api/orders/' . $order->id . '/capture')
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('code', 'captured');

    $order->refresh();

    expect($order->captured_at)->not->toBeNull()
        ->and((int) $order->captured_total)->toBe(25000)
        ->and($order->capture_ref)->toBe('cod:' . $order->order_number);

    // The assertion this whole test exists for. preventStrayRequests() would
    // have thrown on any call; this proves none was even attempted.
    Http::assertNothingSent();
});

it('captures once and is idempotent when the button is clicked twice', function () {
    $this->actingAs(settlementAdmin('twice'), 'admin');

    $order = settlementOrder([
        'payment_method' => 'tabby',
        'transaction_id' => 'pay_kbb_1',
        'paid_at' => now(),
    ]);

    tabbyConfigured();

    Http::fake([
        // The live status read, which is the authority on whether Tabby will
        // let us capture. AUTHORIZED first; CLOSED once we have.
        'api.tabby.ai/api/v2/payments/*' => Http::sequence()
            ->push(['id' => 'pay_kbb_1', 'status' => 'AUTHORIZED'], 200)
            ->push(['id' => 'pay_kbb_1', 'status' => 'CLOSED', 'captures' => [['id' => 'cap_kbb_1']]], 200),
        'api.tabby.ai/api/v1/payments/*/captures' => Http::response([
            'id' => 'pay_kbb_1',
            'captures' => [['id' => 'cap_kbb_1', 'amount' => '250.00']],
        ], 200),
    ]);

    $first = $this->postJson('/admin-api/orders/' . $order->id . '/capture')->assertOk();

    expect($first->json('code'))->toBe('captured');

    $order->refresh();
    $capturedAt = $order->captured_at;

    // The second click. It must not reach the capture endpoint at all: the
    // claim on `captured_at` is taken before the provider is called, so the
    // second caller is turned away before any network happens.
    $this->postJson('/admin-api/orders/' . $order->id . '/capture')
        ->assertOk()
        ->assertJsonPath('code', 'already_captured');

    $order->refresh();

    expect((int) $order->captured_total)->toBe(25000)
        ->and($order->capture_ref)->toBe('cap_kbb_1')
        // Not re-stamped, so nothing ran a second time.
        ->and($order->captured_at->toAtomString())->toBe($capturedAt->toAtomString());

    // Exactly one capture POST, and — because the second click never got past
    // the claim — exactly one status read as well.
    $captures = 0;
    $reads = 0;

    Http::recorded(function ($request) use (&$captures, &$reads) {
        if (str_contains($request->url(), '/captures')) {
            $captures++;
        } elseif (str_contains($request->url(), '/api/v2/payments/')) {
            $reads++;
        }
    });

    expect($captures)->toBe(1)->and($reads)->toBe(1);

    // One capture event in the ledger, not two.
    expect(PaymentEvent::where('type', 'capture')->count())->toBe(1);
});

it('leaves the order uncaptured and records the failure when the gateway refuses a capture', function () {
    $this->actingAs(settlementAdmin('capfail'), 'admin');

    $order = settlementOrder([
        'payment_method' => 'tabby',
        'transaction_id' => 'pay_kbb_2',
        'paid_at' => now(),
    ]);

    tabbyConfigured();

    Http::fake([
        'api.tabby.ai/api/v2/payments/*' => Http::response(['id' => 'pay_kbb_2', 'status' => 'AUTHORIZED'], 200),
        'api.tabby.ai/api/v1/payments/*/captures' => Http::response(['errorType' => 'capture_failed'], 400),
    ]);

    $this->postJson('/admin-api/orders/' . $order->id . '/capture')
        ->assertStatus(502)
        ->assertJsonPath('ok', false);

    $order->refresh();

    // The claim was RELEASED. An order left marked captured after a failed
    // call is the exact lie this lane exists to stop telling, and it would
    // also make the capture unretryable.
    expect($order->captured_at)->toBeNull()
        ->and((int) $order->captured_total)->toBe(0);

    $event = PaymentEvent::where('type', 'capture_failed')->first();

    expect($event)->not->toBeNull()
        ->and($event->provider)->toBe('tabby')
        // The gateway's own code, kept — enough to find it in Tabby's
        // dashboard, and nothing that would be a breach if the table leaked.
        ->and($event->payload['error'] ?? null)->toBe('capture_failed');
});

/* ================================================================= refund === */

it('refuses a refund larger than the captured amount, checking against the database', function () {
    $this->actingAs(settlementAdmin('over'), 'admin');

    $order = capturedTabbyOrder();     // captured_total 25000 fils = AED 250

    $this->postJson('/admin-api/orders/' . $order->id . '/refund', [
        // AED 300 against AED 250 taken.
        'amount_aed' => 300,
    ])->assertStatus(422)->assertJsonPath('code', 'over_captured');

    $order->refresh();

    expect(Refund::where('order_id', $order->id)->count())->toBe(0)
        ->and($order->status)->not->toBe('refunded');

    // Refused before the provider was troubled, which is the point of checking
    // our own figures first.
    Http::assertNothingSent();
});

it('refuses an over-refund even when the browser sends its own idea of the amount', function () {
    $this->actingAs(settlementAdmin('tamper'), 'admin');

    $order = capturedTabbyOrder();

    // The screen is JavaScript and JavaScript is editable. The ceiling is read
    // from `captured_total` inside a locked transaction and nothing in this
    // body contributes to it.
    $this->postJson('/admin-api/orders/' . $order->id . '/refund', [
        'amount_fils' => 999999,
        'reason' => 'tampered',
    ])->assertStatus(422)->assertJsonPath('code', 'over_captured');

    expect(Refund::where('order_id', $order->id)->count())->toBe(0);

    Http::assertNothingSent();
});

it('refuses two partial refunds that each fit but together do not', function () {
    $this->actingAs(settlementAdmin('partials'), 'admin');

    $order = capturedTabbyOrder();     // AED 250 captured

    Http::fake([
        'api.tabby.ai/api/v1/payments/*/refunds' => Http::response([
            'id' => 'pay_kbb_1',
            'refunds' => [['id' => 'ref_kbb_1', 'amount' => '150.00']],
        ], 200),
    ]);

    // AED 150 of 250. Fine on its own.
    $this->postJson('/admin-api/orders/' . $order->id . '/refund', [
        'amount_aed' => 150, 'reason' => 'damaged',
    ])->assertOk()->assertJsonPath('ok', true);

    // Another AED 150. Also fine on its own; 300 together is not.
    $this->postJson('/admin-api/orders/' . $order->id . '/refund', [
        'amount_aed' => 150, 'reason' => 'second',
    ])->assertStatus(422)->assertJsonPath('code', 'over_captured');

    $order->refresh();

    expect(Refund::where('order_id', $order->id)->count())->toBe(1)
        ->and((int) Refund::where('order_id', $order->id)->sum('amount'))->toBe(15000)
        // Partly refunded is not refunded: the other AED 100 still has to ship.
        ->and($order->status)->not->toBe('refunded');
});

it('makes one refund out of a double-clicked button', function () {
    $this->actingAs(settlementAdmin('double'), 'admin');

    $order = capturedTabbyOrder();

    Http::fake([
        'api.tabby.ai/api/v1/payments/*/refunds' => Http::response([
            'refunds' => [['id' => 'ref_kbb_1']],
        ], 200),
    ]);

    // The same key twice — one form render, two clicks. This is what the
    // screen really sends: odRefundKey is generated once per render.
    $body = ['amount_aed' => 100, 'reason' => 'oops', 'idempotency_key' => 'ui:one-render'];

    $this->postJson('/admin-api/orders/' . $order->id . '/refund', $body)
        ->assertOk()->assertJsonPath('ok', true);

    $this->postJson('/admin-api/orders/' . $order->id . '/refund', $body)
        ->assertOk()->assertJsonPath('code', 'duplicate');

    expect(Refund::where('order_id', $order->id)->count())->toBe(1);

    // And only one charge-back was ever asked for.
    $calls = 0;
    Http::recorded(function () use (&$calls) {
        $calls++;
    });

    expect($calls)->toBe(1);
});

it('collapses a double click that brings no key of its own', function () {
    $this->actingAs(settlementAdmin('nokey'), 'admin');

    $order = capturedTabbyOrder();

    Http::fake([
        'api.tabby.ai/api/v1/payments/*/refunds' => Http::response(['refunds' => [['id' => 'ref_x']]], 200),
    ]);

    // No idempotency_key at all — an older screen, or a script. The derived
    // key still collides, because nothing settled between the two calls to
    // change what it is derived from.
    $body = ['amount_aed' => 50, 'reason' => 'no key'];

    $this->postJson('/admin-api/orders/' . $order->id . '/refund', $body)->assertOk();
    $this->postJson('/admin-api/orders/' . $order->id . '/refund', $body)
        ->assertOk()->assertJsonPath('code', 'duplicate');

    expect(Refund::where('order_id', $order->id)->count())->toBe(1);
});

it('records a failed refund and does not mark the order refunded', function () {
    $this->actingAs(settlementAdmin('reffail'), 'admin');

    $order = capturedTabbyOrder();

    Http::fake([
        'api.tabby.ai/api/v1/payments/*/refunds' => Http::response(['errorType' => 'refund_declined'], 422),
    ]);

    // The whole captured amount. If this were believed the order would read
    // as fully refunded and the customer would have nothing.
    $this->postJson('/admin-api/orders/' . $order->id . '/refund', [
        'amount_aed' => 250, 'reason' => 'return',
    ])->assertStatus(502)->assertJsonPath('ok', false);

    $order->refresh();

    expect($order->status)->not->toBe('refunded');

    $refund = Refund::where('order_id', $order->id)->first();

    expect($refund)->not->toBeNull()
        // The row survives as the record that an attempt was made...
        ->and($refund->status)->toBe('failed')
        ->and($refund->failure_code)->toBe('refund_declined')
        // ...with its key released, so a real retry is possible.
        ->and($refund->idempotency_key)->toBeNull();

    // A failed row holds no money: the full amount is still refundable.
    $refunder = app(\App\Services\Payments\PaymentRefunder::class);

    expect($refunder->refundedFils($order))->toBe(0);

    $event = PaymentEvent::where('type', 'refund_failed')->first();

    expect($event)->not->toBeNull()
        ->and($event->provider)->toBe('tabby')
        ->and($event->payload['error'] ?? null)->toBe('refund_declined');

    // And the merchant is told in the order's own notes, not only in a table
    // nobody opens.
    expect($order->notes()->get()->pluck('content')->implode(' '))
        ->toContain('FAILED');
});

it('marks an order refunded only once the whole captured amount has gone back', function () {
    $this->actingAs(settlementAdmin('full'), 'admin');

    $order = capturedTabbyOrder();

    Http::fake([
        'api.tabby.ai/api/v1/payments/*/refunds' => Http::response(['refunds' => [['id' => 'ref_full']]], 200),
    ]);

    $this->postJson('/admin-api/orders/' . $order->id . '/refund', ['amount_aed' => 250])
        ->assertOk()->assertJsonPath('ok', true);

    $order->refresh();

    expect($order->status)->toBe('refunded');

    $refund = Refund::where('order_id', $order->id)->first();

    expect($refund->status)->toBe('succeeded')
        ->and($refund->provider_ref)->toBe('ref_full')
        ->and((int) $refund->amount)->toBe(25000);
});

it('refuses to refund an order where nothing was ever captured', function () {
    $this->actingAs(settlementAdmin('nocap'), 'admin');

    // Placed, never paid, never captured.
    $order = settlementOrder(['payment_method' => 'tabby', 'status' => 'pending']);

    $this->postJson('/admin-api/orders/' . $order->id . '/refund', ['amount_aed' => 10])
        ->assertStatus(422)->assertJsonPath('code', 'nothing_captured');

    expect(Refund::where('order_id', $order->id)->count())->toBe(0);

    Http::assertNothingSent();
});

it('keeps the money in integer fils across the screen\'s decimal input', function () {
    $this->actingAs(settlementAdmin('fils'), 'admin');

    $order = capturedTabbyOrder();

    Http::fake([
        'api.tabby.ai/api/v1/payments/*/refunds' => Http::response(['refunds' => [['id' => 'ref_fils']]], 200),
    ]);

    // 10.10 is the classic float trap: (int) (10.10 * 100) is 1009 on a binary
    // float, and a refund a fil short is a reconciliation nobody can close.
    $this->postJson('/admin-api/orders/' . $order->id . '/refund', ['amount_aed' => 10.10])
        ->assertOk();

    expect((int) Refund::where('order_id', $order->id)->value('amount'))->toBe(1010);
});

/* ============================================================ the read API === */

it('reports the capture window and what is still refundable', function () {
    $this->actingAs(settlementAdmin('state'), 'admin');

    $order = capturedTabbyOrder();

    $body = $this->getJson('/admin-api/orders/' . $order->id . '/settlement')
        ->assertOk()
        ->json();

    expect($body['supported'])->toBeTrue()
        ->and($body['captured'])->toBeTrue()
        ->and($body['window_days'])->toBe(30)
        ->and($body['window'])->toContain('auto-voids')
        ->and($body['refundable_fils'])->toBe(25000);

    // A read must never call a provider: this is rendered with every order
    // page and a slow BNPL API must not be why an admin cannot open one.
    Http::assertNothingSent();
});

/* ========================================================== the other three === */

it('captures and refunds a Tamara order through its own flat settlement endpoints', function () {
    $this->actingAs(settlementAdmin('tamara'), 'admin');

    $row = PaymentProvider::create([
        'id' => 'tamara', 'title' => 'Tamara', 'enabled' => true, 'mode' => 'test', 'position' => 2,
    ]);

    $row->config = ['api_token' => 'tok_kbb', 'notification_token' => 'ntok_kbb'];
    $row->save();

    app(GatewayCredentials::class)->forget();

    $order = settlementOrder([
        'payment_method' => 'tamara',
        'transaction_id' => 'tam_order_1',
        'paid_at' => now(),
    ]);

    Http::fake([
        // Sandbox, because mode is test — the gateway picks the base URL and
        // this pins that it really does.
        'api-sandbox.tamara.co/merchants/orders/*' => Http::response([
            'order_id' => 'tam_order_1',
            'order_reference_id' => $order->order_number,
            'status' => 'authorised',
        ], 200),
        'api-sandbox.tamara.co/payments/capture' => Http::response(['capture_id' => 'tam_cap_1'], 200),
        'api-sandbox.tamara.co/payments/refund' => Http::response([
            'refunds' => [['capture_id' => 'tam_cap_1', 'refund_id' => 'tam_ref_1']],
        ], 200),
    ]);

    $this->postJson('/admin-api/orders/' . $order->id . '/capture')
        ->assertOk()->assertJsonPath('code', 'captured');

    $order->refresh();

    expect($order->capture_ref)->toBe('tam_cap_1')
        ->and((int) $order->captured_total)->toBe(25000);

    $this->postJson('/admin-api/orders/' . $order->id . '/refund', ['amount_aed' => 100])
        ->assertOk()->assertJsonPath('ok', true);

    expect(Refund::where('order_id', $order->id)->value('provider_ref'))->toBe('tam_ref_1');
});

it('treats a Stripe payment that Stripe already captured as captured, without capturing again', function () {
    $this->actingAs(settlementAdmin('stripe'), 'admin');

    $row = PaymentProvider::create([
        'id' => 'stripe', 'title' => 'Card', 'enabled' => true, 'mode' => 'test', 'position' => 3,
    ]);

    $row->config = ['secret_key' => 'sk_test_kbb', 'webhook_signing_secret' => 'whsec_kbb'];
    $row->save();

    app(GatewayCredentials::class)->forget();

    $order = settlementOrder([
        'payment_method' => 'stripe',
        'transaction_id' => 'pi_kbb_1',
        'paid_at' => now(),
    ]);

    Http::fake([
        // Checkout sessions capture automatically, so the intent is already
        // succeeded. Reporting a capture that never happened would be worse
        // than reporting nothing.
        'api.stripe.com/v1/payment_intents/pi_kbb_1' => Http::response([
            'id' => 'pi_kbb_1', 'status' => 'succeeded',
        ], 200),
        'api.stripe.com/v1/refunds' => Http::response([
            'id' => 're_kbb_1', 'status' => 'succeeded',
        ], 200),
    ]);

    $this->postJson('/admin-api/orders/' . $order->id . '/capture')
        ->assertOk()->assertJsonPath('code', 'already_captured');

    $order->refresh();

    expect($order->captured_at)->not->toBeNull()
        ->and((int) $order->captured_total)->toBe(25000);

    // No POST to a capture endpoint was made; only the read.
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/capture'));

    $this->postJson('/admin-api/orders/' . $order->id . '/refund', ['amount_aed' => 250])
        ->assertOk()->assertJsonPath('ok', true);

    $order->refresh();

    expect($order->status)->toBe('refunded')
        ->and(Refund::where('order_id', $order->id)->value('provider_ref'))->toBe('re_kbb_1');
});

it('refuses a Stripe refund that Stripe answers 200 but marks failed', function () {
    $this->actingAs(settlementAdmin('stripefail'), 'admin');

    $row = PaymentProvider::create([
        'id' => 'stripe', 'title' => 'Card', 'enabled' => true, 'mode' => 'test', 'position' => 3,
    ]);

    $row->config = ['secret_key' => 'sk_test_kbb'];
    $row->save();

    app(GatewayCredentials::class)->forget();

    $order = settlementOrder([
        'payment_method' => 'stripe',
        'transaction_id' => 'pi_kbb_2',
        'paid_at' => now(),
        'captured_at' => now(),
        'captured_total' => 25000,
        'capture_ref' => 'pi_kbb_2',
    ]);

    // `failed` is a real Stripe refund state and it comes back on a 200. A
    // caller that only checked the HTTP status would record this as money
    // returned.
    Http::fake([
        'api.stripe.com/v1/refunds' => Http::response(['id' => 're_x', 'status' => 'failed'], 200),
    ]);

    $this->postJson('/admin-api/orders/' . $order->id . '/refund', ['amount_aed' => 250])
        ->assertStatus(502)->assertJsonPath('ok', false);

    $order->refresh();

    expect($order->status)->not->toBe('refunded')
        ->and(Refund::where('order_id', $order->id)->value('status'))->toBe('failed');
});

it('records a cash-on-delivery refund as a ledger entry with no HTTP call', function () {
    $this->actingAs(settlementAdmin('codref'), 'admin');

    $order = settlementOrder([
        'payment_method' => 'cod',
        'captured_at' => now(),
        'captured_total' => 25000,
        'capture_ref' => 'cod:x',
    ]);

    $response = $this->postJson('/admin-api/orders/' . $order->id . '/refund', ['amount_aed' => 60])
        ->assertOk()->assertJsonPath('ok', true);

    // Honest about what happened: recorded, not sent anywhere.
    expect($response->json('code'))->toBe('recorded_only')
        ->and(Refund::where('order_id', $order->id)->value('status'))->toBe('succeeded');

    expect($order->notes()->get()->pluck('content')->implode(' '))
        ->toContain('settle this by hand');

    Http::assertNothingSent();
});

/* ============================================ the route file, unmounted yet === */

/**
 * routes/payments-settlement.php ships unmounted, so a mistake in it would not
 * surface until the integrator wired it up and the first Capture click 404'd.
 * These load the real file into the router and assert what it defines.
 */
function settlementRoutesFrom(callable $register): Illuminate\Support\Collection
{
    $before = Route::getRoutes()->getRoutes();

    $register(base_path('routes/payments-settlement.php'));

    return collect(Route::getRoutes()->getRoutes())
        ->reject(fn ($r) => in_array($r, $before, true))
        ->values();
}

it('defines exactly the two settlement routes, and no third way to move money', function () {
    $routes = settlementRoutesFrom(fn (string $path) => Route::prefix('admin-api')->group($path));

    expect($routes)->toHaveCount(2);

    $signatures = $routes->map(fn ($r) => implode('|', $r->methods()) . ' ' . $r->uri())
        ->sort()->values()->all();

    expect($signatures)->toBe([
        'GET|HEAD admin-api/orders/{id}/settlement',
        'POST admin-api/orders/{id}/capture',
    ]);

    // No refund route here: POST /admin-api/orders/{id}/refund already exists
    // in web.php. A second one would be a second way to move money past a
    // ceiling check that only works because there is one.
    expect($routes->contains(fn ($r) => str_contains($r->uri(), 'refund')))->toBeFalse();
});

it('points the settlement routes at real controller methods and pins the id to digits', function () {
    $routes = settlementRoutesFrom(fn (string $path) => Route::prefix('admin-api')->group($path));

    foreach ($routes as $route) {
        [$class, $method] = explode('@', $route->getAction('controller'));

        // A typo'd action is a 500 the moment the integrator wires this up.
        expect(method_exists($class, $method))->toBeTrue();
        expect($route->wheres)->toHaveKey('id');
        expect(preg_match('/^' . $route->wheres['id'] . '$/', '12'))->toBe(1);
        expect(preg_match('/^' . $route->wheres['id'] . '$/', '1;drop'))->toBe(0);
    }
});
