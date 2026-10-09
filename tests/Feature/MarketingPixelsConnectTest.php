<?php

declare(strict_types=1);

/*
 * Marketing Pixels: Connect wizards, server events, catalog feeds, verification
 * tags (Lane MP). Each case names the defect it would catch and how to make it
 * go red (MUTATION).
 */

use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\ModuleToggle;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Setting;
use App\Services\MarketingPixels;
use App\Services\Pixels\BrowserContext;
use App\Services\Pixels\CustomCode;
use App\Services\Pixels\PixelConfig;
use App\Services\Pixels\PixelGuide;
use App\Services\Pixels\ServerEvents;
use App\Services\Pixels\ShopTags;
use App\Services\Pixels\UserData;
use App\Services\SettingsService;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\PixelConnectRoutes;

const MPX_BASE = 'https://kbeautybliss.test';
const MPX_META_TOKEN = 'EAAGtokenAbCdEfGhIjKlMnOpQrStUvWxYz0123WXYZ';
const MPX_TT_TOKEN = 'tiktokTokenAbCdEfGhIjKlMn9876';
const MPX_GA_SECRET = 'gaSecretAbCd1234';

beforeEach(function () {
    PixelConnectRoutes::wire(app());
    Setting::updateOrCreate(['key' => 'site_url'], ['value' => MPX_BASE]);
    mpxFlush();
});

function mpxFlush(): void
{
    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
    Cache::flush();
}

function mpxAdmin(string $role): AdminUser
{
    return AdminUser::create(['name' => 'MPX '.$role, 'email' => 'mpx-'.$role.'-'.uniqid().'@example.test', 'password' => 'secret-secret', 'role' => $role]);
}

/** Pixels on, all three IDs, and (optionally) all three server credentials. */
function mpxConfigure(bool $server = true): void
{
    ModuleToggle::query()->updateOrCreate(['module' => 'marketing_pixels'], ['enabled' => true]);
    app(MarketingPixels::class)->save(['meta_id' => '111122223333444', 'ga4_id' => 'G-TESTMP12', 'tiktok_id' => 'CQ1ABCDEFGHIJ']);

    if ($server) {
        expect(app(PixelConfig::class)->save([
            'meta_capi_token' => MPX_META_TOKEN, 'ga4_api_secret' => MPX_GA_SECRET, 'tiktok_token' => MPX_TT_TOKEN,
        ]))->toBe([]);
    }

    mpxFlush();
}

function mpxFake(int $status = 200): void
{
    Http::fake([
        'graph.facebook.com/*' => Http::response($status === 200 ? ['events_received' => 1, 'fbtrace_id' => 'Abc'] : ['error' => ['message' => 'Invalid OAuth access token.', 'code' => 190]], $status),
        'business-api.tiktok.com/*' => Http::response($status === 200 ? ['code' => 0, 'message' => 'OK'] : ['code' => 40001, 'message' => 'Access token invalid'], $status),
        'www.google-analytics.com/*' => Http::response('', $status === 200 ? 204 : $status),
    ]);
}

function mpxProduct(int $fils = 12600, array $attrs = []): Product
{
    static $n = 0;
    $n++;
    $brand = Brand::firstOrCreate(['slug' => 'mpx-brand'], ['name' => 'MPX Brand']);

    return Product::create(array_merge([
        'slug' => 'mpx-product-'.$n, 'name' => 'MPX Product '.$n, 'brand_id' => $brand->id, 'status' => 'publish',
        'is_visible' => true, 'type' => 'simple', 'price' => $fils, 'stock_status' => 'instock',
        'description' => '<p>Calming toner.</p>', 'image' => '/wp-content/uploads/mpx-'.$n.'.jpg', 'sku' => 'MPX-SKU-'.$n,
    ], $attrs));
}

function mpxOrder(string $status = 'pending'): Order
{
    $product = mpxProduct(12600);
    $order = Order::create([
        'order_number' => '10'.random_int(1000, 9999), 'email' => ' Buyer@Example.COM ', 'phone' => '050 123 4567',
        'status' => $status, 'currency' => 'AED', 'subtotal' => 25200, 'total' => 25200,
        'billing_address' => ['first_name' => 'Aisha', 'last_name' => 'Khan', 'city' => 'Dubai', 'country' => 'AE'],
    ]);
    OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'name' => $product->name, 'quantity' => 2,
        'unit_price' => 12600, 'subtotal' => 25200, 'total' => 25200]);

    return $order;
}

