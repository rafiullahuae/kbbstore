<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Order;
use App\Models\QuiqupConfig;
use App\Models\Shipment;
use App\Services\Quiqup\QuiqupSettings;
use App\Services\Quiqup\ShipmentSync;
use App\Support\DeliveryTimeline;
use App\Support\OrderLinks;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\ArabicShop;
use Tests\Support\FakeQuiqupClient;
use Tests\Support\QuiqupKit;
use Tests\Support\QuiqupTimelineRoutes;

/*
 * QUIQUP P3: THE CUSTOMER DELIVERY TIMELINE (QUIQUP-PLAN §6.4, §7).
 *
 * The owner, 14 Nov 2026: "all the status must be update auto live on our
 * website in real time ... a full road map on the order tracking upon
 * entering the correct details. and also inside the user account page accross
 * each order". Every case names the defect a shopper would have seen.
 */

beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake(['*fcm.googleapis.com*' => Http::response('', 201)]);
    FakeQuiqupClient::install($this->app);
    QuiqupKit::configure();
    QuiqupTimelineRoutes::wire($this->app);
});

function qtApply(Shipment $s, string $state, string $at, array $extra = []): void
{
    $obs = ShipmentSync::observe(array_merge(['state' => $state, 'state_updated_at' => $at], $extra));
    app(ShipmentSync::class)->observeAndApply($s, $obs, 'webhook');
}

function qtCustomer(string $email): Customer
{
    return Customer::create(['name' => 'Mariam Ali', 'first_name' => 'Mariam', 'last_name' => 'Ali', 'email' => $email, 'password' => 'password123']);
}

function qtAs(Customer $c)
{
    app('auth')->forgetGuards();

    return test()->withSession(['login_customer_' . sha1(\Illuminate\Auth\SessionGuard::class) => $c->id]);
}

/** An order with a shipment driven through $states (state => ISO time) by the real ShipmentSync. */
function qtShipped(array $states, array $order = [], array $shipment = []): array
{
    $o = QuiqupKit::order($order);
    $s = QuiqupKit::shipment($o, $shipment + ['state' => null, 'state_at' => null, 'tracking_url' => 'https://track-parcel.quiqup.com/0b9e2a4c-1f7d-4e55-9a3b-6c1d2e3f4a5b']);

    foreach ($states as $state => $at) {
        [$state] = explode('#', (string) $state);
        $extra = is_array($at) ? $at[1] : [];
        qtApply($s, $state, is_array($at) ? $at[0] : $at, $extra);
    }

    return [Order::find($o->id), Shipment::find($s->id)];
}

/** The road map's step labels in page order, with their state class. */
function qtSteps(string $html): array
{
    preg_match_all('#<li class="kdt-s ([a-z ]+)"[^>]*>.*?<b>(.*?)</b>#s', $html, $m, PREG_SET_ORDER);

    return array_map(static fn ($r) => trim($r[1]) . ':' . html_entity_decode($r[2], ENT_QUOTES), $m);
}

function qtTrack(Order $o): string
{
    RateLimiter::clear('kbb-track:' . sha1('127.0.0.1|' . mb_strtolower((string) $o->email)));

    return test()->get('/track-my-order/?order=' . urlencode((string) $o->order_number) . '&email=' . urlencode((string) $o->email))->assertOk()->getContent();
}

it('draws the road map from shipment events in order, with skipped stages done and no invented time', function () {
    /* DEFECT: a timeline that lists steps out of order, or claims a time for a
       stage Quiqup never reported (a poll that jumped at_depot -> out). The
       skipped "Picked up" is ticked with no time. MUTATION: drop the filler
       loop in DeliveryTimeline::build() and "Picked up" disappears -- red. */
    [$order] = qtShipped([
        'ready_for_collection' => '2026-11-12T12:02:00Z',
        'at_depot' => '2026-11-13T07:05:00Z',
        'out_for_delivery' => '2026-11-14T04:55:00Z',
    ]);

    $html = qtTrack($order);

    expect(qtSteps($html))->toBe([
        'done:Placed', 'done:Confirmed', 'done:Booked with courier', 'done:Picked up by courier',
        'done:At the delivery hub', 'now:Out for delivery', 'wait:Delivered',
    ])
        // Shop time (Asia/Dubai, +4): 04:55Z reads 08:55.
        ->and($html)->toContain('14 Nov, 08:55')
        ->and($html)->toMatch('#Picked up by courier</b>\s*</span>#');
});

