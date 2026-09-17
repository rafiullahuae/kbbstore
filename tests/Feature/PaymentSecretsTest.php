<?php

/**
 * Phase 11 — gateway secrets must not leave the server.
 *
 * /api/settings is public and unauthenticated, and it returned the entire
 * settings table to anyone who asked until the PUBLIC_KEYS allowlist landed.
 * A leak of exactly this kind has already shipped here once, which is why the
 * gateway credentials are not in `settings` at all — they are in
 * `payment_providers.config`, encrypted.
 *
 * These tests assert the absence of specific strings rather than the shape of
 * a response, the same way ApiSecurityTest does, because a failure here is a
 * live Stripe key in somebody's logs and not a broken page.
 */

use App\Http\Controllers\Admin\PaymentsApiController;
use App\Models\PaymentProvider;
use App\Models\Setting;
use App\Services\Payments\GatewayCredentials;
use App\Services\Payments\GatewayRegistry;

/** Values distinctive enough that finding them anywhere is unambiguous. */
const LIVE_STRIPE_SECRET = 'sk_live_KBBLEAKCANARY000000001';
const LIVE_STRIPE_WHSEC = 'whsec_KBBLEAKCANARY000000002';
const LIVE_TABBY_SECRET = 'sk_KBBLEAKCANARY000000003';
const LIVE_TAMARA_NOTIFY = 'tamara_notify_KBBLEAKCANARY04';

const ALL_CANARIES = [
    LIVE_STRIPE_SECRET,
    LIVE_STRIPE_WHSEC,
    LIVE_TABBY_SECRET,
    LIVE_TAMARA_NOTIFY,
];

beforeEach(function () {
    PaymentProvider::query()->delete();
    app(GatewayCredentials::class)->forget();

    $seed = [
        'stripe' => [
            'publishable_key' => 'pk_live_safe_to_show',
            'secret_key' => LIVE_STRIPE_SECRET,
            'webhook_signing_secret' => LIVE_STRIPE_WHSEC,
            'webhook_secret' => 'whsec-url-stripe-canary-000000',
        ],
        'tabby' => [
            'public_key' => 'pk_safe',
            'secret_key' => LIVE_TABBY_SECRET,
            'merchant_code' => 'AE',
            'webhook_secret' => 'whsec-url-tabby-canary-0000000',
        ],
        'tamara' => [
            'api_token' => 'tamara_api_canary',
            'notification_token' => LIVE_TAMARA_NOTIFY,
            'webhook_secret' => 'whsec-url-tamara-canary-000000',
        ],
    ];

    foreach ($seed as $id => $config) {
        $row = PaymentProvider::create([
            'id' => $id, 'title' => ucfirst($id), 'enabled' => true, 'mode' => 'live', 'position' => 1,
        ]);

        $row->config = $config;
        $row->save();
    }

    app(GatewayCredentials::class)->forget();
});

/* ------------------------------------------------------- the public API ---- */

it('never exposes a gateway secret through the public settings endpoint', function () {
    $raw = $this->getJson('/api/settings')->assertOk()->getContent();

    foreach (ALL_CANARIES as $canary) {
        expect($raw)->not->toContain($canary);
    }
});

it('keeps every gateway config key out of the settings allowlist', function () {
    // Setting::map() memoises in a process-level static, so assert on the
    // allowlist directly as well as on a response -- the same reasoning
    // ApiSecurityTest gives for admin_path.
    $allowed = (new ReflectionClass(App\Http\Controllers\Api\SettingController::class))
        ->getConstant('PUBLIC_KEYS');

    expect($allowed)->toBeArray();

    $configKeys = app(GatewayRegistry::class)->all()
        ->flatMap(fn ($g) => array_keys($g->configSchema()))
        ->unique()
        ->values()
        ->all();

    // Every credential field name -- secret_key, api_token, notification_token,
    // webhook_signing_secret and the rest -- must be absent from the allowlist.
    foreach ($configKeys as $key) {
        expect($allowed)->not->toContain($key);
    }

    // And the allowlist must not have grown a payment-shaped key by any other
    // name. Fails closed: a new secret-looking setting has to be noticed here.
    foreach ($allowed as $key) {
        expect($key)->not->toContain('secret')
            ->and($key)->not->toContain('token')
            ->and($key)->not->toContain('_key');
    }
});

it('does not write gateway credentials into the settings table at all', function () {
    // The defence that survives a rewrite of SettingController: if the value is
    // not in `settings`, no allowlist decision can leak it.
    $values = Setting::query()->pluck('value')->implode(' ');

    foreach (ALL_CANARIES as $canary) {
        expect($values)->not->toContain($canary);
    }

    expect(Setting::query()->count())->toBeGreaterThan(0);   // the table is not simply empty
});

it('does not leak a secret through any other public api endpoint', function () {
    foreach (['/api/settings', '/api/products', '/api/posts', '/api/reviews'] as $url) {
        $raw = $this->getJson($url)->getContent();

        foreach (ALL_CANARIES as $canary) {
            expect($raw)->not->toContain($canary);
        }
    }
});

/* -------------------------------------------------------- the admin API ---- */

it('never returns a secret through the admin payments screen either', function () {
    $response = app(PaymentsApiController::class)->show();
    $raw = $response->getContent();

    foreach (ALL_CANARIES as $canary) {
        expect($raw)->not->toContain($canary);
    }
});

it('reports that a secret is set without disclosing it', function () {
    $body = app(PaymentsApiController::class)->show()->getData(true);

    $stripe = collect($body['gateways'])->firstWhere('id', 'stripe');
    $secretField = collect($stripe['fields'])->firstWhere('key', 'secret_key');

    expect($secretField['type'])->toBe('secret')
        // Empty, not masked. A mask still discloses the length.
        ->and($secretField['value'])->toBe('')
        ->and($secretField['has_value'])->toBeTrue();

    // A non-secret field is shown normally, so the screen is still usable.
    $publishable = collect($stripe['fields'])->firstWhere('key', 'publishable_key');

    expect($publishable['value'])->toBe('pk_live_safe_to_show');
});