function mpxBrowser(array $server = []): Request
{
    return Request::create(MPX_BASE.'/checkout/place', 'POST', [], [], [], $server + [
        'REMOTE_ADDR' => '203.0.113.9',
        'HTTP_USER_AGENT' => 'Mozilla/5.0 MPX',
        'HTTP_REFERER' => MPX_BASE.'/checkout',
        'HTTP_COOKIE' => '_fbp=fb.1.1700000000000.123456789; _fbc=fb.1.1700000000000.IwAR0abcdefgh; _ga=GA1.1.987654321.1700000000; _ga_ABC123=GS2.1.s1700000123$o1$g1$t1; _ttp=ttpCookie12345678',
    ]);
}

/** @return list<array> the JSON bodies sent to one host */
function mpxSent(string $host): array
{
    return collect(Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), $host)))
        ->map(fn ($pair) => $pair[0]->data())->values()->all();
}

// ------------------------------------------------------------------ hashing

it('normalises before hashing, the way each platform documents', function () {
    // MUTATION: drop mb_strtolower() in UserData::email() and the first line is red.
    expect(UserData::email(' Buyer@Example.COM '))->toBe('buyer@example.com')
        ->and(UserData::email('j.o.e@gmail.com', true))->toBe('joe@gmail.com')
        ->and(UserData::email('j.o.e@gmail.com'))->toBe('j.o.e@gmail.com')
        ->and(UserData::email('not an email'))->toBeNull()
        // A UAE mobile in each way a shopper types it ends as the same number.
        ->and(UserData::phoneDigits('050 123 4567'))->toBe('971501234567')
        ->and(UserData::phoneDigits('+971 50 123 4567'))->toBe('971501234567')
        ->and(UserData::phoneDigits('00971501234567'))->toBe('971501234567')
        ->and(UserData::phoneDigits('501234567'))->toBe('971501234567')
        ->and(UserData::phoneE164('050-123-4567'))->toBe('+971501234567')
        ->and(UserData::phoneDigits('12'))->toBeNull()
        ->and(UserData::name("  O'Neil-Smith "))->toBe('oneilsmith')
        ->and(UserData::city('Ras Al Khaimah'))->toBe('rasalkhaimah')
        ->and(UserData::country('AE'))->toBe('ae')
        ->and(UserData::hashed(UserData::email('Buyer@Example.com')))->toBe(hash('sha256', 'buyer@example.com'));
});

it('reads the platforms\' own cookies from the raw header, shape-checked', function () {
    // The defect: $request->cookie() returns null for every JS-set cookie
    // under EncryptCookies, so fbp/fbc/_ga were silently never sent.
    $ctx = BrowserContext::from(mpxBrowser(['HTTP_COOKIE' => '_fbp=fb.1.1700000000000.123456789; _ga=GA1.1.987654321.1700000000; kbb_eid=atc-abc12345; _fbc=<script>']));

    expect($ctx['fbp'])->toBe('fb.1.1700000000000.123456789')
        ->and($ctx['ga_client'])->toBe('987654321.1700000000')
        ->and($ctx['eid'])->toBe('atc-abc12345')
        ->and($ctx['fbc'])->toBeNull()
        ->and($ctx['ip'])->toBe('203.0.113.9');
});

// ------------------------------------------------------- purchase, end to end

