<?php

/*
 * Integration cover for what Lane D could not reach: until the route files were
 * required from api.php and web.php, nothing dispatched to these handlers, so
 * the lane tested the controllers directly and the wiring by inspection. These
 * go over HTTP against the routes as actually registered.
 */

use App\Models\PaymentProvider;

// A wrong secret of the right SHAPE. Too short and the route simply does not
// match, the GET-only fallback claims the URI and the answer is 405 -- which
// looks like a rejection but proves nothing about the handler.
const WRONG_SECRET = 'wrongbutwellformedsecret0123456789';

it('rejects a webhook carrying a wrong URL secret', function () {
    $r = $this->postJson('/api/payments/webhook/stripe/'.WRONG_SECRET, ['id' => 'evt_1']);

    expect($r->status())->toBeIn([401, 403, 404])
        ->and($r->status())->not->toBe(200);
});

it('rejects a webhook for an unknown gateway', function () {
    $r = $this->postJson('/api/payments/webhook/nosuchgateway/'.WRONG_SECRET, []);

    expect($r->status())->toBeIn([401, 403, 404]);
});

it('reaches the handler rather than the fallback, for a well-formed URL', function () {
    // web.php's Route::fallback is GET-only, so an unmatched POST answers 405.
    // A 405 here would mean the webhook route is not registered at all and the
    // rejections above are meaningless.
    $r = $this->postJson('/api/payments/webhook/stripe/'.WRONG_SECRET, []);

    expect($r->status())->not->toBe(405);
});

it('keeps gateway credentials behind the admin guard', function () {
    $this->getJson('/admin-api/payments')->assertStatus(401);
    $this->postJson('/admin-api/payments', ['gateway' => 'stripe'])->assertStatus(401);
});

it('does not expose any gateway secret through the public settings endpoint', function () {
    // The primary key is `id`, not `code`. An earlier version of this test
    // filtered on `code`, matched nothing, wrote nothing, and then "proved" no
    // secret leaked -- a test that could never fail. Create the row outright.
    PaymentProvider::query()->updateOrCreate(
        ['id' => 'stripe'],
        [
            'title' => 'Stripe',
            'enabled' => true,
            'config' => ['secret_key' => 'sk_live_LEAKCANARY', 'webhook_secret' => 'whsec_LEAKCANARY'],
        ],
    );

    expect(PaymentProvider::whereKey('stripe')->exists())->toBeTrue();

    $body = $this->getJson('/api/settings')->assertOk()->getContent();

    expect($body)->not->toContain('LEAKCANARY')
        ->not->toContain('secret_key')
        ->not->toContain('whsec_');
});