it('treats a blank secret on save as unchanged rather than a wipe', function () {
    $controller = app(PaymentsApiController::class);

    // Exactly what the screen posts back when someone edits the title only:
    // the secret boxes were rendered blank, so blank is what returns.
    $controller->save(new \Illuminate\Http\Request([
        'id' => 'stripe',
        'title' => 'Pay by card',
        'settings' => ['secret_key' => '', 'publishable_key' => 'pk_live_updated'],
    ]));

    app(GatewayCredentials::class)->forget();

    expect(app(GatewayCredentials::class)->get('stripe', 'secret_key'))
        ->toBe(LIVE_STRIPE_SECRET)
        ->and(app(GatewayCredentials::class)->get('stripe', 'publishable_key'))
        ->toBe('pk_live_updated');
});

it('refuses an unknown setting key rather than storing it', function () {
    $response = app(PaymentsApiController::class)->save(new \Illuminate\Http\Request([
        'id' => 'stripe',
        'settings' => ['secret_key' => 'sk_live_new', 'admin_path' => 'oops'],
    ]));

    expect($response->getStatusCode())->toBe(422);

    // And nothing was written -- not even the valid half of the payload.
    app(GatewayCredentials::class)->forget();
    expect(app(GatewayCredentials::class)->get('stripe', 'secret_key'))->toBe(LIVE_STRIPE_SECRET);
});

it('refuses to configure a gateway this build does not ship', function () {
    $response = app(PaymentsApiController::class)->save(new \Illuminate\Http\Request([
        'id' => 'paypal',
        'settings' => [],
    ]));

    expect($response->getStatusCode())->toBe(422);
});

it('stores credentials encrypted rather than as readable columns', function () {
    // Straight at the column, past the model's cast.
    $stored = \Illuminate\Support\Facades\DB::table('payment_providers')
        ->where('id', 'stripe')->value('config');

    expect($stored)->toBeString();

    foreach (ALL_CANARIES as $canary) {
        expect($stored)->not->toContain($canary);
    }
});

it('generates a webhook secret on first save so the url is never guessable', function () {
    PaymentProvider::query()->delete();
    app(GatewayCredentials::class)->forget();

    $response = app(PaymentsApiController::class)->save(new \Illuminate\Http\Request([
        'id' => 'stripe',
        'enabled' => true,
        'settings' => ['secret_key' => 'sk_test_fresh'],
    ]));

    $url = $response->getData(true)['webhook_url'];

    expect($url)->toBeString()
        ->and($url)->toContain('/api/payments/webhook/stripe/');

    app(GatewayCredentials::class)->forget();

    expect(strlen(app(GatewayCredentials::class)->get('stripe', 'webhook_secret')))
        ->toBeGreaterThanOrEqual(16);
});

/* ------------------------------------------- after an automatic connection -- */

/*
 * Store -> Payments -> Stripe -> Connect stores the same two Stripe secrets the
 * form stored, plus a handful of facts about the account. Those extra keys are
 * NOT in StripeGateway::configSchema() -- deliberately, so the preflight does
 * not report a shop that will never register a Connect application as
 * half-configured -- which means the loop in show() does not iterate them.
 *
 * That is exactly the sort of "it cannot reach them, so it is fine" argument
 * that stops being true when somebody later makes show() dump the whole config
 * blob for convenience. Pinned rather than reasoned about.
 */

function secretsConnectedStripe(): void
{
    PaymentProvider::query()->delete();

    $row = PaymentProvider::create([
        'id' => 'stripe', 'title' => 'Stripe', 'enabled' => true, 'mode' => 'live', 'position' => 1,
    ]);

    $row->config = [
        'secret_key' => LIVE_STRIPE_SECRET,
        'publishable_key' => 'pk_live_safe_to_show',
        'webhook_signing_secret' => LIVE_STRIPE_WHSEC,
        'webhook_secret' => 'whsec-url-stripe-canary-000000',
        'connect_account_id' => 'acct_CANARY',
        'connect_link' => 'key',
        'connect_client_id' => 'ca_CANARY',
        'account_name' => 'K Beauty Bliss',
        'account_currency' => 'AED',
        'charges_enabled' => '1',
        'webhook_endpoint_id' => 'we_CANARY',
        'webhook_endpoint_managed' => '1',
    ];

    $row->save();

    app(GatewayCredentials::class)->forget();
}

it('returns no secret from the payments screen after an automatic connection', function () {
    secretsConnectedStripe();

    $body = app(PaymentsApiController::class)->show()->getContent();

    expect($body)->not->toContain(LIVE_STRIPE_SECRET)
        ->and($body)->not->toContain(LIVE_STRIPE_WHSEC);

    // Still reports THAT they are stored, which is what the screen renders.
    $stripe = collect(json_decode($body, true)['gateways'])->firstWhere('id', 'stripe');

    $secretField = collect($stripe['fields'])->firstWhere('key', 'secret_key');

    expect($secretField['value'])->toBe('')
        ->and($secretField['has_value'])->toBeTrue();
});

it('does not put the connect bookkeeping into the settings table either', function () {
    secretsConnectedStripe();

    $values = Setting::query()->pluck('value')->implode(' ');

    foreach ([LIVE_STRIPE_SECRET, LIVE_STRIPE_WHSEC, 'we_CANARY', 'acct_CANARY'] as $canary) {
        expect($values)->not->toContain($canary);
    }
});