it('sends Purchase to all three platforms after the response, with the browser\'s event id', function () {
    mpxConfigure();
    mpxFake();
    $order = mpxOrder();
    app(ServerEvents::class)->captureContext($order, mpxBrowser());

    // The context row is ciphertext: the shopper's IP is not readable at rest.
    $stored = (string) DB::table('marketing_event_contexts')->where('order_id', $order->id)->value('payload');
    expect($stored)->not->toContain('203.0.113.9')->and(Crypt::decryptString($stored))->toContain('203.0.113.9');

    $order->update(['status' => 'processing']);

    // MUTATION: call $send() inline in ServerEvents::dispatch() instead of
    // self::later() and this is red — the checkout would wait on three APIs.
    Http::assertNothingSent();
    app()->terminate();

    $eventId = ServerEvents::purchaseId($order);
    $meta = mpxSent('graph.facebook.com')[0];
    $event = $meta['data'][0];
    expect($meta['access_token'])->toBe(MPX_META_TOKEN)
        ->and($event['event_name'])->toBe('Purchase')
        ->and($event['event_id'])->toBe($eventId)
        ->and($event['action_source'])->toBe('website')
        ->and($event['custom_data']['value'])->toBe(252.0)
        ->and($event['custom_data']['currency'])->toBe('AED')
        ->and($event['custom_data']['content_ids'])->toBe([(string) $order->items->first()->product_id])
        ->and($event['user_data']['em'])->toBe([hash('sha256', 'buyer@example.com')])
        ->and($event['user_data']['ph'])->toBe([hash('sha256', '971501234567')])
        ->and($event['user_data']['country'])->toBe([hash('sha256', 'ae')])
        ->and($event['user_data']['client_ip_address'])->toBe('203.0.113.9')
        ->and($event['user_data']['client_user_agent'])->toBe('Mozilla/5.0 MPX')
        ->and($event['user_data']['fbp'])->toBe('fb.1.1700000000000.123456789');

    $tt = mpxSent('business-api.tiktok.com')[0];
    expect($tt['event_source'])->toBe('web')->and($tt['event_source_id'])->toBe('CQ1ABCDEFGHIJ')
        ->and($tt['data'][0]['event'])->toBe('CompletePayment')
        ->and($tt['data'][0]['event_id'])->toBe($eventId)
        ->and($tt['data'][0]['user']['phone'])->toBe(hash('sha256', '+971501234567'))
        ->and($tt['data'][0]['user']['ttp'])->toBe('ttpCookie12345678')
        ->and($tt['data'][0]['properties']['value'])->toBe(252.0);

    $ga = mpxSent('google-analytics.com')[0];
    expect($ga['client_id'])->toBe('987654321.1700000000')
        ->and($ga['events'][0]['name'])->toBe('purchase')
        ->and($ga['events'][0]['params']['transaction_id'])->toBe((string) $order->order_number)
        ->and($ga['events'][0]['params']['session_id'])->toBe('1700000123');

    // The browser's own Purchase carries the same id (deduplication).
    $order->refresh();
    $html = app(MarketingPixels::class)->purchase($order);
    expect($html)->toContain("{eventID:\"{$eventId}\"}")->and($html)->toContain("{event_id:\"{$eventId}\"}");

    // Once per order: a second status move does not send again.
    $order->update(['status' => 'onhold']);
    $order->update(['status' => 'pending']);
    $order->update(['status' => 'processing']);
    app()->terminate();
    expect(mpxSent('graph.facebook.com'))->toHaveCount(1);
    expect(DB::table('marketing_server_events')->where('event', 'Purchase')->value('status'))->toBe('sent');
});

it('sends nothing for an order that did not come through the shop\'s checkout', function () {
    // An imported or hand-made order has no browser context: no ad can claim it.
    // MUTATION: remove the `$ctx === null` return in ServerEvents::purchase().
    mpxConfigure();
    mpxFake();
    $order = mpxOrder();
    $order->update(['status' => 'processing']);
    app()->terminate();

    Http::assertNothingSent();
});

it('never blocks or fails the order when a platform is down, and logs why', function () {
    mpxConfigure();
    Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('Connection timed out after 4000 ms'));
    $order = mpxOrder();
    app(ServerEvents::class)->captureContext($order, mpxBrowser());

    $order->update(['status' => 'processing']);
    app()->terminate();

    expect($order->fresh()->status)->toBe('processing');
    $rows = DB::table('marketing_server_events')->get();
    expect($rows->where('status', 'failed'))->toHaveCount(3)
        ->and((string) $rows->firstWhere('platform', 'meta')->message)->toContain('Connection timed out')
        // The token never reaches the log.
        ->and($rows->pluck('message')->implode(' '))->not->toContain(MPX_META_TOKEN);
});

