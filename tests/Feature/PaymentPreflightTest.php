<?php

/**
 * "Have I set this gateway up correctly?" — without a merchant account.
 *
 * The three remote gateways need keys this project does not have and must not
 * invent, so the deliverable is not "we tested Stripe", it is "the owner can
 * prove his own wiring the moment he has keys, and can see what is still
 * missing before he does".
 *
 * GatewayPreflight is what answers that, and this file holds it to the two
 * promises that make it usable on a live shop: it reaches no provider, and it
 * discloses no secret.
 */

use App\Models\Order;
use App\Models\PaymentProvider;
use App\Services\Payments\GatewayCredentials;
use App\Services\Payments\GatewayPreflight;
use Illuminate\Support\Facades\Http;

const PF_STRIPE_SECRET = 'sk_test_PREFLIGHTCANARY0000001';
const PF_STRIPE_WHSEC = 'whsec_PREFLIGHTCANARY0000002';
const PF_TABBY_SECRET = 'sk_test_PREFLIGHTCANARY0000003';
const PF_TAMARA_TOKEN = 'tamara_PREFLIGHTCANARY000000004';

beforeEach(function () {
    PaymentProvider::query()->delete();
    app(GatewayCredentials::class)->forget();

    // Nothing in this file may leave the machine. Any request that escapes the
    // preflight's own fake would land here and fail loudly.
    Http::preventStrayRequests();
});

function pfProvider(string $id, array $config, string $mode = 'test'): void
{
    $row = PaymentProvider::create([
        'id' => $id, 'enabled' => true, 'mode' => $mode, 'position' => 1,
    ]);

    $row->config = $config;
    $row->save();

    app(GatewayCredentials::class)->forget();
}

function pfStripe(string $mode = 'test'): void
{
    pfProvider('stripe', [
        'publishable_key' => 'pk_test_visible',
        'secret_key' => PF_STRIPE_SECRET,
        'webhook_signing_secret' => PF_STRIPE_WHSEC,
        'webhook_secret' => 'whsec-url-preflight-0123456789',
    ], $mode);
}

/* ------------------------------------------------------- what is still missing */

it('names the fields the owner has not filled in yet, rather than saying "not configured"', function () {
    // A half-finished Stripe setup: the publishable key is in, the rest is not.
    pfProvider('stripe', ['publishable_key' => 'pk_test_visible']);

    $result = app(GatewayPreflight::class)->inspect('stripe');

    $labels = collect($result['missing_fields'])->pluck('label');
    $keys = collect($result['missing_fields'])->pluck('key');

    expect($result['configured'])->toBeFalse()
        ->and($keys)->toContain('secret_key')
        ->and($keys)->toContain('webhook_signing_secret')
        // The LABEL is what the owner reads, and it has to be the words on the
        // form rather than the column name.
        ->and($labels)->toContain('Secret key')
        ->and($labels)->toContain('Webhook signing secret')
        // The one he HAS filled in is not listed as missing.
        ->and($keys)->not->toContain('publishable_key');
});

it('says which gate a gateway is failing, in the order they are applied', function () {
    // Switched off, and with nothing in it.
    PaymentProvider::create(['id' => 'tabby', 'enabled' => false, 'mode' => 'test', 'position' => 1]);
    app(GatewayCredentials::class)->forget();

    $blocked = app(GatewayPreflight::class)->inspect('tabby')['blocked_by'];

    expect($blocked)->toContain('The provider row is switched off.')
        ->and($blocked)->toContain('Credentials are missing.');

    // Fully set up, it is blocked by nothing -- which is the answer the owner
    // is actually looking for.
    pfStripe();

    expect(app(GatewayPreflight::class)->inspect('stripe')['blocked_by'])->toBe([]);
});

it('hands over the webhook url to paste, and says so plainly when there is not one yet', function () {
    pfStripe();

    expect(app(GatewayPreflight::class)->inspect('stripe')['webhook_url'])
        ->toContain('/api/payments/webhook/stripe/');

    // Before the first save there is no URL secret, so there is no URL. Null
    // rather than a half-built one that would 404 in Stripe's dashboard.
    PaymentProvider::query()->delete();
    pfProvider('stripe', ['secret_key' => PF_STRIPE_SECRET]);

    expect(app(GatewayPreflight::class)->inspect('stripe')['webhook_url'])->toBeNull();
});

/* ------------------------------------------------------------------- the dry run */

it('shows the real request it would send, without sending it', function () {
    pfStripe();

    $dry = app(GatewayPreflight::class)->inspect('stripe')['dry_run'];

    expect($dry['ran'])->toBeTrue()
        ->and($dry['sent'])->toBeTrue()
        ->and($dry['method'])->toBe('POST')
        // A PaymentIntent, not a Checkout session. The card fields are on our
        // own checkout now, so what this shop asks Stripe for is an intent the
        // page can confirm — and a preflight still naming /v1/checkout/sessions
        // would be telling the owner his shop does something it stopped doing.
        ->and($dry['url'])->toBe('https://api.stripe.com/v1/payment_intents')
        // Stripe's API is form-encoded with bracketed nesting, and the amount
        // is already integer minor units. Both of those are things that are
        // silently wrong until a real payment is attempted.
        ->and($dry['body'])->toContain('amount=25000')
        ->and($dry['body'])->toContain('payment_method_types%5B0%5D=card')
        ->and($dry['sample_total_fils'])->toBe(25000);
});

