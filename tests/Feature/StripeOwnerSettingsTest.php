<?php

declare(strict_types=1);

/*
 * Store -> Payments -> Stripe: the owner's settings, end to end. (Lane SR.)
 *
 * The owner: "for stripe, i need the setting on backend to control the name on
 * statements, hooks, invoice number etc. same like stripe wordpress plugin
 * provides. but make sure everything works, so i will connect the stripe and
 * test the payment."
 *
 * This repository cannot reach Stripe, so every Stripe answer below is an
 * Http::fake() shaped like Stripe's documented responses. What these tests
 * prove is the request this shop sends, the order it is sent in, and what the
 * shop does with each answer -- not that Stripe accepts it. That last part is
 * the owner's test-mode run, which docs/STRIPE-TEST-GUIDE.md walks through.
 *
 * Each test says what it catches on the shop and, for the load-bearing ones,
 * the one-line MUTATION that turns it red.
 */

use App\Models\AdminUser;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\PaymentProvider;
use App\Models\Setting;
use App\Services\Payments\GatewayCredentials;
use App\Services\Payments\GatewayRegistry;
use App\Services\Payments\Gateways\StripeGateway;
use App\Services\Payments\PaymentCapturer;
use App\Services\Payments\PaymentLog;
use App\Services\Payments\PaymentRefunder;
use App\Services\Payments\StripeConnect;
use App\Services\Payments\StripeKeys;
use App\Services\Payments\StripePaymentText;
use App\Services\SettingsService;
use App\Support\AdminCapabilities;
use App\Support\SiteHost;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

const SR_URL_SECRET = 'whsec-stripe-sr0123456789abcdefABCDEF';
const SR_SIGNING_TEST = 'whsec_sr_test_signing_secret';
const SR_SIGNING_LIVE = 'whsec_sr_live_signing_secret';

beforeEach(function () {
    PaymentProvider::query()->delete();
    DB::table(PaymentLog::TABLE)->delete();
    app(GatewayCredentials::class)->forget();
});

/** A Stripe row. Test mode, the TEST set in the test boxes, unless told otherwise. */
function srProvider(array $config = [], string $mode = 'test', bool $replace = false): void
{
    $row = PaymentProvider::query()->firstOrNew(['id' => 'stripe']);
    $row->fill(['title' => 'Credit or debit card', 'enabled' => true, 'mode' => $mode, 'position' => 0]);

    $row->config = $replace ? $config : array_merge([
        'publishable_key_test' => 'pk_test_sr',
        'secret_key_test' => 'sk_test_sr',
        'webhook_signing_secret_test' => SR_SIGNING_TEST,
        'publishable_key' => 'pk_live_sr',
        'secret_key' => 'sk_live_sr',
        'webhook_signing_secret' => SR_SIGNING_LIVE,
        'webhook_secret' => SR_URL_SECRET,
    ], $config);

    $row->save();

    app(GatewayCredentials::class)->forget();
}

function srConfig(): array
{
    return PaymentProvider::find('stripe')->config ?? [];
}

function srGateway(): StripeGateway
{
    // A fresh registry, so no memo from an earlier step leaks in.
    return (new GatewayRegistry())->find('stripe');
}

function srOrder(int $total = 40000, array $extra = []): Order
{
    return Order::create(array_merge([
        'order_number' => (string) random_int(100000, 999999),
        'email' => 'buyer@example.com',
        'status' => 'pending',
        'currency' => 'AED',
        'subtotal' => $total,
        'total' => $total,
        'payment_method' => 'stripe',
    ], $extra));
}

function srWebhook(array $event, ?string $signWith = SR_SIGNING_TEST, string $urlSecret = SR_URL_SECRET): Request
{
    $raw = json_encode($event);
    $ts = time();
    $server = ['CONTENT_TYPE' => 'application/json'];

    if ($signWith !== null) {
        $server['HTTP_STRIPE_SIGNATURE'] = "t={$ts},v1=" . hash_hmac('sha256', $ts . '.' . $raw, $signWith);
    }

    $request = Request::create('/api/payments/webhook/stripe/' . $urlSecret, 'POST', [], [], [], $server, $raw);
    $route = new \Illuminate\Routing\Route(['POST'], '/api/payments/webhook/{gateway}/{secret}', fn () => null);
    $route->bind($request);
    $request->setRouteResolver(fn () => $route);

    return $request;
}

function srIntentEvent(string $type, Order $order, array $object = []): array
{
    return [
        'id' => 'evt_' . uniqid(),
        'type' => $type,
        'data' => ['object' => array_merge([
            'id' => (string) $order->transaction_id ?: 'pi_sr_1',
            'object' => 'payment_intent',
            'amount' => (int) $order->total,
            'amount_received' => (int) $order->total,
            'currency' => 'aed',
            'status' => 'succeeded',
            'metadata' => ['order_number' => $order->order_number],
        ], $object)],
    ];
}

/** The POST that opened the intent, decoded from its form body. */
function srIntentRequest(): array
{
    $found = null;

    Http::assertSent(function (ClientRequest $r) use (&$found) {
        if ($r->method() === 'POST' && parse_url($r->url(), PHP_URL_PATH) === '/v1/payment_intents') {
            parse_str($r->body(), $found);
            $found['__headers'] = $r->headers();

            return true;
        }

        return false;
    });

    return $found ?? [];
}

function srOwner(string $role = 'owner'): AdminUser
{
    return AdminUser::create([
        'name' => 'SR ' . $role,
        'email' => 'sr-' . $role . '-' . uniqid() . '@example.test',
        'password' => 'password-long-enough',
        'role' => $role,
    ]);
}

function srFakeIntentCreate(string $id = 'pi_sr_1', array $more = []): void
{
    Http::fake(array_merge([
        'api.stripe.com/v1/payment_intents' => Http::response([
            'id' => $id, 'client_secret' => $id . '_secret_abc', 'status' => 'requires_payment_method',
        ], 200),
    ], $more));
}

/* =====================================================================
 | Defaults: an untouched shop sends Stripe exactly what it always sent
 ===================================================================== */

