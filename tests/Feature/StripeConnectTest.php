<?php

/**
 * Connecting and disconnecting Stripe — Lane EO.
 *
 * WHAT THIS FILE CAN AND CANNOT PROVE
 *
 * This project has no Stripe account and no keys, and a test that made a live
 * network call would be a test that fails on a laptop with no internet and
 * passes for the wrong reason on one with. So the HTTP client is faked
 * throughout and every assertion is about the REQUEST this shop would send —
 * method, URL, Authorization header, form body, and the order the calls are
 * made in — plus what is done with each shape of response.
 *
 * That proves the wire format, the ordering, the refusals, the rollback and
 * that no credential escapes. It does NOT prove that Stripe accepts these
 * requests: no test here has ever spoken to Stripe. The first real connection
 * is the first real evidence, and the screen is built to relay what Stripe says
 * rather than to claim success on its behalf.
 *
 * Http::preventStrayRequests() is on for every test. A call this file did not
 * explicitly fake is an error, which is what turns "refuses a live key without
 * contacting Stripe" into a real assertion instead of a hopeful one.
 */

use App\Models\AdminUser;
use App\Models\Order;
use App\Models\PaymentProvider;
use App\Services\Payments\GatewayCredentials;
use App\Services\Payments\StripeConnect;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

/** Distinctive enough that finding one anywhere is unambiguous. */
const CONNECT_TEST_KEY = 'sk_test_KBBCONNECTCANARY0000001';
const CONNECT_LIVE_KEY = 'sk_live_KBBCONNECTCANARY0000002';
const CONNECT_WHSEC = 'whsec_KBBCONNECTCANARY0000003';
const CONNECT_OAUTH_KEY = 'sk_test_KBBCONNECTCANARY0000004';

beforeEach(function () {
    PaymentProvider::query()->delete();
    app(GatewayCredentials::class)->forget();
    Http::preventStrayRequests();

    // Exactly the mounting routes/payments-connect.php asks the integrator for:
    // the existing admin-api group, guard included. ONE middleware() call —
    // RouteRegistrar::middleware() REPLACES rather than appends, so a second
    // call here would silently drop `auth:admin` and this file would be testing
    // an unguarded group.
    Route::middleware(['web', 'auth:admin', \App\Http\Middleware\NoStoreAdminApi::class])
        ->prefix('admin-api')
        ->group(base_path('routes/payments-connect.php'));
});

/* ========================================================== the harness ==== */

function connectAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'Connect Admin',
        'email' => 'connect-' . uniqid() . '@example.test',
        'password' => 'password-long-enough',
        'role' => 'owner',
    ]);
}

function connectService(): StripeConnect
{
    app(GatewayCredentials::class)->forget();

    return app(StripeConnect::class);
}

/** The stripe row, with whatever config the test needs. */
function connectRow(array $config = [], string $mode = 'test', bool $enabled = false): PaymentProvider
{
    $row = PaymentProvider::firstOrNew(['id' => 'stripe']);
    $row->fill(['title' => 'Credit or debit card', 'enabled' => $enabled, 'mode' => $mode, 'position' => 1]);
    $row->save();

    if ($config !== []) {
        $row->config = $config;
        $row->save();
    }

    app(GatewayCredentials::class)->forget();

    return $row;
}

function connectStoredConfig(): array
{
    $row = PaymentProvider::find('stripe');

    return $row === null || ! is_array($row->config) ? [] : $row->config;
}

/**
 * A Stripe that answers.
 *
 * Routed on method plus path rather than on a URL glob, because
 * `GET /v1/webhook_endpoints?limit=100` and `POST /v1/webhook_endpoints` are
 * the same URL prefix and mean opposite things — a glob fake would answer the
 * list with the creation response and the test would pass on nothing.
 *
 * @param  array<string, callable|array>  $routes  "METHOD /path" => body|closure
 */
function stripeFake(array $routes): void
{
    Http::fake(function (Illuminate\Http\Client\Request $request) use ($routes) {
        $path = parse_url($request->url(), PHP_URL_PATH) ?: '';
        $key = strtoupper($request->method()) . ' ' . $path;

        if (! array_key_exists($key, $routes)) {
            return Http::response(
                ['error' => ['message' => 'nothing faked for ' . $key]],
                418,
            );
        }

        $answer = $routes[$key];

        return is_callable($answer) ? $answer($request) : Http::response($answer, 200);
    });
}

/** The account body Stripe returns for a healthy AED merchant. */
function stripeAccountBody(array $overrides = []): array
{
    return array_merge([
        'id' => 'acct_KBB123',
        'object' => 'account',
        'country' => 'AE',
        'default_currency' => 'aed',
        'charges_enabled' => true,
        'livemode' => false,
        'email' => 'owner@kbeautybliss.test',
        'business_profile' => ['name' => 'K Beauty Bliss'],
    ], $overrides);
}

/** The whole happy path, faked. */
function stripeHappyFake(array $accountOverrides = [], array $existingEndpoints = []): void
{
    stripeFake([
        'GET /v1/account' => stripeAccountBody($accountOverrides),
        'GET /v1/webhook_endpoints' => ['object' => 'list', 'data' => $existingEndpoints],
        'POST /v1/webhook_endpoints' => [
            'id' => 'we_KBBNEW1',
            'object' => 'webhook_endpoint',
            'secret' => CONNECT_WHSEC,
            'enabled_events' => StripeConnect::EVENTS,
        ],
    ]);
}

/* ================================================ refusals: nothing stored == */