it('shows which host the test/live switch actually selected', function () {
    // Tamara is the one gateway with two hosts, and a sandbox key pointed at
    // the live host is the commonest go-live failure. The dry run is where the
    // owner can see which one he is on.
    pfProvider('tamara', [
        'api_token' => PF_TAMARA_TOKEN,
        'notification_token' => 'notify-token-preflight',
        'webhook_secret' => 'whsec-url-tamara-0123456789ab',
    ], 'test');

    expect(app(GatewayPreflight::class)->inspect('tamara')['dry_run']['url'])
        ->toContain('api-sandbox.tamara.co');

    PaymentProvider::query()->delete();

    pfProvider('tamara', [
        'api_token' => PF_TAMARA_TOKEN,
        'notification_token' => 'notify-token-preflight',
        'webhook_secret' => 'whsec-url-tamara-0123456789ab',
    ], 'live');

    expect(app(GatewayPreflight::class)->inspect('tamara')['dry_run']['url'])
        ->toContain('api.tamara.co')
        ->not->toContain('sandbox');
});

it('leaves nothing behind: no order, no payment, no reference on anything', function () {
    pfStripe();

    $orders = Order::withTrashed()->count();

    app(GatewayPreflight::class)->inspectAll();

    // The sample order is synthetic and unsaved, and every gateway gives up
    // before it writes a provider reference because the fake answers 503.
    expect(Order::withTrashed()->count())->toBe($orders)
        ->and(\App\Models\Payment::count())->toBe(0)
        ->and(\App\Models\PaymentEvent::count())->toBe(0);
});

it('reports cash on delivery as a gateway that calls nobody, instead of as a failure', function () {
    PaymentProvider::create(['id' => 'cod', 'enabled' => true, 'mode' => 'test', 'position' => 0]);
    app(GatewayCredentials::class)->forget();

    $result = app(GatewayPreflight::class)->inspect('cod');

    expect($result['configured'])->toBeTrue()
        ->and($result['blocked_by'])->toBe([])
        ->and($result['webhook_url'])->toBeNull()
        ->and($result['dry_run']['ran'])->toBeTrue()
        ->and($result['dry_run']['sent'])->toBeFalse();
});

it('does not try to dry-run a gateway with nothing in it', function () {
    PaymentProvider::create(['id' => 'tabby', 'enabled' => true, 'mode' => 'test', 'position' => 1]);
    app(GatewayCredentials::class)->forget();

    $dry = app(GatewayPreflight::class)->inspect('tabby')['dry_run'];

    expect($dry['ran'])->toBeFalse()
        ->and($dry['why'])->toContain('credentials');
});

it('answers "not a gateway this build ships" rather than inventing one', function () {
    expect(app(GatewayPreflight::class)->inspect('paypal')['known'])->toBeFalse();
});

/* ------------------------------------------------------------------ no leakage */

it('never prints a credential in the dry run, in any gateway, in any field', function () {
    pfStripe();
    pfProvider('tabby', [
        'public_key' => 'pk_test_visible',
        'secret_key' => PF_TABBY_SECRET,
        'merchant_code' => 'AE',
        'webhook_secret' => 'whsec-url-tabby-0123456789abc',
    ]);
    pfProvider('tamara', [
        'api_token' => PF_TAMARA_TOKEN,
        'notification_token' => 'notify-token-preflight',
        'webhook_secret' => 'whsec-url-tamara-0123456789ab',
    ]);

    // Everything the endpoint would return, flattened. The Authorization
    // header is the obvious place a key rides; the point of checking the whole
    // payload is that it is not the only one.
    $everything = json_encode(app(GatewayPreflight::class)->inspectAll());

    foreach ([PF_STRIPE_SECRET, PF_STRIPE_WHSEC, PF_TABBY_SECRET, PF_TAMARA_TOKEN] as $secret) {
        expect($everything)->not->toContain($secret);
    }

    // And the redaction is visible rather than silent -- a header that simply
    // vanished would read as a gateway that sends no credentials at all.
    expect($everything)->toContain('secret_key as stored')
        ->and($everything)->toContain('api_token as stored');
});

it('still shows the fields that are not secret, or the dry run would be useless', function () {
    pfStripe();

    $dry = app(GatewayPreflight::class)->inspect('stripe')['dry_run'];

    // The publishable key is safe to show by definition, and the body has to
    // be readable or there is nothing to check.
    expect($dry['headers'])->toHaveKey('Authorization')
        ->and($dry['body'])->toContain('PREFLIGHT-STRIPE');

    /*
     * AND THE BUYER'S EMAIL IS NO LONGER IN IT, which is a change worth
     * pinning rather than merely tolerating.
     *
     * The Checkout session carried `customer_email` because Stripe's hosted
     * page needed something to prefill. There is no hosted page now: the
     * shopper's details are already on our own form, and what reaches Stripe
     * from them is `billing_details` handed to confirmCardPayment by the
     * browser, alongside the card and from the same iframe.
     *
     * So the request this SERVER makes to open a payment carries no buyer
     * personal data at all — an order reference, an amount and a currency.
     * That is the property being asserted. A future change that puts the email
     * back into the intent payload should have to come here and say why.
     */
    expect($dry['body'])->not->toContain('preflight%40example.invalid');
    expect($dry['body'])->not->toContain('preflight@example.invalid');
});
