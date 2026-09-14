<?php

/**
 * The payments admin screen — Store → Payments.
 *
 * PaymentSecretsTest covers the controller directly and PaymentWiringTest
 * covers the guard on the two routes. This file covers the screen the owner
 * actually uses: the same endpoint driven over HTTP exactly as the page drives
 * it, plus the rendered admin document itself.
 *
 * The one thing every assertion here is protecting: a live gateway secret must
 * not reach the DOM. Once it is in the page it is in browser history, in every
 * extension with host access, and in any screenshot of the screen. The screen
 * therefore renders "a secret is stored", never the secret — and these tests
 * assert the absence of specific canary strings rather than a response shape,
 * the same way ApiSecurityTest and PaymentSecretsTest do, because a failure
 * here is a live Stripe key somewhere it cannot be recalled from.
 */

use App\Models\AdminUser;
use App\Models\PaymentProvider;
use App\Services\Payments\GatewayCredentials;
use Illuminate\Support\Facades\DB;

/** Distinctive enough that finding one anywhere is unambiguous. */
const SCREEN_STRIPE_SECRET = 'sk_live_KBBSCREENCANARY00000001';
const SCREEN_STRIPE_WHSIG = 'whsec_KBBSCREENCANARY00000002';
const SCREEN_TABBY_SECRET = 'sk_KBBSCREENCANARY00000003';
const SCREEN_TAMARA_NOTIFY = 'tamara_notify_KBBSCREENCANARY4';

const SCREEN_CANARIES = [
    SCREEN_STRIPE_SECRET,
    SCREEN_STRIPE_WHSIG,
    SCREEN_TABBY_SECRET,
    SCREEN_TAMARA_NOTIFY,
];

/* ------------------------------------------------------- the guard ---- */

it('rejects an unauthenticated caller on every verb the screen could use', function () {
    // GET and POST are the two the screen issues. The rest are asserted so a
    // later route addition cannot quietly open a hole next to them: whatever
    // the status, it must never be a 2xx.
    $calls = [
        fn () => $this->getJson('/admin-api/payments'),
        fn () => $this->postJson('/admin-api/payments', ['id' => 'stripe', 'settings' => []]),
        fn () => $this->putJson('/admin-api/payments', ['id' => 'stripe']),
        fn () => $this->patchJson('/admin-api/payments', ['id' => 'stripe']),
        fn () => $this->deleteJson('/admin-api/payments'),
    ];

    foreach ($calls as $call) {
        expect($call()->status())->toBeGreaterThanOrEqual(300);
    }

    // And the two real verbs are specifically the admin guard talking, not a
    // routing accident.
    $this->getJson('/admin-api/payments')->assertStatus(401);
    $this->postJson('/admin-api/payments', ['id' => 'stripe', 'settings' => []])->assertStatus(401);
});

it('writes nothing when an unauthenticated caller tries to configure a gateway', function () {
    $this->postJson('/admin-api/payments', [
        'id' => 'stripe',
        'enabled' => true,
        'settings' => ['secret_key' => SCREEN_STRIPE_SECRET],
    ]);

    expect(PaymentProvider::whereKey('stripe')->exists())->toBeFalse();
});

/* --------------------------------------------- signed in as an admin ---- */