describe('it refuses before it writes', function () {
    it('names the publishable key for what it is and contacts Stripe not at all', function () {
        connectRow();

        $result = connectService()->connectWithKey('pk_test_something', 'test');

        expect($result['ok'])->toBeFalse()
            ->and($result['step'])->toBe('key')
            ->and($result['error'])->toContain('publishable key');

        expect(connectStoredConfig())->not->toHaveKey('secret_key');
        // preventStrayRequests() would have thrown on any call; this says the
        // refusal is local, so a typo never leaves the building.
        Http::assertNothingSent();
    });

    it('refuses a key that is not a key at all', function () {
        connectRow();

        $result = connectService()->connectWithKey('my stripe key is sk_test_abc', 'test');

        expect($result['ok'])->toBeFalse()->and($result['step'])->toBe('key');
        expect(connectStoredConfig())->not->toHaveKey('secret_key');
        Http::assertNothingSent();
    });

    it('catches a LIVE key pasted while the tab says Sandbox, and says which is which', function () {
        connectRow([], 'test');

        $result = connectService()->connectWithKey(CONNECT_LIVE_KEY, 'test');

        expect($result['ok'])->toBeFalse()
            ->and($result['step'])->toBe('mode')
            ->and($result['error'])->toContain('LIVE key')
            ->and($result['error'])->toContain('Sandbox');

        expect(connectStoredConfig())->not->toHaveKey('secret_key');
        Http::assertNothingSent();
    });

    it('catches a TEST key pasted while the tab says Live', function () {
        connectRow([], 'live');

        $result = connectService()->connectWithKey(CONNECT_TEST_KEY, 'live');

        expect($result['ok'])->toBeFalse()
            ->and($result['step'])->toBe('mode')
            ->and($result['error'])->toContain('TEST key');

        expect(connectStoredConfig())->not->toHaveKey('secret_key');
        Http::assertNothingSent();
    });

    it('reads the tab mode off the stored row when the caller names none', function () {
        connectRow([], 'live');

        // No third argument: the row says live, the key says test.
        $result = connectService()->connectWithKey(CONNECT_TEST_KEY);

        expect($result['ok'])->toBeFalse()->and($result['step'])->toBe('mode');
        Http::assertNothingSent();
    });

    it('relays Stripe\'s own words when Stripe rejects the key, and stores nothing', function () {
        connectRow();

        stripeFake([
            'GET /v1/account' => fn () => Http::response([
                'error' => ['message' => 'Invalid API Key provided: sk_test_***CANARY', 'type' => 'invalid_request_error'],
            ], 401),
        ]);

        $result = connectService()->connectWithKey(CONNECT_TEST_KEY, 'test');

        expect($result['ok'])->toBeFalse()
            ->and($result['step'])->toBe('account')
            ->and($result['error'])->toBe('Invalid API Key provided: sk_test_***CANARY');

        expect(connectStoredConfig())->not->toHaveKey('secret_key');

        // One call, and it was the read. Nothing was created before the key
        // was known to work.
        Http::assertSentCount(1);
    });

    it('stores nothing when the webhook endpoint cannot be created', function () {
        connectRow();

        stripeFake([
            'GET /v1/account' => stripeAccountBody(),
            'GET /v1/webhook_endpoints' => ['object' => 'list', 'data' => []],
            'POST /v1/webhook_endpoints' => fn () => Http::response([
                'error' => ['message' => 'This restricted key does not have write access to webhook endpoints.'],
            ], 403),
        ]);

        $result = connectService()->connectWithKey(CONNECT_TEST_KEY, 'test');

        expect($result['ok'])->toBeFalse()
            ->and($result['step'])->toBe('webhook')
            ->and($result['error'])->toContain('restricted key');

        $config = connectStoredConfig();

        expect($config)->not->toHaveKey('secret_key')
            ->and($config)->not->toHaveKey('webhook_signing_secret')
            ->and($config)->not->toHaveKey('connect_account_id');
    });

    it('removes an endpoint Stripe created without a signing secret rather than keeping a deaf one', function () {
        connectRow();

        stripeFake([
            'GET /v1/account' => stripeAccountBody(),
            'GET /v1/webhook_endpoints' => ['object' => 'list', 'data' => []],
            // No `secret`. Nothing could ever be verified against this.
            'POST /v1/webhook_endpoints' => ['id' => 'we_NOSECRET', 'object' => 'webhook_endpoint'],
            'DELETE /v1/webhook_endpoints/we_NOSECRET' => ['id' => 'we_NOSECRET', 'deleted' => true],
        ]);

        $result = connectService()->connectWithKey(CONNECT_TEST_KEY, 'test');

        expect($result['ok'])->toBeFalse()->and($result['step'])->toBe('webhook');
        expect(connectStoredConfig())->not->toHaveKey('secret_key');

        Http::assertSent(fn (Illuminate\Http\Client\Request $r) => $r->method() === 'DELETE'
            && str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/v1/webhook_endpoints/we_NOSECRET'));
    });
});

/* ================================================== the requests we send ==== */

describe('the requests it sends to Stripe', function () {
    it('authenticates with the pasted key and never with the stored one', function () {
        // A stale key is already on the row. The verification must use what was
        // just pasted, or an owner replacing a rotated key would be told his new
        // one works when it was never tried.
        connectRow(['secret_key' => 'sk_test_STALE0000000000000000']);

        stripeHappyFake();

        connectService()->connectWithKey(CONNECT_TEST_KEY, 'test');

        Http::assertSent(fn (Illuminate\Http\Client\Request $r) => $r->method() === 'GET'
            && parse_url($r->url(), PHP_URL_PATH) === '/v1/account'
            && $r->header('Authorization') === ['Bearer ' . CONNECT_TEST_KEY]);
    });

    it('creates the endpoint with exactly the events StripeGateway acts on', function () {
        connectRow();

        stripeHappyFake();

        $result = connectService()->connectWithKey(CONNECT_TEST_KEY, 'test');

        expect($result['ok'])->toBeTrue();

        $sent = null;

        Http::assertSent(function (Illuminate\Http\Client\Request $r) use (&$sent) {
            if ($r->method() !== 'POST' || parse_url($r->url(), PHP_URL_PATH) !== '/v1/webhook_endpoints') {
                return false;
            }

            $sent = $r->data();

            return true;
        });

        // Form-encoded with bracketed indices, which is Stripe's array format.
        expect($sent['url'])->toBe($result['webhook_url']);

        foreach (StripeConnect::EVENTS as $i => $event) {
            expect($sent['enabled_events[' . $i . ']'])->toBe($event);
        }

        // Exactly those four, no fifth.
        expect(array_key_exists('enabled_events[' . count(StripeConnect::EVENTS) . ']', $sent))->toBeFalse();
    });

    it('subscribes to precisely the events the gateway handles, no more and no fewer', function () {
        // Pinned against the gateway itself rather than against a list copied
        // from a changelog. An event added to handleWebhook() and not to
        // EVENTS is a payment this shop never hears about.
        $source = file_get_contents(base_path('app/Services/Payments/Gateways/StripeGateway.php'));

        $handled = [];

        // token_get_all, not a regex over the file: the doc comment at the top
        // of StripeGateway names several of these events in prose, and a regex
        // would count those as code.
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                $value = trim($token[1], "'\"");

                if (str_starts_with($value, 'checkout.session.') || str_starts_with($value, 'payment_intent.')) {
                    $handled[$value] = true;
                }
            }
        }

        expect(array_keys($handled))->toEqualCanonicalizing(StripeConnect::EVENTS);
    });

    it('lists the account\'s endpoints before creating one, so it never makes a second', function () {
        connectRow();
        stripeHappyFake();

        connectService()->connectWithKey(CONNECT_TEST_KEY, 'test');

        Http::assertSent(fn (Illuminate\Http\Client\Request $r) => $r->method() === 'GET'
            && parse_url($r->url(), PHP_URL_PATH) === '/v1/webhook_endpoints'
            && str_contains($r->url(), 'limit=100'));
    });

    it('reuses the endpoint it made last time instead of creating a twin', function () {
        // The reconnect case: same shop, same URL, signing secret already held.
        connectRow([
            'secret_key' => CONNECT_TEST_KEY,
            'webhook_secret' => 'whsec-stripe-reconnect-000000000',
            'webhook_signing_secret' => CONNECT_WHSEC,
            'webhook_endpoint_id' => 'we_KBBOLD1',
            'webhook_endpoint_managed' => '1',
        ]);

        $url = connectService()->webhookUrl();

        stripeFake([
            'GET /v1/account' => stripeAccountBody(),
            'GET /v1/webhook_endpoints' => ['object' => 'list', 'data' => [[
                'id' => 'we_KBBOLD1',
                'url' => $url,
                'enabled_events' => StripeConnect::EVENTS,
            ]]],
        ]);

        $result = connectService()->connectWithKey(CONNECT_TEST_KEY, 'test');

        expect($result['ok'])->toBeTrue()
            ->and($result['webhook_action'])->toBe('reused');

        // The one assertion this test exists for: no creation, so no double
        // delivery of every webhook for the rest of the shop's life.
        Http::assertNotSent(fn (Illuminate\Http\Client\Request $r) => $r->method() === 'POST'
            && parse_url($r->url(), PHP_URL_PATH) === '/v1/webhook_endpoints');

        expect(connectStoredConfig()['webhook_signing_secret'])->toBe(CONNECT_WHSEC);
    });

    it('adds the missing events to an endpoint it is reusing', function () {
        connectRow([
            'webhook_secret' => 'whsec-stripe-reconnect-000000000',
            'webhook_signing_secret' => CONNECT_WHSEC,
            'webhook_endpoint_id' => 'we_KBBOLD1',
            'webhook_endpoint_managed' => '1',
        ]);

        $url = connectService()->webhookUrl();

        stripeFake([
            'GET /v1/account' => stripeAccountBody(),
            'GET /v1/webhook_endpoints' => ['object' => 'list', 'data' => [[
                'id' => 'we_KBBOLD1',
                'url' => $url,
                // Missing the two failure events.
                'enabled_events' => ['checkout.session.completed', 'checkout.session.async_payment_succeeded'],
            ]]],
            'POST /v1/webhook_endpoints/we_KBBOLD1' => ['id' => 'we_KBBOLD1', 'enabled_events' => StripeConnect::EVENTS],
        ]);

        $result = connectService()->connectWithKey(CONNECT_TEST_KEY, 'test');

        expect($result['ok'])->toBeTrue()
            ->and($result['webhook_action'])->toBe('reused_events_updated');

        Http::assertSent(fn (Illuminate\Http\Client\Request $r) => $r->method() === 'POST'
            && parse_url($r->url(), PHP_URL_PATH) === '/v1/webhook_endpoints/we_KBBOLD1'
            && ($r->data()['enabled_events[3]'] ?? null) === 'payment_intent.payment_failed');
    });

    it('leaves an endpoint alone when it already subscribes to everything', function () {
        connectRow([
            'webhook_secret' => 'whsec-stripe-reconnect-000000000',
            'webhook_signing_secret' => CONNECT_WHSEC,
            'webhook_endpoint_id' => 'we_KBBOLD1',
            'webhook_endpoint_managed' => '1',
        ]);

        $url = connectService()->webhookUrl();

        stripeFake([
            'GET /v1/account' => stripeAccountBody(),
            'GET /v1/webhook_endpoints' => ['object' => 'list', 'data' => [[
                'id' => 'we_KBBOLD1', 'url' => $url, 'enabled_events' => ['*'],
            ]]],
        ]);

        expect(connectService()->connectWithKey(CONNECT_TEST_KEY, 'test')['webhook_action'])->toBe('reused');
    });

    it('replaces an endpoint on this shop\'s URL whose signing secret it cannot read back', function () {
        // The owner set the webhook up by hand under the old flow, and the
        // signing secret was never stored. Stripe shows an endpoint's secret
        // ONLY when it creates it, so that endpoint can verify nothing.
        connectRow(['webhook_secret' => 'whsec-stripe-byhand-0000000000']);

        $url = connectService()->webhookUrl();

        stripeFake([
            'GET /v1/account' => stripeAccountBody(),
            'GET /v1/webhook_endpoints' => ['object' => 'list', 'data' => [[
                'id' => 'we_BYHAND', 'url' => $url, 'enabled_events' => ['*'],
            ]]],
            'DELETE /v1/webhook_endpoints/we_BYHAND' => ['id' => 'we_BYHAND', 'deleted' => true],
            'POST /v1/webhook_endpoints' => [
                'id' => 'we_KBBNEW1', 'secret' => CONNECT_WHSEC, 'enabled_events' => StripeConnect::EVENTS,
            ],
        ]);

        $result = connectService()->connectWithKey(CONNECT_TEST_KEY, 'test');

        expect($result['ok'])->toBeTrue()
            ->and($result['webhook_action'])->toBe('replaced');

        // Said out loud, because something in his Stripe account changed.
        expect(implode(' ', $result['warnings']))->toContain('replaced');

        expect(connectStoredConfig()['webhook_endpoint_id'])->toBe('we_KBBNEW1');
    });

    it('never touches an endpoint pointing anywhere but this shop', function () {
        connectRow(['webhook_secret' => 'whsec-stripe-other-00000000000']);

        stripeFake([
            'GET /v1/account' => stripeAccountBody(),
            'GET /v1/webhook_endpoints' => ['object' => 'list', 'data' => [
                ['id' => 'we_HIS_OWN', 'url' => 'https://his-other-app.example/stripe', 'enabled_events' => ['*']],
                ['id' => 'we_ZAPIER', 'url' => 'https://hooks.zapier.test/abc', 'enabled_events' => ['*']],
            ]],
            'POST /v1/webhook_endpoints' => [
                'id' => 'we_KBBNEW1', 'secret' => CONNECT_WHSEC, 'enabled_events' => StripeConnect::EVENTS,
            ],
        ]);

        expect(connectService()->connectWithKey(CONNECT_TEST_KEY, 'test')['ok'])->toBeTrue();

        Http::assertNotSent(fn (Illuminate\Http\Client\Request $r) => $r->method() === 'DELETE');
    });
});