it('sends exactly today\'s PaymentIntent when no new setting has been touched', function () {
    // CATCHES: a lane-added default quietly changing every real payment -- a
    // suffix on statements, a held authorisation, a receipt the owner never
    // asked Stripe to send. MUTATION: default capture_later to '1' in
    // StripeGateway::captureLater() and the capture_method line goes red.
    srProvider();
    srFakeIntentCreate();

    $order = srOrder(40000, ['order_number' => '10234']);
    srGateway()->start($order);

    $sent = srIntentRequest();

    expect($sent['description'])->toBe('Order 10234')
        ->and($sent['metadata'])->toBe(['order_number' => '10234'])
        ->and($sent)->not->toHaveKey('statement_descriptor_suffix')
        ->and($sent)->not->toHaveKey('statement_descriptor')
        ->and($sent)->not->toHaveKey('capture_method')
        ->and($sent)->not->toHaveKey('receipt_email')
        // The idempotency key is the one this shop has always used, so a
        // request in flight across the update still replays rather than 400s.
        ->and($sent['__headers']['Idempotency-Key'][0])->toBe('kbb-intent-10234');
});

/* =====================================================================
 | A card payment, start to finish
 ===================================================================== */

it('takes a successful card payment: intent, browser report, then the webhook is a no-op', function () {
    // CATCHES: the happy path breaking anywhere -- intent not opened, the
    // browser's report not believed after Stripe confirms it, or the webhook
    // applying the same money a second time.
    srProvider();
    $order = srOrder(40000);

    srFakeIntentCreate('pi_sr_ok', [
        'api.stripe.com/v1/payment_intents/pi_sr_ok' => Http::response([
            'id' => 'pi_sr_ok', 'status' => 'succeeded', 'amount' => 40000, 'amount_received' => 40000, 'currency' => 'aed',
        ], 200),
    ]);

    $start = srGateway()->start($order);
    expect($start->clientSecret)->toBe('pi_sr_ok_secret_abc');

    $order->refresh();
    expect(srGateway()->confirmFromBrowser($order)->message)->toBe('payment applied');
    expect($order->fresh()->paid_at)->not->toBeNull()
        ->and($order->fresh()->status)->toBe('processing');

    $late = srGateway()->handleWebhook(srWebhook(srIntentEvent('payment_intent.succeeded', $order->fresh())));

    expect($late->status)->toBe(200)->and($late->message)->toBe('already applied');
    expect(Payment::where('provider_ref', 'pi_sr_ok')->count())->toBe(1);

    // And the owner can see it arrive.
    expect(PaymentLog::latest('stripe', 'webhook.received')['context']['event_type'] ?? null)->toBe('payment_intent.succeeded');
});

it('applies the webhook when it lands BEFORE the browser returns, and the browser is then a no-op', function () {
    // CATCHES: the closed-laptop case -- the bank approves, the tab dies, and
    // only the webhook can tell the shop. Then the browser comes back late.
    srProvider();
    $order = srOrder(25000, ['transaction_id' => 'pi_sr_early']);

    Http::fake(['api.stripe.com/v1/payment_intents/pi_sr_early' => Http::response([
        'id' => 'pi_sr_early', 'status' => 'succeeded', 'amount' => 25000, 'amount_received' => 25000, 'currency' => 'aed',
    ], 200)]);

    $first = srGateway()->handleWebhook(srWebhook(srIntentEvent('payment_intent.succeeded', $order, ['id' => 'pi_sr_early'])));
    expect($first->message)->toBe('payment applied');

    $second = srGateway()->confirmFromBrowser($order->fresh());
    expect($second->message)->toBe('already applied');
    expect(PaymentEvent::where('external_id', 'pi_sr_early')->where('type', 'paid')->count())->toBe(1);
});

it('treats a duplicate webhook delivery as already applied', function () {
    // CATCHES: Stripe's at-least-once delivery doubling an order's payment
    // rows, notes and stock movement -- here through the new logging wrapper
    // around the handler, which must not change what the handler returns.
    srProvider();
    $order = srOrder(30000, ['transaction_id' => 'pi_sr_dup']);
    $event = srIntentEvent('payment_intent.succeeded', $order, ['id' => 'pi_sr_dup']);

    $a = srGateway()->handleWebhook(srWebhook($event));
    $b = srGateway()->handleWebhook(srWebhook($event));

    expect($a->message)->toBe('payment applied')
        ->and($b->message)->toBe('already applied')
        ->and($b->status)->toBe(200);

    expect($order->notes()->where('content', 'like', 'Payment confirmed via stripe%')->count())->toBe(1);
});

it('leaves the order open on a declined card so the shopper can try another', function () {
    // CATCHES: a decline failing the order, after which the shopper's second
    // card succeeds against a `failed` order -- money taken, nothing sold.
    srProvider();
    $order = srOrder(40000, ['transaction_id' => 'pi_sr_decl']);

    $outcome = srGateway()->handleWebhook(srWebhook(srIntentEvent('payment_intent.payment_failed', $order, [
        'id' => 'pi_sr_decl', 'status' => 'requires_payment_method', 'amount_received' => 0,
        'last_payment_error' => ['code' => 'card_declined', 'decline_code' => 'generic_decline'],
    ])));

    expect($outcome->status)->toBe(200)
        ->and($order->fresh()->status)->toBe('pending')
        ->and($order->fresh()->paid_at)->toBeNull();

    // The decline is in the payment log with what the shop decided.
    $row = PaymentLog::latest('stripe', 'webhook.received');
    expect($row['context']['event_type'])->toBe('payment_intent.payment_failed')
        ->and($row['context']['outcome'])->toContain('declined');
});

it('logs Stripe\'s own decline code when the API refuses a call', function () {
    srProvider();
    Http::fake(['api.stripe.com/v1/payment_intents' => Http::response(['error' => [
        'type' => 'card_error', 'code' => 'card_declined', 'decline_code' => 'insufficient_funds',
        'message' => 'Your card has insufficient funds.',
    ]], 402)]);

    $start = srGateway()->start(srOrder());

    expect($start->result)->toBe('failed');

    $error = PaymentLog::latest('stripe', 'api.error');
    expect($error['context']['decline_code'])->toBe('insufficient_funds')
        ->and($error['context']['http_status'])->toBe(402)
        ->and($error['level'])->toBe('error');
});

