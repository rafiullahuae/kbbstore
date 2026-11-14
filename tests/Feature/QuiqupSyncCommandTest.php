<?php

declare(strict_types=1);

use App\Models\Order;
use App\Models\QuiqupConfig;
use App\Models\Shipment;
use App\Services\Quiqup\QuiqupPoller;
use App\Services\Quiqup\QuiqupResult;
use App\Services\Quiqup\ShipmentSync;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeQuiqupClient;
use Tests\Support\QuiqupKit;

/*
 * kbb:quiqup-sync, the reconciliation poll (QUIQUP-PLAN §6.3). Webhooks are
 * primary; this is what catches a paused subscription. Each case is a way the
 * backup could hurt instead: hammering Quiqup, polling a delivered parcel
 * forever, or costing the minute cron real queries when nothing is moving.
 */

beforeEach(function () {
    Http::preventStrayRequests();
    $this->fake = FakeQuiqupClient::install($this->app);
    QuiqupKit::configure();
    Cache::flush();
});

/** A shipment the fake also knows about, due now. */
function qqDue(FakeQuiqupClient $fake, string $state = 'ready_for_collection', array $o = []): Shipment
{
    $order = QuiqupKit::order($o);
    $r = $fake->createOrder('sandbox', ['uuid' => (string) \Illuminate\Support\Str::uuid(), 'partner_order_id' => $order->order_number]);
    $id = $r->order()['id'];
    $fake->move($id, $state, '2026-09-03T09:00:00.000000Z');
    $fake->calls = [];

    return QuiqupKit::shipment($order, ['qq_id' => $id, 'qq_uuid' => $fake->orders[$id]['uuid'], 'state' => $state, 'state_at' => '2026-09-03 09:00:00', 'next_poll_at' => now()->subMinute()]);
}

it('costs one query and no Quiqup call when nothing is due', function () {
    // Defect prevented: an every-minute cron that reads tables for nothing.
    QuiqupKit::shipment(QuiqupKit::order(), ['next_poll_at' => now()->addHour()]);

    DB::enableQueryLog();
    DB::flushQueryLog();
    $this->artisan('kbb:quiqup-sync')->assertSuccessful();
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($queries)->toHaveCount(1)->and($this->fake->calls)->toBe([]);
    // MUTATION: remove the early return on !$due and the count is > 1 -- red.
});

it('reads non-pending shipments with ONE list call and applies what changed', function () {
    $a = qqDue($this->fake, 'ready_for_collection');
    $b = qqDue($this->fake, 'at_depot');
    $this->fake->move($a->qq_id, 'collected', '2026-09-03T10:00:00Z');
    $this->fake->move($b->qq_id, 'out_for_delivery', '2026-09-03T11:00:00Z');

    $this->artisan('kbb:quiqup-sync')->assertSuccessful();

    expect($this->fake->count('listOrders'))->toBe(1)
        ->and($this->fake->count('order'))->toBe(0)
        ->and(Shipment::find($a->id)->state)->toBe('collected')
        ->and(Order::find($a->order_id)->status)->toBe('shipped')
        ->and(Shipment::find($b->id)->state)->toBe('out_for_delivery')
        ->and(DB::table('shipment_events')->where('source', 'poll')->count())->toBe(2);
});

it('reads a pending shipment by id, because the list leaves pending out', function () {
    $p = qqDue($this->fake, 'pending');
    $this->fake->move($p->qq_id, 'pending', '2026-09-03T09:30:00Z');

    $this->artisan('kbb:quiqup-sync')->assertSuccessful();

    expect($this->fake->count('order'))->toBe(1)->and($this->fake->count('listOrders'))->toBe(0);
});

