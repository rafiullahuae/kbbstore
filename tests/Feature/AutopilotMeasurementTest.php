<?php

declare(strict_types=1);

/*
 * Meta measurement fixes for the Ads Autopilot (Lane AP-API): refund and
 * cancellation events, the CAPI retry inside the 48 h dedup window, the On
 * hold switch and the Graph API version. Each case says what it would catch
 * and how to make it go red (MUTATION).
 */

use App\Models\AdminUser;
use App\Models\ModuleToggle;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Refund;
use App\Models\Setting;
use App\Services\MarketingPixels;
use App\Services\Pixels\PixelConfig;
use App\Services\Pixels\PlatformClient;
use App\Services\Pixels\ServerEvents;
use App\Services\SettingsService;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\AutopilotRoutes;

const APM_TOKEN = 'EAAGapmTokenAbCdEfGhIjKlMnOpQrStUv0123';

beforeEach(function () {
    AutopilotRoutes::wire(app());
    ServerEvents::forget();
    apmFlush();
    ModuleToggle::query()->updateOrCreate(['module' => 'marketing_pixels'], ['enabled' => true]);
    app(MarketingPixels::class)->save(['meta_id' => '111122223333444']);
    expect(app(PixelConfig::class)->save(['meta_capi_token' => APM_TOKEN]))->toBe([]);
    apmFlush();
});

function apmFlush(): void
{
    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
    Cache::flush();
    app()->forgetInstance(ServerEvents::class);
}

function apmSet(array $values): void
{
    expect(app(PixelConfig::class)->save($values))->toBe([]);
    apmFlush();
}

/** Meta answers with $status; a closure so a case can change its mind. */
function apmFake(int &$status): void
{
    Http::fake(function (HttpRequest $r) use (&$status) {
        return $status === 200
            ? Http::response(['events_received' => 1], 200)
            : Http::response(['error' => ['message' => 'Service temporarily unavailable', 'code' => 2]], $status);
    });
}

function apmOrder(string $status = 'pending', bool $counted = true): Order
{
    static $n = 0;
    $n++;
    $p = Product::create(['slug' => 'apm-' . $n . '-' . uniqid(), 'name' => 'APM ' . $n, 'status' => 'publish', 'is_visible' => true, 'type' => 'simple', 'price' => 9000, 'stock_status' => 'instock']);
    $order = Order::create([
        'order_number' => 'APM' . $n . random_int(100, 999), 'email' => 'Buyer@Example.com', 'phone' => '050 123 4567', 'status' => $status,
        'currency' => 'AED', 'subtotal' => 18000, 'total' => 18000,
        'billing_address' => ['first_name' => 'Aisha', 'last_name' => 'Khan', 'city' => 'Dubai', 'country' => 'AE'],
        'pixels_fired_at' => $counted ? now() : null,
    ]);
    OrderItem::create(['order_id' => $order->id, 'product_id' => $p->id, 'name' => $p->name, 'quantity' => 2, 'unit_price' => 9000, 'subtotal' => 18000, 'total' => 18000]);

    return $order;
}

function apmBrowser(): Request
{
    return Request::create('https://kbeautybliss.test/checkout/place', 'POST', [], [], [], [
        'REMOTE_ADDR' => '203.0.113.9', 'HTTP_USER_AGENT' => 'Mozilla/5.0 APM', 'HTTP_REFERER' => 'https://kbeautybliss.test/checkout',
        'HTTP_COOKIE' => '_fbp=fb.1.1700000000000.123456789; _fbc=fb.1.1700000000000.IwAR0abcdefgh',
    ]);
}

/** @return list<array> the Meta events sent, in order */
function apmSent(): array
{
    return collect(Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), 'graph.facebook.com')))
        ->map(fn ($pair) => $pair[0]->data()['data'][0] + ['_url' => $pair[0]->url()])->values()->all();
}

// ---------------------------------------------------------- refunds + cancels