it('waits through 3-D Secure: requires_action is not paid, succeeded afterwards is', function () {
    // CATCHES: the browser reporting during the bank's challenge being taken
    // as a payment -- an order marked paid for money that never moved.
    srProvider();
    $order = srOrder(40000, ['transaction_id' => 'pi_sr_3ds']);

    Http::fakeSequence('api.stripe.com/v1/payment_intents/pi_sr_3ds')
        ->push(['id' => 'pi_sr_3ds', 'status' => 'requires_action', 'amount' => 40000, 'currency' => 'aed'], 200)
        ->push(['id' => 'pi_sr_3ds', 'status' => 'succeeded', 'amount' => 40000, 'amount_received' => 40000, 'currency' => 'aed'], 200);

    $during = srGateway()->confirmFromBrowser($order);
    expect($during->message)->toBe('the payment has not succeeded')
        ->and($order->fresh()->paid_at)->toBeNull();

    $after = srGateway()->confirmFromBrowser($order->fresh());
    expect($after->message)->toBe('payment applied')
        ->and($order->fresh()->paid_at)->not->toBeNull();
});

it('rejects a webhook with a bad signature and tells the owner why; a wrong URL is not logged', function () {
    // CATCHES: (a) an unsigned body marking an order paid; (b) the owner never
    // learning that Stripe IS delivering but with a secret this shop lacks;
    // (c) a stranger guessing the URL being able to fill the log.
    srProvider();
    $order = srOrder(40000, ['transaction_id' => 'pi_sr_sig']);
    $event = srIntentEvent('payment_intent.succeeded', $order, ['id' => 'pi_sr_sig']);

    $bad = srGateway()->handleWebhook(srWebhook($event, 'whsec_somebody_else'));
    expect($bad->status)->toBe(401)->and($order->fresh()->paid_at)->toBeNull();

    $failure = PaymentLog::latest('stripe', 'webhook.signature_failed');
    expect($failure)->not->toBeNull()
        ->and($failure['message'])->toContain('test signing secret');

    DB::table(PaymentLog::TABLE)->delete();

    $guess = srGateway()->handleWebhook(srWebhook($event, SR_SIGNING_TEST, 'whsec-stripe-guessedguessedguessed'));
    expect($guess->status)->toBe(401)
        ->and(DB::table(PaymentLog::TABLE)->count())->toBe(0);
});

it('answers 200 to a test event that names no order, so it shows as received', function () {
    // CATCHES: the Dashboard's test events failing with 422 and being retried
    // for three days, while the owner's "last event received" stays empty.
    srProvider();

    $outcome = srGateway()->handleWebhook(srWebhook([
        'id' => 'evt_test_ping', 'type' => 'payment_intent.succeeded',
        'data' => ['object' => ['id' => 'pi_test_ping', 'amount' => 2000, 'currency' => 'usd', 'metadata' => []]],
    ]));

    expect($outcome->status)->toBe(200);
    expect(PaymentLog::latest('stripe', 'webhook.received')['context']['event_type'])->toBe('payment_intent.succeeded');
});

/* =====================================================================
 | Refunds
 ===================================================================== */

it('refunds in full and in part, sending Stripe the amount asked for', function () {
    // CATCHES: a partial refund sending the whole order total to Stripe.
    // MUTATION: send (int) $order->total instead of $amountFils in refund().
    srProvider();
    $order = srOrder(40000, ['transaction_id' => 'pi_sr_ref', 'status' => 'processing', 'paid_at' => now()]);

    Http::fake(['api.stripe.com/v1/refunds' => Http::sequence()
        ->push(['id' => 're_part', 'status' => 'succeeded', 'amount' => 15000], 200)
        ->push(['id' => 're_rest', 'status' => 'succeeded', 'amount' => 25000], 200)]);

    $part = srGateway()->refund($order, 15000, 'damaged', null, 'k-part');
    $rest = srGateway()->refund($order, 25000, null, null, 'k-rest');

    expect($part->ok)->toBeTrue()->and($part->reference)->toBe('re_part')
        ->and($rest->ok)->toBeTrue()->and($rest->reference)->toBe('re_rest');

    $amounts = [];
    Http::assertSent(function (ClientRequest $r) use (&$amounts) {
        if (str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/v1/refunds')) {
            parse_str($r->body(), $b);
            $amounts[] = [(int) $b['amount'], $b['payment_intent'], $r->header('Idempotency-Key')[0] ?? null];
        }

        return true;
    });

    expect($amounts)->toBe([[15000, 'pi_sr_ref', 'k-part'], [25000, 'pi_sr_ref', 'k-rest']]);
    expect(PaymentLog::latest('stripe', 'refund')['context']['refund'])->toBe('re_rest');
});

it('runs a partial then a full refund through the shop\'s own refunder', function () {
    $this->actingAs(srOwner(), 'admin');
    srProvider();
    $order = srOrder(40000, ['transaction_id' => 'pi_sr_rf2']);
    srGateway()->handleWebhook(srWebhook(srIntentEvent('payment_intent.succeeded', $order, ['id' => 'pi_sr_rf2'])));

    Http::fake(['api.stripe.com/v1/refunds' => Http::sequence()
        ->push(['id' => 're_a', 'status' => 'succeeded'], 200)
        ->push(['id' => 're_b', 'status' => 'succeeded'], 200)]);

    $refunder = app(PaymentRefunder::class);

    expect($refunder->refund($order->fresh(), 10000, 'part')->ok)->toBeTrue();
    expect($order->fresh()->status)->not->toBe('refunded');

    expect($refunder->refund($order->fresh(), 30000, 'rest')->ok)->toBeTrue();
    expect($refunder->refundedFils($order->fresh()))->toBe(40000)
        ->and($order->fresh()->status)->toBe('refunded');
});

/* =====================================================================
 | Statement descriptor, order prefix, description, receipts
 ===================================================================== */

