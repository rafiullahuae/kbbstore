<?php

declare(strict_types=1);

/*
 * Lane DS: Platform -> Domain switch -> 1b "Payments ready?".
 *
 * The owner, 8 October 2026: "before switch, i need to finalized check for
 * payment gateways." Before this there was no single place that asked the
 * providers anything: the Stripe status block reads only the shop's own rows,
 * and a webhook still registered on extrabeauty.ae after the switch, a
 * disabled endpoint, a missing event, Tabby holding the wrong environment or
 * Tamara having forgotten the registration were each silent until a real
 * payment went unconfirmed. Every provider is faked at the HTTP boundary.
 */

use App\Models\AdminUser;
use App\Models\PaymentProvider;
use App\Models\Setting;
use App\Services\Payments\Gateways\TabbyGateway;
use App\Services\Payments\Gateways\TamaraGateway;
use App\Services\Payments\PaymentsReadiness;
use App\Services\Payments\StripeConnect;
use App\Services\Payments\Wallets;
use App\Services\SettingsService;
use App\Support\AdminCapabilities;
use App\Support\SiteHost;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\DomainSwitchRoutes;

/** Every secret the shop holds in these tests. None may appear in an answer. */
const PR_SECRETS = [
    'sk_live_' . 'PRstripeSECRETkeyAAAAAAAA9z1q', 'urlsecretSTRIPEprAAAAAAAAAAAAAAAAbb12', 'whsec_PRsigningSECRETzzzzzzzz77',
    'sk_PRtabbySECRET-aaaa-bbbb-cccc-dddd88aa', 'urlsecretTABBYprAAAAAAAAAAAAAAAAAAcc34',
    'eyJPRtamaraAPITOKENxxxxxxxxxxxxxx.yyyy.zz56', 'PRtamaraNOTIFICATIONtoken99', 'urlsecretTAMARAprAAAAAAAAAAAAAAAAAdd78',
];

function prOwner(string $role = 'owner'): AdminUser
{
    return AdminUser::create(['name' => 'PR '.$role, 'email' => 'pr-'.$role.'-'.uniqid().'@example.test', 'password' => 'secret-secret', 'role' => $role]);
}

/** $serving: APP_URL's host; $main: the main address (Platform -> Site address). */
function prShop(string $serving, string $main): void
{
    config(['app.url' => 'https://'.$serving]);

    foreach ([SiteHost::KEY_CANONICAL => $main, SiteHost::KEY_ALIASES => $main === 'kbeautybliss.com' ? 'extrabeauty.ae' : '',
        SiteHost::KEY_REDIRECT => '0', SiteHost::KEY_VISIBILITY => 'public'] as $key => $value) {
        Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }

    app(SettingsService::class)->flush();
    Setting::flushMap();
    SiteHost::forget();
}

