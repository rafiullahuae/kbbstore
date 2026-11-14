<?php

declare(strict_types=1);

use App\Services\Quiqup\QuiqupClient;
use App\Services\Quiqup\QuiqupErrors;
use App\Services\Quiqup\QuiqupSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\QuiqupKit;

/*
 * The REAL QuiqupClient against the documented requests and answers
 * (docs/plans/quiqup/api-docs, fixtures copied into tests/Fixtures/quiqup/).
 * Http::fake() answers; Http::preventStrayRequests() makes any call that is
 * not faked throw -- so this file can never reach api.staging.quiqup.com.
 *
 * Quiqup ignores unknown fields silently, so a misspelt path or key "works"
 * and does nothing. These tests are what notice.
 */

beforeEach(function () {
    Http::preventStrayRequests();
    QuiqupKit::configure('sandbox', 'qq_sandbox_key_1234');
});

it('sends Bearer auth to the fixed staging host, and to the production host only for live', function () {
    Http::fake(['*' => Http::response(QuiqupKit::json('list-response.json'))]);

    app(QuiqupClient::class)->ping('sandbox');
    QuiqupKit::configure('live', 'qq_live_key_9876');
    app(QuiqupClient::class)->ping('live');

    $sent = Http::recorded();
    expect($sent[0][0]->url())->toStartWith('https://api.staging.quiqup.com/orders?')
        ->and($sent[0][0]->header('Authorization')[0])->toBe('Bearer qq_sandbox_key_1234')
        ->and($sent[1][0]->url())->toStartWith('https://api-ae.quiqup.com/orders?')
        ->and($sent[1][0]->header('Authorization')[0])->toBe('Bearer qq_live_key_9876')
        ->and(QuiqupSettings::HOSTS)->toBe(['sandbox' => 'https://api.staging.quiqup.com', 'live' => 'https://api-ae.quiqup.com']);
});

it('builds the list query the docs name: page, per_page, from, to, filters[state]', function () {
    Http::fake(['*' => Http::response(QuiqupKit::json('list-response.json'))]);

    $r = app(QuiqupClient::class)->listOrders('sandbox', ['page' => 2, 'per_page' => 100, 'from' => '2026-11-01', 'to' => '2026-11-14', 'state' => 'pending']);

    Http::assertSent(function (Request $req) {
        parse_str((string) parse_url($req->url(), PHP_URL_QUERY), $q);

        return $req->method() === 'GET' && str_starts_with($req->url(), 'https://api.staging.quiqup.com/orders?')
            && $q === ['page' => '2', 'per_page' => '100', 'from' => '2026-11-01', 'to' => '2026-11-14', 'filters' => ['state' => 'pending']];
    });
    expect($r->ok())->toBeTrue()->and($r->json['results'][0]['state'])->toBe('delivery_complete');
});

it('reads the documented create response (201) and its idempotent replay (200) the same way', function () {
    Http::fakeSequence()
        ->push(QuiqupKit::json('create-response.json'), 201)
        ->push(QuiqupKit::json('create-response.json'), 200);

    $c = app(QuiqupClient::class);
    $a = $c->createOrder('sandbox', ['uuid' => '00000000-0000-4000-8000-000000000001']);
    $b = $c->createOrder('sandbox', ['uuid' => '00000000-0000-4000-8000-000000000001']);

    expect($a->status)->toBe(201)->and($b->status)->toBe(200)
        ->and($a->order()['id'])->toBe(10000001)->and($b->order()['id'])->toBe(10000001)
        ->and($a->ok())->toBeTrue()->and($b->ok())->toBeTrue();
    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === 'https://api.staging.quiqup.com/orders' && $r['uuid'] === '00000000-0000-4000-8000-000000000001');
});

it('turns the documented error envelopes into Quiqup\'s own words plus our hint', function () {
    $c = fn (string $fixture, int $status) => QuiqupErrors::describe($status, QuiqupKit::json($fixture));

    $a1 = $c('error-422-address1.json', 422);
    expect($a1->error)->toBe('HTTP 422 — [destination][address][address1] is missing')->and($a1->retryable())->toBeFalse();

    $empty = $c('error-422-empty.json', 422);
    expect($empty->error)->toBe('HTTP 422 — (Quiqup sent no message)')->and($empty->hint)->toContain('service type or the payment mode');

    $unauth = $c('error-401.json', 401);
    expect($unauth->error)->toBe('HTTP 401 — unauthorized')->and($unauth->hint)->toContain('rejected the API key');

    $policy = $c('error-422-policy.json', 422);
    expect($policy->hint)->toContain('hourly order limit')->toContain('account manager');

    $dup = $c('error-422-api-error.json', 422);
    expect($dup->error)->toContain('a unique reference with the same label and value already exists');

    // The HTTP status decides, not api_error.http_status_code (docs: they disagree).
    $mismatch = QuiqupErrors::describe(400, ['api_error' => ['message' => '[order][items] invalid', 'http_status_code' => 422]]);
    expect($mismatch->status)->toBe(400)->and($mismatch->error)->toStartWith('HTTP 400');
});