it('validates the statement descriptor the way Stripe does', function () {
    // CATCHES: a descriptor Stripe would refuse being saved, and then every
    // card payment failing at the till instead of at the Save button.
    expect(StripePaymentText::fullDescriptorError('K-Beauty Bliss'))->toBeNull()
        ->and(StripePaymentText::fullDescriptorError('KBB'))->toContain('5 to 22')
        ->and(StripePaymentText::fullDescriptorError('K-Beauty Bliss Dubai Store'))->toContain('5 to 22')
        ->and(StripePaymentText::fullDescriptorError('12345'))->toContain('at least one letter')
        ->and(StripePaymentText::fullDescriptorError('KBB "BEAUTY"'))->toContain('cannot contain')
        ->and(StripePaymentText::fullDescriptorError('KBB*BEAUTY'))->toContain('cannot contain')
        ->and(StripePaymentText::fullDescriptorError('بيوتي بليس'))->toContain('Latin')
        ->and(StripePaymentText::suffixError('KBB', 10))->toBeNull()
        // prefix 10 + "* " + 11 = 23 > 22
        ->and(StripePaymentText::suffixError('ORDERS 1234', 10))->toContain('at most 10');

    // The default from the shop's name is itself valid.
    expect(StripePaymentText::defaultFullDescriptor('K-Beauty Bliss'))->toBe('K-Beauty Bliss')
        ->and(StripePaymentText::defaultFullDescriptor('Café "Beauté" Boutique of Korean Skincare'))->toBe('Cafe Beaute Boutique');
});

it('refuses a bad descriptor at the Save button with Stripe\'s rule in the message, and saves a good one', function () {
    $this->actingAs(srOwner(), 'admin');
    srProvider();

    $this->postJson('/admin-api/payments', ['id' => 'stripe', 'settings' => ['statement_descriptor' => 'KBB*BEAUTY']])
        ->assertStatus(422)
        ->assertJsonPath('errors.statement_descriptor', 'Statement descriptor cannot contain any of  < > \\ \' " *');

    expect(srConfig())->not->toHaveKey('statement_descriptor');

    $this->postJson('/admin-api/payments', ['id' => 'stripe', 'settings' => [
        'statement_descriptor' => 'K-Beauty Bliss',
        'payment_description' => 'Bad {customer_email}',
    ]])->assertStatus(422)->assertJsonPath('errors.payment_description', fn ($m) => str_contains($m, '{customer_email}'));

    $this->postJson('/admin-api/payments', ['id' => 'stripe', 'settings' => ['statement_descriptor' => 'K-Beauty Bliss']])
        ->assertOk();

    expect(srConfig()['statement_descriptor'])->toBe('K-Beauty Bliss');
});

it('puts the suffix, the order number, the prefix and the description on the intent -- never the full descriptor', function () {
    // CATCHES: sending `statement_descriptor` on a card intent, which Stripe's
    // PaymentIntent reference says "returns an error" -- every card payment
    // would fail. MUTATION: add 'statement_descriptor' => fullDescriptor() in
    // intentSettings() and the not->toHaveKey line goes red.
    srProvider([
        'statement_descriptor' => 'K-Beauty Bliss',
        'statement_descriptor_suffix' => 'KBB',
        'statement_descriptor_order_number' => '1',
        'order_reference_prefix' => 'KBB-',
        'payment_description' => '{shop} order {number}',
        'account_descriptor_prefix' => 'KBEAUTY',
    ]);
    Setting::query()->updateOrCreate(['key' => 'store_name'], ['value' => 'K-Beauty Bliss']);
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    srFakeIntentCreate();

    srGateway()->start(srOrder(40000, ['order_number' => '10234']));
    $sent = srIntentRequest();

    expect($sent['statement_descriptor_suffix'])->toBe('KBB 10234')   // KBEAUTY* KBB 10234 = 18 chars
        ->and($sent)->not->toHaveKey('statement_descriptor')
        ->and($sent['description'])->toBe('K-Beauty Bliss order KBB-10234')
        ->and($sent['metadata'])->toBe(['order_number' => '10234', 'order_reference' => 'KBB-10234']);
});

it('keeps the order number whole when the suffix would overflow 22 characters', function () {
    // A 10-character prefix leaves 10 for the suffix: the owner's text gives
    // way, the number the customer matches on does not.
    expect(StripePaymentText::suffix('KBEAUTY SHOP', true, '1023456', 10))->toBe('KB 1023456')
        ->and(StripePaymentText::suffix('', true, '10234', null))->toBe('10234')
        ->and(StripePaymentText::suffix('', false, '10234', null))->toBeNull()
        ->and(strlen('KBEAUTYBLS* ' . StripePaymentText::suffix('KBEAUTY SHOP', true, '1023456', 10)))->toBeLessThanOrEqual(22);
});

it('finds the order for payments made before the prefix, after it, and after it changed', function () {
    // CATCHES: a prefix leaking into the lookup key -- every webhook for a
    // payment made under one prefix stranded once the owner changes it, and
    // every payment made before the setting existed stranded the day it is set.
    // MUTATION: look the order up by metadata.order_reference in
    // processWebhook() and the "old" and "changed" cases go red.
    srProvider(['order_reference_prefix' => 'KBB-']);

    $old = srOrder(10000, ['transaction_id' => 'pi_sr_old']);
    $new = srOrder(10000, ['transaction_id' => 'pi_sr_new']);
    $changed = srOrder(10000, ['transaction_id' => 'pi_sr_chg']);

    // Before this change: metadata carried only the plain number.
    $a = srGateway()->handleWebhook(srWebhook(srIntentEvent('payment_intent.succeeded', $old, [
        'id' => 'pi_sr_old', 'metadata' => ['order_number' => $old->order_number],
    ])));

    // After: both, exactly as openIntent() now writes them.
    $b = srGateway()->handleWebhook(srWebhook(srIntentEvent('payment_intent.succeeded', $new, [
        'id' => 'pi_sr_new', 'metadata' => ['order_number' => $new->order_number, 'order_reference' => 'KBB-' . $new->order_number],
    ])));

    // Made under an older prefix the owner has since replaced.
    srProvider(['order_reference_prefix' => 'EB/']);
    $c = srGateway()->handleWebhook(srWebhook(srIntentEvent('payment_intent.succeeded', $changed, [
        'id' => 'pi_sr_chg', 'metadata' => ['order_number' => $changed->order_number, 'order_reference' => 'KBB-' . $changed->order_number],
    ])));

    expect([$a->message, $b->message, $c->message])->toBe(['payment applied', 'payment applied', 'payment applied']);

    // And the shop's own order numbers never changed.
    expect($new->fresh()->order_number)->not->toStartWith('KBB-');
});

