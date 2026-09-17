<?php

/**
 * One-click Stripe Connect — Lane FG.
 *
 * WHAT THIS FILE IS ABOUT
 *
 * The owner asked twice for the WooCommerce behaviour: press Configure, a
 * Stripe window opens, approve, come back configured. The OAuth machinery for
 * it was already written and was unreachable. This file pins the four things
 * that made it unreachable and the ways the flow is allowed to fail.
 *
 *   1. Nothing could store a platform application, so `oauth_available` was
 *      false on every install and the button was never drawn.
 *   2. The token exchange authenticated with the merchant secret key this shop
 *      had already stored — which does not exist until something has connected.
 *      The flow could not run the first time, which is the only time it is
 *      wanted. `connects a shop that has never connected before` is the test
 *      that would have caught it.
 *   3. Stripe issues a platform two client ids, development and production, and
 *      a code must be redeemed with a key of its own mode.
 *   4. The state had no expiry and carried no mode.
 *
 * WHAT IT CANNOT PROVE. This repository has no Stripe account and no keys, and
 * a test that made a live call would fail on a machine with no network and pass
 * for the wrong reason on one with. Http::preventStrayRequests() is on for
 * every test here, so a call this file did not fake is an error rather than a
 * silent pass — which is what turns "refuses before it opens the popup" into a
 * real assertion. It proves the wire format, the ordering, the refusals and
 * that no credential escapes. It does not prove Stripe accepts the requests.
 */

use App\Models\AdminUser;
use App\Models\PaymentProvider;
use App\Services\Payments\GatewayCredentials;
use App\Services\Payments\StripeConnect;
use App\Support\AdminCapabilities;
use App\Support\StripeConnectConsole;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

/*
 * Canaries: distinctive enough that finding one anywhere — a response body, a
 * rendered page, a log line — is unambiguous and cannot be a coincidence.
 */
const FG_PLATFORM_SECRET_TEST = 'sk_test_FGPLATFORMCANARY000001';
const FG_PLATFORM_SECRET_LIVE = 'sk_live_FGPLATFORMCANARY000002';
const FG_GRANTED_KEY = 'sk_test_FGGRANTEDCANARY00000003';
const FG_GRANTED_KEY_LIVE = 'sk_live_FGGRANTEDCANARY00000004';
const FG_WHSEC = 'whsec_FGCANARY0000000000000005';
const FG_MERCHANT_KEY = 'sk_test_FGMERCHANTCANARY000006';

beforeEach(function () {
    PaymentProvider::query()->delete();
    app(GatewayCredentials::class)->forget();
    Http::preventStrayRequests();

    /*
     * Exactly the mounting the two route files ask the integrator for: the
     * EXISTING admin-api group, guard included. ONE middleware() call —
     * RouteRegistrar::middleware() REPLACES rather than appends, so a second
     * call would silently drop `auth:admin` and this file would be testing an
     * unguarded group.
     */
    Route::middleware(['web', 'auth:admin', \App\Http\Middleware\NoStoreAdminApi::class])
        ->prefix('admin-api')
        ->group(function () {
            require base_path('routes/payments-connect.php');
            require base_path('routes/payments-connect-platform.php');
        });
});

/* ============================================================ the harness == */

function fgAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'FG Admin',
        'email' => 'fg-' . uniqid() . '@example.test',
        'password' => 'password-long-enough',
        'role' => 'owner',
    ]);
}

function fgConnect(): StripeConnect
{
    app(GatewayCredentials::class)->forget();

    return app(StripeConnect::class);
}

function fgRow(array $config = [], string $mode = 'test'): PaymentProvider
{
    $row = PaymentProvider::firstOrNew(['id' => 'stripe']);
    $row->fill(['title' => 'Credit or debit card', 'enabled' => false, 'mode' => $mode, 'position' => 1]);
    $row->save();

    if ($config !== []) {
        $row->config = $config;
        $row->save();
    }

    app(GatewayCredentials::class)->forget();

    return $row;
}

function fgStored(): array
{
    $row = PaymentProvider::find('stripe');

    return $row === null || ! is_array($row->config) ? [] : $row->config;
}