describe('signed in as an admin', function () {
    beforeEach(function () {
        PaymentProvider::query()->delete();
        app(GatewayCredentials::class)->forget();

        $this->admin = AdminUser::create([
            'name' => 'Pay Admin',
            'email' => 'pay-admin@example.test',
            'password' => 'password-long-enough',
            'role' => 'owner',
        ]);

        $this->actingAs($this->admin, 'admin');
    });

    it('stores a credential typed into the screen, encrypted rather than in the clear', function () {
        $this->postJson('/admin-api/payments', [
            'id' => 'stripe',
            'enabled' => true,
            'mode' => 'live',
            'settings' => [
                'publishable_key' => 'pk_live_safe_to_show',
                'secret_key' => SCREEN_STRIPE_SECRET,
                'webhook_signing_secret' => SCREEN_STRIPE_WHSIG,
            ],
        ])->assertOk()->assertJsonPath('ok', true);

        app(GatewayCredentials::class)->forget();

        // It really was stored — a test that only checked for absence would
        // pass just as well against a save that silently did nothing.
        expect(app(GatewayCredentials::class)->get('stripe', 'secret_key'))
            ->toBe(SCREEN_STRIPE_SECRET);

        // Straight at the column, past the model's encrypted cast.
        $stored = DB::table('payment_providers')->where('id', 'stripe')->value('config');

        expect($stored)->toBeString();

        foreach ([SCREEN_STRIPE_SECRET, SCREEN_STRIPE_WHSIG] as $canary) {
            expect($stored)->not->toContain($canary);
        }
    });

    it('does not hand the secret back when the screen reloads', function () {
        $this->postJson('/admin-api/payments', [
            'id' => 'stripe',
            'settings' => ['secret_key' => SCREEN_STRIPE_SECRET, 'publishable_key' => 'pk_live_safe_to_show'],
        ])->assertOk();

        $response = $this->getJson('/admin-api/payments')->assertOk();

        expect($response->getContent())->not->toContain(SCREEN_STRIPE_SECRET);

        $stripe = collect($response->json('gateways'))->firstWhere('id', 'stripe');
        $secret = collect($stripe['fields'])->firstWhere('key', 'secret_key');

        // Empty, not masked — a mask still discloses the length. The flag is
        // what the card renders as "stored".
        expect($secret['value'])->toBe('')
            ->and($secret['has_value'])->toBeTrue();

        // The non-secret half is still shown, or the screen would be unusable.
        expect(collect($stripe['fields'])->firstWhere('key', 'publishable_key')['value'])
            ->toBe('pk_live_safe_to_show');
    });

    /*
     * Also the regression test for a stale read: the load-save-reload here is
     * exactly what the screen does, and GatewayCredentials memoises per
     * instance. Before show() dropped that memo, the second GET reported
     * "not configured" over credentials the POST had just stored.
     */
    it('reports plainly whether each gateway is configured, including right after a save', function () {
        $body = $this->getJson('/admin-api/payments')->assertOk()->json('gateways');
        $byId = collect($body)->keyBy('id');

        // Nothing saved yet: every credential-bearing gateway is unconfigured,
        // which is what the card has to say rather than leaving the owner to
        // discover it at the till.
        expect($byId['stripe']['configured'])->toBeFalse()
            ->and($byId['tabby']['configured'])->toBeFalse()
            ->and($byId['tamara']['configured'])->toBeFalse()
            // COD needs no account with anyone.
            ->and($byId['cod']['configured'])->toBeTrue();

        $this->postJson('/admin-api/payments', [
            'id' => 'tabby',
            'enabled' => true,
            'settings' => [
                'public_key' => 'pk_test_tabby',
                'secret_key' => SCREEN_TABBY_SECRET,
                'merchant_code' => 'AE',
            ],
        ])->assertOk();

        $after = collect($this->getJson('/admin-api/payments')->json('gateways'))->keyBy('id');

        expect($after['tabby']['configured'])->toBeTrue()
            ->and($after['tabby']['enabled'])->toBeTrue();
    });

    it('gives each gateway a webhook URL the owner can paste into the provider', function () {
        $save = $this->postJson('/admin-api/payments', [
            'id' => 'tamara',
            'enabled' => true,
            'settings' => [
                'api_token' => 'tamara_api_screen',
                'notification_token' => SCREEN_TAMARA_NOTIFY,
            ],
        ])->assertOk();

        $url = $save->json('webhook_url');

        expect($url)->toBeString()
            ->and($url)->toContain('/api/payments/webhook/tamara/');

        // The same URL comes back on a reload, so the screen can show it
        // without the owner having to catch it once at save time.
        $tamara = collect($this->getJson('/admin-api/payments')->json('gateways'))
            ->firstWhere('id', 'tamara');

        expect($tamara['webhook_url'])->toBe($url);

        // COD has no webhook and must not be given a URL to paste anywhere.
        $cod = collect($this->getJson('/admin-api/payments')->json('gateways'))
            ->firstWhere('id', 'cod');

        expect($cod['webhook_url'])->toBeNull();
    });

    it('regenerates the URL secret when the screen clears it, and only then', function () {
        $first = $this->postJson('/admin-api/payments', [
            'id' => 'stripe',
            'settings' => ['secret_key' => SCREEN_STRIPE_SECRET],
        ])->json('webhook_url');

        // An ordinary save leaves it alone — otherwise every edit would silently
        // break the endpoint already pasted into the provider dashboard.
        $again = $this->postJson('/admin-api/payments', [
            'id' => 'stripe',
            'settings' => ['publishable_key' => 'pk_live_safe_to_show'],
        ])->json('webhook_url');

        expect($again)->toBe($first);

        // The Regenerate button posts an explicit null, which clears the key;
        // the controller then finds it empty and mints a fresh one.
        $regenerated = $this->postJson('/admin-api/payments', [
            'id' => 'stripe',
            'settings' => ['webhook_secret' => null],
        ])->json('webhook_url');

        expect($regenerated)->toBeString()->and($regenerated)->not->toBe($first);
    });

    it('never puts a gateway secret into the admin document the screen lives in', function () {
        foreach ([
            ['id' => 'stripe', 'settings' => ['secret_key' => SCREEN_STRIPE_SECRET, 'webhook_signing_secret' => SCREEN_STRIPE_WHSIG]],
            ['id' => 'tabby', 'settings' => ['public_key' => 'pk', 'secret_key' => SCREEN_TABBY_SECRET, 'merchant_code' => 'AE']],
            ['id' => 'tamara', 'settings' => ['api_token' => 'tok', 'notification_token' => SCREEN_TAMARA_NOTIFY]],
        ] as $payload) {
            $this->postJson('/admin-api/payments', $payload + ['enabled' => true])->assertOk();
        }

        // The console is one big document served to the browser. Nothing about
        // the payments screen may be baked into it — it fetches at render time,
        // behind the guard.
        $html = view('admin.app')->render();

        foreach (SCREEN_CANARIES as $canary) {
            expect($html)->not->toContain($canary);
        }

        // It is the real screen, not the old mock frame: it talks to the guarded
        // endpoint and draws a save button per gateway.
        expect($html)->toContain('/admin-api/payments')
            ->and($html)->toContain('data-paysave')
            ->and($html)->toContain('data-paycopy');
    });
});