it('marks no answer, 429 and 5xx as retryable, and nothing else', function () {
    Http::fake(['*' => Http::response(['error' => 'x'], 503)]);
    expect(app(QuiqupClient::class)->order('sandbox', 1)->retryable())->toBeTrue();

    expect(QuiqupErrors::describe(429, null)->retryable())->toBeTrue()
        ->and(QuiqupErrors::describe(422, null)->retryable())->toBeFalse()
        ->and(QuiqupErrors::describe(401, null)->retryable())->toBeFalse()
        ->and(QuiqupErrors::transport('timed out')->retryable())->toBeTrue();
});

it('refuses the documented zero-byte 200 label and a body that is not %PDF', function () {
    // Defect prevented: "An unknown id returns 200, application/pdf, zero
    // bytes" -- streamed straight through, the owner prints a blank page.
    Http::fakeSequence()
        ->push('', 200, ['Content-Type' => 'application/pdf'])
        ->push('<html>oops</html>', 200, ['Content-Type' => 'application/pdf'])
        ->push("%PDF-1.4\n1 0 obj\n%%EOF", 200, ['Content-Type' => 'application/pdf']);

    $c = app(QuiqupClient::class);
    $empty = $c->labels('sandbox', [10000001]);
    $html = $c->labels('sandbox', [10000001]);
    $good = $c->labels('sandbox', [10000001, 10000002]);

    expect($empty->ok())->toBeFalse()->and($empty->error)->toContain('empty label')
        ->and($html->ok())->toBeFalse()
        ->and($good->ok())->toBeTrue()->and($good->body)->toStartWith('%PDF');
    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.staging.quiqup.com/order_label/10000001,10000002');
    // MUTATION: drop the %PDF check in QuiqupClient::labels() and $html is ok -- red.
});

it('cancels one order per call with the documented body, and reads a 202 projection-pending as done', function () {
    Http::fake(['*' => Http::response(QuiqupKit::json('projection-pending-batch.json'), 202, ['x-projection-pending' => 'true'])]);

    $r = app(QuiqupClient::class)->cancel('sandbox', 10000001, 'Customer changed the address');

    Http::assertSent(fn (Request $req) => $req->method() === 'PUT'
        && $req->url() === 'https://api.staging.quiqup.com/orders/batch/set_cancelled'
        && $req->data() === ['order_ids' => [10000001], 'reason' => 'Customer changed the address']);
    expect($r->ok())->toBeTrue()->and($r->projectionPending)->toBeTrue();
    expect(array_keys(['order_ids' => 1, 'reason' => 1]))->toBe(QuiqupKit::json('schema-keys.json')['CancelOrdersRequest']);
});

it('makes no call at all without an API key, and says what to do', function () {
    \App\Models\QuiqupConfig::query()->delete();
    Http::fake();

    $r = app(QuiqupClient::class)->ping('sandbox');

    Http::assertNothingSent();
    expect($r->ok())->toBeFalse()->and($r->hint)->toContain('Add your staging API key');
});

it('logs a failed call at ERROR with no key and no body', function () {
    $logged = [];
    \Illuminate\Support\Facades\Log::listen(function ($m) use (&$logged) { $logged[] = [$m->level, $m->message . json_encode($m->context)]; });
    Http::fake(['*' => Http::response(QuiqupKit::json('error-401.json'), 401)]);

    app(QuiqupClient::class)->createOrder('sandbox', ['destination' => ['contact_phone' => '+971599999999']]);

    expect($logged)->toHaveCount(1)->and($logged[0][0])->toBe('error')
        ->and($logged[0][1])->not->toContain('qq_sandbox_key_1234')
        ->and($logged[0][1])->not->toContain('+971599999999');
});

it('masks phones in Quiqup\'s messages before they are stored', function () {
    expect(QuiqupErrors::mask('Invalid dropoff phone +971 50 123 4567 for order 10000001'))
        ->not->toContain('123 4567')->toContain('4567')->toContain('10000001');
});