it('pauses a platform for ten minutes after it refuses the token', function () {
    mpxConfigure();
    mpxFake(401);
    $a = mpxOrder();
    app(ServerEvents::class)->captureContext($a, mpxBrowser());
    $a->update(['status' => 'processing']);
    app()->terminate();

    $b = mpxOrder();
    app(ServerEvents::class)->captureContext($b, mpxBrowser());
    $b->update(['status' => 'processing']);
    app()->terminate();

    // MUTATION: drop the Cache::put in ServerEvents::send() and b's row is 'failed'.
    expect(DB::table('marketing_server_events')->where('platform', 'meta')->where('event_id', ServerEvents::purchaseId($b))->value('status'))->toBe('paused')
        ->and(DB::table('marketing_server_events')->where('platform', 'meta')->where('event_id', ServerEvents::purchaseId($a))->value('message'))->toContain('Invalid OAuth access token');
});

// ---------------------------------------------------------------- prefetch

it('fires nothing on a prefetch: no server event, and the order\'s one Purchase is not spent', function () {
    mpxConfigure();
    mpxFake();
    $prefetch = mpxBrowser(['HTTP_SEC_PURPOSE' => 'prefetch']);
    app()->instance('request', $prefetch);

    app(ServerEvents::class)->initiateCheckout($prefetch, 'ic-abc', 25200);
    app(ServerEvents::class)->addToCart($prefetch, 1, null, 1, 100);
    app()->terminate();
    Http::assertNothingSent();

    // MUTATION: drop the isSpeculative() guard in MarketingPixels::purchase()
    // and the prefetch claims pixels_fired_at, so the real visit fires nothing.
    $order = mpxOrder();
    expect(app(MarketingPixels::class)->purchase($order))->toBe('')
        ->and($order->fresh()->pixels_fired_at)->toBeNull();
});

it('gives the checkout\'s browser InitiateCheckout and its server copy one id', function () {
    mpxConfigure();
    mpxFake();
    app()->instance('request', mpxBrowser());

    $html = app(MarketingPixels::class)->beginCheckout(25200);
    preg_match("/fbq\\('track','InitiateCheckout',\\{value:252,currency:\"AED\"\\},\\{eventID:\"(ic-[0-9a-f]{16})\"\\}\\)/", $html, $m);
    expect($m[1] ?? null)->not->toBeNull("no eventID in: {$html}")
        ->and($html)->toContain("ttq.track('InitiateCheckout',{value:252,currency:\"AED\"},{event_id:\"{$m[1]}\"})");

    app()->terminate();
    expect(mpxSent('graph.facebook.com')[0]['data'][0]['event_id'])->toBe($m[1])
        ->and(mpxSent('business-api.tiktok.com')[0]['data'][0]['event_id'])->toBe($m[1])
        // GA4 has no event id to deduplicate with, so it is not sent a copy.
        ->and(mpxSent('google-analytics.com'))->toBe([]);
});

it('prints the same browser events as before when no server token is set', function () {
    // No token, no eventID, no cookie: the listener and the checkout call are
    // what they were. MUTATION: make $dedup always true in addToCart().
    mpxConfigure(server: false);
    app()->instance('request', mpxBrowser());

    $atc = app(MarketingPixels::class)->addToCart();
    $ic = app(MarketingPixels::class)->beginCheckout(25200);
    expect($atc)->not->toContain('kbb_eid')->and($atc)->not->toContain('eventID')
        ->and($ic)->toContain("fbq('track','InitiateCheckout',{value:252,currency:\"AED\"});");
});