/* ------------------------------------------------ the public surface ---- */

it('keeps every gateway secret out of the public settings endpoint', function () {
    $admin = AdminUser::create([
        'name' => 'Pay Admin 2',
        'email' => 'pay-admin-2@example.test',
        'password' => 'password-long-enough',
        'role' => 'owner',
    ]);

    $this->actingAs($admin, 'admin');

    foreach ([
        ['id' => 'stripe', 'settings' => ['secret_key' => SCREEN_STRIPE_SECRET, 'webhook_signing_secret' => SCREEN_STRIPE_WHSIG]],
        ['id' => 'tabby', 'settings' => ['public_key' => 'pk', 'secret_key' => SCREEN_TABBY_SECRET, 'merchant_code' => 'AE']],
        ['id' => 'tamara', 'settings' => ['api_token' => 'tok', 'notification_token' => SCREEN_TAMARA_NOTIFY]],
    ] as $payload) {
        $this->postJson('/admin-api/payments', $payload + ['enabled' => true])->assertOk();
    }

    // Saved through the screen, so this is the path the credentials really
    // take — not a hand-built row that a refactor could stop resembling.
    expect(PaymentProvider::whereKey('stripe')->exists())->toBeTrue();

    // Signed out: /api/* is unauthenticated by design and anyone can read this.
    auth('admin')->logout();

    $body = $this->getJson('/api/settings')->assertOk()->getContent();

    foreach (SCREEN_CANARIES as $canary) {
        expect($body)->not->toContain($canary);
    }

    // The webhook URL secret is not public either: it is what makes the
    // endpoint unguessable, and it belongs on the admin screen alone.
    expect($body)->not->toContain('/api/payments/webhook/');
});