it('adds the side steps where they happened, and Returned ends the list', function () {
    /* DEFECT: a missed attempt or a return that the shopper never sees, or a
       "Delivered" still waiting under a parcel that is coming back to us.
       MUTATION: append the future stages even when returning -- red. */
    [$missed] = qtShipped([
        'collected' => '2026-11-12T09:00:00Z',
        'out_for_delivery' => '2026-11-13T05:00:00Z',
        'delivery_failed' => ['2026-11-13T13:30:00Z', ['delivery_attempts' => 1]],
        'at_depot' => '2026-11-13T15:00:00Z',
        'out_for_delivery#2' => '2026-11-14T04:55:00Z',
    ]);
    expect(array_slice(qtSteps(qtTrack($missed)), 4))->toBe([
        'done:At the delivery hub', 'warn:Delivery attempt 1 missed, we will try again', 'now:Out for delivery', 'wait:Delivered',
    ]);

    [$held] = qtShipped(['collected' => '2026-11-12T09:00:00Z', 'on_hold' => '2026-11-12T12:00:00Z']);
    expect(array_slice(qtSteps(qtTrack($held)), -4))->toBe(['warn now:Delayed', 'wait:At the delivery hub', 'wait:Out for delivery', 'wait:Delivered']);

    [$back] = qtShipped([
        'collected' => '2026-11-12T09:00:00Z',
        'out_for_delivery' => '2026-11-13T05:00:00Z',
        'return_to_origin' => '2026-11-15T05:00:00Z',
        'returned_to_origin' => '2026-11-16T05:00:00Z',
    ]);
    $steps = qtSteps(qtTrack($back));
    expect(array_slice($steps, -2))->toBe(['bad:Returning to us', 'bad now:Returned to us'])
        ->and(implode('|', $steps))->not->toContain('Delivered');
});

it('never moves backwards on a late, out-of-order or looping event', function () {
    /* DEFECT: Quiqup "may redeliver and deliver out of order"; a late at_depot
       arriving after out_for_delivery must not put the shopper's parcel back at
       the hub. MUTATION: remove the `$stage < $max` guard -- the hub step moves
       to the end and becomes "now" -- red. */
    [$order, $s] = qtShipped([
        'collected' => '2026-11-12T09:00:00Z',
        'out_for_delivery' => '2026-11-14T04:55:00Z',
    ]);
    // The late one: older than what the shipment holds. Stored, not applied (P1).
    qtApply($s, 'at_depot', '2026-11-13T07:05:00Z');
    // And a stored-but-applied older event joined straight into the history:
    DB::table('shipment_events')->insert(['shipment_id' => $s->id, 'state' => 'received_at_depot', 'state_at' => '2026-11-13 06:00:00.000000',
        'source' => 'poll', 'received_at' => now(), 'applied_at' => now()]);

    $steps = qtSteps(qtTrack(Order::find($order->id)));
    expect($steps[array_key_last($steps) - 1])->toBe('now:Out for delivery')
        ->and(Order::find($order->id)->ship_state)->toBe('out_for_delivery');
});

it('shows Delivered as the end with every step ticked, and prints no refresh script', function () {
    /* DEFECT: a script and refresh calls on a parcel that has already arrived
       ("no timer that never stops"). MUTATION: print the script whenever a
       timeline shows -- red. */
    [$order] = qtShipped(['collected' => '2026-11-12T09:00:00Z', 'delivery_complete' => '2026-11-13T09:00:00Z']);

    $html = qtTrack($order);
    $steps = qtSteps($html);

    expect(end($steps))->toBe('done:Delivered')
        ->and(implode('|', $steps))->not->toContain('now:')
        ->and($html)->not->toContain('data-live=')
        ->and($html)->not->toContain("fetch(u,")
        ->and($html)->not->toContain('track-parcel.quiqup.com'); // the courier link is for a parcel still moving
});