/* ====================================================== what it reports ===== */

describe('what it reports back', function () {
    it('says what it connected to, not merely that it connected', function () {
        connectRow();
        stripeHappyFake();

        $result = connectService()->connectWithKey(CONNECT_TEST_KEY, 'test');

        expect($result['ok'])->toBeTrue()
            ->and($result['account']['id'])->toBe('acct_KBB123')
            ->and($result['account']['name'])->toBe('K Beauty Bliss')
            ->and($result['account']['country'])->toBe('AE')
            ->and($result['account']['currency'])->toBe('AED')
            ->and($result['account']['charges_enabled'])->toBeTrue()
            ->and($result['account']['livemode'])->toBeFalse();
    });

    it('warns when the Stripe account settles in a different currency from the shop', function () {
        connectRow();
        stripeHappyFake(['default_currency' => 'usd']);

        $result = connectService()->connectWithKey(CONNECT_TEST_KEY, 'test');

        expect($result['ok'])->toBeTrue();
        expect(implode(' ', $result['warnings']))->toContain('USD');
    });

    it('warns when Stripe has not enabled charges on the account yet', function () {
        connectRow();
        stripeHappyFake(['charges_enabled' => false]);

        $result = connectService()->connectWithKey(CONNECT_TEST_KEY, 'test');

        expect($result['ok'])->toBeTrue();
        expect(implode(' ', $result['warnings']))->toContain('activation');
    });

    it('does not switch the gateway on by itself', function () {
        // Connecting proves the credentials work. Offering cards at the till is
        // a separate decision and stays on the toggle the owner already has.
        connectRow([], 'test', enabled: false);
        stripeHappyFake();

        connectService()->connectWithKey(CONNECT_TEST_KEY, 'test');

        expect((bool) PaymentProvider::find('stripe')->enabled)->toBeFalse();
    });

    it('generates this shop\'s webhook URL when connecting before the tab was ever saved', function () {
        connectRow();
        stripeHappyFake();

        $result = connectService()->connectWithKey(CONNECT_TEST_KEY, 'test');

        expect($result['webhook_url'])->toContain('/api/payments/webhook/stripe/');
        expect(connectStoredConfig()['webhook_secret'])->toStartWith('whsec-stripe-');
    });
});