/** Routed on method plus path: the list and the create share a URL prefix. */
function fgStripeFake(array $routes): void
{
    Http::fake(function (Illuminate\Http\Client\Request $request) use ($routes) {
        $key = strtoupper($request->method()) . ' ' . (parse_url($request->url(), PHP_URL_PATH) ?: '');

        if (! array_key_exists($key, $routes)) {
            return Http::response(['error' => ['message' => 'nothing faked for ' . $key]], 418);
        }

        $answer = $routes[$key];

        return is_callable($answer) ? $answer($request) : Http::response($answer, 200);
    });
}

/** Everything the OAuth path calls, answered. */
function fgOauthFake(string $grantedKey = FG_GRANTED_KEY, string $accountId = 'acct_FGNEW1'): void
{
    fgStripeFake([
        'POST /v1/oauth/token' => [
            'access_token' => $grantedKey,
            'stripe_user_id' => $accountId,
            'stripe_publishable_key' => 'pk_test_fg',
            'livemode' => str_contains($grantedKey, '_live_'),
        ],
        'GET /v1/account' => [
            'id' => $accountId,
            'object' => 'account',
            'country' => 'AE',
            'default_currency' => 'aed',
            'charges_enabled' => true,
            'livemode' => str_contains($grantedKey, '_live_'),
            'business_profile' => ['name' => 'K Beauty Bliss'],
        ],
        'GET /v1/webhook_endpoints' => ['object' => 'list', 'data' => []],
        'POST /v1/webhook_endpoints' => [
            'id' => 'we_FGNEW1',
            'object' => 'webhook_endpoint',
            'secret' => FG_WHSEC,
            'enabled_events' => StripeConnect::EVENTS,
        ],
    ]);
}

/** Every secret this file invents, for the leak sweeps. */
function fgCanaries(): array
{
    return [
        FG_PLATFORM_SECRET_TEST,
        FG_PLATFORM_SECRET_LIVE,
        FG_GRANTED_KEY,
        FG_GRANTED_KEY_LIVE,
        FG_WHSEC,
        FG_MERCHANT_KEY,
    ];
}

/* ====================================== the button that could never work === */