function prProviders(array $codRules = []): void
{
    PaymentProvider::query()->updateOrCreate(['id' => 'stripe'], ['title' => 'Card', 'enabled' => true, 'mode' => 'live', 'position' => 1, 'config' => [
        'secret_key' => PR_SECRETS[0], 'publishable_key' => 'pk_live_' . 'PRpublishableKEY0000000pk99',
        'webhook_secret' => PR_SECRETS[1], 'webhook_endpoint_id' => 'we_pr', 'webhook_signing_secret' => PR_SECRETS[2],
    ]]);
    PaymentProvider::query()->updateOrCreate(['id' => 'tabby'], ['title' => 'Tabby', 'enabled' => true, 'mode' => 'live', 'position' => 2, 'config' => [
        'secret_key' => PR_SECRETS[3], 'public_key' => 'pk_PRtabbyPUBLIC-aaaa-bbbb-cccc-dddd0000', 'merchant_code' => 'AE', 'webhook_secret' => PR_SECRETS[4],
    ]]);
    PaymentProvider::query()->updateOrCreate(['id' => 'tamara'], ['title' => 'Tamara', 'enabled' => true, 'mode' => 'live', 'position' => 3, 'config' => [
        'api_token' => PR_SECRETS[5], 'notification_token' => PR_SECRETS[6], 'webhook_secret' => PR_SECRETS[7], 'webhook_id' => 'tw-pr-1',
    ]]);
    PaymentProvider::query()->updateOrCreate(['id' => 'cod'], ['title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'live', 'position' => 0]);

    foreach ($codRules as $key => $value) {
        Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }

    app(\App\Services\Payments\GatewayCredentials::class)->forget();
}

/**
 * The three providers, answering as $world says. Every request is recorded.
 *
 * @param  array<string, mixed>  $world
 */
function prFake(array $world): void
{
    $host = $world['hook_host'] ?? 'kbeautybliss.com';

    // A second world replaces the first: Http::fake() alone would append.
    Http::swap(new \Illuminate\Http\Client\Factory(app('events')));
    Http::fake(function (ClientRequest $r) use ($world, $host) {
        $u = $r->url();
        $p = (string) parse_url($u, PHP_URL_PATH);

        if (str_starts_with($u, PaymentsReadiness::STRIPE_API)) {
            return match (true) {
                $p === '/v1/account' => ($world['stripe_key'] ?? 'ok') === 'bad'
                    ? Http::response(['error' => ['message' => 'Invalid API Key provided: '.PR_SECRETS[0]]], 401)
                    : Http::response(['id' => 'acct_pr', 'charges_enabled' => true, 'business_profile' => ['name' => 'K-Beauty Bliss']]),
                $p === '/v1/webhook_endpoints/we_pr' => Http::response([
                    'id' => 'we_pr', 'status' => $world['stripe_status'] ?? 'enabled',
                    'url' => 'https://'.$host.'/api/payments/webhook/stripe/'.PR_SECRETS[1],
                    'enabled_events' => $world['stripe_events'] ?? StripeConnect::EVENTS,
                ]),
                $p === '/v1/payment_method_domains' => (function () use ($u, $world) {
                    parse_str((string) parse_url($u, PHP_URL_QUERY), $q);
                    $name = (string) ($q['domain_name'] ?? '');

                    return Http::response(['data' => in_array($name, $world['wallet_domains'] ?? [], true)
                        ? [['domain_name' => $name, 'enabled' => true, 'apple_pay' => ['status' => 'active']]] : []]);
                })(),
                default => Http::response(['error' => 'unexpected'], 404),
            };
        }

        if (str_starts_with($u, PaymentsReadiness::TABBY_API)) {
            if (($world['tabby'] ?? 'ok') === 'refused') {
                return Http::response(['status' => 'error', 'errorType' => 'not_authorized', 'error' => 'secret '.PR_SECRETS[3]], 401);
            }

            return Http::response([['id' => 'wh_pr', 'url' => 'https://'.$host.'/api/payments/webhook/tabby/'.PR_SECRETS[4], 'is_test' => $world['tabby_is_test'] ?? false]]);
        }

        if (str_starts_with($u, PaymentsReadiness::TAMARA_LIVE)) {
            return match (true) {
                str_starts_with($p, '/checkout/payment-types') => Http::response([['name' => 'PAY_BY_INSTALMENTS']]),
                $p === '/webhooks/tw-pr-1' => ($world['tamara_hook'] ?? 'ok') === 'gone'
                    ? Http::response(['message' => 'Not found'], 404)
                    : Http::response(['webhook_id' => 'tw-pr-1', 'url' => 'https://'.$host.'/api/payments/webhook/tamara/'.PR_SECRETS[7], 'events' => TamaraGateway::WEBHOOK_EVENTS]),
                default => Http::response([], 404),
            };
        }

        return Http::response('unexpected '.$u, 599);
    });
}

function prRun(string $origin = 'https://kbeautybliss.com'): array
{
    $r = test()->postJson($origin.'/admin-api/domain-switch/payments-check', []);
    $r->assertOk();

    // NO SECRET LEAVES: not a key, not a webhook secret, not a token.
    /* MUTATION: print $secret instead of self::last4($secret) in the Keys
       line, or the registered URL instead of maskUrl() -> red. */
    foreach (PR_SECRETS as $secret) {
        expect($r->getContent())->not->toContain($secret)->not->toContain(substr($secret, 0, -4));
    }

    return $r->json();
}

/** @return array<string, array{level: string, detail: string, fix: ?string}> title => check, for one provider */
function prChecks(array $result, string $provider): array
{
    $p = collect($result['providers'])->firstWhere('id', $provider);

    return collect($p['checks'])->keyBy('title')->all();
}

beforeEach(function () {
    DomainSwitchRoutes::wire($this->app);
    Cache::flush();
    prProviders();
    $this->actingAs(prOwner(), 'admin');
});

it('is all green after the switch when every provider points at the main address', function () {
    prShop('kbeautybliss.com', 'kbeautybliss.com');
    prFake([]);

    $r = prRun();

    expect($r['main'])->toBe('kbeautybliss.com')->and($r['switching'])->toBeFalse()
        ->and($r['counts']['red'])->toBe(0);

    foreach (['stripe', 'tabby', 'tamara', 'cod'] as $id) {
        expect(collect($r['providers'])->firstWhere('id', $id)['level'])->toBe('green', $id);
    }

    expect(prChecks($r, 'stripe')['Webhook']['detail'])->toContain('https://kbeautybliss.com/api/payments/webhook/stripe/…')
        ->and(prChecks($r, 'stripe')['Keys']['detail'])->toContain('…9z1q');

    // READ ONLY: nothing but GETs left this server.
    /* MUTATION: make get() a post() -> red. */
    Http::assertNotSent(fn (ClientRequest $req) => $req->method() !== 'GET');
});

it('reads amber before the switch: right for today, to be re-registered at step 7', function () {
    prShop('extrabeauty.ae', 'kbeautybliss.com');
    prFake(['hook_host' => 'extrabeauty.ae']);

    $r = prRun('https://extrabeauty.ae');

    expect($r['switching'])->toBeTrue()->and($r['serving'])->toBe('extrabeauty.ae')
        ->and(prChecks($r, 'stripe')['Webhook']['level'])->toBe('amber')
        ->and(prChecks($r, 'stripe')['Webhook']['fix'])->toContain('After step '.\App\Services\DomainMove\SwitchInstaller::num('switch').' of Platform → Domain switch')
        ->and(prChecks($r, 'tabby')['Webhook']['level'])->toBe('amber')
        ->and(prChecks($r, 'tamara')['Webhook']['level'])->toBe('amber')
        ->and($r['counts']['red'])->toBe(0);
});

it('turns red with a plain fix for every way a gateway can be wrong after the switch', function () {
    /* MUTATION: drop the host comparison in pointsAt() (green on any exact
       match) -> the extrabeauty.ae webhooks below read green after the
       switch, which is the failure this screen exists to catch. */
    prShop('kbeautybliss.com', 'kbeautybliss.com');
    prProviders(['cod_fee' => '7500']);
    prFake(['hook_host' => 'extrabeauty.ae', 'stripe_status' => 'disabled', 'stripe_events' => ['payment_intent.succeeded'],
        'tabby_is_test' => true, 'tamara_hook' => 'gone']);

    $r = prRun();
    $stripe = prChecks($r, 'stripe');
    $tabby = prChecks($r, 'tabby');
    $tamara = prChecks($r, 'tamara');

    expect($stripe['Webhook']['level'])->toBe('red')
        ->and($stripe['Webhook']['detail'])->toContain('Still points at extrabeauty.ae')
        ->and($stripe['Webhook']['fix'])->toContain('Set up webhook automatically')
        ->and($stripe['Webhook switched on']['level'])->toBe('red')
        ->and($stripe['Webhook events']['detail'])->toContain('payment_intent.canceled')
        ->and($tabby['Webhook']['level'])->toBe('red')
        ->and($tabby['Webhook']['fix'])->toContain('Register / re-sync')
        ->and($tabby['Webhook environment']['level'])->toBe('red')
        ->and($tamara['Webhook']['level'])->toBe('red')
        ->and($tamara['Webhook']['fix'])->toContain('Remove the registration')
        ->and(prChecks($r, 'cod')['Fee']['level'])->toBe('amber')
        // Words, not markup: Money::format()'s <span>s were printed as text on the screen.
        /* MUTATION: Money::plain -> Money::format in cod() -> red. */
        ->and(prChecks($r, 'cod')['Fee']['detail'])->not->toContain('<');

    // Every red carries a fix.
    foreach ($r['providers'] as $p) {
        foreach ($p['checks'] as $c) {
            if ($c['level'] === 'red') {
                expect($c['fix'])->not->toBeNull($p['id'].' '.$c['title']);
            }
        }
    }
});

it('says when a provider refuses the keys, without repeating the provider’s words', function () {
    prShop('kbeautybliss.com', 'kbeautybliss.com');
    prFake(['stripe_key' => 'bad', 'tabby' => 'refused']);

    $r = prRun();

    expect(prChecks($r, 'stripe')['Stripe account']['level'])->toBe('red')
        ->and(prChecks($r, 'stripe')['Stripe account']['detail'])->toContain('…9z1q')
        ->and(prChecks($r, 'tabby')['Tabby account']['level'])->toBe('red')
        ->and(json_encode($r))->not->toContain('Invalid API Key provided');
});

it('checks Apple Pay domains only when wallets are offered: red where the shop takes payment, amber where it is moving', function () {
    app()->instance(Wallets::class, new class extends Wallets
    {
        public function any(): bool
        {
            return true;
        }
    });
    prShop('extrabeauty.ae', 'kbeautybliss.com');
    prFake(['hook_host' => 'extrabeauty.ae', 'wallet_domains' => []]);

    $c = prChecks(prRun('https://extrabeauty.ae'), 'stripe');

    expect($c['Apple Pay / Google Pay · extrabeauty.ae']['level'])->toBe('red')
        ->and($c['Apple Pay / Google Pay · kbeautybliss.com']['level'])->toBe('amber')
        ->and($c['Apple Pay / Google Pay · kbeautybliss.com']['fix'])->toContain('Payment method domains → Add kbeautybliss.com');

    prFake(['hook_host' => 'extrabeauty.ae', 'wallet_domains' => ['extrabeauty.ae', 'kbeautybliss.com']]);
    $c = prChecks(prRun('https://extrabeauty.ae'), 'stripe');
    expect($c['Apple Pay / Google Pay · kbeautybliss.com']['level'])->toBe('green');
});

it('calls no provider until the button is pressed, and refuses anyone without payments.check', function () {
    prShop('kbeautybliss.com', 'kbeautybliss.com');
    Http::fake();

    // Opening the wizard: no outbound request at all.
    $this->getJson('https://kbeautybliss.com/admin-api/domain-switch')->assertOk();
    Http::assertNothingSent();

    /* MUTATION: drop the payments.check rule from AdminCapabilities::RULES
       -> the path falls to the domain-switch wildcard and this reads
       platform.domain_switch; drop the key from the map -> owner-only by
       the closed default, and the roleCan line goes red. */
    expect(AdminCapabilities::forPath('POST', 'admin-api/domain-switch/payments-check'))->toBe('payments.check')
        ->and(AdminCapabilities::roleCan('owner', 'payments.check'))->toBeTrue()
        ->and(AdminCapabilities::roleCan('manager', 'payments.check'))->toBeFalse();

    $this->actingAs(prOwner('manager'), 'admin');
    $this->postJson('https://kbeautybliss.com/admin-api/domain-switch/payments-check', [])->assertForbidden();
    // No GET: a prefetch or a reload must never start provider calls.
    expect($this->getJson('https://kbeautybliss.com/admin-api/domain-switch/payments-check')->status())->toBeIn([404, 405]);
    Http::assertNothingSent();
});

it('talks to the same hosts the gateways do', function () {
    /* MUTATION: change a host constant here or in a gateway -> red, so the
       check can never ask a different server than the one that takes money. */
    $const = fn (string $class, string $name) => (new ReflectionClassConstant($class, $name))->getValue();

    expect(PaymentsReadiness::TABBY_API)->toBe($const(TabbyGateway::class, 'API'))
        ->and(PaymentsReadiness::TAMARA_LIVE)->toBe($const(TamaraGateway::class, 'LIVE'))
        ->and(PaymentsReadiness::TAMARA_SANDBOX)->toBe($const(TamaraGateway::class, 'SANDBOX'))
        ->and(PaymentsReadiness::STRIPE_API)->toBe($const(StripeConnect::class, 'API'));
});