/* ========================================================= disconnecting ==== */

describe('disconnecting', function () {
    function connectedShop(array $overrides = []): void
    {
        connectRow(array_merge([
            'secret_key' => CONNECT_TEST_KEY,
            'publishable_key' => 'pk_test_visible',
            'webhook_signing_secret' => CONNECT_WHSEC,
            'webhook_secret' => 'whsec-stripe-connected-0000000',
            'webhook_endpoint_id' => 'we_KBBNEW1',
            'webhook_endpoint_managed' => '1',
            'connect_account_id' => 'acct_KBB123',
            'connect_link' => 'key',
            'account_name' => 'K Beauty Bliss',
        ], $overrides), 'test', enabled: true);
    }

    it('deletes the endpoint it created and clears every credential', function () {
        connectedShop();

        $url = connectService()->webhookUrl();

        stripeFake([
            'GET /v1/webhook_endpoints/we_KBBNEW1' => ['id' => 'we_KBBNEW1', 'url' => $url],
            'DELETE /v1/webhook_endpoints/we_KBBNEW1' => ['id' => 'we_KBBNEW1', 'deleted' => true],
        ]);

        $result = connectService()->disconnect();

        expect($result['ok'])->toBeTrue()
            ->and($result['was_connected'])->toBeTrue()
            ->and($result['steps'])->toContain('webhook_deleted')
            ->and($result['steps'])->toContain('credentials_cleared');

        $config = connectStoredConfig();

        foreach (['secret_key', 'publishable_key', 'webhook_signing_secret', 'webhook_secret',
            'webhook_endpoint_id', 'connect_account_id', 'connect_link', 'account_name'] as $key) {
            expect($config)->not->toHaveKey($key);
        }

        // And the gateway stops being offered.
        expect((bool) PaymentProvider::find('stripe')->enabled)->toBeFalse();
    });

    it('checks the endpoint is still this shop\'s before deleting it', function () {
        // A recorded id whose URL has moved is not the endpoint we think it is,
        // and deleting it would be deleting something of his.
        connectedShop();

        stripeFake([
            'GET /v1/webhook_endpoints/we_KBBNEW1' => [
                'id' => 'we_KBBNEW1', 'url' => 'https://somewhere-else.example/hook',
            ],
        ]);

        $result = connectService()->disconnect();

        expect($result['steps'])->toContain('webhook_not_ours');
        Http::assertNotSent(fn (Illuminate\Http\Client\Request $r) => $r->method() === 'DELETE');

        // Cleared locally regardless. Disconnect means disconnect.
        expect(connectStoredConfig())->not->toHaveKey('secret_key');
    });

    it('never deletes an endpoint this screen did not create', function () {
        connectedShop(['webhook_endpoint_managed' => '0']);

        // No fake at all: preventStrayRequests() turns any call into a failure,
        // which is the strongest form this assertion can take.
        $result = connectService()->disconnect();

        expect($result['ok'])->toBeTrue()
            ->and($result['steps'])->toContain('webhook_left_alone');

        Http::assertNothingSent();
        expect(connectStoredConfig())->not->toHaveKey('secret_key');
    });

    it('treats an endpoint Stripe has already lost as done', function () {
        connectedShop();

        stripeFake([
            'GET /v1/webhook_endpoints/we_KBBNEW1' => fn () => Http::response(
                ['error' => ['message' => 'No such webhook endpoint']],
                404,
            ),
        ]);

        $result = connectService()->disconnect();

        expect($result['ok'])->toBeTrue()->and($result['steps'])->toContain('webhook_already_gone');
    });

    it('clears the credentials even when Stripe cannot be reached', function () {
        // The alternative — refusing to disconnect because Stripe had a bad
        // minute — is a Disconnect button that does not disconnect.
        connectedShop();

        stripeFake([
            'GET /v1/webhook_endpoints/we_KBBNEW1' => fn () => Http::response('gateway timeout', 504),
        ]);

        $result = connectService()->disconnect();

        expect($result['ok'])->toBeTrue()
            ->and($result['steps'])->toContain('webhook_unreachable')
            ->and(implode(' ', $result['warnings']))->toContain('Developers -> Webhooks');

        expect(connectStoredConfig())->not->toHaveKey('secret_key');
    });

    it('is idempotent: pressing it twice, or on a shop that was never connected, is not an error', function () {
        connectedShop();

        $url = connectService()->webhookUrl();

        stripeFake([
            'GET /v1/webhook_endpoints/we_KBBNEW1' => ['id' => 'we_KBBNEW1', 'url' => $url],
            'DELETE /v1/webhook_endpoints/we_KBBNEW1' => ['id' => 'we_KBBNEW1', 'deleted' => true],
        ]);

        expect(connectService()->disconnect()['ok'])->toBeTrue();

        $sentAfterFirst = count(Http::recorded());

        $second = connectService()->disconnect();

        expect($second['ok'])->toBeTrue()
            ->and($second['was_connected'])->toBeFalse()
            ->and($second['steps'])->toContain('credentials_cleared');

        // The second press reached Stripe not at all: there was nothing left
        // to remove, and a second DELETE of a deleted endpoint would be noise
        // in his dashboard's logs.
        expect(count(Http::recorded()))->toBe($sentAfterFirst);

        // And a third, on a shop with no row at all.
        PaymentProvider::query()->delete();
        app(GatewayCredentials::class)->forget();

        expect(connectService()->disconnect()['ok'])->toBeTrue();
    });

    it('keeps the Connect application id, which is configuration rather than a credential', function () {
        connectedShop(['connect_client_id' => 'ca_KBBPLATFORM1']);

        stripeFake([
            'GET /v1/webhook_endpoints/we_KBBNEW1' => fn () => Http::response(['error' => ['message' => 'gone']], 404),
        ]);

        connectService()->disconnect();

        expect(connectStoredConfig()['connect_client_id'])->toBe('ca_KBBPLATFORM1');
    });

    it('deauthorises at Stripe when the connection was made through Connect', function () {
        connectedShop([
            'connect_link' => 'oauth',
            'connect_client_id' => 'ca_KBBPLATFORM1',
            'webhook_endpoint_managed' => '0',
        ]);

        stripeFake([
            'POST /v1/oauth/deauthorize' => ['stripe_user_id' => 'acct_KBB123'],
        ]);

        $result = connectService()->disconnect();

        expect($result['steps'])->toContain('deauthorized');

        Http::assertSent(fn (Illuminate\Http\Client\Request $r) => $r->method() === 'POST'
            && parse_url($r->url(), PHP_URL_PATH) === '/v1/oauth/deauthorize'
            && $r->data()['client_id'] === 'ca_KBBPLATFORM1'
            && $r->data()['stripe_user_id'] === 'acct_KBB123');
    });

    it('still clears the credentials when the deauthorise is refused', function () {
        connectedShop([
            'connect_link' => 'oauth',
            'connect_client_id' => 'ca_KBBPLATFORM1',
            'webhook_endpoint_managed' => '0',
        ]);

        stripeFake([
            'POST /v1/oauth/deauthorize' => fn () => Http::response(
                ['error' => ['message' => 'This application is not connected to that account.']],
                400,
            ),
        ]);

        $result = connectService()->disconnect();

        expect($result['ok'])->toBeTrue()
            ->and($result['steps'])->toContain('deauthorize_failed');

        expect(connectStoredConfig())->not->toHaveKey('secret_key');
    });

    it('does not deauthorise a connection that was made by pasting a key', function () {
        connectedShop(['connect_client_id' => 'ca_KBBPLATFORM1', 'webhook_endpoint_managed' => '0']);

        connectService()->disconnect();

        Http::assertNothingSent();
    });
});