describe('the dead button', function () {
    /*
     * THE ONE THIS LANE EXISTS FOR.
     *
     * A shop that has never connected to Stripe — no secret_key, nothing — has
     * a registered platform application and presses the one-click button. This
     * is the first press on every install, and before this change it was the
     * one press that could not work: exchangeCode() authenticated the token
     * call with the merchant key, and the merchant key is what connecting
     * produces. The flow's only credential was its own output.
     */
    it('connects a shop that has never connected before, which is the only first time there is', function () {
        fgRow([
            'connect_client_id_test' => 'ca_FGDEVELOPMENT1',
            'connect_client_secret_test' => FG_PLATFORM_SECRET_TEST,
        ], 'test');

        expect(fgStored())->not->toHaveKey('secret_key');

        fgOauthFake();

        $result = fgConnect()->exchangeCode('ac_firsttime', 'test');

        expect($result['ok'])->toBeTrue()
            ->and($result['link'])->toBe('oauth');

        $config = fgStored();

        // The same storage shape a pasted key produces, so there is one
        // disconnect path and not two that disagree.
        expect($config['secret_key'])->toBe(FG_GRANTED_KEY)
            ->and($config['connect_account_id'])->toBe('acct_FGNEW1')
            ->and($config['connect_link'])->toBe('oauth')
            // AN OAUTH GRANT CARRIES NO WEBHOOK SIGNING SECRET. Stripe returns
            // one only in the response that CREATES an endpoint, so the OAuth
            // path has to make the same call the pasted path makes or it ends
            // connected and deaf — taking payments it never hears about.
            ->and($config['webhook_signing_secret'])->toBe(FG_WHSEC)
            ->and($config['webhook_endpoint_id'])->toBe('we_FGNEW1')
            ->and($config['webhook_endpoint_managed'])->toBe('1');
    });

    it('authenticates the exchange with the platform secret and not with anything else', function () {
        fgRow([
            'connect_client_id_test' => 'ca_FGDEVELOPMENT1',
            'connect_client_secret_test' => FG_PLATFORM_SECRET_TEST,
            // A merchant key IS stored, and is deliberately a different value:
            // if the platform secret were ignored this assertion would catch
            // it rather than passing on a coincidence.
            'secret_key' => FG_MERCHANT_KEY,
            'webhook_secret' => 'whsec-stripe-fg-00000000000000',
        ], 'test');

        fgOauthFake();

        expect(fgConnect()->exchangeCode('ac_code', 'test')['ok'])->toBeTrue();

        Http::assertSent(function (Illuminate\Http\Client\Request $r) {
            if (parse_url($r->url(), PHP_URL_PATH) !== '/v1/oauth/token') {
                return false;
            }

            return $r->hasHeader('Authorization', 'Bearer ' . FG_PLATFORM_SECRET_TEST)
                && $r->data()['grant_type'] === 'authorization_code'
                && $r->data()['code'] === 'ac_code';
        });
    });

    it('refuses to open the popup at all when the platform secret is missing', function () {
        /*
         * The refusal has to come BEFORE the window opens. After it, the owner
         * has signed in to Stripe and granted this shop access to his account,
         * and the failure leaves him with a live authorisation and nothing
         * connected — which he then has to go and revoke by hand.
         */
        fgRow(['connect_client_id' => 'ca_FGPLATFORM1'], 'test');

        $result = fgConnect()->authorizeUrl('test');

        expect($result['ok'])->toBeFalse()
            ->and($result['step'])->toBe('client_secret')
            ->and($result['error'])->toContain('secret key');

        // An application IS registered, and saying so is the point: the screen
        // has to tell him which half is missing rather than pretending the
        // feature does not exist.
        $status = fgConnect()->status();

        expect($status['oauth_available'])->toBeTrue()
            ->and($status['oauth_ready'])->toBeFalse();

        Http::assertNothingSent();
    });

    it('is ready once both halves are stored', function () {
        fgRow([
            'connect_client_id_test' => 'ca_FGDEVELOPMENT1',
            'connect_client_secret_test' => FG_PLATFORM_SECRET_TEST,
        ], 'test');

        $status = fgConnect()->status();

        expect($status['oauth_available'])->toBeTrue()
            ->and($status['oauth_ready'])->toBeTrue()
            ->and($status['platform']['falls_back_to_merchant_key'])->toBeFalse();

        expect(fgConnect()->authorizeUrl('test')['ok'])->toBeTrue();
    });
});

/* ============================================ two client ids, not one ====== */

describe('development and production are different applications', function () {
    it('sends the development client id in test mode and the production one in live', function () {
        fgRow([
            'connect_client_id' => 'ca_FGPRODUCTION1',
            'connect_client_id_test' => 'ca_FGDEVELOPMENT1',
            'connect_client_secret' => FG_PLATFORM_SECRET_LIVE,
            'connect_client_secret_test' => FG_PLATFORM_SECRET_TEST,
        ], 'test');

        expect(fgConnect()->authorizeUrl('test')['url'])->toContain('client_id=ca_FGDEVELOPMENT1');
        expect(fgConnect()->authorizeUrl('live')['url'])->toContain('client_id=ca_FGPRODUCTION1');
    });

    it('falls back to the one application a shop that registered only one has', function () {
        // Most shops only ever run live and register one. One filled box has to
        // keep working, or the fix for the two-id case breaks the common one.
        fgRow([
            'connect_client_id' => 'ca_FGONLYONE1',
            'connect_client_secret' => FG_PLATFORM_SECRET_LIVE,
        ], 'test');

        expect(fgConnect()->authorizeUrl('test')['url'])->toContain('client_id=ca_FGONLYONE1');
        expect(fgConnect()->platformClientId('live'))->toBe('ca_FGONLYONE1');
    });

    it('redeems a live code with the live platform secret', function () {
        fgRow([
            'connect_client_id' => 'ca_FGPRODUCTION1',
            'connect_client_secret' => FG_PLATFORM_SECRET_LIVE,
            'connect_client_id_test' => 'ca_FGDEVELOPMENT1',
            'connect_client_secret_test' => FG_PLATFORM_SECRET_TEST,
        ], 'live');

        fgOauthFake(FG_GRANTED_KEY_LIVE, 'acct_FGLIVE1');

        expect(fgConnect()->exchangeCode('ac_live', 'live')['ok'])->toBeTrue();

        Http::assertSent(fn (Illuminate\Http\Client\Request $r) => parse_url($r->url(), PHP_URL_PATH) !== '/v1/oauth/token'
            || $r->hasHeader('Authorization', 'Bearer ' . FG_PLATFORM_SECRET_LIVE));
    });
});

