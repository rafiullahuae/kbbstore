<?php

declare(strict_types=1);

use App\Models\Shipment;
use App\Services\Quiqup\OrderDraft;
use App\Services\Quiqup\QuiqupErrors;
use App\Services\Quiqup\ShipmentCreator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeQuiqupClient;
use Tests\Support\QuiqupKit;
use Tests\Support\QuiqupRoutes;

/*
 * "Pressing it twice, closing the tab or a slow connection can never book the
 * same order twice" (QUIQUP-PLAN §0, §4.3). A duplicate is a second courier,
 * a second label and a second bill. Each case is one of the ways it happens.
 */

beforeEach(function () {
    Http::preventStrayRequests();
    $this->fake = FakeQuiqupClient::install($this->app);
    QuiqupRoutes::wire($this->app);
    QuiqupKit::configure('sandbox', 'k', ['test' => ['emails' => [], 'phones' => ['+971501234567'], 'orders' => []]]);
    $this->actingAs(QuiqupKit::admin('owner'), 'admin');
});

it('derives the same uuid for the same env, order and generation, and a different one otherwise', function () {
    $a = OrderDraft::requestUuid('sandbox', 7, 0);
    expect($a)->toBe(OrderDraft::requestUuid('sandbox', 7, 0))
        ->and($a)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/')
        ->and($a)->not->toBe(OrderDraft::requestUuid('sandbox', 7, 1))
        ->and($a)->not->toBe(OrderDraft::requestUuid('live', 7, 0))
        ->and($a)->not->toBe(OrderDraft::requestUuid('sandbox', 8, 0));
});

it('a double click schedules once: the second press finds the order already booked', function () {
    $o = QuiqupKit::order();
    $this->postJson('/admin-api/quiqup-schedule', ['ids' => [$o->id]])->assertOk()->assertJsonPath('queued', [$o->id]);
    $this->postJson('/admin-api/quiqup-schedule', ['ids' => [$o->id]])->assertOk()->assertJsonPath('queued', [])
        ->assertJsonPath('skipped.0.id', $o->id);

    expect(Shipment::where('order_id', $o->id)->count())->toBe(1);
});

it('the database refuses a second live booking for one order', function () {
    $o = QuiqupKit::order();
    QuiqupKit::shipment($o);

    expect(fn () => QuiqupKit::shipment($o))->toThrow(\Illuminate\Database\QueryException::class);
    // MUTATION: drop ->unique() on active_key and this does not throw -- red.
});

it('two workers racing (the sheet and the cron) send ONE create', function () {
    $o = QuiqupKit::order();
    $this->postJson('/admin-api/quiqup-schedule', ['ids' => [$o->id]])->assertOk();
    $s = Shipment::where('order_id', $o->id)->first();

    $creator = app(ShipmentCreator::class);
    $creator->run($s);              // the sheet
    $creator->run($s->fresh());     // the cron, a moment later
    $this->artisan('kbb:quiqup-sync')->assertSuccessful();

    expect($this->fake->count('createOrder'))->toBe(1);
});

it('a timeout is retried with the SAME uuid, and Quiqup\'s 200 replay is recorded once', function () {
    $o = QuiqupKit::order();
    $this->postJson('/admin-api/quiqup-schedule', ['ids' => [$o->id]])->assertOk();

    // The first send reaches Quiqup (the order is created) but the answer is lost.
    $fake = $this->fake;
    $fake->script['createOrder'][] = function ($env, $payload) use ($fake) {
        $fake->script['createOrder'] = [];
        $fake->createOrder($env, $payload);   // created upstream...

        return QuiqupErrors::transport('timed out'); // ...but we never hear it
    };

    $r1 = $this->postJson('/admin-api/quiqup-step', ['ids' => [$o->id]])->assertOk();
    expect($r1->json('results.0.job'))->toBe('queued')->and($r1->json('results.0.error'))->toContain('did not answer');

    Shipment::where('order_id', $o->id)->update(['next_poll_at' => now()->subMinute()->format('Y-m-d H:i:s.u')]);
    $r2 = $this->postJson('/admin-api/quiqup-step', ['ids' => [$o->id]])->assertOk();

    $creates = array_values(array_filter($fake->calls, fn ($c) => $c[0] === 'createOrder'));
    $uuids = array_unique(array_map(fn ($c) => $c[2]['uuid'], $creates));
    expect($uuids)->toHaveCount(1)
        ->and(count($fake->orders))->toBe(1)
        ->and($r2->json('results.0.ok'))->toBeTrue()
        ->and(DB::table('order_notes')->where('order_id', $o->id)->where('content', 'like', '%already booked; Quiqup returned the original%')->count())->toBe(1);
});

