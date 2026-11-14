<?php

declare(strict_types=1);

use App\Models\Order;
use App\Models\Shipment;
use App\Services\Quiqup\OrderDraft;
use App\Services\Quiqup\QuiqupResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeQuiqupClient;
use Tests\Support\QuiqupKit;
use Tests\Support\QuiqupRoutes;

/*
 * Store -> Orders -> Schedule with Quiqup (QUIQUP-PLAN §3.2). Each case is a
 * booking that would otherwise reach Quiqup wrong -- a COD amount on a paid
 * order, the private gift message on a courier's screen, an unpaid card
 * order shipped -- or a whole batch lost to one bad row.
 */

beforeEach(function () {
    Http::preventStrayRequests();
    $this->fake = FakeQuiqupClient::install($this->app);
    QuiqupRoutes::wire($this->app);
    QuiqupKit::configure('sandbox', 'qq_test_key_abcd1234', ['test' => ['emails' => [], 'phones' => ['+971501234567'], 'orders' => []]]);
    $this->actingAs(QuiqupKit::admin('owner'), 'admin');
});

function qqDraft(Order $o, array $defaults = [], array $rows = []): array
{
    return app(OrderDraft::class)->drafts([$o->id], $defaults, $rows)[0];
}

function qqBlockFields(array $d): array
{
    return array_column($d['blocks'], 'field');
}

it('builds a COD payload with the amount to collect, the customer note and no gift message', function () {
    $o = QuiqupKit::order(['gift_note' => 'Happy birthday my love, from S', 'is_gift' => true, 'total' => 18900]);
    DB::table('order_items')->insert(['order_id' => $o->id, 'name' => 'COSRX Advanced Snail 96 Mucin Power Essence', 'sku' => 'CSX-96', 'quantity' => 2, 'unit_price' => 9450, 'subtotal' => 18900, 'created_at' => now(), 'updated_at' => now()]);

    $d = qqDraft($o);
    expect($d['blocks'])->toBe([]);
    $p = app(OrderDraft::class)->payload($d, OrderDraft::requestUuid('sandbox', $o->id, 0));

    expect($p['payment_mode'])->toBe('paid_on_delivery')
        ->and($p['payment_amount'])->toBe(189.0)
        ->and($p['kind'])->toBe('partner_next_day')
        ->and($p['partner_order_id'])->toBe($o->order_number)
        ->and($p['ready_for_collection'])->toBeTrue()
        ->and($p['destination']['contact_phone'])->toBe('+971501234567')
        ->and($p['destination']['contact_name'])->toBe('Mariam Ali')
        ->and($p['destination']['emirate'])->toBe('Dubai')
        ->and($p['destination']['address'])->toBe(['address1' => 'Villa 12, Street 5', 'address2' => 'Al Barsha 2', 'town' => 'Dubai', 'country' => 'AE'])
        ->and($p['destination']['notes'])->toBe('Call before arriving')
        ->and($p['items'])->toBe([['name' => 'Parcel 1 of 1', 'quantity' => 1, 'parcel_barcode' => '']])
        ->and($p['products'])->toBe([['sku' => 'CSX-96', 'description' => 'COSRX Advanced Sna', 'quantity' => 2]])
        ->and(json_encode($p))->not->toContain('Happy birthday');
    // MUTATION: put gift_note into the note and the last expect is red.
});

it('sends a paid card order as prepaid with no amount at all', function () {
    $o = QuiqupKit::order(['payment_method' => 'stripe', 'paid_at' => now()]);
    $p = app(OrderDraft::class)->payload(qqDraft($o), 'x');

    expect($p['payment_mode'])->toBe('pre_paid')->and($p)->not->toHaveKey('payment_amount');
});