it('prints the tiny refresh script only while the parcel is moving, under 1 KB, bounded and layout-free', function () {
    /* DEFECT: a polling loop or a script on every account page. The owner
       approved one tiny script on these two pages only while a parcel moves.
       MUTATION: change `if(++m>6)return` or drop the n>5 stop -- red. */
    [$order] = qtShipped(['collected' => '2026-11-12T09:00:00Z', 'out_for_delivery' => '2026-11-14T04:55:00Z']);
    $html = qtTrack($order);

    preg_match_all('#<script>\(function\(\)\{var e=document\.querySelector\(\'\[data-kbb-tl\]\[data-live\]\'\).*?</script>#s', $html, $m);
    expect($m[0])->toHaveCount(1);
    $js = $m[0][0];

    expect(strlen($js))->toBeLessThan(1024)
        ->and($js)->toContain('if(++m>6)return')->toContain('if(n>5){s();return}')->toContain('6e4')->toContain('3e5')
        ->toContain("visibilitychange")->toContain('If-None-Match')
        ->not->toContain('setInterval')->not->toContain('getBoundingClientRect')->not->toContain('offsetHeight')
        ->and($html)->toContain('data-live="/track-my-order/live?order=' . $order->order_number . '&amp;t=')
        // The courier's live view: Quiqup's own page, new tab, no opener, no referrer.
        ->and($html)->toContain('href="https://track-parcel.quiqup.com/0b9e2a4c-1f7d-4e55-9a3b-6c1d2e3f4a5b" target="_blank" rel="noopener noreferrer"');
});

it('changes nothing for an order with no shipment, or whose booking was cancelled, and costs it no query', function () {
    /* DEFECT: every other shopper's track and account pages must be exactly
       today's (Internal testing books only test orders). MUTATION: make
       visible() true for a null ship_state -- the shipments query runs and the
       old steps vanish -- red. */
    $c = qtCustomer('plain@example.test');
    $plain = QuiqupKit::order(['customer_id' => $c->id, 'email' => $c->email]);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $track = qtTrack($plain);
    $page = qtAs($c)->get('/my-account/orders/' . $plain->id)->assertOk()->getContent();
    $touched = array_filter(DB::getQueryLog(), static fn ($q) => str_contains($q['query'], 'shipment') || str_contains($q['query'], 'quiqup_config'));
    DB::disableQueryLog();

    expect($touched)->toBe([])
        ->and($track . $page)->not->toContain('kdt')->not->toContain('data-kbb-tl')
        ->and($page)->toContain('class="kac-steps"');

    [$cancelled] = qtShipped(['ready_for_collection' => '2026-11-12T09:00:00Z', 'cancelled' => '2026-11-12T10:00:00Z'], ['customer_id' => $c->id, 'email' => $c->email]);
    $page = qtAs($c)->get('/my-account/orders/' . $cancelled->id)->assertOk()->getContent();
    expect($page)->toContain('class="kac-steps"')->not->toContain('data-kbb-tl');
});

it('adds exactly one query for an order that has a shipment, on both pages', function () {
    /* The plan's budget (§8): +1 only with a shipment -- shipment, events and
       the owner's step wording in ONE statement. MUTATION: load the shipment
       with Eloquent ->with('events') and this reads +2 -- red. */
    $c = qtCustomer('budget@example.test');
    $plain = QuiqupKit::order(['customer_id' => $c->id, 'email' => $c->email]);
    [$moving] = qtShipped(['collected' => '2026-11-12T09:00:00Z', 'out_for_delivery' => '2026-11-14T04:55:00Z'], ['customer_id' => $c->id, 'email' => $c->email]);

    $count = function (callable $fn): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $fn();
        $n = count(array_filter(DB::getQueryLog(), static fn ($q) => ! str_contains($q['query'], 'owner_app_push')));
        DB::disableQueryLog();

        return $n;
    };

    qtAs($c)->get('/my-account/orders/' . $plain->id); // warm
    $a0 = $count(fn () => qtAs($c)->get('/my-account/orders/' . $plain->id)->assertOk());
    $a1 = $count(fn () => qtAs($c)->get('/my-account/orders/' . $moving->id)->assertOk());
    $t0 = $count(fn () => qtTrack($plain));
    $t1 = $count(fn () => qtTrack($moving));

    expect($a1 - $a0)->toBe(1)->and($t1 - $t0)->toBe(1);
});

it('answers a wrong email and a wrong order number the same way, shows the road map only after verification, and throttles', function () {
    /* DEFECT: an order-number oracle (plan §5.3) -- or the courier link shown
       to someone who only knows a number. MUTATION: render the timeline before
       hash_equals() -- red. */
    [$order] = qtShipped(['collected' => '2026-11-12T09:00:00Z', 'out_for_delivery' => '2026-11-14T04:55:00Z']);

    $wrongEmail = $this->get('/track-my-order/?order=' . $order->order_number . '&email=nobody@example.test');
    $wrongNumber = $this->get('/track-my-order/?order=NOPE999&email=nobody@example.test');

    expect($wrongEmail->status())->toBe($wrongNumber->status())
        ->and(str_replace('NOPE999', $order->order_number, $wrongNumber->getContent()))->toBe($wrongEmail->getContent())
        ->and($wrongEmail->getContent())->not->toContain('data-kbb-tl')->not->toContain('quiqup.com');

    for ($i = 0; $i < 10; $i++) {
        $this->get('/track-my-order/?order=X' . $i . '&email=walker@example.test');
    }
    $this->get('/track-my-order/?order=' . $order->order_number . '&email=walker@example.test')->assertStatus(429);
});