it('sets the next poll by state: 10 min moving, 30 in flight, 60 pending, doubled while webhooks are healthy, none at an end state', function () {
    $sync = app(ShipmentSync::class);
    $mk = fn (string $state) => new Shipment(['state' => $state, 'job' => 'created', 'env' => 'sandbox']);

    expect(now()->diffInMinutes($sync->nextPoll($mk('out_for_delivery'))))->toBeGreaterThan(9.0)->toBeLessThan(10.1)
        ->and(now()->diffInMinutes($sync->nextPoll($mk('at_depot'))))->toBeGreaterThan(29.0)->toBeLessThan(30.1)
        ->and(now()->diffInMinutes($sync->nextPoll($mk('pending'))))->toBeGreaterThan(59.0)->toBeLessThan(60.1)
        ->and($sync->nextPoll($mk('delivery_complete')))->toBeNull()
        ->and($sync->nextPoll($mk('returned_to_origin')))->toBeNull()
        ->and($sync->nextPoll($mk('cancelled')))->toBeNull();

    QuiqupConfig::query()->whereKey('sandbox')->update(['last_webhook_at' => now()->subMinutes(5)]);
    expect(now()->diffInMinutes($sync->nextPoll($mk('out_for_delivery'))))->toBeGreaterThan(19.0)->toBeLessThan(20.1);
});

it('stops polling a shipment that reached an end state', function () {
    $a = qqDue($this->fake, 'out_for_delivery');
    $this->fake->move($a->qq_id, 'delivery_complete', '2026-09-03T15:00:00Z');

    $this->artisan('kbb:quiqup-sync')->assertSuccessful();

    expect(Shipment::find($a->id)->next_poll_at)->toBeNull();
});

it('never makes more than 30 Quiqup calls in an hour, whatever the backlog', function () {
    // Defect prevented: a paused webhook plus 200 parcels turning the cron
    // into a flood (Quiqup publishes no rate limit; the hourly order limit
    // is a commercial block we must not trip).
    for ($i = 0; $i < 12; $i++) {
        qqDue($this->fake, 'pending');
    }

    for ($tick = 0; $tick < 10; $tick++) {
        Shipment::query()->update(['next_poll_at' => now()->subMinute()->format('Y-m-d H:i:s.u')]);
        $this->artisan('kbb:quiqup-sync')->assertSuccessful();
    }

    expect(count($this->fake->calls))->toBe(QuiqupPoller::HOURLY_CEILING);
    // MUTATION: raise the ceiling check to > instead of >= and this reads 31 -- red.
});

it('backs off on an API error and records it for Activity', function () {
    $p = qqDue($this->fake, 'pending');
    $this->fake->script['order'][] = new QuiqupResult(500, null, '', 'HTTP 500 — (Quiqup sent no message)');

    $this->artisan('kbb:quiqup-sync')->assertSuccessful();

    $s = Shipment::find($p->id);
    expect($s->tries)->toBe(1)
        ->and(now()->diffInMinutes($s->next_poll_at))->toBeGreaterThan(9.0)
        ->and(QuiqupConfig::find('sandbox')->last_poll_error)->toContain('HTTP 500');
});

it('does nothing for an environment with no API key', function () {
    QuiqupConfig::find('sandbox')->update(['secrets' => []]);
    $p = qqDue($this->fake, 'pending');

    $this->artisan('kbb:quiqup-sync')->assertSuccessful();

    expect($this->fake->calls)->toBe([]);
});

it('--order refreshes one booking now and --dry calls nothing', function () {
    $p = qqDue($this->fake, 'ready_for_collection');
    $this->fake->move($p->qq_id, 'out_for_collection', '2026-09-03T12:00:00Z');

    $this->artisan('kbb:quiqup-sync', ['--dry' => true])->assertSuccessful();
    expect($this->fake->calls)->toBe([]);

    $this->artisan('kbb:quiqup-sync', ['--order' => $p->order_id])->assertSuccessful();
    expect(Shipment::find($p->id)->state)->toBe('out_for_collection');
});

it('is scheduled every minute without overlapping', function () {
    $console = (string) file_get_contents(base_path('routes/console.php'));
    expect(substr_count($console, "Schedule::command('kbb:quiqup-sync')"))->toBe(1)
        ->and($console)->toMatch("/Schedule::command\\('kbb:quiqup-sync'\\)\\s*->everyMinute\\(\\)\\s*->withoutOverlapping\\(5\\)/");
});