/* ====================================================== payments in flight == */

describe('payments in flight', function () {
    function inFlightOrder(array $attributes = []): Order
    {
        return Order::create(array_merge([
            'order_number' => 'SC-' . uniqid(),
            'email' => 'buyer@example.com',
            'status' => 'pending',
            'currency' => 'AED',
            'subtotal' => 25000,
            'total' => 25000,
            'payment_method' => 'stripe',
            'transaction_id' => 'cs_test_' . uniqid(),
        ], $attributes));
    }

    it('counts an order sent to Stripe that has had no answer', function () {
        inFlightOrder();

        expect(connectService()->inFlight()['count'])->toBe(1);
    });

    it('does not count an order that has been paid, cancelled or refunded', function () {
        inFlightOrder(['paid_at' => now()]);
        inFlightOrder(['status' => 'cancelled']);
        inFlightOrder(['status' => 'refunded']);
        inFlightOrder(['status' => 'completed']);
        inFlightOrder(['status' => 'failed']);

        expect(connectService()->inFlight()['count'])->toBe(0);
    });

    it('does not count an order that never reached Stripe, or one from another gateway', function () {
        inFlightOrder(['transaction_id' => null]);
        inFlightOrder(['payment_method' => 'tabby']);

        expect(connectService()->inFlight()['count'])->toBe(0);
    });

    it('does not count an abandoned checkout from last month', function () {
        // A Stripe Checkout session expires after 24 hours; a three-week-old
        // unpaid order is an abandoned cart, and counting it would make the
        // warning cry wolf on every shop with a history.
        $old = inFlightOrder();
        $old->forceFill(['created_at' => now()->subWeeks(3)])->save();

        expect(connectService()->inFlight()['count'])->toBe(0);
    });

    it('tells the screen which orders they are, before the owner confirms', function () {
        $order = inFlightOrder();

        $flight = connectService()->inFlight();

        expect($flight['count'])->toBe(1)
            ->and($flight['orders'][0]['order_number'])->toBe($order->order_number)
            ->and($flight['orders'][0]['total'])->toBe(25000);
    });

    it('reports them on the disconnect result too, so the screen can say what was stranded', function () {
        inFlightOrder();
        connectRow();

        expect(connectService()->disconnect()['in_flight']['count'])->toBe(1);
    });

    it('leaves an in-flight order intact and still identifiable after a disconnect', function () {
        // The promise this makes to the owner: a stranded order is recoverable
        // by hand, because nothing about it is destroyed.
        $order = inFlightOrder();
        connectRow(['secret_key' => CONNECT_TEST_KEY, 'webhook_secret' => 'whsec-stripe-x-000000000000000']);

        connectService()->disconnect();

        $order->refresh();

        expect($order->exists)->toBeTrue()
            ->and($order->transaction_id)->not->toBeNull()
            ->and($order->status)->toBe('pending')
            ->and($order->paid_at)->toBeNull();
    });
});

/* ================================================================ OAuth ===== */