it('refreshes through an allowlisted, private JSON answer: signed token only for guests, own orders only for shoppers, 304 when unchanged', function () {
    /* DEFECT: /track-my-order/live leaking a Quiqup id, the courier's failure
       note or a phone; or answering number + email (an oracle); or reading
       another shopper's order. MUTATION: return $tl whole -- the key list is
       red; drop the hash_equals ETag check -- the 304 is red. */
    $c = qtCustomer('live@example.test');
    [$order] = qtShipped([
        'collected' => '2026-11-12T09:00:00Z',
        'delivery_failed' => ['2026-11-13T13:30:00Z', ['delivery_attempts' => 1, 'delivery_failure_reason' => 'Customer not answering +971 50 123 4567', 'otp_code' => '4321']],
    ], ['customer_id' => $c->id, 'email' => $c->email]);
    $token = OrderLinks::token(OrderLinks::TRACK, $order, OrderLinks::TRACK_TTL);

    $r = $this->getJson('/track-my-order/live?order=' . $order->order_number . '&t=' . $token)->assertOk();
    expect(array_keys($r->json()))->toBe(\App\Http\Controllers\Store\DeliveryLiveController::KEYS)
        ->and($r->json('stage'))->toBe('missed')
        ->and($r->headers->get('Cache-Control'))->toContain('private')->toContain('no-cache')
        ->and($r->getContent())->not->toContain('123 4567')->not->toContain('not answering')->not->toContain('4321')
        ->not->toContain((string) Shipment::query()->where('order_id', $order->id)->value('qq_id'));

    // 304 when nothing moved, before the shipment is even read.
    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->get('/track-my-order/live?order=' . $order->order_number . '&t=' . $token, ['If-None-Match' => '"' . $r->json('v') . '"'])->assertStatus(304);
    expect(array_filter(DB::getQueryLog(), static fn ($q) => str_contains($q['query'], 'shipment')))->toBe([]);
    DB::disableQueryLog();

    // Forged token, other order's token, number + email: one identical 404.
    $other = QuiqupKit::order();
    $forged = $this->getJson('/track-my-order/live?order=' . $order->order_number . '&t=' . explode('.', $token)[0] . '.deadbeef');
    $wrongOrder = $this->getJson('/track-my-order/live?order=' . $order->order_number . '&t=' . OrderLinks::token(OrderLinks::TRACK, $other, 60));
    $byEmail = $this->getJson('/track-my-order/live?order=' . $order->order_number . '&email=' . urlencode($c->email));
    foreach ([$forged, $wrongOrder, $byEmail] as $x) {
        expect($x->status())->toBe(404)->and($x->getContent())->toBe($forged->getContent());
    }

    // Signed in: own order yes, somebody else's is the same 404 as none at all.
    $mine = qtAs($c)->getJson('/my-account/orders/' . $order->id . '/live')->assertOk();
    expect(array_keys($mine->json()))->toBe(\App\Http\Controllers\Store\DeliveryLiveController::KEYS);
    $stranger = qtCustomer('stranger@example.test');
    expect(qtAs($stranger)->getJson('/my-account/orders/' . $order->id . '/live')->status())->toBe(404);
});

it('escapes every word it prints, including the owner\'s own step wording', function () {
    /* DEFECT: stored XSS through Store -> Quiqup -> Statuses (or a translation
       row) into a customer page. MUTATION: print {!! $kdtStep['label'] !!} --
       red. */
    $opts = QuiqupConfig::query()->find(QuiqupSettings::OPTIONS_ROW)->options;
    $opts['statuses']['out_for_delivery']['en'] = '<img src=x onerror=alert(1)>On the way';
    QuiqupConfig::query()->whereKey(QuiqupSettings::OPTIONS_ROW)->update(['options' => json_encode($opts)]);

    [$order] = qtShipped(['collected' => '2026-11-12T09:00:00Z', 'out_for_delivery' => '2026-11-14T04:55:00Z']);
    $html = qtTrack($order);
    $json = $this->getJson('/track-my-order/live?order=' . $order->order_number . '&t=' . OrderLinks::token(OrderLinks::TRACK, $order, 60))->json();

    expect($html)->not->toContain('<img src=x')->toContain('&lt;img src=x onerror=alert(1)&gt;On the way')
        ->and($json['html'])->not->toContain('<img src=x')->toContain('&lt;img src=x')
        // The owner's own wording wins over the shipped string -- that is what the Statuses screen promises.
        ->and($json['label'])->toBe('<img src=x onerror=alert(1)>On the way');
});