it('gives up after five unanswered tries and frees the order', function () {
    $o = QuiqupKit::order();
    $this->postJson('/admin-api/quiqup-schedule', ['ids' => [$o->id]])->assertOk();

    for ($i = 0; $i < 5; $i++) {
        $this->fake->script['createOrder'][] = QuiqupErrors::describe(503, ['error' => 'unavailable']);
        Shipment::where('order_id', $o->id)->update(['next_poll_at' => now()->subMinute()->format('Y-m-d H:i:s.u')]);
        $this->postJson('/admin-api/quiqup-step', ['ids' => [$o->id]])->assertOk();
    }

    $s = Shipment::where('order_id', $o->id)->first();
    expect($s->job)->toBe('failed')->and($s->active_key)->toBeNull()->and($s->tries)->toBe(5)
        ->and($s->last_error)->toContain('HTTP 503');
});

it('a failed booking is retried with its own uuid; a cancelled one re-books with generation + 1', function () {
    $o = QuiqupKit::order();
    $this->fake->script['createOrder'][] = QuiqupErrors::describe(422, QuiqupKit::json('error-422-policy.json'));
    $this->postJson('/admin-api/quiqup-schedule', ['ids' => [$o->id]])->assertOk();
    $this->postJson('/admin-api/quiqup-step', ['ids' => [$o->id]])->assertOk()->assertJsonPath('results.0.ok', false);
    $failedUuid = Shipment::where('order_id', $o->id)->value('request_uuid');

    $this->postJson('/admin-api/quiqup-schedule', ['ids' => [$o->id]])->assertOk()->assertJsonPath('queued', [$o->id]);
    $this->postJson('/admin-api/quiqup-step', ['ids' => [$o->id]])->assertOk()->assertJsonPath('results.0.ok', true);
    expect(Shipment::where('order_id', $o->id)->count())->toBe(1)
        ->and(Shipment::where('order_id', $o->id)->value('request_uuid'))->toBe($failedUuid);

    $this->postJson('/admin-api/quiqup-rebook', ['order_id' => $o->id, 'overrides' => ['service' => 'partner_same_day']])->assertOk()->assertJsonPath('ok', true);
    $rows = Shipment::where('order_id', $o->id)->orderBy('generation')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->job)->toBe('cancelled')
        ->and($rows[1]->generation)->toBe(1)
        ->and($rows[1]->request_uuid)->toBe(OrderDraft::requestUuid('sandbox', $o->id, 1))
        ->and($rows[1]->service_kind)->toBe('partner_same_day')
        ->and($rows[1]->active_key)->toBe($o->id);
});

it('a closed tab is finished by the minute cron', function () {
    $o = QuiqupKit::order();
    $this->postJson('/admin-api/quiqup-schedule', ['ids' => [$o->id]])->assertOk();

    $this->artisan('kbb:quiqup-sync')->assertSuccessful();

    expect(Shipment::where('order_id', $o->id)->value('job'))->toBe('created')
        ->and($this->fake->count('createOrder'))->toBe(1);
});

it('remembers the environment it was booked in: after Live, its label and cancel still go to staging', function () {
    $o = QuiqupKit::order();
    $this->postJson('/admin-api/quiqup-schedule', ['ids' => [$o->id]])->assertOk();
    $this->postJson('/admin-api/quiqup-step', ['ids' => [$o->id]])->assertOk();

    // The owner goes Live (both keys present).
    $live = \App\Models\QuiqupConfig::firstOrNew(['id' => 'live']);
    $live->secrets = ['api_key' => 'live_k', 'webhook_secret' => 'w', 'url_secret' => str_repeat('L', 40)];
    $live->save();
    $opts = app(\App\Services\Quiqup\QuiqupSettings::class)->options();
    $opts['mode'] = 'live';
    \App\Models\QuiqupConfig::query()->whereKey('sandbox')->update(['options' => json_encode($opts)]);

    $this->get('/admin-api/quiqup-labels?ids=' . $o->id)->assertOk();
    $this->postJson('/admin-api/quiqup-cancel', ['order_id' => $o->id])->assertOk();

    $envs = array_unique(array_map(fn ($c) => $c[1], $this->fake->calls));
    expect($envs)->toBe(['sandbox'])
        ->and(Shipment::where('order_id', $o->id)->value('test'))->toBeTrue();
});