it('subtracts counted refunds from the cash to collect', function () {
    $o = QuiqupKit::order(['total' => 20000]);
    DB::table('refunds')->insert(['order_id' => $o->id, 'amount' => 5000, 'status' => 'succeeded', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('refunds')->insert(['order_id' => $o->id, 'amount' => 9999, 'status' => 'failed', 'created_at' => now(), 'updated_at' => now()]);

    expect(qqDraft($o)['cod_aed'])->toBe('150.00');
});

it('blocks a bad phone, a missing street, a non-UAE address, an unpaid card order, COD of AED 1 or less and a cancelled order', function () {
    expect(qqBlockFields(qqDraft(QuiqupKit::order(['phone' => '12345', 'shipping_address' => ['first_name' => 'A', 'line1' => 'x', 'city' => 'Dubai', 'country' => 'AE', 'phone' => '04 123 4567']]))))->toContain('phone')
        ->and(qqBlockFields(qqDraft(QuiqupKit::order(['shipping_address' => ['first_name' => 'A', 'line1' => '', 'city' => 'Dubai', 'country' => 'AE', 'phone' => '0501234567']]))))->toContain('address1')
        ->and(qqBlockFields(qqDraft(QuiqupKit::order(['ship_emirate' => null, 'shipping_address' => ['first_name' => 'A', 'line1' => 'x', 'city' => 'Riyadh', 'country' => 'SA', 'phone' => '0501234567']]))))->toContain('country')
        ->and(qqBlockFields(qqDraft(QuiqupKit::order(['payment_method' => 'tabby', 'paid_at' => null]))))->toContain('payment')
        ->and(qqBlockFields(qqDraft(QuiqupKit::order(['total' => 100]))))->toContain('payment')
        ->and(qqBlockFields(qqDraft(QuiqupKit::order(['status' => 'cancelled']))))->toContain('status')
        ->and(qqBlockFields(qqDraft(QuiqupKit::order(['status' => 'shipped']))))->toContain('status')
        ->and(qqDraft(QuiqupKit::order(['status' => 'shipped']), ['allow_shipped' => true])['blocks'])->toBe([]);
});

it('accepts every common UAE way of writing a mobile and sends E.164 with one plus', function () {
    foreach (['0501234567', '050 123 4567', '+971501234567', '+971 50 123 4567', '00971501234567', '971501234567', '501234567', '(050) 123-4567'] as $raw) {
        $o = QuiqupKit::order(['phone' => null, 'shipping_address' => ['first_name' => 'A', 'line1' => 'x', 'city' => 'Dubai', 'country' => 'AE', 'phone' => $raw]]);
        $d = qqDraft($o);
        expect($d['phone'])->toBe('+971501234567', $raw)->and(qqBlockFields($d))->not->toContain('phone');
    }
    foreach (['++971501234567', '0412345678', '+971 4 123 4567', '+966501234567', '05012345'] as $bad) {
        $o = QuiqupKit::order(['phone' => null, 'shipping_address' => ['first_name' => 'A', 'line1' => 'x', 'city' => 'Dubai', 'country' => 'AE', 'phone' => $bad]]);
        expect(qqBlockFields(qqDraft($o)))->toContain('phone');
    }
});

it('maps our emirate spelling to Quiqup\'s and lets a row fix a phone inline', function () {
    $o = QuiqupKit::order(['ship_emirate' => 'Ras Al Khaimah', 'phone' => 'none', 'shipping_address' => ['first_name' => 'A', 'line1' => 'x', 'city' => 'RAK', 'country' => 'AE', 'phone' => 'call me']]);
    app(\App\Services\Quiqup\QuiqupSettings::class)->setTestOrder($o->id, true);
    $d = qqDraft($o, [], [$o->id => ['phone' => '055 765 4321']]);

    expect($d['emirate'])->toBe('Ras Al-Khaimah')->and($d['phone'])->toBe('+971557654321')->and($d['blocks'])->toBe([]);
});

it('in Internal testing books only test orders; in Off nothing', function () {
    $mine = QuiqupKit::order();
    $shopper = QuiqupKit::order(['phone' => '0559998888', 'shipping_address' => ['first_name' => 'B', 'line1' => 'x', 'city' => 'Dubai', 'country' => 'AE', 'phone' => '0559998888']]);

    expect(qqDraft($mine)['blocks'])->toBe([])
        ->and(qqBlockFields(qqDraft($shopper)))->toContain('mode');

    QuiqupKit::configure('sandbox', 'k', ['mode' => 'off']);
    expect(qqBlockFields(qqDraft($mine)))->toContain('mode');
});

it('schedules, then books row by row with each order\'s own answer; one refusal does not stop the rest', function () {
    $a = QuiqupKit::order();
    $b = QuiqupKit::order(['phone' => '+971501234567']);
    $c = QuiqupKit::order();
    $blocked = QuiqupKit::order(['status' => 'cancelled']);

    $ids = [$a->id, $b->id, $c->id, $blocked->id];
    $r = $this->postJson('/admin-api/quiqup-schedule', ['ids' => $ids])->assertOk();
    expect($r->json('queued'))->toBe([$a->id, $b->id, $c->id])
        ->and($r->json('blocked.0.id'))->toBe($blocked->id);

    // Quiqup accepts the first and third and refuses the second with its
    // documented 422 (a closure answering null falls through to the fake).
    $this->fake->script['createOrder'] = [fn () => null, \App\Services\Quiqup\QuiqupErrors::describe(422, QuiqupKit::json('error-422-address1.json')), fn () => null];

    $step = $this->postJson('/admin-api/quiqup-step', ['ids' => $ids])->assertOk();
    $res = collect($step->json('results'))->keyBy('order_id');

    expect($res[$a->id]['ok'])->toBeTrue()->and($res[$a->id]['qq_id'])->toBeInt()
        ->and($res[$a->id]['tracking_url'])->toStartWith('https://track-parcel.quiqup.com/')
        ->and($res[$b->id]['ok'])->toBeFalse()
        ->and($res[$b->id]['error'])->toBe('HTTP 422 — [destination][address][address1] is missing')
        ->and($res[$c->id]['ok'])->toBeTrue()
        ->and($step->json('remaining'))->toBe(0)
        ->and(Order::find($a->id)->ship_state)->toBe('pending')
        ->and(Shipment::where('order_id', $b->id)->value('active_key'))->toBeNull();
});

it('costs the same number of queries for 3 orders and for 40 (preview)', function () {
    $count = function (int $n) {
        $ids = [];
        for ($i = 0; $i < $n; $i++) {
            $ids[] = QuiqupKit::order()->id;
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->postJson('/admin-api/quiqup-preview', ['ids' => $ids])->assertOk();
        $q = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $q;
    };

    expect($count(3))->toBe($count(40));
    // MUTATION: look the live shipment up per order inside one() and 40 costs more -- red.
});

it('lets support preview but not book, and refuses more than 100 orders', function () {
    $o = QuiqupKit::order();
    $this->actingAs(QuiqupKit::admin('support'), 'admin');
    $this->postJson('/admin-api/quiqup-preview', ['ids' => [$o->id]])->assertOk();
    $this->postJson('/admin-api/quiqup-schedule', ['ids' => [$o->id]])->assertForbidden();
    $this->postJson('/admin-api/quiqup-step', ['ids' => [$o->id]])->assertForbidden();
    $this->getJson('/admin-api/quiqup-labels?ids=' . $o->id)->assertForbidden();

    $this->actingAs(QuiqupKit::admin('owner'), 'admin');
    $this->postJson('/admin-api/quiqup-preview', ['ids' => range(1, 101)])->assertStatus(422);
    expect(Shipment::count())->toBe(0);
});

it('sends a 4-hour collection time in UTC with Z inside 08:00-18:59 Dubai', function () {
    $now = \Carbon\CarbonImmutable::parse('2026-11-14 07:00:00', 'Asia/Dubai');
    expect(OrderDraft::fourHourSlot('10:00', $now))->toBe('2026-11-14T06:00:00Z')
        ->and(OrderDraft::fourHourSlot('10:00', \Carbon\CarbonImmutable::parse('2026-11-14 20:00:00', 'Asia/Dubai')))->toBe('2026-11-15T06:00:00Z');

    foreach (['08:00', '13:30', '18:59'] as $t) {
        $h = (int) substr(OrderDraft::fourHourSlot($t, $now), 11, 2);
        expect($h)->toBeGreaterThanOrEqual(4)->toBeLessThanOrEqual(14);
    }
});
