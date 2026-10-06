<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Customer;
use App\Services\OwnerApp\VapidKeys;
use App\Services\OwnerApp\WebPush;
use App\Services\Push\PushAudience;
use App\Services\Push\PushLinks;
use App\Services\Push\PushRules;
use App\Services\Push\PushSender;
use App\Services\Push\PushTick;
use App\Services\SettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\PushAdminRoutes;

/*
 * Lane PN: Growth & Marketing → Push Notifications → Campaigns, and the rules
 * every sender obeys.
 *
 * The owner, 5 October: "push notifications offers will be manual ... send to
 * all or specific groups of customers etc, city, region wise ... if customer
 * is in dubai, and we want to target sharjah, so in this case the dubai
 * customers will be annoy ... i want to minimal the notifications."
 */

require_once __DIR__.'/../Support/PushTestHelpers.php';

beforeEach(function () {
    PushAdminRoutes::wire($this->app);
    VapidKeys::forget();
    VapidKeys::pair();          // made BEFORE any phone: a new pair drops every subscription
    pnRules(['quiet_on' => false]);   // the clock in CI is not the shop's; tests that need quiet hours set them
});

/* ------------------------------------------------------------ audience */

it('aims a Sharjah campaign at phones in Sharjah only, never at Dubai', function () {
    /* DEFECT the owner named: "if customer is in dubai, and we want to target
       sharjah ... the dubai customers will be annoy". MUTATION: drop the
       emirate whereIn in PushAudience::query() -> the Dubai phone is counted
       and receives it. */
    pnFakePush();
    $sharjah = [pnPhone(['region' => 'Sharjah', 'city' => 'Sharjah']), pnPhone(['region' => 'SHJ', 'city' => 'Al Nahda']), pnPhone(['region' => 'الشارقة', 'city' => null, 'locale' => 'ar'])];
    $dubai = [pnPhone(['region' => 'Dubai', 'city' => 'Dubai']), pnPhone(['region' => 'DU', 'city' => 'Jumeirah'])];
    $abroad = pnPhone(['region' => 'Sharjah', 'country' => 'GB']);  // a "Sharjah" street abroad is not Sharjah

    expect(app(PushAudience::class)->count(['emirates' => ['sharjah']]))->toBe(3);

    $id = pnCampaign(['emirates' => ['sharjah']]);
    $sender = app(PushSender::class);
    expect($sender->start($id))->toBeTrue();
    $sender->step($id, 10);

    $got = DB::table('push_sends')->where('campaign_id', $id)->pluck('subscription_id')->map(fn ($v) => (int) $v)->sort()->values()->all();
    expect($got)->toBe($sharjah)
        ->and(array_intersect($got, $dubai))->toBe([])
        ->and($got)->not->toContain($abroad);
    Http::assertSentCount(3);
    expect(DB::table('push_campaigns')->where('id', $id)->value('status'))->toBe('sent')
        ->and(DB::table('push_sends')->where('campaign_id', $id)->distinct()->pluck('emirate')->all())->toBe(['sharjah']);
});

it('narrows by city, language, platform and customers vs guests, and by what the shopper bought', function () {
    $c = Customer::create(['name' => 'Buyer', 'email' => 'pn-buyer-'.uniqid().'@example.test', 'password' => 'password123']);
    $ar = pnPhone(['locale' => 'ar', 'platform' => 'ios', 'city' => 'Jumeirah']);
    pnPhone(['locale' => 'en', 'platform' => 'ios', 'city' => 'Jumeirah']);
    $cust = pnPhone(['customer_id' => $c->id, 'platform' => 'android']);
    pnPhone(['platform' => 'desktop']);
    $a = app(PushAudience::class);

    expect($a->count(['locales' => ['ar']]))->toBe(1)
        ->and($a->count(['cities' => ['jumeirah']]))->toBe(2)
        ->and($a->count(['platforms' => ['ios']]))->toBe(2)
        ->and($a->count(['who' => 'customers']))->toBe(1)
        ->and($a->count(['who' => 'guests']))->toBe(3)
        ->and($a->query(['locales' => ['ar'], 'platforms' => ['ios']])->pluck('s.id')->all())->toEqual([$ar])
        // Marketing's own rule vocabulary: "never ordered" is this customer.
        ->and($a->query(['rules' => [['field' => 'never_ordered', 'op' => 'yes']]])->pluck('s.id')->all())->toEqual([$cust])
        // ...and a rule outside the push vocabulary is dropped, not run.
        ->and(PushAudience::clean(['rules' => [['field' => 'newsletter', 'op' => 'yes']]])['rules'])->toBe([]);
});