it('tells Meta about a refund once, as a positive custom event with the order\'s hashed customer', function () {
    // MUTATION: remove the Refund::saved hook in MarketingPixelsServiceProvider and nothing is sent.
    $status = 200;
    apmFake($status);
    $order = apmOrder('processing');
    app()->terminate();
    Http::fake(); // forget the order's own traffic
    apmFake($status);

    $refund = Refund::create(['order_id' => $order->id, 'amount' => 4500, 'status' => 'succeeded']);
    $refund->update(['reason' => 'damaged']); // a second save is not a second event
    app()->terminate();

    $sent = apmSent();
    expect($sent)->toHaveCount(1);
    $e = $sent[0];
    expect($e['event_name'])->toBe('OrderRefunded')
        ->and($e['event_id'])->toBe('refund-' . $refund->id)
        ->and($e['action_source'])->toBe('other')
        ->and($e['custom_data'])->toMatchArray(['currency' => 'AED', 'value' => 45, 'order_id' => (string) $order->order_number, 'refund_of' => ServerEvents::purchaseId($order)])
        ->and($e['user_data']['em'])->toBe([hash('sha256', 'buyer@example.com')])
        ->and(json_encode($e))->not->toContain('Buyer@Example.com')->and(json_encode($e))->not->toContain('Aisha');
});

it('sends OrderCancelled when a placed order is cancelled, and nothing for an order Meta never counted', function () {
    // MUTATION: drop the metaCounted() check and the imported order sends too.
    $status = 200;
    apmFake($status);
    $placed = apmOrder('processing');
    $never = apmOrder('processing', false);
    $unpaid = apmOrder('pending');
    app()->terminate();
    Http::fake();
    apmFake($status);

    $placed->update(['status' => 'cancelled']);
    $never->update(['status' => 'cancelled']);
    $unpaid->update(['status' => 'cancelled']); // never a sale: no correction
    app()->terminate();

    $sent = apmSent();
    expect($sent)->toHaveCount(1)
        ->and($sent[0]['event_name'])->toBe('OrderCancelled')
        ->and($sent[0]['custom_data']['value'])->toEqual(180)
        ->and($sent[0]['event_id'])->toStartWith('cancel-APM');
});

it('sends no refund or cancellation when the owner switches them off', function () {
    apmSet(['meta_refund_events' => '0']);
    $status = 200;
    apmFake($status);
    $order = apmOrder('processing');
    Refund::create(['order_id' => $order->id, 'amount' => 100, 'status' => 'succeeded']);
    $order->update(['status' => 'cancelled']);
    app()->terminate();

    expect(collect(apmSent())->pluck('event_name')->intersect(['OrderRefunded', 'OrderCancelled'])->all())->toBe([]);
});

// ------------------------------------------------------------------- retries

it('keeps a failed Purchase encrypted and re-sends it from the scheduler with the same id and time', function () {
    // MUTATION: drop keepForRetry() from ServerEvents::send() and the queue is empty.
    $status = 503;
    apmFake($status);
    $order = apmOrder();
    app(ServerEvents::class)->captureContext($order, apmBrowser());
    $order->update(['status' => 'processing']);
    app()->terminate();

    $row = DB::table('marketing_capi_retries')->first();
    expect($row)->not->toBeNull()->and($row->event)->toBe('Purchase')
        ->and($row->payload)->not->toContain('203.0.113.9')->and($row->payload)->not->toContain(hash('sha256', 'buyer@example.com'))
        ->and(DB::table('marketing_server_events')->where('event', 'Purchase')->value('message'))->toContain('Will retry');

    // Not due yet: nothing goes.
    $before = count(apmSent());
    expect(app(ServerEvents::class)->retryDue()['due'])->toBe(0)->and(count(apmSent()))->toBe($before);

    // Meta is back; six minutes later the scheduler's run delivers it.
    $status = 200;
    test()->travel(6)->minutes();
    expect(Artisan::call('kbb:capi-retry'))->toBe(0);

    $sent = apmSent();
    $first = $sent[0];
    $last = end($sent);
    expect($last['event_id'])->toBe(ServerEvents::purchaseId($order))
        ->and($last['event_time'])->toBe($first['event_time'])
        ->and($last['user_data']['client_ip_address'])->toBe('203.0.113.9')
        ->and(DB::table('marketing_capi_retries')->count())->toBe(0)
        ->and(DB::table('marketing_server_events')->where('event', 'Purchase')->value('status'))->toBe('sent')
        ->and(DB::table('marketing_server_events')->where('event', 'Purchase')->value('message'))->toContain('(retry 1)');
});

