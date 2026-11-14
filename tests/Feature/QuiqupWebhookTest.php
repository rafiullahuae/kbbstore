<?php

declare(strict_types=1);

use App\Models\Order;
use App\Models\QuiqupConfig;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Services\Quiqup\ShipmentSync;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Support\FakeQuiqupClient;
use Tests\Support\QuiqupKit;
use Tests\Support\QuiqupRoutes;

/*
 * POST /api/shipping/quiqup/webhook/{secret} -- QUIQUP-PLAN §5.2.
 *
 * Every case is a defect this receiver would otherwise have on the live shop:
 * a forged delivery moving an order to Completed, a replay of an old capture
 * re-sending emails, an out-of-order event dragging a delivered parcel back to
 * "at depot", or a crash in the apply losing the event for good.
 */

beforeEach(function () {
    Http::preventStrayRequests();
    FakeQuiqupClient::install($this->app);
    QuiqupRoutes::wire($this->app);
    QuiqupKit::configure();
});

function qqHook(string $raw, array $server, string $secret = QuiqupKit::URL_SECRET)
{
    return test()->call('POST', '/api/shipping/quiqup/webhook/' . $secret, [], [], [], $server, $raw);
}

it('applies a correctly signed documented webhook body', function () {
    // Defect prevented: the receiver not understanding Quiqup's real body.
    // The fixture is copied verbatim from qq-docs_webhooks.md.
    $order = QuiqupKit::order();
    $s = QuiqupKit::shipment($order, ['state' => 'pending', 'state_at' => '2026-09-03 09:09:22']);
    $raw = QuiqupKit::webhookBody($s, 'ready_for_collection', '2026-09-03T09:14:06.812345Z');

    qqHook($raw, QuiqupKit::signed($raw))->assertOk()->assertExactJson(['result' => 'ok']);

    $s->refresh();
    expect($s->state)->toBe('ready_for_collection')
        ->and($s->state_at->format('Y-m-d H:i:s.u'))->toBe('2026-09-03 09:14:06.812345')
        ->and($s->delivery_before?->format('Y-m-d H:i'))->toBe('2026-09-04 15:00')
        ->and(Order::find($order->id)->ship_state)->toBe('ready_for_collection');

    $e = ShipmentEvent::query()->where('shipment_id', $s->id)->first();
    expect($e->source)->toBe('webhook')
        ->and($e->body_sha256)->toBe(hash('sha256', $raw))
        ->and($e->applied_at)->not->toBeNull();
    // MUTATION: drop the app()->terminating() apply in the controller and
    // ship_state stays null -- red.
});

it('answers 404 for a wrong url secret, indistinguishable from no route', function () {
    // Defect prevented: anyone who guesses the path reaching the signature check.
    $s = QuiqupKit::shipment(QuiqupKit::order());
    $raw = QuiqupKit::webhookBody($s, 'collected', now()->utc()->toIso8601String());

    qqHook($raw, QuiqupKit::signed($raw), str_repeat('x', 40))->assertNotFound();
    expect(ShipmentEvent::count())->toBe(0);
});

it('refuses a bad signature, a stale or future timestamp and missing headers with 401 and the same body', function () {
    // Defect prevented: a forged or replayed delivery moving an order.
    $s = QuiqupKit::shipment(QuiqupKit::order());
    $raw = QuiqupKit::webhookBody($s, 'delivery_complete', now()->utc()->toIso8601String());

    $bodies = [];
    $bodies[] = qqHook($raw, QuiqupKit::signed($raw, null, 'wrong-secret'))->assertStatus(401)->getContent();
    $bodies[] = qqHook($raw, QuiqupKit::signed($raw, time() - 301))->assertStatus(401)->getContent();
    $bodies[] = qqHook($raw, QuiqupKit::signed($raw, time() + 301))->assertStatus(401)->getContent();
    $bodies[] = qqHook($raw, ['CONTENT_TYPE' => 'application/json'])->assertStatus(401)->getContent();
    $tampered = QuiqupKit::signed($raw);
    $bodies[] = qqHook($raw . ' ', $tampered)->assertStatus(401)->getContent();

    expect(array_unique($bodies))->toHaveCount(1)
        ->and(ShipmentEvent::count())->toBe(0)
        ->and(Shipment::find($s->id)->state)->toBe('ready_for_collection');
    // MUTATION: widen WINDOW_SECONDS to 400 and the ±301 s cases are 200 -- red.
});