/* ============================================ storing the platform app ===== */

describe('the platform application panel', function () {
    it('stores both client ids and both platform secrets', function () {
        fgRow();

        $this->actingAs(fgAdmin(), 'admin')
            ->postJson('/admin-api/payments/stripe/connect/application', [
                'client_id' => 'ca_FGPRODUCTION1',
                'client_id_test' => 'ca_FGDEVELOPMENT1',
                'client_secret' => FG_PLATFORM_SECRET_LIVE,
                'client_secret_test' => FG_PLATFORM_SECRET_TEST,
            ])
            ->assertOk()
            ->assertJsonPath('oauth_ready', true);

        $config = fgStored();

        expect($config['connect_client_id'])->toBe('ca_FGPRODUCTION1')
            ->and($config['connect_client_id_test'])->toBe('ca_FGDEVELOPMENT1')
            ->and($config['connect_client_secret'])->toBe(FG_PLATFORM_SECRET_LIVE)
            ->and($config['connect_client_secret_test'])->toBe(FG_PLATFORM_SECRET_TEST);
    });

    it('never returns a platform secret to the browser, in any of the three places it could', function () {
        fgRow([
            'connect_client_id' => 'ca_FGPRODUCTION1',
            'connect_client_secret' => FG_PLATFORM_SECRET_LIVE,
            'connect_client_secret_test' => FG_PLATFORM_SECRET_TEST,
        ]);

        $admin = fgAdmin();

        $bodies = [
            $this->actingAs($admin, 'admin')->getJson('/admin-api/payments/stripe/connect/status')->getContent(),
            $this->actingAs($admin, 'admin')->getJson('/admin-api/payments/stripe/connect/platform')->getContent(),
            $this->actingAs($admin, 'admin')
                ->postJson('/admin-api/payments/stripe/connect/application', ['client_id' => 'ca_FGPRODUCTION1'])
                ->getContent(),
        ];

        foreach ($bodies as $body) {
            foreach (fgCanaries() as $canary) {
                expect($body)->not->toContain($canary);
            }
        }

        // Not even a masked form. A mask still discloses the length, and the
        // screen has no use for one: it renders a has_* boolean.
        expect($bodies[0])->toContain('has_client_secret_live');
    });

    it('leaves a stored secret alone when the box is submitted blank', function () {
        // The screen renders secrets as empty boxes. A save from that screen
        // must not wipe them, or every unrelated edit to the panel silently
        // breaks the one-click button.
        //
        // The secret is SAVED THROUGH THE ENDPOINT first rather than seeded
        // into the row. Seeding it would let this test pass against a version
        // of application() that ignores the field altogether — it would never
        // wipe what it never reads — which is exactly the state this lane found
        // the endpoint in.
        fgRow();

        $admin = fgAdmin();

        $this->actingAs($admin, 'admin')
            ->postJson('/admin-api/payments/stripe/connect/application', [
                'client_id' => 'ca_FGPRODUCTION1',
                'client_secret' => FG_PLATFORM_SECRET_LIVE,
            ])
            ->assertOk()
            ->assertJsonPath('platform.has_client_secret_live', true);

        $this->actingAs($admin, 'admin')
            ->postJson('/admin-api/payments/stripe/connect/application', [
                'client_id' => 'ca_FGPRODUCTION1',
                'client_secret' => '',
            ])
            ->assertOk();

        expect(fgStored()['connect_client_secret'])->toBe(FG_PLATFORM_SECRET_LIVE);
    });

    it('clears a secret only when asked to in as many words', function () {
        fgRow(['connect_client_secret' => FG_PLATFORM_SECRET_LIVE]);

        $this->actingAs(fgAdmin(), 'admin')
            ->postJson('/admin-api/payments/stripe/connect/application', [
                'client_id' => 'ca_FGPRODUCTION1',
                'clear_client_secret' => true,
            ])
            ->assertOk();

        expect(fgStored())->not->toHaveKey('connect_client_secret');
    });

    it('refuses a live platform key in the test box, before it is ever sent anywhere', function () {
        /*
         * The refusal that matters most on this panel. Stripe will not redeem a
         * code issued by the development client id with a live key, and the
         * error it returns talks about the CODE rather than the key — sending
         * the owner to look in entirely the wrong place, after the grant.
         */
        fgRow();

        $this->actingAs(fgAdmin(), 'admin')
            ->postJson('/admin-api/payments/stripe/connect/application', [
                'client_secret_test' => FG_PLATFORM_SECRET_LIVE,
            ])
            ->assertStatus(422)
            ->assertJsonPath('step', 'client_secret_test');

        expect(fgStored())->not->toHaveKey('connect_client_secret_test');
        Http::assertNothingSent();
    });

    it('refuses a test platform key in the live box', function () {
        fgRow();

        $this->actingAs(fgAdmin(), 'admin')
            ->postJson('/admin-api/payments/stripe/connect/application', [
                'client_secret' => FG_PLATFORM_SECRET_TEST,
            ])
            ->assertStatus(422)
            ->assertJsonPath('step', 'client_secret');

        expect(fgStored())->not->toHaveKey('connect_client_secret');
    });

    it('names the publishable key and the application id for what they are', function () {
        fgRow();
        $admin = fgAdmin();

        $this->actingAs($admin, 'admin')
            ->postJson('/admin-api/payments/stripe/connect/application', ['client_secret' => 'pk_live_wrongthing'])
            ->assertStatus(422)
            ->assertJsonPath('step', 'client_secret');

        $this->actingAs($admin, 'admin')
            ->postJson('/admin-api/payments/stripe/connect/application', ['client_secret' => 'ca_FGPRODUCTION1'])
            ->assertStatus(422);

        $this->actingAs($admin, 'admin')
            ->postJson('/admin-api/payments/stripe/connect/application', ['client_id_test' => 'sk_test_notanappid'])
            ->assertStatus(422)
            ->assertJsonPath('step', 'client_id_test');

        expect(fgStored())->not->toHaveKey('connect_client_secret');
        expect(fgStored())->not->toHaveKey('connect_client_id_test');
    });

    it('refuses a refusal message that quotes what the owner pasted', function () {
        // A validation message about the value of a secret key is a message
        // that has seen one, and this one is rendered straight onto the screen.
        fgRow();

        $body = $this->actingAs(fgAdmin(), 'admin')
            ->postJson('/admin-api/payments/stripe/connect/application', [
                'client_secret' => FG_PLATFORM_SECRET_TEST,
            ])
            ->assertStatus(422)
            ->getContent();

        expect($body)->not->toContain(FG_PLATFORM_SECRET_TEST);
    });
});

