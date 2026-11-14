<?php

declare(strict_types=1);

use App\Models\Order;
use App\Models\QuiqupConfig;
use App\Models\Shipment;
use App\Services\Quiqup\QuiqupSettings;
use App\Services\Quiqup\ShipmentSync;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeQuiqupClient;
use Tests\Support\QuiqupKit;

/*
 * QUIQUP-PLAN §6.1/§6.2: what a Quiqup state does to OUR order. Shipped at the
 * plan's defaults (owner's Q2/Q3 unanswered): collected -> Shipped and
 * delivery_complete -> Completed ON; returned_to_origin changes nothing.
 * Every failure here is an order the owner would have to put right by hand --
 * or a customer emailed "delivered" about a parcel that came back.
 */

beforeEach(function () {
    Http::preventStrayRequests();
    FakeQuiqupClient::install($this->app);
    QuiqupKit::configure();
});

function qqApply(Shipment $s, string $state, string $at, array $extra = []): void
{
    $obs = ShipmentSync::observe(array_merge(['state' => $state, 'state_updated_at' => $at], $extra));
    app(ShipmentSync::class)->observeAndApply($s, $obs, 'webhook');
}

it('moves a processing order to Shipped on collected and to Completed on delivery_complete', function () {
    $order = QuiqupKit::order(['status' => 'processing']);
    $s = QuiqupKit::shipment($order, ['state_at' => '2026-01-01 00:00:00']);

    qqApply($s, 'collected', '2026-09-03T09:40:00Z');
    expect(Order::find($order->id)->status)->toBe('shipped');

    qqApply($s, 'delivery_complete', '2026-09-04T12:00:00Z');
    expect(Order::find($order->id)->status)->toBe('completed')
        ->and(Order::find($order->id)->ship_state)->toBe('delivery_complete');
    // MUTATION: set DEFAULTS['collected']['status'] to '' and the first expect is red.
});

it('never moves an order backwards, and a failure loop is a timeline entry, not a status change', function () {
    $order = QuiqupKit::order(['status' => 'completed']);
    $s = QuiqupKit::shipment($order, ['state_at' => '2026-01-01 00:00:00']);

    qqApply($s, 'collected', '2026-09-03T09:40:00Z');
    qqApply($s, 'delivery_failed', '2026-09-03T17:30:00Z', ['delivery_attempts' => 1, 'delivery_failure_reason' => 'Customer not answering +971 50 123 4567']);
    qqApply($s, 'at_depot', '2026-09-03T19:00:00Z');

    expect(Order::find($order->id)->status)->toBe('completed')
        ->and(Shipment::find($s->id)->attempts)->toBe(1)
        ->and(Shipment::find($s->id)->last_reason)->not->toContain('123 4567');
    // MUTATION: make forward() return true for any known pair and the order reads shipped -- red.
});

it('never touches a refunded, cancelled, failed, draft or pending-payment order', function () {
    foreach (['refunded', 'cancelled', 'failed', 'draft', 'pending'] as $status) {
        $order = QuiqupKit::order(['status' => $status]);
        $s = QuiqupKit::shipment($order, ['state_at' => '2026-01-01 00:00:00']);
        qqApply($s, 'collected', '2026-09-03T09:40:00Z');
        qqApply($s, 'delivery_complete', '2026-09-04T09:40:00Z');
        expect(Order::find($order->id)->status)->toBe($status, "a {$status} order was moved");
    }
});

it('does not cancel the order on returned_to_origin by default, and does if the owner maps it', function () {
    $order = QuiqupKit::order(['status' => 'shipped']);
    $s = QuiqupKit::shipment($order, ['state_at' => '2026-01-01 00:00:00']);
    qqApply($s, 'returned_to_origin', '2026-09-05T09:00:00Z');
    expect(Order::find($order->id)->status)->toBe('shipped')
        ->and(Order::find($order->id)->ship_state)->toBe('returned_to_origin');

    $opts = app(QuiqupSettings::class)->options();
    $opts['statuses']['returned_to_origin']['status'] = 'cancelled';
    QuiqupConfig::query()->whereKey(QuiqupSettings::OPTIONS_ROW)->update(['options' => json_encode($opts)]);

    $order2 = QuiqupKit::order(['status' => 'shipped']);
    $s2 = QuiqupKit::shipment($order2, ['state_at' => '2026-01-01 00:00:00']);
    qqApply($s2, 'returned_to_origin', '2026-09-05T09:00:00Z');
    expect(Order::find($order2->id)->status)->toBe('cancelled');
});

it('stores an unknown state verbatim and changes no order status', function () {
    $order = QuiqupKit::order(['status' => 'processing']);
    $s = QuiqupKit::shipment($order, ['state_at' => '2026-01-01 00:00:00']);
    qqApply($s, 'teleported', '2026-09-03T09:40:00Z');

    expect(Shipment::find($s->id)->state)->toBe('teleported')
        ->and(Order::find($order->id)->status)->toBe('processing');
});

it('a cancel at Quiqup frees the order for a new booking without touching the sale', function () {
    $order = QuiqupKit::order(['status' => 'processing']);
    $s = QuiqupKit::shipment($order, ['state_at' => '2026-01-01 00:00:00']);
    qqApply($s, 'cancelled', '2026-09-03T09:40:00Z');

    $s->refresh();
    expect($s->job)->toBe('cancelled')->and($s->active_key)->toBeNull()
        ->and(Order::find($order->id)->status)->toBe('processing');
});

it('forward() is the whole rule', function () {
    expect(ShipmentSync::forward('processing', 'shipped'))->toBeTrue()
        ->and(ShipmentSync::forward('onhold', 'completed'))->toBeTrue()
        ->and(ShipmentSync::forward('shipped', 'completed'))->toBeTrue()
        ->and(ShipmentSync::forward('completed', 'shipped'))->toBeFalse()
        ->and(ShipmentSync::forward('shipped', 'shipped'))->toBeFalse()
        ->and(ShipmentSync::forward('processing', 'cancelled'))->toBeFalse()
        ->and(ShipmentSync::forward('shipped', 'cancelled'))->toBeTrue()
        ->and(ShipmentSync::forward('refunded', 'completed'))->toBeFalse()
        ->and(ShipmentSync::forward('processing', 'onhold'))->toBeTrue()
        ->and(ShipmentSync::forward('shipped', 'onhold'))->toBeFalse()
        ->and(ShipmentSync::forward('processing', 'refunded'))->toBeFalse();
});

it('writes one admin-only order note per applied state and none for a late event', function () {
    $order = QuiqupKit::order();
    $s = QuiqupKit::shipment($order, ['state_at' => '2026-01-01 00:00:00']);
    qqApply($s, 'out_for_delivery', '2026-09-04T08:55:00Z');
    qqApply($s, 'at_depot', '2026-09-03T08:55:00Z');

    $notes = DB::table('order_notes')->where('order_id', $order->id)->where('author', 'Quiqup')->get();
    expect($notes)->toHaveCount(1)
        ->and((bool) $notes[0]->is_customer_note)->toBeFalse()
        ->and($notes[0]->content)->toContain('Out for delivery')->toContain('SANDBOX');
});