describe('Connect OAuth', function () {
    it('is not offered until a platform application is registered', function () {
        connectRow();

        $result = connectService()->authorizeUrl('test');

        expect($result['ok'])->toBeFalse()
            ->and($result['step'])->toBe('client_id')
            ->and($result['error'])->toContain('Settings -> Connect');

        expect(connectService()->status()['oauth_available'])->toBeFalse();
    });

    it('lights up the moment a client id exists, without a redeploy', function () {
        connectRow(['connect_client_id' => 'ca_KBBPLATFORM1']);

        expect(connectService()->status()['oauth_available'])->toBeTrue();
    });

    it('builds an authorize URL with an unguessable single-use state', function () {
        connectRow(['connect_client_id' => 'ca_KBBPLATFORM1']);

        $a = connectService()->authorizeUrl('test');
        $b = connectService()->authorizeUrl('test');

        expect($a['ok'])->toBeTrue()
            ->and($a['url'])->toStartWith('https://connect.stripe.com/oauth/authorize?')
            ->and($a['url'])->toContain('client_id=ca_KBBPLATFORM1')
            ->and($a['url'])->toContain('scope=read_write')
            ->and($a['url'])->toContain('response_type=code')
            ->and(strlen($a['state']))->toBeGreaterThanOrEqual(32)
            // Two calls, two states. A state that repeated would be a state
            // that could be replayed.
            ->and($a['state'])->not->toBe($b['state']);

        expect($a['url'])->toContain(urlencode($a['state']));
        expect($a['redirect_uri'])->toContain('/admin-api/payments/stripe/connect/callback');
    });

    it('refuses the exchange when this shop holds no key to authenticate it with', function () {
        connectRow(['connect_client_id' => 'ca_KBBPLATFORM1']);

        $result = connectService()->exchangeCode('ac_code');

        expect($result['ok'])->toBeFalse()->and($result['step'])->toBe('platform_key');
        Http::assertNothingSent();
    });

    it('stores an OAuth connection in the same shape a pasted key produces', function () {
        connectRow([
            'connect_client_id' => 'ca_KBBPLATFORM1',
            'secret_key' => CONNECT_TEST_KEY,
            'webhook_secret' => 'whsec-stripe-oauth-0000000000',
        ]);

        stripeFake([
            'POST /v1/oauth/token' => [
                'access_token' => CONNECT_OAUTH_KEY,
                'stripe_user_id' => 'acct_OAUTH9',
                'stripe_publishable_key' => 'pk_test_oauth',
                'livemode' => false,
            ],
            'GET /v1/account' => stripeAccountBody(['id' => 'acct_OAUTH9']),
            'GET /v1/webhook_endpoints' => ['object' => 'list', 'data' => []],
            'POST /v1/webhook_endpoints' => [
                'id' => 'we_OAUTH1', 'secret' => CONNECT_WHSEC, 'enabled_events' => StripeConnect::EVENTS,
            ],
        ]);

        $result = connectService()->exchangeCode('ac_code', 'test');

        expect($result['ok'])->toBeTrue()->and($result['link'])->toBe('oauth');

        $config = connectStoredConfig();

        // One storage shape, so one disconnect path: the same keys a pasted
        // connection writes, with `connect_link` as the only difference.
        expect($config['secret_key'])->toBe(CONNECT_OAUTH_KEY)
            ->and($config['publishable_key'])->toBe('pk_test_oauth')
            ->and($config['webhook_signing_secret'])->toBe(CONNECT_WHSEC)
            ->and($config['connect_account_id'])->toBe('acct_OAUTH9')
            ->and($config['connect_link'])->toBe('oauth')
            ->and($config['webhook_endpoint_id'])->toBe('we_OAUTH1')
            ->and($config['webhook_endpoint_managed'])->toBe('1');
    });

    it('catches a live account authorised under a Sandbox tab', function () {
        connectRow([
            'connect_client_id' => 'ca_KBBPLATFORM1',
            'secret_key' => CONNECT_TEST_KEY,
        ], 'test');

        stripeFake([
            'POST /v1/oauth/token' => [
                'access_token' => CONNECT_LIVE_KEY,
                'stripe_user_id' => 'acct_LIVE9',
            ],
        ]);

        $result = connectService()->exchangeCode('ac_code', 'test');

        expect($result['ok'])->toBeFalse()->and($result['step'])->toBe('mode');

        // The old key is untouched: a refused exchange changes nothing.
        expect(connectStoredConfig()['secret_key'])->toBe(CONNECT_TEST_KEY);
    });
});

/* ============================================= the endpoints, over HTTP ===== */