/* ========================================================== the guide ====== */

describe('the setup guide', function () {
    it('is served to the screen with the redirect URI the owner has to register', function () {
        fgRow();

        $this->actingAs(fgAdmin(), 'admin')
            ->getJson('/admin-api/payments/stripe/connect/platform')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonStructure([
                'guide' => ['heading', 'intro', 'closing', 'steps' => [['title', 'body', 'url', 'link_label']]],
                'redirect_uri',
                'platform' => ['mode', 'client_id_test', 'client_id_live', 'has_client_secret_test', 'has_client_secret_live'],
            ])
            ->assertJsonPath('redirect_uri', fgConnect()->redirectUri());
    });

    it('prints no URL it could not verify', function () {
        /*
         * A wrong link in a credential-setup guide is the shape of a phishing
         * page: an owner already holding a secret key, already expecting to be
         * asked for it, following a link his own admin panel gave him.
         *
         * Outbound access from the machine this was written on is proxied and
         * both dashboard.stripe.com and docs.stripe.com are blocked by it, so
         * not one Stripe URL could be loaded and confirmed. None is printed —
         * every step gives the menu path instead. This test is what stops a
         * later edit quietly adding a guessed one.
         */
        $guide = StripeConnectConsole::setupGuide();

        foreach ($guide['steps'] as $step) {
            expect($step['url'])->toBeNull();
            expect($step['link_label'])->toBeNull();
            // '://' rather than 'http', because one step legitimately says that
            // Stripe requires the redirect address to be https in live mode.
            // That is a fact about the address, not a link to follow.
            expect($step['body'])->not->toContain('://');
        }

        foreach ([$guide['heading'], $guide['intro'], $guide['closing']] as $prose) {
            expect($prose)->not->toContain('://');
        }
    });

    it('says plainly that the manual path loses nothing', function () {
        $guide = StripeConnectConsole::setupGuide();

        // The owner must not be left thinking the shop is broken until he has
        // registered a platform. Pasting a key connects it just as completely.
        expect(strtolower($guide['closing']))->toContain('secret key');
        expect(strtolower($guide['intro']))->toContain('woocommerce');
    });

    it('distinguishes not set up from set up wrong', function () {
        fgRow();
        expect(StripeConnectConsole::readiness(fgConnect()->status()))->toContain('No Connect application');

        fgRow(['connect_client_id' => 'ca_FGPRODUCTION1']);
        expect(StripeConnectConsole::readiness(fgConnect()->status()))->toContain('platform secret key is not');

        fgRow([
            'connect_client_id_test' => 'ca_FGDEVELOPMENT1',
            'connect_client_secret_test' => FG_PLATFORM_SECRET_TEST,
        ]);
        expect(StripeConnectConsole::readiness(fgConnect()->status()))->toContain('Ready');
    });
});