it('sends AddToCart from the add request with the id the click listener minted', function () {
    mpxConfigure();
    mpxFake();
    $product = mpxProduct(9900);

    $listener = app(MarketingPixels::class)->addToCart();
    expect($listener)->toContain("document.cookie='kbb_eid='+eid")->and($listener)->toContain('{eventID:eid}');

    test()->withHeaders(['Cookie' => 'kbb_eid=atc-lmn0p9q8r7; _fbp=fb.1.1700000000000.555', 'Referer' => MPX_BASE.'/product/x/'])
        ->postJson('/api/cart/add', ['product_id' => $product->id, 'quantity' => 2])->assertOk();

    $event = mpxSent('graph.facebook.com')[0]['data'][0] ?? null;
    expect($event)->not->toBeNull()
        ->and($event['event_name'])->toBe('AddToCart')
        ->and($event['event_id'])->toBe('atc-lmn0p9q8r7')
        ->and($event['custom_data']['content_ids'])->toBe([(string) $product->id])
        ->and($event['custom_data']['value'])->toBe(198.0)
        ->and($event['event_source_url'])->toBe(MPX_BASE.'/product/x/');
});

// -------------------------------------------------------------------- feeds

it('serves the Meta and TikTok catalog feeds with the pixels\' ids, allowlisted fields and flat queries', function () {
    DB::table('products')->update(['status' => 'draft']);
    $make = fn (int $n) => collect(range(1, $n))->map(fn () => mpxProduct(10000, ['wc_id' => random_int(100000, 999999), 'total_sales' => 77]))->all();

    $count = function () {
        Cache::flush();
        mpxFlush();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $xml = test()->get('/feeds/meta-catalog.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')->getContent();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [$xml, $n];
    };

    $three = $make(3);
    [$xml3, $q3] = $count();
    $make(37);
    [$xml40, $q40] = $count();

    // MUTATION: load the brand per product inside the loop and $q40 > $q3.
    expect($q40)->toBe($q3);

    $doc = simplexml_load_string($xml40);
    expect($doc)->not->toBeFalse();
    $ids = [];
    foreach ($doc->channel->item as $item) {
        $g = $item->children('http://base.google.com/ns/1.0');
        $ids[] = (string) $g->id;
        foreach (['title', 'description', 'link', 'image_link', 'availability', 'price', 'brand', 'condition'] as $field) {
            expect(trim((string) $g->{$field}))->not->toBe('', "item {$g->id} has no {$field}");
        }
    }
    expect($ids)->toContain((string) $three[0]->id)
        ->and(str_contains($xml40, 'wc_id') || str_contains($xml40, 'total_sales') || str_contains($xml40, '>77<'))->toBeFalse()
        ->and(test()->get('/feeds/tiktok-catalog.xml')->assertOk()->getContent())->toBe($xml40);

    // The Google feed keeps its SKU ids: a separate cache entry, not overwritten.
    expect(app(\App\Services\Seo\MerchantFeed::class)->cached()['xml'])->toContain('<g:id>MPX-SKU-');
});

// ------------------------------------------------------------- admin + secrets

it('stores tokens encrypted and shows back only the last four characters', function () {
    $owner = mpxAdmin('owner');
    test()->actingAs($owner, 'admin')->postJson('/admin-api/marketing-pixels/connect', [
        'values' => ['meta_capi_token' => MPX_META_TOKEN, 'tiktok_token' => MPX_TT_TOKEN, 'ga4_api_secret' => MPX_GA_SECRET],
        'ids' => ['meta_id' => '111122223333444'],
    ])->assertOk()->assertJson(['ok' => true]);

    $raw = (string) DB::table('module_settings')->where('module', 'marketing_pixels')->where('key', 'meta_capi_token')->value('value');
    // MUTATION: store $value instead of Crypt::encryptString($value) in PixelConfig::save().
    expect($raw)->not->toContain(MPX_META_TOKEN)->and(Crypt::decryptString($raw))->toBe(MPX_META_TOKEN);

    mpxFlush();
    $body = test()->actingAs($owner, 'admin')->getJson('/admin-api/marketing-pixels/connect')->assertOk()->getContent();
    expect($body)->not->toContain(MPX_META_TOKEN)->and($body)->not->toContain(MPX_TT_TOKEN)->and($body)->not->toContain(MPX_GA_SECRET)
        ->and(json_decode($body, true)['masked']['meta_capi_token'])->toBe('••••WXYZ');

    // A blank secret box means "unchanged", never "delete".
    test()->actingAs($owner, 'admin')->postJson('/admin-api/marketing-pixels/connect', ['values' => ['meta_capi_token' => '']])->assertOk();
    mpxFlush();
    expect(app(PixelConfig::class)->secret('meta_capi_token'))->toBe(MPX_META_TOKEN);

    // Nor do they reach the public settings API or a shop page.
    mpxConfigure();
    expect(test()->getJson('/api/settings')->getContent())->not->toContain(MPX_META_TOKEN);
    expect(test()->get('/')->getContent())->not->toContain(MPX_META_TOKEN)->not->toContain(MPX_GA_SECRET);
});