it('never re-sends after 47 hours, never retries a 400, and keeps nothing when retries are off', function () {
    // MUTATION: drop the $tooLate check in retryDue() and a 2-day-old Purchase is sent as a second sale.
    $status = 503;
    apmFake($status);
    $a = apmOrder();
    app(ServerEvents::class)->captureContext($a, apmBrowser());
    $a->update(['status' => 'processing']);
    app()->terminate();
    DB::table('marketing_capi_retries')->update(['first_at' => now()->subHours(48), 'next_at' => now()->subMinute()]);
    $status = 200;
    $before = count(apmSent());
    expect(app(ServerEvents::class)->retryDue()['dropped'])->toBe(1)->and(count(apmSent()))->toBe($before)
        ->and(DB::table('marketing_server_events')->where('event_id', ServerEvents::purchaseId($a))->value('message'))->toContain('Gave up');

    // A 400 is Meta refusing the event itself: it would fail the same way every time.
    $status = 400;
    $b = apmOrder();
    app(ServerEvents::class)->captureContext($b, apmBrowser());
    $b->update(['status' => 'processing']);
    app()->terminate();
    expect(DB::table('marketing_capi_retries')->count())->toBe(0);

    apmSet(['meta_capi_retry' => '0']);
    $status = 503;
    $c = apmOrder();
    app(ServerEvents::class)->captureContext($c, apmBrowser());
    $c->update(['status' => 'processing']);
    app()->terminate();
    expect(DB::table('marketing_capi_retries')->count())->toBe(0);

    expect(ServerEvents::retryable(['status' => 0, 'data' => []]))->toBeTrue()
        ->and(ServerEvents::retryable(['status' => 429, 'data' => []]))->toBeTrue()
        ->and(ServerEvents::retryable(['status' => 400, 'data' => ['error' => ['code' => 100]]]))->toBeFalse();
});

it('is on the scheduler every minute', function () {
    // MUTATION: remove the Schedule::command('kbb:capi-retry') block and no retry ever runs on the live host.
    $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
        ->filter(fn ($e) => str_contains((string) $e->command, 'kbb:capi-retry'));
    expect($events)->toHaveCount(1)->and($events->first()->expression)->toBe('* * * * *');
});

// ------------------------------------------------------------------- on hold

it('sends Purchase on On hold by default, and only when the order moves on once switched off', function () {
    // MUTATION: ignore placedStatuses() in the provider and the switched-off case sends on onhold.
    $status = 200;
    apmFake($status);
    $a = apmOrder();
    app(ServerEvents::class)->captureContext($a, apmBrowser());
    $a->update(['status' => 'onhold']);
    app()->terminate();
    expect(collect(apmSent())->where('event_name', 'Purchase'))->toHaveCount(1);

    apmSet(['meta_purchase_onhold' => '0']);
    Http::fake();
    apmFake($status);
    $b = apmOrder();
    app(ServerEvents::class)->captureContext($b, apmBrowser());
    $b->update(['status' => 'onhold']);
    app()->terminate();
    expect(apmSent())->toHaveCount(0);

    $b->update(['status' => 'processing']);
    app()->terminate();
    expect(collect(apmSent())->pluck('event_id')->all())->toBe([ServerEvents::purchaseId($b)]);
});

// ------------------------------------------------------------------ version

it('calls Graph v27.0 by default and the owner\'s pick when he picks one', function () {
    // MUTATION: put GRAPH_VERSION back to v25.0 and the first expectation is red.
    $status = 200;
    apmFake($status);
    app(PlatformClient::class)->metaEvents('111122223333444', APM_TOKEN, [['event_name' => 'X']]);
    expect(apmSent()[0]['_url'])->toStartWith('https://graph.facebook.com/v27.0/');

    apmSet(['meta_graph_version' => 'v25.0']);
    app(PlatformClient::class)->metaEvents('111122223333444', APM_TOKEN, [['event_name' => 'X']]);
    expect(apmSent()[1]['_url'])->toStartWith('https://graph.facebook.com/v25.0/');

    // A stored value that is not a listed version is never put in a URL.
    DB::table('module_settings')->where('module', 'marketing_pixels')->where('key', 'meta_graph_version')->update(['value' => '../v1']);
    apmFlush();
    expect(PlatformClient::graphVersion())->toBe('v27.0');

    $owner = AdminUser::create(['name' => 'APM', 'email' => 'apm-' . uniqid() . '@example.test', 'password' => 'secret-secret', 'role' => 'owner']);
    test()->actingAs($owner, 'admin')->postJson('/admin-api/ads-autopilot/measurement', ['graph_version' => 'v99.0'])->assertStatus(422);
    $saved = test()->actingAs($owner, 'admin')->postJson('/admin-api/ads-autopilot/measurement', ['graph_version' => 'v26.0', 'refund_events' => false])->assertOk()->json('measurement');
    expect($saved['graph_version'])->toBe('v26.0')->and($saved['refund_events'])->toBeFalse()->and($saved['consent_banner'])->toBeFalse();
});