describe('the endpoints', function () {
    it('refuses every one of them to a caller who is not signed in', function () {
        connectRow();

        $calls = [
            fn () => $this->getJson('/admin-api/payments/stripe/connect/status'),
            fn () => $this->postJson('/admin-api/payments/stripe/connect', ['secret_key' => CONNECT_TEST_KEY]),
            fn () => $this->postJson('/admin-api/payments/stripe/connect/application', ['client_id' => 'ca_x']),
            fn () => $this->postJson('/admin-api/payments/stripe/disconnect', ['confirm' => 'disconnect']),
            fn () => $this->get('/admin-api/payments/stripe/connect/start'),
            fn () => $this->get('/admin-api/payments/stripe/connect/callback?state=x&code=y'),
        ];

        foreach ($calls as $call) {
            expect($call()->status())->toBeGreaterThanOrEqual(300);
        }

        Http::assertNothingSent();
    });

    it('writes nothing when an unauthenticated caller tries to connect', function () {
        $this->postJson('/admin-api/payments/stripe/connect', ['secret_key' => CONNECT_TEST_KEY]);

        expect(PaymentProvider::whereKey('stripe')->exists())->toBeFalse();
    });

    it('connects through the screen\'s own endpoint and returns no credential', function () {
        connectRow();
        stripeHappyFake();

        $raw = $this->actingAs(connectAdmin(), 'admin')
            ->postJson('/admin-api/payments/stripe/connect', [
                'secret_key' => CONNECT_TEST_KEY,
                'mode' => 'test',
            ])
            ->assertOk()
            ->getContent();

        expect($raw)->not->toContain(CONNECT_TEST_KEY)
            ->and($raw)->not->toContain(CONNECT_WHSEC);

        // And it really did connect.
        expect(connectStoredConfig()['secret_key'])->toBe(CONNECT_TEST_KEY);
    });

    it('returns 422 and Stripe\'s wording when the key is refused', function () {
        connectRow();

        stripeFake([
            'GET /v1/account' => fn () => Http::response(
                ['error' => ['message' => 'Invalid API Key provided: sk_test_***']],
                401,
            ),
        ]);

        $this->actingAs(connectAdmin(), 'admin')
            ->postJson('/admin-api/payments/stripe/connect', ['secret_key' => CONNECT_TEST_KEY])
            ->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('step', 'account')
            ->assertJsonPath('error', 'Invalid API Key provided: sk_test_***');
    });

    it('will not disconnect without the confirmation the dialog sends', function () {
        connectRow(['secret_key' => CONNECT_TEST_KEY]);

        $admin = connectAdmin();

        $this->actingAs($admin, 'admin')
            ->postJson('/admin-api/payments/stripe/disconnect', [])
            ->assertStatus(422);

        $this->actingAs($admin, 'admin')
            ->postJson('/admin-api/payments/stripe/disconnect', ['confirm' => 'yes please'])
            ->assertStatus(422)
            ->assertJsonPath('step', 'confirm');

        // Nothing was cleared by either.
        expect(connectStoredConfig()['secret_key'])->toBe(CONNECT_TEST_KEY);
    });

    it('disconnects on the confirmation, and says so again on a second press', function () {
        connectRow(['secret_key' => CONNECT_TEST_KEY, 'webhook_secret' => 'whsec-stripe-e2e-0000000000000'], enabled: true);

        $admin = connectAdmin();

        $this->actingAs($admin, 'admin')
            ->postJson('/admin-api/payments/stripe/disconnect', ['confirm' => 'disconnect'])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('was_connected', true);

        $this->actingAs($admin, 'admin')
            ->postJson('/admin-api/payments/stripe/disconnect', ['confirm' => 'disconnect'])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('was_connected', false);
    });

    it('returns the status without a credential in it', function () {
        connectRow([
            'secret_key' => CONNECT_TEST_KEY,
            'webhook_signing_secret' => CONNECT_WHSEC,
            'webhook_secret' => 'whsec-stripe-status-000000000',
            'connect_account_id' => 'acct_KBB123',
            'account_name' => 'K Beauty Bliss',
            'account_currency' => 'AED',
            'charges_enabled' => '1',
        ]);

        $response = $this->actingAs(connectAdmin(), 'admin')
            ->getJson('/admin-api/payments/stripe/connect/status')
            ->assertOk();

        $raw = $response->getContent();

        expect($raw)->not->toContain(CONNECT_TEST_KEY)
            ->and($raw)->not->toContain(CONNECT_WHSEC);

        $response->assertJsonPath('connected', true)
            ->assertJsonPath('account.name', 'K Beauty Bliss')
            ->assertJsonPath('account.currency', 'AED')
            ->assertJsonPath('account.charges_enabled', true);
    });

    it('validates the Connect application id rather than storing whatever arrives', function () {
        connectRow();

        $admin = connectAdmin();

        $this->actingAs($admin, 'admin')
            ->postJson('/admin-api/payments/stripe/connect/application', ['client_id' => 'pk_test_wrong_thing'])
            ->assertStatus(422)
            ->assertJsonPath('step', 'client_id');

        expect(connectStoredConfig())->not->toHaveKey('connect_client_id');

        $this->actingAs($admin, 'admin')
            ->postJson('/admin-api/payments/stripe/connect/application', ['client_id' => 'ca_KBBPLATFORM1'])
            ->assertOk()
            ->assertJsonPath('oauth_available', true);

        expect(connectStoredConfig()['connect_client_id'])->toBe('ca_KBBPLATFORM1');

        // And it can be taken away again.
        $this->actingAs($admin, 'admin')
            ->postJson('/admin-api/payments/stripe/connect/application', ['client_id' => ''])
            ->assertOk()
            ->assertJsonPath('oauth_available', false);

        expect(connectStoredConfig())->not->toHaveKey('connect_client_id');
    });

    it('sends the owner to Stripe and remembers the state in his session', function () {
        connectRow(['connect_client_id' => 'ca_KBBPLATFORM1']);

        $response = $this->actingAs(connectAdmin(), 'admin')
            ->get('/admin-api/payments/stripe/connect/start?mode=test')
            ->assertRedirect();

        $location = $response->headers->get('Location');

        expect($location)->toStartWith('https://connect.stripe.com/oauth/authorize?');

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        $this->assertNotEmpty($query['state']);
        expect(session(StripeConnect::STATE_SESSION_KEY))->toBe($query['state']);
    });

    it('changes nothing on a callback whose state does not match', function () {
        connectRow(['connect_client_id' => 'ca_KBBPLATFORM1', 'secret_key' => CONNECT_TEST_KEY]);

        $this->actingAs(connectAdmin(), 'admin')
            ->withSession([StripeConnect::STATE_SESSION_KEY => 'the-real-state-value-0000000000'])
            ->get('/admin-api/payments/stripe/connect/callback?state=a-guess&code=ac_code')
            ->assertOk()
            ->assertSee('not connected', false);

        // No token exchange was even attempted.
        Http::assertNothingSent();
        expect(connectStoredConfig()['secret_key'])->toBe(CONNECT_TEST_KEY);
    });

    it('changes nothing on a callback with no state at all', function () {
        connectRow(['connect_client_id' => 'ca_KBBPLATFORM1', 'secret_key' => CONNECT_TEST_KEY]);

        $this->actingAs(connectAdmin(), 'admin')
            ->get('/admin-api/payments/stripe/connect/callback?code=ac_code')
            ->assertOk();

        Http::assertNothingSent();
    });

    it('consumes the state, so the same callback cannot be replayed', function () {
        connectRow([
            'connect_client_id' => 'ca_KBBPLATFORM1',
            'secret_key' => CONNECT_TEST_KEY,
            'webhook_secret' => 'whsec-stripe-replay-000000000',
        ]);

        stripeFake([
            'POST /v1/oauth/token' => [
                'access_token' => CONNECT_OAUTH_KEY,
                'stripe_user_id' => 'acct_OAUTH9',
            ],
            'GET /v1/account' => stripeAccountBody(['id' => 'acct_OAUTH9']),
            'GET /v1/webhook_endpoints' => ['object' => 'list', 'data' => []],
            'POST /v1/webhook_endpoints' => [
                'id' => 'we_OAUTH1', 'secret' => CONNECT_WHSEC, 'enabled_events' => StripeConnect::EVENTS,
            ],
        ]);

        $state = 'one-use-only-state-000000000000';
        $admin = connectAdmin();

        $this->actingAs($admin, 'admin')
            ->withSession([StripeConnect::STATE_SESSION_KEY => $state])
            ->get('/admin-api/payments/stripe/connect/callback?state=' . $state . '&code=ac_code')
            ->assertOk();

        expect(connectStoredConfig()['connect_link'])->toBe('oauth');

        $before = count(Http::recorded());

        // The identical URL again, with the state now spent.
        $this->actingAs($admin, 'admin')
            ->get('/admin-api/payments/stripe/connect/callback?state=' . $state . '&code=ac_code')
            ->assertOk()
            ->assertSee('not connected', false);

        expect(count(Http::recorded()))->toBe($before);
    });

    it('reports Stripe\'s refusal when the owner presses Cancel in the popup', function () {
        connectRow(['connect_client_id' => 'ca_KBBPLATFORM1', 'secret_key' => CONNECT_TEST_KEY]);

        $state = 'cancelled-state-0000000000000000';

        $this->actingAs(connectAdmin(), 'admin')
            ->withSession([StripeConnect::STATE_SESSION_KEY => $state])
            ->get('/admin-api/payments/stripe/connect/callback?state=' . $state
                . '&error=access_denied&error_description=' . urlencode('The user denied your request'))
            ->assertOk()
            ->assertSee('The user denied your request', false);

        Http::assertNothingSent();
    });

    it('never renders a credential into the popup page', function () {
        connectRow([
            'connect_client_id' => 'ca_KBBPLATFORM1',
            'secret_key' => CONNECT_TEST_KEY,
            'webhook_secret' => 'whsec-stripe-popup-0000000000',
        ]);

        stripeFake([
            'POST /v1/oauth/token' => [
                'access_token' => CONNECT_OAUTH_KEY,
                'stripe_user_id' => 'acct_OAUTH9',
            ],
            'GET /v1/account' => stripeAccountBody(['id' => 'acct_OAUTH9']),
            'GET /v1/webhook_endpoints' => ['object' => 'list', 'data' => []],
            'POST /v1/webhook_endpoints' => [
                'id' => 'we_OAUTH1', 'secret' => CONNECT_WHSEC, 'enabled_events' => StripeConnect::EVENTS,
            ],
        ]);

        $state = 'popup-state-00000000000000000000';

        $html = $this->actingAs(connectAdmin(), 'admin')
            ->withSession([StripeConnect::STATE_SESSION_KEY => $state])
            ->get('/admin-api/payments/stripe/connect/callback?state=' . $state . '&code=ac_code')
            ->assertOk()
            ->getContent();

        foreach ([CONNECT_OAUTH_KEY, CONNECT_WHSEC, CONNECT_TEST_KEY] as $canary) {
            expect($html)->not->toContain($canary);
        }

        // The webhook URL is confidential too: its random tail is what makes
        // the endpoint unguessable, and this page can end up in history.
        expect($html)->not->toContain('whsec-stripe-popup-0000000000');
    });
});