it('refuses a value of the wrong shape and names the field', function () {
    test()->actingAs(mpxAdmin('owner'), 'admin')->postJson('/admin-api/marketing-pixels/connect', [
        'values' => ['ads_id' => 'AW-12<script>', 'consent_mode' => 'maybe'],
    ])->assertStatus(422)->assertJsonFragment(['fields' => ['ads_id', 'consent_mode']]);
});

it('fails closed: each endpoint needs its own capability', function () {
    // MUTATION: delete the custom-code lines from AdminCapabilities::RULES and
    // the manager reaches raw shop script through the `/**` rule below them.
    test()->actingAs(mpxAdmin('editor'), 'admin')->getJson('/admin-api/marketing-pixels/connect')->assertForbidden();
    test()->actingAs(mpxAdmin('editor'), 'admin')->postJson('/admin-api/marketing-pixels/check/meta')->assertForbidden();
    test()->actingAs(mpxAdmin('support'), 'admin')->postJson('/admin-api/marketing-pixels/connect', ['values' => []])->assertForbidden();
    test()->actingAs(mpxAdmin('manager'), 'admin')->getJson('/admin-api/marketing-pixels/connect')->assertOk();
    test()->actingAs(mpxAdmin('manager'), 'admin')->getJson('/admin-api/marketing-pixels/custom-code')->assertForbidden();
    test()->actingAs(mpxAdmin('manager'), 'admin')->postJson('/admin-api/marketing-pixels/custom-code', ['slots' => []])->assertForbidden();
    test()->actingAs(mpxAdmin('manager'), 'admin')->postJson('/admin-api/marketing-pixels/custom-code/restore/1')->assertForbidden();
    test()->actingAs(mpxAdmin('owner'), 'admin')->getJson('/admin-api/marketing-pixels/custom-code')->assertOk();

    expect(\App\Support\AdminCapabilities::forPath('POST', 'admin-api/marketing-pixels/custom-code/restore/3'))->toBe('marketing.customcode')
        ->and(\App\Support\AdminCapabilities::CAPABILITIES['marketing.customcode'])->toBe(['owner']);

    auth('admin')->logout();
    expect(test()->getJson('/admin-api/marketing-pixels/connect')->status())->toBeIn([401, 302, 403]);
});

// ------------------------------------------------------------------ checks

it('shows the platform\'s own refusal on Check, and a green test event when it accepts', function () {
    mpxConfigure();
    app(PixelConfig::class)->save(['meta_test_code' => 'TEST4242']);
    mpxFlush();
    $owner = mpxAdmin('owner');

    // One fake for the whole case (Http::fake() stubs accumulate, first match wins).
    $mode = 'refuse';
    Http::fake(function (HttpRequest $r) use (&$mode) {
        if (str_contains($r->url(), 'google-analytics.com/debug/')) {
            return Http::response(['validationMessages' => [['description' => 'Unable to parse Measurement Protocol JSON payload.']]]);
        }
        if ($mode === 'refuse') {
            return Http::response(['error' => ['message' => 'Invalid OAuth access token.', 'code' => 190]], 400);
        }

        return str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/events')
            ? Http::response(['events_received' => 1])
            : Http::response(['id' => '111122223333444', 'name' => 'Shop pixel']);
    });

    $bad = test()->actingAs($owner, 'admin')->postJson('/admin-api/marketing-pixels/check/meta')->assertOk()->json();
    expect($bad['ok'])->toBeFalse()->and(collect($bad['steps'])->pluck('message')->implode(' '))->toContain('Invalid OAuth access token.');

    $mode = 'accept';
    $good = test()->actingAs($owner, 'admin')->postJson('/admin-api/marketing-pixels/check/meta')->assertOk()->json();
    expect($good['ok'])->toBeTrue()
        ->and(collect(Http::recorded())->last()[0]->data()['test_event_code'])->toBe('TEST4242');

    // GA4: Google's validation server's own message comes back.
    $ga = test()->actingAs($owner, 'admin')->postJson('/admin-api/marketing-pixels/check/ga4')->assertOk()->json();
    expect($ga['ok'])->toBeFalse()->and(json_encode($ga))->toContain('Unable to parse');
});