it('accepts a timestamp inside the five-minute window and the previous secret for 24 h after a rotation', function () {
    $s = QuiqupKit::shipment(QuiqupKit::order(), ['state_at' => '2026-01-01 00:00:00']);
    $raw = QuiqupKit::webhookBody($s, 'out_for_collection', '2026-09-03T10:00:00Z');
    qqHook($raw, QuiqupKit::signed($raw, time() - 299))->assertOk();

    $row = QuiqupConfig::find('sandbox');
    $row->secrets = array_merge($row->secrets, ['webhook_secret' => 'new-secret', 'webhook_secret_prev' => QuiqupKit::SIGNING, 'webhook_secret_rotated_at' => time() - 3600]);
    $row->save();
    $raw2 = QuiqupKit::webhookBody($s, 'collected', '2026-09-03T11:00:00Z');
    qqHook($raw2, QuiqupKit::signed($raw2))->assertOk();

    $row->secrets = array_merge($row->secrets, ['webhook_secret_rotated_at' => time() - 90000]);
    $row->save();
    $raw3 = QuiqupKit::webhookBody($s, 'at_depot', '2026-09-03T12:00:00Z');
    qqHook($raw3, QuiqupKit::signed($raw3))->assertStatus(401);
});

it('dedupes a redelivered event on (order, state, state_updated_at)', function () {
    // Defect prevented: Quiqup redelivers; a second apply would write a
    // second note and could re-send a status email.
    $order = QuiqupKit::order();
    $s = QuiqupKit::shipment($order, ['state_at' => '2026-01-01 00:00:00']);
    $raw = QuiqupKit::webhookBody($s, 'out_for_delivery', '2026-09-03T08:55:00Z');

    qqHook($raw, QuiqupKit::signed($raw))->assertOk();
    qqHook($raw, QuiqupKit::signed($raw))->assertOk();

    expect(ShipmentEvent::where('shipment_id', $s->id)->count())->toBe(1)
        ->and(DB::table('order_notes')->where('order_id', $order->id)->where('author', 'Quiqup')->count())->toBe(1);
});

it('stores an older out-of-order event for the history but never regresses the state', function () {
    // Defect prevented: "events can arrive out of order" -- a late at_depot
    // dragging a delivered parcel back.
    $order = QuiqupKit::order(['status' => 'completed']);
    $s = QuiqupKit::shipment($order, ['state' => 'delivery_complete', 'state_at' => '2026-09-04 12:00:00']);
    $raw = QuiqupKit::webhookBody($s, 'at_depot', '2026-09-03T11:05:00Z');

    qqHook($raw, QuiqupKit::signed($raw))->assertOk();

    expect(Shipment::find($s->id)->state)->toBe('delivery_complete')
        ->and(ShipmentEvent::where('shipment_id', $s->id)->where('state', 'at_depot')->whereNotNull('applied_at')->exists())->toBeTrue();
    // MUTATION: make applyEvent's $newer always true and the state reads at_depot -- red.
});

it('answers 200 ignored for an order this shop never booked, and for junk shapes', function () {
    // Defect prevented: a 4xx makes Quiqup count failures and, at 50, pause
    // the subscription for good; an order made by hand in Quiqdash is normal.
    $s = QuiqupKit::shipment(QuiqupKit::order());
    $raw = QuiqupKit::webhookBody($s, 'collected', now()->utc()->toIso8601String(), ['id' => 99999999, 'uuid' => '00000000-0000-0000-0000-00000000dead']);
    qqHook($raw, QuiqupKit::signed($raw))->assertOk()->assertExactJson(['result' => 'ignored']);

    $junk = json_encode(['action' => 'update', 'type' => 'inventory', 'payload' => ['id' => 1]]);
    qqHook($junk, QuiqupKit::signed($junk))->assertOk()->assertExactJson(['result' => 'ignored']);

    $badState = QuiqupKit::webhookBody($s, 'DROP TABLE', now()->utc()->toIso8601String());
    qqHook($badState, QuiqupKit::signed($badState))->assertOk()->assertExactJson(['result' => 'ignored']);

    expect(ShipmentEvent::count())->toBe(0);
});

it('refuses a body over 256 KB with 413 before reading it as JSON', function () {
    $s = QuiqupKit::shipment(QuiqupKit::order());
    $raw = QuiqupKit::webhookBody($s, 'collected', now()->utc()->toIso8601String(), ['notes' => str_repeat('a', 262200)]);

    qqHook($raw, QuiqupKit::signed($raw))->assertStatus(413);
    expect(ShipmentEvent::count())->toBe(0);
});