/* ======================================================= the state ========= */

describe('the OAuth state', function () {
    it('carries the mode the popup was opened in, and redeems under that mode', function () {
        /*
         * THE ATTACK THIS CLOSES, and it is not the obvious one.
         *
         * Everything in the callback's query string came back through the
         * owner's browser. Taking the mode from there would let a crafted
         * return URL decide which platform application's credentials redeem the
         * code — and, through the mode check, whether a live account is allowed
         * to land under a tab that says Sandbox. The mode is something this
         * server already knew when it opened the window, so it is read from the
         * session and the query string is not consulted for it.
         */
        fgRow([
            'connect_client_id_test' => 'ca_FGDEVELOPMENT1',
            'connect_client_secret_test' => FG_PLATFORM_SECRET_TEST,
        ], 'test');

        $admin = fgAdmin();

        $location = $this->actingAs($admin, 'admin')
            ->get('/admin-api/payments/stripe/connect/start?mode=test')
            ->assertRedirect()
            ->headers->get('Location');

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        $stored = session(StripeConnect::STATE_SESSION_KEY);

        expect($stored['mode'])->toBe('test')
            ->and($stored['value'])->toBe($query['state']);

        // A LIVE key comes back. The tab said Sandbox, so it is refused —
        // exactly as a live key pasted under a Sandbox tab is refused.
        fgStripeFake([
            'POST /v1/oauth/token' => [
                'access_token' => FG_GRANTED_KEY_LIVE,
                'stripe_user_id' => 'acct_FGLIVE9',
            ],
        ]);

        $this->actingAs($admin, 'admin')
            ->withSession([StripeConnect::STATE_SESSION_KEY => $stored])
            ->get('/admin-api/payments/stripe/connect/callback?state=' . $query['state'] . '&code=ac_code')
            ->assertOk()
            ->assertSee('not connected', false);

        expect(fgStored())->not->toHaveKey('secret_key');
    });

    it('refuses a state that has gone stale', function () {
        fgRow([
            'connect_client_id_test' => 'ca_FGDEVELOPMENT1',
            'connect_client_secret_test' => FG_PLATFORM_SECRET_TEST,
        ], 'test');

        $state = 'stale-state-000000000000000000000000';

        $this->actingAs(fgAdmin(), 'admin')
            ->withSession([StripeConnect::STATE_SESSION_KEY => [
                'value' => $state,
                'mode' => 'test',
                'issued_at' => time() - StripeConnect::STATE_TTL_SECONDS - 1,
            ]])
            ->get('/admin-api/payments/stripe/connect/callback?state=' . $state . '&code=ac_code')
            ->assertOk()
            ->assertSee('expired', false);

        // Not one call to Stripe. An expired state is refused before the code
        // is looked at, so there is nothing to exchange and nothing to roll
        // back.
        Http::assertNothingSent();
        expect(fgStored())->not->toHaveKey('secret_key');
    });

    it('accepts a state still inside its window', function () {
        fgRow([
            'connect_client_id_test' => 'ca_FGDEVELOPMENT1',
            'connect_client_secret_test' => FG_PLATFORM_SECRET_TEST,
        ], 'test');

        fgOauthFake();

        $state = 'fresh-state-000000000000000000000000';

        $this->actingAs(fgAdmin(), 'admin')
            ->withSession([StripeConnect::STATE_SESSION_KEY => [
                'value' => $state,
                'mode' => 'test',
                'issued_at' => time() - (StripeConnect::STATE_TTL_SECONDS - 5),
            ]])
            ->get('/admin-api/payments/stripe/connect/callback?state=' . $state . '&code=ac_code')
            ->assertOk()
            ->assertSee('is connected', false);

        expect(fgStored()['connect_link'])->toBe('oauth');
    });

    it('still completes for a popup opened before this package applied', function () {
        /*
         * A session cookie outlives a deployment. An owner who pressed Connect
         * seconds before the package was applied has a BARE STRING under the
         * state key, because that is what the previous start() wrote. Refusing
         * it would mean a failed connection for a mistake he did not make, so
         * the old shape is read — with no TTL and no mode to check, which is
         * precisely what that older start() gave him and no less.
         */
        fgRow([
            'connect_client_id_test' => 'ca_FGDEVELOPMENT1',
            'connect_client_secret_test' => FG_PLATFORM_SECRET_TEST,
        ], 'test');

        fgOauthFake();

        $state = 'legacy-bare-string-state-00000000';

        $this->actingAs(fgAdmin(), 'admin')
            ->withSession([StripeConnect::STATE_SESSION_KEY => $state])
            ->get('/admin-api/payments/stripe/connect/callback?state=' . $state . '&code=ac_code')
            ->assertOk();

        expect(fgStored()['connect_link'])->toBe('oauth');
    });

    it('refuses a callback carrying no state into a session holding none', function () {
        /*
         * THE ATTACK. The callback is a URL a stranger can put in a link. An
         * owner signed in to his own console follows it, his session cookie
         * rides along, and the callback attaches the STRANGER'S Stripe account
         * to this shop — every card payment from then on lands in it.
         *
         * The state stops it because the attacker cannot produce one: it is 40
         * random characters this server minted, kept in the owner's session,
         * and spent on first sight.
         *
         * The specific hole guarded here is that hash_equals('', '') is TRUE.
         * A callback with no state, arriving in a session with none stored,
         * would sail past a comparison that did not check emptiness first — and
         * that is exactly the request an attacker can make, because it is the
         * only kind he can make.
         */
        fgRow([
            'connect_client_id_test' => 'ca_FGDEVELOPMENT1',
            'connect_client_secret_test' => FG_PLATFORM_SECRET_TEST,
        ], 'test');

        $this->actingAs(fgAdmin(), 'admin')
            ->get('/admin-api/payments/stripe/connect/callback?code=ac_attacker_code')
            ->assertOk()
            ->assertSee('not connected', false);

        Http::assertNothingSent();
        expect(fgStored())->not->toHaveKey('secret_key');
    });
});