it('counts the audience live through one POST, behind push.view', function () {
    pnPhone(['region' => 'Sharjah']);
    pnPhone(['region' => 'Dubai']);
    $this->actingAs(pnAdmin(), 'admin')->postJson('/admin-api/push/count', ['audience' => ['emirates' => ['sharjah']]])
        ->assertOk()->assertJson(['n' => 1, 'label' => 'Sharjah']);
});

/* ------------------------------------------------------- send once */

it('fires a scheduled campaign once however many ticks overlap', function () {
    /* DEFECT: two cron ticks (or a tick and the owner's tab) both send it, so
       every phone gets it twice. MUTATION: drop the lease's lock_until test
       in PushSender::step(), or make dedupe non-unique -> two POSTs a phone. */
    pnFakePush();
    $phones = [pnPhone(), pnPhone(), pnPhone()];
    $id = pnCampaign([], ['status' => 'scheduled', 'scheduled_at' => now()->subMinute()]);

    // A second stepper already holds the lease: this one must not touch it.
    $tick = app(PushTick::class);
    $sender = app(PushSender::class);
    expect($sender->startDue())->toBe(1)->and($sender->startDue())->toBe(0);
    DB::table('push_campaigns')->where('id', $id)->update(['lock_until' => now()->addMinute(), 'lock_token' => 'other']);
    $sender->step($id, 5);
    Http::assertSentCount(0);
    DB::table('push_campaigns')->where('id', $id)->update(['lock_until' => null, 'lock_token' => null]);

    $tick->run();
    $tick->run();
    $sender->step($id, 5);

    Http::assertSentCount(3);
    expect(DB::table('push_sends')->where('campaign_id', $id)->count())->toBe(3)
        ->and(DB::table('push_campaigns')->where('id', $id)->first())->status->toBe('sent')
        ->and((int) DB::table('push_campaigns')->where('id', $id)->value('delivered'))->toBe(3);
    // A row a dead process queued but never sent is sent once, not twice.
    expect(DB::table('push_sends')->where('campaign_id', $id)->where('status', '<>', 'delivered')->count())->toBe(0)
        ->and($phones)->toHaveCount(3);
});

/* ---------------------------------------------------- minimal: cap + quiet */