it('reads Arabic, right to left, on /ar, with no hard-coded left/right and sized icons', function () {
    /* DEFECT: English steps on the Arabic shop, or a road map that does not
       mirror. MUTATION: replace inset-inline-start with left in the partial --
       red. */
    ArabicShop::on();
    (require base_path('database/migrations/2028_02_14_100000_delivery_timeline_arabic.php'))->up();
    [$order] = qtShipped(['collected' => '2026-11-12T09:00:00Z', 'out_for_delivery' => '2026-11-14T04:55:00Z']);

    RateLimiter::clear('kbb-track:' . sha1('127.0.0.1|' . $order->email));
    $html = $this->get('/ar/track-my-order/?order=' . $order->order_number . '&email=' . urlencode($order->email))->assertOk()->getContent();

    expect($html)->toContain('dir="rtl"')->toContain('خرج للتوصيل')->toContain('تم الاستلام من المندوب')->toContain('مراحل التوصيل')
        ->not->toContain('>Out for delivery<');

    $partials = file_get_contents(resource_path('views/partials/account/delivery-timeline.blade.php'))
        . file_get_contents(resource_path('views/partials/account/delivery-timeline-list.blade.php'));
    expect($partials)->not->toMatch('/[{;](margin|padding)-(left|right)|[{;](left|right):|text-align:(left|right)/');

    preg_match_all('#<svg[^>]*>#', (string) preg_replace('#.*?(<section class="kdt".*?</section>).*#s', '$1', $html), $svgs);
    expect($svgs[0])->not->toBeEmpty();
    foreach ($svgs[0] as $svg) {
        expect($svg)->toMatch('/ width="\d+"/')->toMatch('/ height="\d+"/');
    }
});

it('names the delivery stage on My account -> Orders with zero extra queries', function () {
    /* DEFECT: the orders list says "On its way" while the courier is at the
       door; or it pays a query per row to say better. MUTATION: load the
       shipment in bandLabel() -- the 3-vs-12 count differs -- red. */
    $c = qtCustomer('list@example.test');
    [$o] = qtShipped(['collected' => '2026-11-12T09:00:00Z', 'out_for_delivery' => '2026-11-14T04:55:00Z'], ['customer_id' => $c->id, 'email' => $c->email]);

    expect(qtAs($c)->get('/my-account/orders')->assertOk()->getContent())->toContain('Out for delivery');

    $count = function () use ($c): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        qtAs($c)->get('/my-account/orders')->assertOk();
        $n = count(array_filter(DB::getQueryLog(), static fn ($q) => ! str_contains($q['query'], 'owner_app_push')));
        DB::disableQueryLog();

        return $n;
    };
    $three = $count();
    foreach (range(1, 9) as $i) {
        DB::table('orders')->insert(['order_number' => 'QTL' . $i, 'customer_id' => $c->id, 'email' => $c->email, 'status' => 'shipped',
            'ship_state' => 'at_depot', 'ship_state_at' => now(), 'total' => 100, 'subtotal' => 100, 'created_at' => now(), 'updated_at' => now()]);
    }
    expect($count())->toBe($three);
});