it('sends receipt_email only when Stripe receipts are switched on', function () {
    // CATCHES: Stripe emailing every shopper a second receipt the owner did not
    // ask for, or the switch doing nothing. MUTATION: drop the `=== '1'` test
    // on receipt_email in intentSettings() and the "off" half goes red.
    srProvider();
    srFakeIntentCreate();
    srGateway()->start(srOrder(40000, ['email' => 'shopper@example.com']));
    expect(srIntentRequest())->not->toHaveKey('receipt_email');

    srProvider(['receipt_email' => '1']);
    srFakeIntentCreate('pi_sr_rcpt');
    srGateway()->start(srOrder(40000, ['email' => 'shopper@example.com']));
    expect(srIntentRequest()['receipt_email'])->toBe('shopper@example.com');
});

/* =====================================================================
 | Capture later
 ===================================================================== */

it('authorises only, shows the order as authorised with a Capture button, and captures on the press', function () {
    // CATCHES: the three ways "capture later" fails an owner -- the intent
    // still auto-capturing; the order reading green "Paid" with no button while
    // Stripe counts down seven days to releasing the money; and Capture not
    // reaching Stripe. MUTATION: drop the `awaiting_capture` arm in
    // OrderPaymentPanel::capture() and `offered` goes false.
    $this->actingAs(srOwner(), 'admin');
    srProvider(['capture_later' => '1']);
    $order = srOrder(40000);

    srFakeIntentCreate('pi_sr_hold', [
        'api.stripe.com/v1/payment_intents/pi_sr_hold/capture' => Http::response(['id' => 'pi_sr_hold', 'status' => 'succeeded', 'amount_received' => 40000], 200),
        'api.stripe.com/v1/payment_intents/pi_sr_hold' => Http::sequence()
            ->push(['id' => 'pi_sr_hold', 'status' => 'requires_capture', 'amount' => 40000, 'amount_capturable' => 40000, 'amount_received' => 0, 'currency' => 'aed'], 200)
            ->push(['id' => 'pi_sr_hold', 'status' => 'requires_capture', 'amount' => 40000, 'amount_capturable' => 40000, 'amount_received' => 0, 'currency' => 'aed'], 200),
    ]);

    srGateway()->start($order);
    expect(srIntentRequest()['capture_method'])->toBe('manual');

    // The browser comes back with requires_capture: that is a payment.
    expect(srGateway()->confirmFromBrowser($order->fresh())->message)->toBe('payment applied');

    $settlement = app(PaymentCapturer::class)->status($order->fresh());
    expect($settlement['awaiting_capture'])->toBeTrue();

    $panel = $this->getJson("/admin-api/orders/{$order->id}/detail")->assertOk()->json('payment');
    expect($panel['state'])->toBe('authorised')
        ->and($panel['tone'])->toBe('amber')
        ->and($panel['headline'])->toBe('Card authorised — not captured yet')
        ->and($panel['capture']['offered'])->toBeTrue();

    $this->postJson("/admin-api/orders/{$order->id}/capture")->assertOk();

    Http::assertSent(fn (ClientRequest $r) => str_ends_with($r->url(), '/v1/payment_intents/pi_sr_hold/capture')
        && str_contains($r->body(), 'amount_to_capture=40000'));

    expect($order->fresh()->captured_at)->not->toBeNull();
    $after = $this->getJson("/admin-api/orders/{$order->id}/detail")->json('payment');
    expect($after['state'])->toBe('paid')->and($after['capture']['offered'])->toBeFalse();
});

it('confirms an authorised-only payment from the webhook when the browser never returns', function () {
    srProvider(['capture_later' => '1']);
    $order = srOrder(40000, ['transaction_id' => 'pi_sr_hold2']);

    $outcome = srGateway()->handleWebhook(srWebhook(srIntentEvent('payment_intent.amount_capturable_updated', $order, [
        'id' => 'pi_sr_hold2', 'status' => 'requires_capture', 'amount_capturable' => 40000, 'amount_received' => 0,
    ])));

    expect($outcome->message)->toBe('payment applied')
        ->and(srGateway()->awaitingCapture($order->fresh()))->toBeTrue();

    // The `succeeded` that follows the capture is the same money: not again.
    $later = srGateway()->handleWebhook(srWebhook(srIntentEvent('payment_intent.succeeded', $order->fresh(), ['id' => 'pi_sr_hold2'])));
    expect($later->message)->toBe('already applied');
});

it('never offers Capture on an ordinary, already-captured card order', function () {
    // CATCHES: the authorised-only check reading the CURRENT setting, so every
    // past card order sprouts a Capture button the day the switch goes on.
    srProvider();
    $order = srOrder(40000, ['transaction_id' => 'pi_sr_auto']);
    srGateway()->handleWebhook(srWebhook(srIntentEvent('payment_intent.succeeded', $order, ['id' => 'pi_sr_auto'])));

    srProvider(['capture_later' => '1']);

    expect(srGateway()->awaitingCapture($order->fresh()))->toBeFalse();
});

it('does not reuse an automatic intent once capture-later is on', function () {
    // CATCHES: a shopper's half-finished checkout from before the switch being
    // completed on an auto-capture intent, taking money the owner asked to hold.
    srProvider(['capture_later' => '1']);
    $order = srOrder(40000, ['transaction_id' => 'pi_sr_oldauto']);

    Http::fake([
        'api.stripe.com/v1/payment_intents/pi_sr_oldauto' => Http::response([
            'id' => 'pi_sr_oldauto', 'status' => 'requires_payment_method', 'amount' => 40000, 'currency' => 'aed',
            'capture_method' => 'automatic', 'client_secret' => 'pi_sr_oldauto_secret_x', 'setup_future_usage' => null,
        ], 200),
        'api.stripe.com/v1/payment_intents' => Http::response(['id' => 'pi_sr_fresh', 'client_secret' => 'pi_sr_fresh_secret_y'], 200),
    ]);

    expect(srGateway()->start($order)->providerRef)->toBe('pi_sr_fresh');
});