it('holds marketing back by the daily cap and quiet hours, but never an order update', function () {
    /* DEFECT: "i want to minimal the notifications". MUTATION: return all-true
       from PushRules::room() -> the second campaign reaches the phone the same
       day; drop the quiet() check in step() -> it is sent at 23:00. */
    pnFakePush();
    $phone = pnPhone();
    $sender = app(PushSender::class);

    $first = pnCampaign();
    $sender->start($first);
    $sender->step($first, 5);
    Http::assertSentCount(1);

    $second = pnCampaign();
    $sender->start($second);
    $sender->step($second, 5);
    Http::assertSentCount(1);
    expect(DB::table('push_sends')->where('campaign_id', $second)->value('status'))->toBe('held')
        ->and((int) DB::table('push_campaigns')->where('id', $second)->value('held'))->toBe(1);

    // Quiet hours: 22:00-09:00 shop time. At 23:00 a campaign waits...
    pnRules(['quiet_on' => true, 'quiet_from' => '22:00', 'quiet_to' => '09:00']);
    $rules = app(PushRules::class);
    $late = CarbonImmutable::now(\App\Support\StoreTime::timezone())->setTime(23, 0)->setTimezone('UTC');
    $noon = $late->setTimezone(\App\Support\StoreTime::timezone())->setTime(12, 0)->setTimezone('UTC');
    expect($rules->quiet($late))->toBeTrue()->and($rules->quiet($noon))->toBeFalse()
        ->and($rules->quietEnds($late)->setTimezone(\App\Support\StoreTime::timezone())->format('H:i'))->toBe('09:00');

    CarbonImmutable::setTestNow($late);
    \Illuminate\Support\Carbon::setTestNow($late);
    try {
        $third = pnCampaign([], ['title' => 'Night']);
        $sender = app(PushSender::class);
        $sender->start($third);
        $sender->step($third, 5);
        expect(DB::table('push_sends')->where('campaign_id', $third)->count())->toBe(0);

        // ...and an order update at 23:00, to a phone already at its cap, goes at once.
        DB::table('push_sends')->insert(['kind' => 'order', 'ref' => 1, 'subscription_id' => $phone, 'title' => 'Order 1 shipped', 'body' => '',
            'url' => '/track-my-order', 'status' => 'queued', 'dedupe' => 'o:1:shipped:'.$phone, 'due_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('push_sends')->insert(['kind' => 'stock', 'ref' => 9, 'subscription_id' => $phone, 'title' => 'Back', 'body' => '',
            'url' => '/', 'status' => 'queued', 'dedupe' => 's:9:'.$phone, 'due_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        expect($sender->runQueue())->toBe(1);
        expect(DB::table('push_sends')->where('kind', 'order')->value('status'))->toBe('delivered')
            ->and(DB::table('push_sends')->where('kind', 'stock')->value('status'))->toBe('queued');
    } finally {
        CarbonImmutable::setTestNow();
        \Illuminate\Support\Carbon::setTestNow();
    }
    Http::assertSentCount(2);
});

/* ------------------------------------------------------- gone endpoints */

it('marks a phone gone the moment its push service answers 404 or 410, and never sends to it again', function () {
    /* MUTATION: treat 410 as "failed" in PushSender::deliver() -> the row stays
       active and the second campaign posts to it again. */
    pnFakePush(['mozilla.com' => 410, 'notify.windows.com' => 404]);
    $ok = pnPhone();
    $gone = pnPhone(['endpoint' => 'https://updates.push.services.mozilla.com/wpush/v2/pn-gone']);
    $gone2 = pnPhone(['endpoint' => 'https://wns2-par02p.notify.windows.com/w/?token=pn-gone']);
    pnRules(['cap_day' => 0, 'cap_week' => 0]);
    $sender = app(PushSender::class);

    $a = pnCampaign();
    $sender->start($a);
    $sender->step($a, 5);
    expect(DB::table('site_app_push_subscriptions')->whereIn('id', [$gone, $gone2])->pluck('status')->unique()->all())->toBe(['gone'])
        ->and(DB::table('site_app_push_subscriptions')->where('id', $ok)->value('status'))->toBe('active')
        ->and((int) DB::table('push_campaigns')->where('id', $a)->value('gone'))->toBe(2)
        ->and((int) DB::table('push_daily')->where('kind', 'gone')->sum('n'))->toBe(2);
    Http::assertSentCount(3);

    $b = pnCampaign();
    $sender->start($b);
    $sender->step($b, 5);
    Http::assertSentCount(4);
});

/* ------------------------------------------------------ payload + click */

it('sends text the worker shows as text, a shop address, and a signed click token, well under 4 KB', function () {
    $captured = [];
    Http::fake(function (HttpRequest $r) use (&$captured) {
        $captured[] = $r;

        return Http::response('', 201);
    });
    $phone = pnPhone(['locale' => 'en']);
    $id = (int) DB::table('push_sends')->insertGetId(['kind' => 'campaign', 'campaign_id' => 7, 'ref' => 7, 'subscription_id' => $phone,
        'title' => '<img src=x onerror=alert(1)>', 'body' => str_repeat('ب', 200), 'url' => '/sale/', 'status' => 'queued',
        'dedupe' => 'c:7:'.$phone, 'due_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $row = DB::table('push_sends')->where('id', $id)->first();
    $row->locale = 'en';
    $json = PushSender::payload($row);
    $p = json_decode($json, true);

    expect(strlen($json))->toBeLessThan(1500)
        ->and(array_keys($p))->toBe(['t', 'b', 'u', 'g', 'c'])
        ->and($p['t'])->toBe('<img src=x onerror=alert(1)>')    // data, shown by showNotification() as text
        ->and($p['u'])->toBe(\App\Support\Url::raw('/sale/'))
        ->and(PushSender::clickId($p['c']))->toBe($id)
        ->and(PushSender::clickId($id.'.'.str_repeat('0', 24)))->toBeNull()
        ->and(PushSender::clickId(($id + 1).'.'.substr($p['c'], -24)))->toBeNull();

    app(PushSender::class)->deliver([$id]);
    expect($captured)->toHaveCount(1)
        ->and($captured[0]->header('TTL')[0])->toBe('43200')
        ->and($captured[0]->header('Urgency')[0])->toBe('normal')
        ->and($captured[0]->header('Content-Encoding')[0])->toBe('aes128gcm')
        ->and(strlen($captured[0]->body()))->toBeLessThan(4096);
});

it('counts a tap once, from a token this shop signed, through the rate-limited beacon', function () {
    /* DEFECT: a click counter anybody can inflate. MUTATION: drop the
       hash_equals in clickId() -> the forged token counts. */
    pnFakePush();
    $phone = pnPhone();
    $cid = pnCampaign();
    $sender = app(PushSender::class);
    $sender->start($cid);
    $sender->step($cid, 5);
    $send = (int) DB::table('push_sends')->where('campaign_id', $cid)->value('id');

    $this->postJson('/api/site-app/push/click', ['c' => $send.'.'.str_repeat('a', 24)])->assertExactJson(['ok' => true]);
    expect((int) DB::table('push_campaigns')->where('id', $cid)->value('clicks'))->toBe(0);

    $this->postJson('/api/site-app/push/click', ['c' => PushSender::clickToken($send)])->assertExactJson(['ok' => true]);
    $this->postJson('/api/site-app/push/click', ['c' => PushSender::clickToken($send)])->assertExactJson(['ok' => true]);
    expect((int) DB::table('push_campaigns')->where('id', $cid)->value('clicks'))->toBe(1);

    $route = collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())->first(fn ($r) => $r->uri() === 'api/site-app/push/click');
    expect($route->gatherMiddleware())->toContain('throttle:30,1,site-app-push-click');
});

it('posts the tap from the worker to this shop only, with the signed token and no cookies', function () {
    /* MUTATION: send data.c to d.u's origin, or include credentials -> red. */
    $node = trim((string) shell_exec('command -v node 2>/dev/null'));
    if ($node === '') {
        $this->markTestSkipped('node is not installed here');
    }
    $sw = json_encode((string) $this->get('/sw.js')->assertOk()->getContent());
    $js = <<<JS
const vm = require('vm');
const L = {}, shown = [], posts = [];
const self = { location: new URL('https://shop.test/sw.js'), addEventListener: (t, f) => { L[t] = f; },
  registration: { showNotification: async (t, o) => { shown.push(o.data); } },
  clients: { matchAll: async () => [], openWindow: async () => {} }, skipWaiting() {} };
const fetch = async (u, o) => { posts.push({ u, m: o.method, cr: o.credentials, b: o.body }); return {}; };
vm.runInContext({$sw}, vm.createContext({ self, URL, caches: {}, fetch, Response: function () {}, console }));
const push = async (d) => { const w = []; L.push({ data: { json: () => d }, waitUntil: (p) => w.push(p) }); await Promise.all(w); };
const click = async (data) => { const w = []; L.notificationclick({ notification: { close() {}, data }, waitUntil: (p) => w.push(p) }); await Promise.all(w); };
(async () => {
  await push({ t: 'Sale', u: 'https://evil.example/', c: '12.0123456789abcdef01234567' });
  await push({ t: 'Bad token', c: 'javascript:alert(1)' });
  await click(shown[0]); await click(shown[1]);
  console.log(JSON.stringify({ shown, posts }));
})();
JS;
    $file = storage_path('framework/testing/pn-sw-'.getmypid().'-'.bin2hex(random_bytes(3)).'.cjs');
    @mkdir(dirname($file), 0777, true);
    file_put_contents($file, $js);
    $raw = (string) shell_exec(escapeshellarg($node).' '.escapeshellarg($file).' 2>&1');
    @unlink($file);
    $out = json_decode(trim((string) strrchr("\n".trim($raw), "\n")), true);

    expect($out, $raw)->toBeArray()
        ->and($out['shown'][1]['c'])->toBe('')
        ->and($out['posts'])->toHaveCount(1)
        ->and($out['posts'][0]['u'])->toStartWith('https://shop.test/')
        ->and($out['posts'][0]['u'])->toEndWith('/api/site-app/push/click')
        ->and($out['posts'][0]['cr'])->toBe('omit')
        ->and(json_decode($out['posts'][0]['b'], true))->toBe(['c' => '12.0123456789abcdef01234567']);
});

/* ------------------------------------------------------------- links */

it('accepts a page on this shop as a link and refuses everything else', function () {
    /* DEFECT: a push that opens another site, or javascript:. MUTATION: drop
       the '//' test in PushLinks::path() -> //evil.example is stored. */
    $host = parse_url((string) config('app.url'), PHP_URL_HOST);
    expect(PushLinks::path('/product/snail-mucin/'))->toBe('/product/snail-mucin/')
        ->and(PushLinks::path('/sale/?ref=push#top'))->toBe('/sale/?ref=push#top')
        ->and(PushLinks::path('https://'.$host.'/brand/cosrx/'))->toBe('/brand/cosrx/');
    foreach (['//evil.example/x', '/\\evil.example', 'https://evil.example/', 'javascript:alert(1)', 'data:text/html,x',
        'http://'.$host.'@evil.example/', '/api/products', '/admin-api/push', '/a b', "/x\ty", "/x\0", 'sale', '', str_repeat('/a', 200)] as $bad) {
        expect(PushLinks::path($bad))->toBeNull($bad);
    }

    $this->actingAs(pnAdmin(), 'admin')->postJson('/admin-api/push/campaigns', ['title' => 'Hi', 'url' => 'https://evil.example/'])
        ->assertStatus(422)->assertJsonFragment(['error' => 'The link must be a page on this shop, like /product/… or /sale/.']);
    expect(DB::table('push_campaigns')->count())->toBe(0);
});

/* --------------------------------------------------------- capabilities */

it('refuses every endpoint to roles without push.view or push.send, failing closed', function () {
    /* MUTATION: map 'admin-api/push/**' to admin.access -> support reads it. */
    foreach (['support', 'editor'] as $role) {
        $a = pnAdmin($role);
        $this->actingAs($a, 'admin')->getJson('/admin-api/push')->assertForbidden();
        $this->actingAs($a, 'admin')->getJson('/admin-api/push/analytics')->assertForbidden();
        $this->actingAs($a, 'admin')->postJson('/admin-api/push/count', [])->assertForbidden();
        $this->actingAs($a, 'admin')->postJson('/admin-api/push/campaigns', ['title' => 'x'])->assertForbidden();
        $this->actingAs($a, 'admin')->postJson('/admin-api/push/test', ['title' => 'x'])->assertForbidden();
    }
    foreach (['owner', 'manager'] as $role) {
        $this->actingAs(pnAdmin($role), 'admin')->getJson('/admin-api/push')->assertOk()->assertJsonPath('can_send', true);
    }
    expect(\App\Support\AdminCapabilities::forPath('POST', 'admin-api/push/campaigns/{id}/send'))->toBe('push.send')
        ->and(\App\Support\AdminCapabilities::forPath('POST', 'admin-api/push/count'))->toBe('push.view')
        ->and(\App\Support\AdminCapabilities::forPath('PATCH', 'admin-api/push/anything-new'))->toBe('push.send')
        ->and(\App\Support\AdminCapabilities::CAPABILITIES['push.send'])->toBe(['owner', 'manager']);
    // The Marketing Manager preset builds emails but does not push.
    expect(\App\Support\AdminRoles::presetDefault('marketing-manager'))->not->toContain('push.view')
        ->and(\App\Support\AdminRoles::presetDefault('sub-admin'))->toContain('push.send');
});

it('refuses the writes and reads to support, and answers nobody signed out', function () {
    $a = pnAdmin('support');
    foreach ([['GET', '/admin-api/push'], ['GET', '/admin-api/push/campaigns'], ['POST', '/admin-api/push/settings'], ['POST', '/admin-api/push/templates']] as [$m, $u]) {
        $this->actingAs($a, 'admin')->json($m, $u, [])->assertForbidden();
    }
});

it('answers nobody who is signed out', function () {
    expect($this->getJson('/admin-api/push')->status())->toBeIn([401, 302, 403]);
    expect($this->postJson('/admin-api/push/campaigns', ['title' => 'x'])->status())->toBeIn([401, 302, 403, 419]);
    expect(DB::table('push_campaigns')->count())->toBe(0);
});

/* ------------------------------------------------------------ the screen */

it('drafts, schedules on the shop clock, cancels and reports through the endpoints', function () {
    pnFakePush();
    pnPhone(['region' => 'Sharjah']);
    pnPhone(['region' => 'Dubai']);
    $owner = pnAdmin();

    $d = $this->actingAs($owner, 'admin')->postJson('/admin-api/push/campaigns', [
        'title' => '  Sharjah only  ', 'body' => 'Free delivery today', 'url' => '/sale/', 'audience' => ['emirates' => ['sharjah']],
    ])->assertOk()->json('campaign');
    expect($d['title'])->toBe('Sharjah only')->and($d['audience_label'])->toBe('Sharjah')->and($d['status'])->toBe('draft');

    $this->actingAs($owner, 'admin')->postJson('/admin-api/push/campaigns', ['title' => str_repeat('x', 51)])->assertStatus(422);
    $this->actingAs($owner, 'admin')->postJson("/admin-api/push/campaigns/{$d['id']}/schedule", ['at' => '2001-01-01T10:00'])->assertStatus(422);

    $at = \App\Support\StoreTime::now()->addDay()->setTime(10, 30)->format('Y-m-d\TH:i');
    $s = $this->actingAs($owner, 'admin')->postJson("/admin-api/push/campaigns/{$d['id']}/schedule", ['at' => $at])->assertOk()->json('campaign');
    expect($s['status'])->toBe('scheduled')->and($s['scheduled_local'])->toBe($at);

    $this->actingAs($owner, 'admin')->postJson("/admin-api/push/campaigns/{$d['id']}/cancel")->assertOk()->assertJsonPath('campaign.status', 'cancelled');
    Http::assertSentCount(0);

    $n = $this->actingAs($owner, 'admin')->postJson('/admin-api/push/campaigns', ['title' => 'Now', 'audience' => ['emirates' => ['sharjah']]])->json('campaign.id');
    $r = $this->actingAs($owner, 'admin')->postJson("/admin-api/push/campaigns/{$n}/send")->assertOk()->json();
    expect($r['campaign']['status'])->toBe('sent')->and($r['campaign']['delivered'])->toBe(1)
        ->and($r['by_emirate'])->toBe([['key' => 'sharjah', 'label' => 'Sharjah', 'sent' => 1, 'delivered' => 1, 'clicks' => 0]]);
    $this->actingAs($owner, 'admin')->postJson("/admin-api/push/campaigns/{$n}/send")->assertStatus(422);
    Http::assertSentCount(1);
});

it('tests on the owner\'s own phone only: the shop account with his admin email', function () {
    pnFakePush();
    $owner = pnAdmin('owner', 'owner-pn-'.uniqid().'@example.test');
    $this->actingAs($owner, 'admin')->postJson('/admin-api/push/test', ['title' => 'Test'])->assertStatus(422);

    $c = Customer::create(['name' => 'Owner', 'email' => strtoupper($owner->email), 'password' => 'password123']);
    $mine = pnPhone(['customer_id' => $c->id]);
    pnPhone();  // somebody else's
    $this->actingAs($owner, 'admin')->postJson('/admin-api/push/test', ['title' => 'Test', 'body' => 'Hello', 'url' => '/'])
        ->assertOk()->assertJson(['ok' => true, 'sent' => 1]);
    expect(DB::table('push_sends')->where('kind', 'test')->pluck('subscription_id')->all())->toEqual([$mine]);
});

it('holds the order statuses equal to the ones that send an order email', function () {
    /* A status the emails learn is a status the pushes learn. */
    expect(PushRules::ORDER_STATUSES)->toEqualCanonicalizing(array_keys(\App\Mail\OrderStatusChanged::WORDING));
});

it('ships every automation ON, with the owner\'s minimal defaults', function () {
    DB::table('settings')->where('key', PushRules::SETTING)->delete();
    $r = (new PushRules(app(SettingsService::class)))->all();
    expect($r)->toMatchArray(['cap_day' => 1, 'cap_week' => 3, 'quiet_on' => true, 'quiet_from' => '22:00', 'quiet_to' => '09:00',
        'order_on' => true, 'stock_on' => true, 'cart_on' => true, 'price_on' => true, 'cart_hours' => 3, 'price_pct' => 10, 'geo_ip' => true])
        ->and(PushRules::clean(['cap_day' => 99, 'cart_hours' => 0, 'quiet_from' => '25:00', 'order_statuses' => ['shipped', 'evil']]))
        ->toMatchArray(['cap_day' => 10, 'cart_hours' => 0.25, 'quiet_from' => '22:00', 'order_statuses' => ['shipped']]);
});