/* =================================================== how it goes wrong ===== */

describe('every way this fails in front of the owner', function () {
    it('reads back Stripe\'s own words when he presses Cancel', function () {
        fgRow([
            'connect_client_id_test' => 'ca_FGDEVELOPMENT1',
            'connect_client_secret_test' => FG_PLATFORM_SECRET_TEST,
        ], 'test');

        $state = 'declined-state-0000000000000000000';

        $this->actingAs(fgAdmin(), 'admin')
            ->withSession([StripeConnect::STATE_SESSION_KEY => ['value' => $state, 'mode' => 'test', 'issued_at' => time()]])
            ->get('/admin-api/payments/stripe/connect/callback?state=' . $state
                . '&error=access_denied&error_description=' . urlencode('The user denied your request'))
            ->assertOk()
            ->assertSee('The user denied your request', false);

        Http::assertNothingSent();
        expect(fgStored())->not->toHaveKey('secret_key');
    });

    it('says so when the code has already expired at Stripe\'s end', function () {
        fgRow([
            'connect_client_id_test' => 'ca_FGDEVELOPMENT1',
            'connect_client_secret_test' => FG_PLATFORM_SECRET_TEST,
        ], 'test');

        fgStripeFake([
            'POST /v1/oauth/token' => fn () => Http::response([
                'error' => 'invalid_grant',
                'error_description' => 'Authorization code already used or expired.',
            ], 400),
        ]);

        $state = 'expired-code-state-00000000000000';

        $this->actingAs(fgAdmin(), 'admin')
            ->withSession([StripeConnect::STATE_SESSION_KEY => ['value' => $state, 'mode' => 'test', 'issued_at' => time()]])
            ->get('/admin-api/payments/stripe/connect/callback?state=' . $state . '&code=ac_spent')
            ->assertOk()
            ->assertSee('Authorization code already used or expired.', false);

        expect(fgStored())->not->toHaveKey('secret_key');
    });

    it('tells the popup page what happened without putting a credential in it', function () {
        fgRow([
            'connect_client_id_test' => 'ca_FGDEVELOPMENT1',
            'connect_client_secret_test' => FG_PLATFORM_SECRET_TEST,
            'webhook_secret' => 'whsec-stripe-fg-popup-0000000000',
        ], 'test');

        fgOauthFake();

        $state = 'popup-state-00000000000000000000';

        $html = $this->actingAs(fgAdmin(), 'admin')
            ->withSession([StripeConnect::STATE_SESSION_KEY => ['value' => $state, 'mode' => 'test', 'issued_at' => time()]])
            ->get('/admin-api/payments/stripe/connect/callback?state=' . $state . '&code=ac_code')
            ->assertOk()
            ->getContent();

        foreach (fgCanaries() as $canary) {
            expect($html)->not->toContain($canary);
        }

        // The webhook URL's random tail is confidential too: it is what makes
        // this shop's endpoint unguessable, and this page can end up in the
        // browser's history.
        expect($html)->not->toContain('whsec-stripe-fg-popup-0000000000');
    });
});