/* =====================================================================
 | Test and live: two key sets, one switch
 ===================================================================== */

it('uses the test keys and test signing secret in test mode, and the live ones in live mode', function () {
    // CATCHES: test and live crossing -- a live key charging real cards while
    // the screen says Sandbox, or live webhooks verified with the test secret.
    // MUTATION: in StripeKeys::get() read $value($name) instead of
    // $value($name . '_test') in test mode, and the test-mode Authorization
    // line goes red (it sends sk_live_).
    srProvider();
    srFakeIntentCreate();
    srGateway()->start(srOrder());
    expect(srIntentRequest()['__headers']['Authorization'][0])->toBe('Bearer sk_test_sr');
    expect(srGateway()->publishableKey())->toBe('pk_test_sr');

    srProvider([], 'live');
    srFakeIntentCreate('pi_sr_live');
    srGateway()->start(srOrder());
    expect(srIntentRequest()['__headers']['Authorization'][0])->toBe('Bearer sk_live_sr');
    expect(srGateway()->publishableKey())->toBe('pk_live_sr');

    // Live mode verifies with the LIVE secret only.
    $order = srOrder(40000, ['transaction_id' => 'pi_sr_m']);
    $event = srIntentEvent('payment_intent.succeeded', $order, ['id' => 'pi_sr_m']);
    expect(srGateway()->handleWebhook(srWebhook($event, SR_SIGNING_TEST))->status)->toBe(401);
    expect(srGateway()->handleWebhook(srWebhook($event, SR_SIGNING_LIVE))->message)->toBe('payment applied');
});

it('keeps a shop set up with ONE key set working in test mode, and refuses its test key in live mode', function () {
    // CATCHES: (a) the update switching off card payments on a shop that put
    // its test keys in the old single boxes; (b) a test key on a shop whose
    // switch says Live, where Stripe's public 4242 card would "pay" for real
    // goods. MUTATION: in StripeKeys::get() return $value($name) for live
    // regardless of $legacy and (b) goes red.
    srProvider([
        'publishable_key' => 'pk_test_legacy', 'secret_key' => 'sk_test_legacy',
        'webhook_signing_secret' => SR_SIGNING_TEST, 'webhook_secret' => SR_URL_SECRET,
    ], 'test', replace: true);

    expect(srGateway()->configured())->toBeTrue()
        ->and(srGateway()->publishableKey())->toBe('pk_test_legacy')
        ->and(srGateway()->key('webhook_signing_secret'))->toBe(SR_SIGNING_TEST);

    srProvider([], 'live');
    PaymentProvider::find('stripe')->forceFill(['config' => [
        'publishable_key' => 'pk_test_legacy', 'secret_key' => 'sk_test_legacy', 'webhook_secret' => SR_URL_SECRET,
    ]])->save();
    app(GatewayCredentials::class)->forget();

    expect(srGateway()->configured())->toBeFalse()
        ->and(srGateway()->availableFor(40000))->toBeFalse();

    expect(app(StripeConnect::class)->settingsStatus()['warnings'])
        ->toContain('Mode is Live but the Live key boxes hold TEST keys, so card payments are switched off until you paste your sk_live_ and pk_live_ keys. Saving the Stripe tab once moves the test keys into the Test boxes.');
});

it('moves an old single test set into the Test boxes on the next save', function () {
    $this->actingAs(srOwner(), 'admin');
    srProvider([
        'publishable_key' => 'pk_test_legacy', 'secret_key' => 'sk_test_legacy',
        'webhook_signing_secret' => SR_SIGNING_TEST, 'webhook_endpoint_id' => 'we_legacy', 'webhook_secret' => SR_URL_SECRET,
    ], 'test', replace: true);

    // The screen re-posts the publishable key it painted in the Live box.
    $this->postJson('/admin-api/payments', ['id' => 'stripe', 'mode' => 'test', 'settings' => ['publishable_key' => 'pk_test_legacy']])
        ->assertOk();

    $config = srConfig();

    expect($config['publishable_key_test'])->toBe('pk_test_legacy')
        ->and($config['secret_key_test'])->toBe('sk_test_legacy')
        ->and($config['webhook_signing_secret_test'])->toBe(SR_SIGNING_TEST)
        ->and($config['webhook_endpoint_id_test'])->toBe('we_legacy')
        ->and($config)->not->toHaveKey('secret_key')
        ->and($config)->not->toHaveKey('publishable_key');

    expect(srGateway()->configured())->toBeTrue();
});

it('refuses a key typed into the other mode\'s box', function () {
    $this->actingAs(srOwner(), 'admin');
    srProvider();

    $this->postJson('/admin-api/payments', ['id' => 'stripe', 'settings' => ['publishable_key_test' => 'pk_live_oops']])
        ->assertStatus(422)
        ->assertJsonPath('errors.publishable_key_test', 'That is a LIVE key in the Test box. Paste it into the Live box instead.');

    $this->postJson('/admin-api/payments', ['id' => 'stripe', 'settings' => ['secret_key' => 'sk_test_oops']])
        ->assertStatus(422);

    expect(srConfig()['publishable_key_test'])->toBe('pk_test_sr');
});

it('lists only the active set as missing in the setup check', function () {
    srProvider(['publishable_key' => null, 'secret_key' => null, 'webhook_signing_secret' => null], 'test');
    PaymentProvider::find('stripe')->forceFill(['config' => array_filter(srConfig())])->save();

    $labels = collect(app(\App\Services\Payments\GatewayPreflight::class)->inspect('stripe')['missing_fields'])->pluck('label')->all();
    expect($labels)->toBe([]);

    PaymentProvider::find('stripe')->forceFill(['mode' => 'live'])->save();
    $labels = collect(app(\App\Services\Payments\GatewayPreflight::class)->inspect('stripe')['missing_fields'])->pluck('label')->all();
    expect($labels)->toBe(['Live publishable key', 'Live secret key', 'Live webhook signing secret']);
});