it('still answers 200 when the apply throws, keeps applied_at NULL, and the minute cron applies it', function () {
    // Defect prevented: a crash after the durable write losing the event (Quiqup
    // would not retry a 200) -- or answering 500 and having Quiqup resend
    // something we already hold.
    $order = QuiqupKit::order();
    $s = QuiqupKit::shipment($order, ['state_at' => '2026-01-01 00:00:00']);
    $raw = QuiqupKit::webhookBody($s, 'collected', '2026-09-03T09:40:00Z');

    // The order_notes insert inside the apply is made to fail once.
    $fail = true;
    Order::getEventDispatcher(); // models booted
    \App\Models\OrderNote::creating(function () use (&$fail) {
        if ($fail) {
            $fail = false;
            throw new RuntimeException('simulated database hiccup');
        }
    });

    qqHook($raw, QuiqupKit::signed($raw))->assertOk()->assertExactJson(['result' => 'ok']);

    $e = ShipmentEvent::where('shipment_id', $s->id)->first();
    expect($e)->not->toBeNull()->and($e->applied_at)->toBeNull()
        ->and(Shipment::find($s->id)->state)->toBe('ready_for_collection');

    test()->artisan('kbb:quiqup-sync')->assertSuccessful();

    expect(ShipmentEvent::find($e->id)->applied_at)->not->toBeNull()
        ->and(Shipment::find($s->id)->state)->toBe('collected')
        ->and(Order::find($order->id)->status)->toBe('shipped');
});

it('never writes the body, a phone, the otp or the signature to the log', function () {
    // Defect prevented: customer data and Quiqup's delivery OTP in laravel.log.
    $logged = [];
    Log::listen(function ($m) use (&$logged) { $logged[] = $m->message . json_encode($m->context); });

    $s = QuiqupKit::shipment(QuiqupKit::order());
    $raw = QuiqupKit::webhookBody($s, 'out_for_delivery', now()->utc()->toIso8601String(), ['otp_code' => '482913']);
    qqHook($raw, QuiqupKit::signed($raw, null, 'wrong'))->assertStatus(401);
    qqHook($raw, QuiqupKit::signed($raw))->assertOk();

    $all = implode("\n", $logged);
    expect($all)->not->toContain('482913')
        ->and($all)->not->toContain('+971599999999')
        ->and($all)->not->toContain('Customer name')
        ->and($all)->not->toContain(QuiqupKit::SIGNING);
});

it('keeps no personal data from the payload: no names, phones, addresses, courier or otp', function () {
    $s = QuiqupKit::shipment(QuiqupKit::order(), ['state_at' => '2026-01-01 00:00:00']);
    $raw = QuiqupKit::webhookBody($s, 'out_for_delivery', '2026-09-03T08:55:00Z', ['otp_code' => '482913', 'courier_id' => 4242, 'mission_id' => 'm-77']);
    qqHook($raw, QuiqupKit::signed($raw))->assertOk();

    $dump = json_encode(DB::table('shipment_events')->get()) . json_encode(DB::table('shipments')->get());
    foreach (['482913', '4242', 'm-77', '+971599999999', 'Customer name', '2nd Road', '24.4539'] as $needle) {
        expect($dump)->not->toContain($needle);
    }
});

it('normalises a webhook payload and an API order to the same facts, offsets parsed', function () {
    $hook = ShipmentSync::observe(['state' => 'collected', 'state_updated_at' => '2026-10-06T12:55:11.000Z', 'delivery_attempts' => 1, 'delivery_before' => '2026-10-07T19:00:00Z']);
    $api = ShipmentSync::observe(['state' => 'collected', 'state_updated_at' => '2026-10-06T16:55:11.000+04:00', 'delivery_attempts' => 1, 'delivery_time' => ['delivery_before' => '2026-10-07T23:00:00.000+04:00']]);

    expect($hook['state_at']->equalTo($api['state_at']))->toBeTrue()
        ->and($hook['facts']['delivery_before'])->toBe($api['facts']['delivery_before'])
        ->and(ShipmentSync::trackingUrl('https://track-parcel.quiqup.com/00000000-0000-4000-8000-000000000001'))->not->toBeNull()
        ->and(ShipmentSync::trackingUrl('https://track-ae.quiqup.com/00000000000000000000000000000002'))->not->toBeNull()
        ->and(ShipmentSync::trackingUrl('https://evil.example/track-parcel.quiqup.com/abc'))->toBeNull()
        ->and(ShipmentSync::trackingUrl('javascript:alert(1)//track-parcel.quiqup.com/0000000000000000'))->toBeNull();
});