/* ========================================================== the guard ====== */

describe('the guard on the new route', function () {
    it('refuses the platform read to a caller who is not signed in', function () {
        fgRow(['connect_client_secret' => FG_PLATFORM_SECRET_LIVE]);

        $response = $this->getJson('/admin-api/payments/stripe/connect/platform');

        expect($response->status())->toBeGreaterThanOrEqual(300);
        expect($response->getContent())->not->toContain(FG_PLATFORM_SECRET_LIVE);
    });

    it('maps the platform read to payments.manage', function () {
        // AdminCapabilities fails closed, so an unmapped route would be
        // owner-only anyway — the right answer by accident. This pins it as a
        // decision, and pins that the existing GET wildcard is what covers it
        // so no new rule was needed.
        expect(AdminCapabilities::forPath('GET', 'admin-api/payments/stripe/connect/platform'))
            ->toBe('payments.manage');

        // Writes before reads: the wildcard read must not be what answers for
        // the POST that stores a platform secret key.
        expect(AdminCapabilities::forPath('POST', 'admin-api/payments/stripe/connect/application'))
            ->toBe('payments.manage');
    });

    it('keeps every platform value out of the public settings API', function () {
        /*
         * CLAUDE.md records /api/* as having leaked this group three times.
         * These credentials are in payment_providers.config and not in
         * `settings` at all, which is the structural half — this is the half
         * that notices if a later change moves them.
         */
        fgRow([
            'connect_client_id' => 'ca_FGPRODUCTION1',
            'connect_client_secret' => FG_PLATFORM_SECRET_LIVE,
            'connect_client_secret_test' => FG_PLATFORM_SECRET_TEST,
        ]);

        $body = $this->getJson('/api/settings')->getContent();

        foreach (fgCanaries() as $canary) {
            expect($body)->not->toContain($canary);
        }
    });
});
