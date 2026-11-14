<?php

declare(strict_types=1);

use App\Models\Order;
use App\Models\Shipment;
use App\Services\Quiqup\QuiqupResult;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeQuiqupClient;
use Tests\Support\QuiqupKit;
use Tests\Support\QuiqupRoutes;

/*
 * Cancel, labels and the Delivery card (QUIQUP-PLAN §3.3). Prevents: one
 * refusal cancelling nothing for a whole batch; a cancelled DELIVERY
 * cancelling the SALE; a blank label printed and stuck on a parcel.
 */

beforeEach(function () {
    Http::preventStrayRequests();
    $this->fake = FakeQuiqupClient::install($this->app);
    QuiqupRoutes::wire($this->app);
    QuiqupKit::configure('sandbox', 'k', ['test' => ['emails' => [], 'phones' => ['+971501234567'], 'orders' => []]]);
    $this->actingAs(QuiqupKit::admin('owner'), 'admin');
});

function qqBooked(): Order
{
    $o = QuiqupKit::order();
    test()->postJson('/admin-api/quiqup-schedule', ['ids' => [$o->id]])->assertOk();
    test()->postJson('/admin-api/quiqup-step', ['ids' => [$o->id]])->assertOk()->assertJsonPath('results.0.ok', true);

    return $o;
}

it('cancels one order per call and leaves our order status alone', function () {
    $o = qqBooked();

    $this->postJson('/admin-api/quiqup-cancel', ['order_id' => $o->id, 'reason' => 'Customer asked'])->assertOk()->assertJsonPath('ok', true);

    $call = collect($this->fake->calls)->firstWhere(0, 'cancel');
    expect($call[2])->toBe(['order_ids' => [Shipment::where('order_id', $o->id)->value('qq_id')], 'reason' => 'Customer asked'])
        ->and(Order::find($o->id)->status)->toBe('processing')
        ->and(Shipment::where('order_id', $o->id)->value('job'))->toBe('cancelled')
        ->and(Shipment::where('order_id', $o->id)->value('active_key'))->toBeNull();
});

it('refuses to cancel once a courier is on the way, without calling Quiqup, and says where to do it', function () {
    $o = qqBooked();
    Shipment::where('order_id', $o->id)->update(['state' => 'out_for_collection']);

    $r = $this->postJson('/admin-api/quiqup-cancel', ['order_id' => $o->id])->assertStatus(422);
    expect($r->json('message'))->toContain('Quiqdash')->and($this->fake->count('cancel'))->toBe(0)
        ->and($r->json('dashboard'))->toBe('https://quiqdash-beta.staging.quiqup.com');
});

it('shows Quiqup\'s own refusal text when it refuses a cancel', function () {
    $o = qqBooked();
    $this->fake->script['cancel'][] = \App\Services\Quiqup\QuiqupErrors::describe(422, ['api_error' => ['message' => 'Order already has a courier assigned']]);

    $this->postJson('/admin-api/quiqup-cancel', ['order_id' => $o->id])->assertStatus(422)
        ->assertJsonPath('message', 'HTTP 422 — Order already has a courier assigned');
    expect(Shipment::where('order_id', $o->id)->value('active_key'))->toBe($o->id);
});

it('streams a real PDF for booked orders, refuses Quiqup\'s zero-byte answer, and caps at 25', function () {
    $a = qqBooked();
    $b = qqBooked();

    $r = $this->get('/admin-api/quiqup-labels?ids=' . $a->id . ',' . $b->id)->assertOk();
    expect($r->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($r->getContent())->toStartWith('%PDF')
        ->and($r->headers->get('Cache-Control'))->toContain('no-store');
    expect(collect($this->fake->calls)->firstWhere(0, 'labels')[2])->toHaveCount(2);

    $this->fake->script['labels'][] = new QuiqupResult(200, null, '', 'Quiqup returned an empty label (HTTP 200, 0 bytes).');
    $this->getJson('/admin-api/quiqup-labels?ids=' . $a->id)->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'empty label'));

    $this->getJson('/admin-api/quiqup-labels?ids=' . implode(',', range(1, 26)))->assertStatus(422);
    $this->getJson('/admin-api/quiqup-labels?ids=' . QuiqupKit::order()->id)->assertStatus(422);
});

it('gives the Delivery card its booking, history and what this admin may do', function () {
    $o = qqBooked();
    $r = $this->getJson('/admin-api/quiqup-shipment?order=' . $o->id)->assertOk();

    expect($r->json('shipment.job'))->toBe('created')
        ->and($r->json('shipment.cancellable'))->toBeTrue()
        ->and($r->json('shipment.test'))->toBeTrue()
        ->and($r->json('events.0.state'))->toBe('pending')
        ->and($r->json('is_test'))->toBeTrue()
        ->and($r->json('can_ship'))->toBeTrue()
        ->and(json_encode($r->json()))->not->toContain('request_uuid')->not->toContain('draft');
});

it('refreshes one booking from Quiqup on request', function () {
    $o = qqBooked();
    $qq = Shipment::where('order_id', $o->id)->value('qq_id');
    $this->fake->move($qq, 'out_for_collection', now()->addMinute()->utc()->format('Y-m-d\TH:i:s.u\Z'));

    $this->postJson('/admin-api/quiqup-refresh', ['order_id' => $o->id])->assertOk()
        ->assertJsonPath('shipment.shipment.state', 'out_for_collection');
});