it('sends Web Push for out for delivery and a missed attempt, once, only while fresh, only to opted-in phones', function () {
    /* DEFECT: no word to a shopper whose courier is on the way; or the same
       push twice when the webhook and the poll both report it; or yesterday's
       moment announced today. MUTATION: drop PUSH_FRESH_HOURS -- the stale
       one queues -- red. */
    require_once __DIR__ . '/../Support/PushTestHelpers.php';
    $c = qtCustomer('push@example.test');
    $phone = pnPhone(['customer_id' => $c->id]);
    $o = QuiqupKit::order(['customer_id' => $c->id, 'email' => $c->email]);
    $s = QuiqupKit::shipment($o, ['state' => null, 'state_at' => null]);

    qtApply($s, 'collected', now()->subHours(3)->toIso8601ZuluString());
    expect(DB::table('push_sends')->where('dedupe', 'like', 'd:%')->count())->toBe(0); // push off for collected

    qtApply($s, 'out_for_delivery', now()->subHour()->toIso8601ZuluString());
    qtApply($s, 'delivery_failed', now()->subMinutes(10)->toIso8601ZuluString(), ['delivery_attempts' => 1]);

    $sends = DB::table('push_sends')->where('dedupe', 'like', 'd:%')->orderBy('id')->get();
    expect($sends->pluck('dedupe')->all())->toBe(['d:' . $o->id . ':out_for_delivery:0:' . $phone, 'd:' . $o->id . ':delivery_failed:1:' . $phone])
        ->and($sends[0]->title)->toBe('Order ' . $o->order_number . ' · Out for delivery')
        ->and($sends[0]->url)->toBe('/my-account/orders/' . $o->id)
        ->and($sends[1]->body)->not->toContain('+971');

    // The same moment again (poll after webhook): still one.
    qtApply($s, 'delivery_failed', now()->subMinutes(10)->toIso8601ZuluString(), ['delivery_attempts' => 1]);
    // A stale moment (8 h old) on another order: nothing.
    $o2 = QuiqupKit::order(['customer_id' => $c->id, 'email' => $c->email]);
    $s2 = QuiqupKit::shipment($o2, ['state' => null, 'state_at' => null]);
    qtApply($s2, 'out_for_delivery', now()->subHours(8)->toIso8601ZuluString());

    expect(DB::table('push_sends')->where('dedupe', 'like', 'd:%')->count())->toBe(2);
});

it('publishes the Arabic wording into missing and unreviewed rows only', function () {
    /* DEFECT: a package overwriting the owner's own Arabic. MUTATION: update
       published rows too -- the owner's row is red. */
    DB::table('translations')->where('field', 'like', 'store.delivery.%')->delete();
    $now = now();
    DB::table('translations')->insert([
        ['locale' => 'ar', 'group' => 'ui', 'item_id' => 0, 'field' => 'store.delivery.step_out', 'value' => 'كلمات المالك', 'status' => 'published', 'source' => 'manual', 'source_hash' => sha1('x'), 'reviewed_at' => $now, 'created_at' => $now, 'updated_at' => $now],
        ['locale' => 'ar', 'group' => 'ui', 'item_id' => 0, 'field' => 'store.delivery.step_hub', 'value' => 'مسودة', 'status' => 'draft', 'source' => 'machine', 'source_hash' => sha1('x'), 'reviewed_at' => null, 'created_at' => $now, 'updated_at' => $now],
    ]);

    (require base_path('database/migrations/2028_02_14_100000_delivery_timeline_arabic.php'))->up();
    (require base_path('database/migrations/2028_02_14_100000_delivery_timeline_arabic.php'))->up(); // twice: safe

    $v = fn (string $f) => DB::table('translations')->where('locale', 'ar')->where('field', $f)->get();
    expect($v('store.delivery.step_out')->pluck('value')->all())->toBe(['كلمات المالك'])
        ->and($v('store.delivery.step_hub')->pluck('value')->all())->toBe(['في مركز التوزيع'])
        ->and($v('store.delivery.step_hub')->first()->status)->toBe('published')
        ->and($v('store.delivery.step_picked')->pluck('value')->all())->toBe(['تم الاستلام من المندوب']);
});

it('is wired at most once, and every string it uses is keyed in both languages', function () {
    /* The finished state: the integrator requires routes/quiqup-timeline.php
       exactly once (twice registers the routes twice). Never asserts the
       require is ABSENT (CLAUDE.md). */
    expect(substr_count((string) file_get_contents(base_path('routes/web.php')), "require __DIR__.'/quiqup-timeline.php';"))->toBeLessThanOrEqual(1);

    $en = \App\Services\Translation\InterfaceStrings::all()['store'];
    $ar = \App\Services\Translation\ArabicInterfaceDrafts::all();
    foreach (array_keys(\App\Services\Translation\DeliveryTimelineStrings::ROWS) as $key) {
        expect($en)->toHaveKey($key)->and($ar)->toHaveKey('store.' . $key);
    }
    // Every Quiqup state with a customer step has one here.
    foreach (\App\Services\Quiqup\QuiqupStates::DEFAULTS as $state => $row) {
        if ($row['en'] !== '') {
            expect(DeliveryTimeline::label($state, 1, null))->not->toBe('');
        }
    }
});