/* =====================================================================
 | Set up webhook automatically
 ===================================================================== */

function srSiteHosts(): void
{
    config(['app.url' => 'https://kbeautybliss.com']);

    foreach ([SiteHost::KEY_CANONICAL => 'kbeautybliss.com', SiteHost::KEY_ALIASES => 'extrabeauty.ae'] as $key => $value) {
        Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }

    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();
    SiteHost::forget();
}

it('registers the webhook with the stored key, stores the signing secret, and removes this shop\'s endpoint at its old domain', function () {
    // CATCHES: the owner having to visit Developers -> Webhooks at all; the
    // signing secret not being kept (every delivery then fails); and the
    // endpoint at the address the shop moved away from calling the old domain
    // for ever with this shop's URL secret. MUTATION: return 0 at the top of
    // removeStaleEndpoints() and the two DELETE assertions go red.
    $this->actingAs(srOwner(), 'admin');
    srSiteHosts();
    srProvider(['webhook_signing_secret_test' => null]);
    PaymentProvider::find('stripe')->forceFill(['config' => array_filter(srConfig())])->save();
    app(GatewayCredentials::class)->forget();

    $ours = app(StripeConnect::class)->webhookUrl();
    expect($ours)->toStartWith('https://kbeautybliss.com/');

    $oldDomain = str_replace('https://kbeautybliss.com', 'https://extrabeauty.ae', $ours);
    $oldTail = substr($ours, 0, -strlen(SR_URL_SECRET)) . 'whsec-stripe-OLDTAILOLDTAILOLDTAIL';

    Http::fake([
        'api.stripe.com/v1/webhook_endpoints?limit=100' => Http::response(['data' => [
            ['id' => 'we_olddomain', 'url' => $oldDomain, 'enabled_events' => StripeConnect::EVENTS],
            ['id' => 'we_oldtail', 'url' => $oldTail, 'enabled_events' => StripeConnect::EVENTS],
            ['id' => 'we_other', 'url' => 'https://erp.example.com/stripe/hook', 'enabled_events' => ['*']],
            ['id' => 'we_unlisted', 'url' => 'https://staging.example.com/api/payments/webhook/stripe/x', 'enabled_events' => ['*']],
        ]], 200),
        'api.stripe.com/v1/webhook_endpoints/*' => Http::response(['id' => 'x', 'deleted' => true], 200),
        'api.stripe.com/v1/webhook_endpoints' => Http::response(['id' => 'we_new', 'url' => $ours, 'secret' => 'whsec_new_from_stripe'], 200),
        'api.stripe.com/v1/account' => Http::response(['id' => 'acct_1', 'settings' => [
            'payments' => ['statement_descriptor' => 'KBEAUTYBLISS.COM'],
            'card_payments' => ['statement_descriptor_prefix' => 'KBEAUTY'],
        ]], 200),
    ]);

    $res = $this->postJson('/admin-api/payments/stripe/webhook/setup')->assertOk();

    expect($res->json('action'))->toBe('created')
        ->and($res->json('stale_removed'))->toBe(2)
        ->and($res->json('mode'))->toBe('test')
        // The secret goes to the server's store, never to the browser.
        ->and($res->getContent())->not->toContain('whsec_new_from_stripe')
        ->and($res->getContent())->not->toContain('sk_test_sr')
        ->and($res->getContent())->not->toContain(SR_URL_SECRET);

    $config = srConfig();
    expect($config['webhook_signing_secret_test'])->toBe('whsec_new_from_stripe')
        ->and($config['webhook_endpoint_id_test'])->toBe('we_new')
        ->and($config['webhook_endpoint_managed_test'])->toBe('1')
        // The live set is untouched by a test-mode setup.
        ->and($config['webhook_signing_secret'])->toBe(SR_SIGNING_LIVE)
        ->and($config['account_descriptor_prefix'])->toBe('KBEAUTY');

    Http::assertSent(fn (ClientRequest $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/we_olddomain'));
    Http::assertSent(fn (ClientRequest $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/we_oldtail'));
    Http::assertNotSent(fn (ClientRequest $r) => $r->method() === 'DELETE' && (str_ends_with($r->url(), '/we_other') || str_ends_with($r->url(), '/we_unlisted')));

    // Exactly the events the handler acts on, and the stored key authenticated it.
    Http::assertSent(function (ClientRequest $r) use ($ours) {
        if ($r->method() !== 'POST' || parse_url($r->url(), PHP_URL_PATH) !== '/v1/webhook_endpoints') {
            return false;
        }

        parse_str($r->body(), $b);

        return $b['url'] === $ours && $b['enabled_events'] === StripeConnect::EVENTS
            && $r->header('Authorization')[0] === 'Bearer sk_test_sr';
    });

    // And a delivery signed with the new secret is now accepted.
    $order = srOrder(40000, ['transaction_id' => 'pi_sr_after']);
    expect(srGateway()->handleWebhook(srWebhook(srIntentEvent('payment_intent.succeeded', $order, ['id' => 'pi_sr_after']), 'whsec_new_from_stripe'))->message)
        ->toBe('payment applied');
});

it('refuses to set up a webhook with no key for the current mode, without calling Stripe', function () {
    $this->actingAs(srOwner(), 'admin');
    srProvider(['secret_key' => null], 'live');
    PaymentProvider::find('stripe')->forceFill(['config' => array_filter(srConfig())])->save();
    Http::fake();

    $this->postJson('/admin-api/payments/stripe/webhook/setup')
        ->assertStatus(422)
        ->assertJsonPath('error', 'There is no Live secret key stored. Paste your sk_live_ key in Live secret key and save first.');

    Http::assertNothingSent();
});

it('shows last event received and last signature failure, and never a secret, on the status block', function () {
    $this->actingAs(srOwner(), 'admin');
    srProvider();
    $order = srOrder(40000, ['transaction_id' => 'pi_sr_st']);
    srGateway()->handleWebhook(srWebhook(srIntentEvent('payment_intent.succeeded', $order, ['id' => 'pi_sr_st'])));
    srGateway()->handleWebhook(srWebhook(srIntentEvent('payment_intent.succeeded', $order, ['id' => 'pi_sr_st']), 'whsec_wrong'));

    $res = $this->getJson('/admin-api/payments/stripe/webhook')->assertOk();

    expect($res->json('test_mode'))->toBeTrue()
        ->and($res->json('webhook.last_event.event_type'))->toBe('payment_intent.succeeded')
        ->and($res->json('webhook.last_signature_failure.message'))->toContain('does not match')
        ->and($res->json('webhook.events'))->toBe(StripeConnect::EVENTS)
        ->and($res->json('keys.publishable_mode'))->toBe('test');

    foreach (['sk_test_sr', 'sk_live_sr', SR_SIGNING_TEST, SR_SIGNING_LIVE, SR_URL_SECRET, 'pk_test_sr'] as $secret) {
        expect($res->getContent())->not->toContain($secret);
    }
});

/* =====================================================================
 | Admin endpoints: their own capabilities, failing closed
 ===================================================================== */

it('gives the three new endpoints their own capabilities and refuses a manager', function () {
    // CATCHES: a new admin endpoint left unmapped or folded into a broad rule.
    expect(AdminCapabilities::forPath('GET', 'admin-api/payments/stripe/webhook'))->toBe('payments.stripe_webhook')
        ->and(AdminCapabilities::forPath('POST', 'admin-api/payments/stripe/webhook/setup'))->toBe('payments.stripe_webhook')
        ->and(AdminCapabilities::forPath('GET', 'admin-api/payments/stripe/log'))->toBe('payments.log')
        ->and(AdminCapabilities::roleCan('manager', 'payments.stripe_webhook'))->toBeFalse()
        ->and(AdminCapabilities::roleCan('manager', 'payments.log'))->toBeFalse();

    srProvider();
    Http::fake();
    $this->actingAs(srOwner('manager'), 'admin');

    $this->getJson('/admin-api/payments/stripe/webhook')->assertForbidden();
    $this->postJson('/admin-api/payments/stripe/webhook/setup')->assertForbidden();
    $this->getJson('/admin-api/payments/stripe/log')->assertForbidden();

    Http::assertNothingSent();
});

/* =====================================================================
 | The payment log
 ===================================================================== */

it('keeps no card number, key or secret in the payment log, whatever a caller passes', function () {
    // CATCHES: a log line becoming the place a secret leaks from. MUTATION:
    // remove the sk_/rk_ pattern from PaymentLog::scrub() and the first
    // not->toContain goes red.
    PaymentLog::record('stripe', 'error', 'api.error', 'Bearer sk_live_ABCDEF123456 rejected for 4242 4242 4242 4242', [
        'error_message' => 'whsec_abcdef1234 and pi_123_secret_abc and 4000002500003155',
        'payload' => ['card' => ['number' => '4242424242424242']],
        'secret_key' => 'sk_test_SHOULDNOTAPPEAR',
    ]);

    $raw = json_encode(DB::table(PaymentLog::TABLE)->get());

    expect($raw)->not->toContain('ABCDEF123456')
        ->and($raw)->not->toContain('4242 4242 4242 4242')
        ->and($raw)->not->toContain('4242424242424242')
        ->and($raw)->not->toContain('4000002500003155')
        ->and($raw)->not->toContain('whsec_abcdef1234')
        ->and($raw)->not->toContain('secret_abc')
        ->and($raw)->not->toContain('SHOULDNOTAPPEAR');
});

it('bounds the payment log to its newest rows', function () {
    // CATCHES: a log that grows for ever on a busy shop.
    for ($i = 0; $i < PaymentLog::KEEP + 25; $i++) {
        PaymentLog::record('stripe', 'info', 'tick', 'row ' . $i);
    }

    expect(DB::table(PaymentLog::TABLE)->count())->toBe(PaymentLog::KEEP)
        ->and(DB::table(PaymentLog::TABLE)->orderByDesc('id')->value('message'))->toBe('row ' . (PaymentLog::KEEP + 24));
});

it('shows the log to the owner, newest first', function () {
    $this->actingAs(srOwner(), 'admin');
    PaymentLog::record('stripe', 'info', 'first', 'one');
    PaymentLog::record('stripe', 'error', 'second', 'two');
    PaymentLog::record('tabby', 'info', 'other', 'not stripe');

    $rows = $this->getJson('/admin-api/payments/stripe/log')->assertOk()->json('rows');

    expect(array_column($rows, 'event'))->toBe(['second', 'first']);
});

/* =====================================================================
 | The checkout card script, and the admin panel's wiring
 ===================================================================== */

it('lets the card form and the wallets finish on requires_capture', function () {
    // CATCHES: "Authorise only" showing the shopper an error -- and the wallet
    // sheet RELEASING the order -- for a card the bank just approved.
    foreach (['stripe-elements', 'express-wallets'] as $partial) {
        $src = file_get_contents(resource_path("views/partials/checkout/{$partial}.blade.php"));
        expect($src)->toContain("intent.status !== 'succeeded' && intent.status !== 'processing' && intent.status !== 'requires_capture'");
    }
});

it('mounts the Stripe panel exactly once, and every path it calls is routed', function () {
    // Pinned as the FINISHED state (CLAUDE.md): zero is "built, never wired",
    // two would attach a second panel and a second observer.
    $admin = file_get_contents(resource_path('views/admin/app.blade.php'));
    expect(substr_count($admin, "@include('admin.partials.stripe-settings-panel')"))->toBe(1);

    $panel = file_get_contents(resource_path('views/admin/partials/stripe-settings-panel.blade.php'));
    preg_match_all("#'(/admin-api/[a-z0-9/_-]+)'#", $panel, $m);

    expect($m[1])->toEqualCanonicalizing([
        '/admin-api/payments/stripe/webhook',
        '/admin-api/payments/stripe/webhook/setup',
        '/admin-api/payments/stripe/log',
    ]);

    // No element-measuring API, no timer that repeats.
    expect($panel)->not->toContain('getBoundingClientRect')
        ->and($panel)->not->toContain('offsetHeight')
        ->and($panel)->not->toContain('setInterval');
});