it('only ever calls the three platform hosts over https', function () {
    $client = new \App\Services\Pixels\PlatformClient();
    $ref = new ReflectionMethod($client, 'call');
    Http::fake();

    $answer = $ref->invoke($client, 'post', 'https://evil.example/collect', [], [], []);
    expect($answer['ok'])->toBeFalse();
    Http::assertNothingSent();
});

// ------------------------------------------------------- verification tags

it('prints a verification tag only as a validated code, rebuilt from constants', function () {
    $owner = mpxAdmin('owner');
    test()->actingAs($owner, 'admin')->postJson('/admin-api/marketing-pixels/connect', ['values' => [
        'facebook_domain_verification' => '<meta name="facebook-domain-verification" content="abc123def456ghi789" />',
    ]])->assertOk();
    expect(Setting::query()->where('key', 'facebook_domain_verification')->value('value'))->toBe('abc123def456ghi789');

    // MUTATION: store the pasted text as-is and this markup reaches the head.
    test()->actingAs($owner, 'admin')->postJson('/admin-api/marketing-pixels/connect', ['values' => [
        'facebook_domain_verification' => '"><script>alert(1)</script>',
    ]])->assertStatus(422);
    test()->actingAs($owner, 'admin')->postJson('/admin-api/marketing-pixels/connect', ['values' => [
        'google_site_verification' => 'x" onload="alert(1)',
    ]])->assertStatus(422);

    mpxFlush();
    expect(app(ShopTags::class)->head())->toBe('<meta name="facebook-domain-verification" content="abc123def456ghi789">'."\n");
});

// ------------------------------------------------------------ untouched shop

it('adds nothing at all to a shop page while nothing is configured', function () {
    // The three print points return '' — not a newline — so the layout is
    // byte-identical (StorefrontEnglishUnchangedTest pins the pages).
    app()->instance('request', Request::create(MPX_BASE.'/'));
    $tags = app(ShopTags::class);
    expect($tags->head())->toBe('')->and($tags->bodyStart())->toBe('')->and($tags->footer())->toBe('');

    $html = test()->get('/')->assertOk()->getContent();
    expect($html)->not->toContain('data-kbb-cc')->not->toContain('facebook-domain-verification')->not->toContain('kbb_eid');
});

// ------------------------------------------------------------------- guide

it('links only to the platforms\' own https pages, in a new tab', function () {
    $allowed = ['business.facebook.com', 'developers.facebook.com', 'www.facebook.com', 'analytics.google.com', 'ads.google.com',
        'merchants.google.com', 'support.google.com', 'developers.google.com', 'ads.tiktok.com', 'business.tiktok.com',
        'clarity.microsoft.com', 'ads.pinterest.com', 'ads.snapchat.com'];

    foreach (PixelGuide::urls() as $url) {
        expect(str_starts_with($url, 'https://'))->toBeTrue($url)
            ->and(in_array(parse_url($url, PHP_URL_HOST), $allowed, true))->toBeTrue($url);
    }

    $partial = (string) file_get_contents(resource_path('views/admin/partials/marketing-pixels-connect.blade.php'));
    expect(substr_count($partial, 'target="_blank" rel="noopener noreferrer"'))->toBeGreaterThanOrEqual(2);

    // The markdown copy is written from the same data and must not drift.
    $md = (string) file_get_contents(base_path('docs/MARKETING-PIXELS-GUIDE.md'));
    foreach (PixelGuide::urls() as $url) {
        expect($md)->toContain($url);
    }
    expect($md)->toContain(PixelGuide::CHECKED);
});