/* ============================================================== the guard === */

describe('the guard', function () {
    it('maps every route in the file to payments.manage', function () {
        $before = collect(Route::getRoutes()->getRoutes());

        // Registered by this file's beforeEach; diff by identity against a
        // freshly-loaded copy would double-register, so read what is there.
        $mine = $before->filter(fn ($r) => str_starts_with($r->uri(), 'admin-api/payments/stripe/'))->values();

        expect($mine)->toHaveCount(6);

        foreach ($mine as $route) {
            expect(App\Support\AdminCapabilities::for($route))->toBe('payments.manage');
        }
    });

    it('does not let the read rule reach the application POST', function () {
        // 'admin-api/payments/stripe/connect/*' is a GET rule and sits BELOW
        // the write rules. This asserts the ordering rather than trusting it:
        // matched the other way round, a marketing.view-style read capability
        // would have been handed a write endpoint. It is the coupons/manage
        // mistake, and it was found by a test there too.
        expect(App\Support\AdminCapabilities::forPath('POST', 'admin-api/payments/stripe/connect/application'))
            ->toBe('payments.manage');

        expect(App\Support\AdminCapabilities::forPath('GET', 'admin-api/payments/stripe/connect/status'))
            ->toBe('payments.manage');
    });

    it('gives payments.manage to the owner alone', function () {
        expect(App\Support\AdminCapabilities::CAPABILITIES['payments.manage'])->toBe(['owner']);

        foreach (['manager', 'support', 'editor'] as $role) {
            expect(App\Support\AdminCapabilities::roleCan($role, 'payments.manage'))->toBeFalse();
        }
    });

    it('refuses a manager, who can run the shop but not rewire its money', function () {
        connectRow();

        $manager = AdminUser::create([
            'name' => 'Manager', 'email' => 'mgr-' . uniqid() . '@example.test',
            'password' => 'password-long-enough', 'role' => 'manager',
        ]);

        $this->actingAs($manager, 'admin')
            ->postJson('/admin-api/payments/stripe/connect', ['secret_key' => CONNECT_TEST_KEY])
            ->assertForbidden();

        $this->actingAs($manager, 'admin')
            ->postJson('/admin-api/payments/stripe/disconnect', ['confirm' => 'disconnect'])
            ->assertForbidden();

        Http::assertNothingSent();
        expect(connectStoredConfig())->not->toHaveKey('secret_key');
    });

    it('defines exactly the six routes the header describes, and chains no middleware', function () {
        $mine = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), 'admin-api/payments/stripe/'))
            ->values();

        $byUri = $mine->mapWithKeys(fn ($r) => [$r->methods()[0] . ' ' . $r->uri() => $r]);

        expect($byUri->keys()->all())->toEqualCanonicalizing([
            'GET admin-api/payments/stripe/connect/status',
            'GET admin-api/payments/stripe/connect/start',
            'GET admin-api/payments/stripe/connect/callback',
            'POST admin-api/payments/stripe/connect/application',
            'POST admin-api/payments/stripe/connect',
            'POST admin-api/payments/stripe/disconnect',
        ]);

        // RouteRegistrar::middleware() REPLACES rather than appends. A chained
        // ->middleware() in the route file would have dropped NoStoreAdminApi
        // off the group and let a browser cache the payment configuration.
        foreach ($mine as $route) {
            expect($route->middleware())->toContain('auth:admin')
                ->and($route->middleware())->toContain(App\Http\Middleware\NoStoreAdminApi::class);
        }
    });
});

/* ========================================================= secrets in code == */

describe('secrets stay where they are put', function () {
    it('writes no credential to the settings table, whatever else it does', function () {
        connectRow();
        stripeHappyFake();

        connectService()->connectWithKey(CONNECT_TEST_KEY, 'test');

        $settings = Illuminate\Support\Facades\DB::table('settings')->get();

        foreach ($settings as $setting) {
            foreach ((array) $setting as $column) {
                if (is_string($column)) {
                    expect($column)->not->toContain(CONNECT_TEST_KEY);
                    expect($column)->not->toContain(CONNECT_WHSEC);
                }
            }
        }
    });

    it('keeps them out of the public API after a connection', function () {
        connectRow();
        stripeHappyFake();

        connectService()->connectWithKey(CONNECT_TEST_KEY, 'test');

        $raw = $this->getJson('/api/settings')->assertOk()->getContent();

        expect($raw)->not->toContain(CONNECT_TEST_KEY)
            ->and($raw)->not->toContain(CONNECT_WHSEC);
    });

    it('never logs, because a log file is read by more people than a database', function () {
        // A source check, not a behavioural one: proving "nothing was logged"
        // for every branch would need a fake for every branch, while the
        // absence of the facade proves it for all of them at once.
        //
        // token_get_all rather than a regex. The class comment says the word
        // "logged" several times and quotes "Log" in prose, and a regex over
        // the file would read those as code -- the trap CLAUDE.md names.
        $tokens = token_get_all(file_get_contents(base_path('app/Services/Payments/StripeConnect.php')));

        $names = [];

        foreach ($tokens as $i => $token) {
            if (! is_array($token) || $token[0] !== T_STRING) {
                continue;
            }

            // Only a name followed by :: or -> is a call; a bare word in a
            // comment is not even a T_STRING.
            $next = $tokens[$i + 1] ?? null;

            if (is_array($next) && in_array($next[0], [T_DOUBLE_COLON, T_OBJECT_OPERATOR], true)) {
                $names[] = $token[1];
            }
        }

        expect($names)->not->toContain('Log');
        expect($names)->not->toContain('logger');
    });
});
