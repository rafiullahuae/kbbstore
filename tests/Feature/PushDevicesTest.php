<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\PushDevicesController;
use App\Models\Customer;
use App\Services\OwnerApp\VapidKeys;
use App\Services\Push\PushRules;
use App\Services\SiteAppPush;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\PushAdminRoutes;

/*
 * Lane PD: Growth & Marketing → Push Notifications → Devices.
 *
 * The owner, 6 October: "on the notification for site, i need an option to
 * test to my device, or there should be proper list of installed devices, and
 * i can manually select and test notification. for main site."
 *
 * What was wrong on the shop: "Send a test to my phone" found the owner's phone
 * only through a shop account with his admin email or this browser's push
 * cookie. The installed app on an iPhone keeps its own cookies and he does not
 * sign in to the shop as a customer on it, so the button always answered "No
 * phone of yours is subscribed" and there was no way to pick a phone at all.
 */

require_once __DIR__.'/../Support/PushTestHelpers.php';

beforeEach(function () {
    PushAdminRoutes::wire($this->app);
    VapidKeys::forget();
    VapidKeys::pair();
    pnRules(['quiet_on' => false]);
});

/** Queries one GET of the device list costs. */
function pdListQueries($test, $admin, string $query = ''): array
{
    $n = 0;
    DB::listen(function () use (&$n) {
        $n++;
    });
    $before = $n;
    $json = $test->actingAs($admin, 'admin')->getJson('/admin-api/push/devices'.$query)->assertOk()->json();

    return [$n - $before, $json];
}

it('lists every active phone, newest seen first, at the same query cost for 3 devices as for 40', function () {
    /* DEFECT this pins: a list that looks each shopper up row by row costs one
       query per phone, and the screen slows as the shop app spreads.
       MUTATION: in PushDevicesController::row() read the customer's name with
       Customer::find($r->customer_id) -> 3 and 40 no longer cost the same. */
    $owner = pnAdmin();
    $c = Customer::create(['name' => 'Layla Customer', 'email' => 'pd-c-'.uniqid().'@example.test', 'password' => 'password123']);
    for ($i = 0; $i < 3; $i++) {
        pnPhone(['customer_id' => $c->id, 'last_seen_at' => now()->subMinutes(60 - $i)]);
    }
    pdListQueries($this, $owner);   // warm the per-process memos (settings, roles)
    [$three, $j3] = pdListQueries($this, $owner);

    for ($i = 0; $i < 37; $i++) {
        pnPhone(['customer_id' => $i % 2 ? $c->id : null, 'last_seen_at' => now()->subMinutes(30 - ($i % 30))]);
    }
    $gone = pnPhone(['status' => 'gone']);
    [$forty, $j40] = pdListQueries($this, $owner);

    expect($j3['total'])->toBe(3)
        ->and($j40['total'])->toBe(40)
        ->and(count($j40['devices']))->toBe(PushDevicesController::PER_PAGE)
        ->and($j40['pages'])->toBe(2)
        ->and($forty)->toBe($three)
        ->and(array_column($j40['devices'], 'id'))->not->toContain($gone);

    // Newest seen first.
    $seen = array_column($j40['devices'], 'last_seen_at');
    $sorted = $seen;
    rsort($sorted);
    expect($seen)->toBe($sorted);

    $page2 = $this->actingAs($owner, 'admin')->getJson('/admin-api/push/devices?page=2')->assertOk()->json();
    expect(count($page2['devices']))->toBe(15)
        ->and(array_intersect(array_column($page2['devices'], 'id'), array_column($j40['devices'], 'id')))->toBe([]);
});

it('never puts a phone\'s endpoint, keys or cookie in the list, only the allowlisted fields', function () {
    /* /admin-api answers a browser: an endpoint plus its keys is everything
       needed to push to that phone from anywhere. MUTATION: return (array) $r
       from row() with s.* selected -> endpoint and p256dh appear. */
    $id = pnPhone(['endpoint' => 'https://fcm.googleapis.com/fcm/send/pd-secret-endpoint']);
    $row = DB::table('site_app_push_subscriptions')->where('id', $id)->first();
    $res = $this->actingAs(pnAdmin(), 'admin')->getJson('/admin-api/push/devices')->assertOk();
    $body = $res->getContent();

    expect($body)->not->toContain('pd-secret-endpoint')
        ->and($body)->not->toContain($row->p256dh)
        ->and($body)->not->toContain($row->auth)
        ->and($body)->not->toContain((string) $row->cookie_hash)
        ->and(array_keys($res->json('devices.0')))->toBe([
            'id', 'platform', 'platform_label', 'place', 'located_by', 'language', 'customer', 'nickname', 'mine',
            'marked_by_other', 'installed_at', 'last_seen_at',
        ])
        ->and($res->json('devices.0.place'))->toBe('Dubai')
        ->and($res->json('devices.0.located_by'))->toBe('Delivery address (accurate)')
        ->and($res->json('devices.0.customer'))->toBeNull();
});

it('finds a phone by its nickname, its shopper\'s name or its city, wildcards taken literally', function () {
    $owner = pnAdmin();
    $c = Customer::create(['name' => 'Noura Haddad', 'email' => 'pd-n-'.uniqid().'@example.test', 'password' => 'password123']);
    $named = pnPhone();
    $this->actingAs($owner, 'admin')->putJson("/admin-api/push/devices/{$named}", ['nickname' => "Rafi's iPhone"])->assertOk();
    $noura = pnPhone(['customer_id' => $c->id]);
    $ajman = pnPhone(['region' => 'Ajman', 'city' => 'Al Nuaimiya']);

    $ids = fn (string $q) => array_column($this->actingAs($owner, 'admin')->getJson('/admin-api/push/devices?q='.urlencode($q))->json('devices'), 'id');
    expect($ids('rafi'))->toBe([$named])
        ->and($ids('noura'))->toBe([$noura])
        ->and($ids('nuaimiya'))->toBe([$ajman])
        ->and($ids('ajman'))->toBe([$ajman])
        ->and($ids('%'))->toBe([])
        ->and($ids('_'))->toBe([]);
});

it('sends a test to the selected devices only, each told its own number, and reports each one', function () {
    /* The owner: "i can manually select and test notification". MUTATION: send
       to every active phone instead of whereIn('id', $ids) -> the unselected
       two receive it and Http::assertSentCount(2) fails. */
    pnFakePush();
    $a = pnPhone();
    $b = pnPhone();
    $c = pnPhone();
    $d = pnPhone();
    $owner = pnAdmin();

    $r = $this->actingAs($owner, 'admin')->postJson('/admin-api/push/devices/test', ['ids' => [$b, (string) $d]])->assertOk()->json();

    Http::assertSentCount(2);
    expect($r['ok'])->toBeTrue()->and($r['delivered'])->toBe(2)
        ->and($r['results'])->toBe([['id' => $b, 'result' => 'delivered'], ['id' => $d, 'result' => 'delivered']])
        ->and(DB::table('push_sends')->where('kind', 'test')->orderBy('subscription_id')->pluck('subscription_id')->map(fn ($v) => (int) $v)->all())->toBe([$b, $d])
        ->and(DB::table('push_sends')->where('subscription_id', $b)->value('body'))->toBe("This is device #{$b} in Push Notifications → Devices.")
        ->and(DB::table('push_sends')->whereIn('subscription_id', [$a, $c])->count())->toBe(0);

    // The campaign's own words, from the editor.
    $this->actingAs($owner, 'admin')->postJson('/admin-api/push/devices/test', ['ids' => [$a], 'title' => 'Sharjah sale', 'body' => '20% off', 'url' => '/sale/'])->assertOk();
    $row = DB::table('push_sends')->where('subscription_id', $a)->first();
    expect($row->title)->toBe('Sharjah sale')->and($row->url)->toBe('/sale/')->and($row->campaign_id)->toBeNull();
    // ...and a link off the shop is refused, as in the editor.
    $this->actingAs($owner, 'admin')->postJson('/admin-api/push/devices/test', ['ids' => [$a], 'title' => 'x', 'url' => 'https://evil.example/'])->assertStatus(422);
    Http::assertSentCount(3);
});

it('keeps a test out of every campaign total and the frequency cap, and sends it in quiet hours', function () {
    /* A test that counted would cost a real shopper's phone its one campaign
       of the day, and one held by quiet hours is no test at all. MUTATION:
       queue the rows as kind 'campaign' in PushSender::testEach() -> the
       phone's room() goes false; route them through runQueue() -> held at 23:00. */
    pnFakePush();
    pnRules(['cap_day' => 1, 'cap_week' => 3, 'quiet_on' => false]);
    $phone = pnPhone();
    $sender = app(\App\Services\Push\PushSender::class);
    $camp = pnCampaign();
    $sender->start($camp);
    $sender->step($camp, 5);
    $before = DB::table('push_campaigns')->where('id', $camp)->first();
    $daily = DB::table('push_daily')->orderBy('kind')->get()->toArray();
    expect((int) $before->delivered)->toBe(1);

    pnRules(['cap_day' => 1, 'quiet_on' => true, 'quiet_from' => '22:00', 'quiet_to' => '09:00']);
    $other = pnPhone();
    $late = CarbonImmutable::now(\App\Support\StoreTime::timezone())->setTime(23, 0)->setTimezone('UTC');
    CarbonImmutable::setTestNow($late);
    \Illuminate\Support\Carbon::setTestNow($late);
    try {
        expect(app(PushRules::class)->quiet())->toBeTrue();
        // The phone at its cap, at 23:00, still receives the test.
        $r = $this->actingAs(pnAdmin(), 'admin')->postJson('/admin-api/push/devices/test', ['ids' => [$phone, $other]])->assertOk()->json();
        expect($r['delivered'])->toBe(2);
        expect(app(PushRules::class)->room([$other]))->toBe([$other => true]);
    } finally {
        CarbonImmutable::setTestNow();
        \Illuminate\Support\Carbon::setTestNow();
    }
    $sender->recount($camp);
    $after = DB::table('push_campaigns')->where('id', $camp)->first();
    expect([(int) $after->targeted, (int) $after->delivered, (int) $after->clicks])->toBe([(int) $before->targeted, (int) $before->delivered, (int) $before->clicks])
        ->and(DB::table('push_daily')->orderBy('kind')->get()->toArray())->toEqual($daily)
        ->and(DB::table('push_sends')->where('kind', 'test')->whereNotNull('campaign_id')->count())->toBe(0);
    Http::assertSentCount(3);
});

it('marks a device gone the moment its push service says so, and says so on the row', function () {
    /* MUTATION: report every non-delivered row as 'failed' in testEach() -> the
       owner sees "Failed" for a phone that uninstalled the app. */
    pnFakePush(['mozilla.com' => 410]);
    $ok = pnPhone();
    $gone = pnPhone(['endpoint' => 'https://updates.push.services.mozilla.com/wpush/v2/pd-gone']);

    $r = $this->actingAs(pnAdmin(), 'admin')->postJson('/admin-api/push/devices/test', ['ids' => [$ok, $gone, 999999]])->assertOk()->json();

    expect($r['results'])->toBe([
        ['id' => $ok, 'result' => 'delivered'],
        ['id' => $gone, 'result' => 'gone'],
        ['id' => 999999, 'result' => 'not_subscribed'],
    ])->and(DB::table('site_app_push_subscriptions')->where('id', $gone)->value('status'))->toBe('gone');
    // ...and it leaves the list.
    expect(array_column($this->actingAs(pnAdmin(), 'admin')->getJson('/admin-api/push/devices')->json('devices'), 'id'))->toBe([$ok]);
});

it('caps one test at 20 devices and the endpoint at 10 a minute', function () {
    pnFakePush();
    $ids = [];
    for ($i = 0; $i < 21; $i++) {
        $ids[] = pnPhone();
    }
    $owner = pnAdmin();
    $this->actingAs($owner, 'admin')->postJson('/admin-api/push/devices/test', ['ids' => $ids])->assertStatus(422);
    $this->actingAs($owner, 'admin')->postJson('/admin-api/push/devices/test', ['ids' => []])->assertStatus(422);
    $this->actingAs($owner, 'admin')->postJson('/admin-api/push/devices/test', ['ids' => 'all'])->assertStatus(422);
    Http::assertSentCount(0);

    for ($i = 0; $i < 7; $i++) {
        $this->actingAs($owner, 'admin')->postJson('/admin-api/push/devices/test', ['ids' => [$ids[0]]])->assertOk();
    }
    $this->actingAs($owner, 'admin')->postJson('/admin-api/push/devices/test', ['ids' => [$ids[0]]])->assertStatus(429);
    Http::assertSentCount(7);
});

it('names a device in plain text, capped at 40, and marks it "mine" so the editor\'s test reaches it', function () {
    /* DEFECT the owner hit: "Send a test to my phone" -> "No phone of yours is
       subscribed", because his admin account was linked to no phone.
       MUTATION: drop the admin_user_id orWhere from ownDevices() -> the last
       request answers 422 again. */
    pnFakePush();
    $owner = pnAdmin();
    $mine = pnPhone(['platform' => 'ios']);
    pnPhone();
    $this->actingAs($owner, 'admin')->postJson('/admin-api/push/test', ['title' => 'Test'])->assertStatus(422);

    $this->actingAs($owner, 'admin')->putJson("/admin-api/push/devices/{$mine}", ['nickname' => str_repeat('x', 41)])->assertStatus(422);
    $this->actingAs($owner, 'admin')->putJson("/admin-api/push/devices/{$mine}", ['nickname' => ['<b>']])->assertStatus(422);
    $d = $this->actingAs($owner, 'admin')->putJson("/admin-api/push/devices/{$mine}", ['nickname' => "  <b>Rafi's</b>\n iPhone ", 'mine' => true])->assertOk()->json('device');
    expect($d['nickname'])->toBe("<b>Rafi's</b>  iPhone")->and($d['mine'])->toBeTrue()->and($d['platform_label'])->toBe('iPhone / iPad');

    // Another admin sees it as somebody else's and cannot take the mark off.
    $other = pnAdmin('manager');
    $seen = collect($this->actingAs($other, 'admin')->getJson('/admin-api/push/devices')->json('devices'))->keyBy('id');
    expect($seen[$mine])->toMatchArray(['mine' => false, 'marked_by_other' => true]);
    $this->actingAs($other, 'admin')->putJson("/admin-api/push/devices/{$mine}", ['mine' => false])->assertOk();
    expect((int) DB::table('site_app_push_subscriptions')->where('id', $mine)->value('admin_user_id'))->toBe((int) $owner->id);

    // His own phone is first in his list, and the editor's button now reaches it.
    expect($this->actingAs($owner, 'admin')->getJson('/admin-api/push/devices')->json('devices.0.id'))->toBe($mine);
    $this->actingAs($owner, 'admin')->getJson('/admin-api/push')->assertJsonPath('test_devices', 1);
    $this->actingAs($owner, 'admin')->postJson('/admin-api/push/test', ['title' => 'Test', 'body' => 'Hello'])->assertOk()->assertJson(['ok' => true, 'sent' => 1]);
    expect(DB::table('push_sends')->where('kind', 'test')->pluck('subscription_id')->map(fn ($v) => (int) $v)->all())->toBe([$mine]);

    // Empty clears the nickname; "Not my phone" clears his own mark.
    $d = $this->actingAs($owner, 'admin')->putJson("/admin-api/push/devices/{$mine}", ['nickname' => '', 'mine' => false])->assertOk()->json('device');
    expect($d['nickname'])->toBeNull()->and($d['mine'])->toBeFalse();
    $this->actingAs($owner, 'admin')->putJson('/admin-api/push/devices/999999', ['nickname' => 'x'])->assertNotFound();
});

it('keeps the nickname and the mark when the phone re-subscribes under a new endpoint', function () {
    /* MUTATION: drop the carry-over in SiteAppPush::store() -> the iPhone the
       owner named is "iPhone / iPad" again after its next key change. */
    $owner = pnAdmin();
    SiteAppPush::store(['endpoint' => 'https://web.push.apple.com/pd-old', 'p256dh' => 'k1', 'auth' => 'a1'], 'pd-token', null, 'en', null, 'ios');
    $old = (int) DB::table('site_app_push_subscriptions')->where('endpoint', 'https://web.push.apple.com/pd-old')->value('id');
    $this->actingAs($owner, 'admin')->putJson("/admin-api/push/devices/{$old}", ['nickname' => "Rafi's iPhone", 'mine' => true])->assertOk();

    SiteAppPush::store(['endpoint' => 'https://web.push.apple.com/pd-new', 'p256dh' => 'k2', 'auth' => 'a2'], 'pd-token', null, 'en', null, 'ios');
    $rows = DB::table('site_app_push_subscriptions')->get(['endpoint', 'nickname', 'admin_user_id']);
    expect($rows)->toHaveCount(1)
        ->and($rows[0]->endpoint)->toBe('https://web.push.apple.com/pd-new')
        ->and($rows[0]->nickname)->toBe("Rafi's iPhone")
        ->and((int) $rows[0]->admin_user_id)->toBe((int) $owner->id);
});

it('lets push.view read the list but refuses naming and testing without push.send, failing closed', function () {
    /* MUTATION: add ['POST', 'admin-api/push/devices/**', 'push.view'] to
       AdminCapabilities::RULES -> the manager below sends a test. */
    pnFakePush();
    $id = pnPhone();
    $manager = pnAdmin('manager');
    $manager->revokes = ['push.send'];
    $manager->save();
    \App\Support\AdminRoles::flush();

    $this->actingAs($manager, 'admin')->getJson('/admin-api/push/devices')->assertOk()->assertJsonPath('devices.0.id', $id);
    $this->actingAs($manager, 'admin')->postJson('/admin-api/push/devices/test', ['ids' => [$id]])->assertForbidden();
    $this->actingAs($manager, 'admin')->putJson("/admin-api/push/devices/{$id}", ['nickname' => 'x', 'mine' => true])->assertForbidden();

    $support = pnAdmin('support');
    $this->actingAs($support, 'admin')->getJson('/admin-api/push/devices')->assertForbidden();
    $this->actingAs($support, 'admin')->postJson('/admin-api/push/devices/test', ['ids' => [$id]])->assertForbidden();

    expect(\App\Support\AdminCapabilities::forPath('GET', 'admin-api/push/devices'))->toBe('push.view')
        ->and(\App\Support\AdminCapabilities::forPath('POST', 'admin-api/push/devices/test'))->toBe('push.send')
        ->and(\App\Support\AdminCapabilities::forPath('PUT', 'admin-api/push/devices/{id}'))->toBe('push.send')
        ->and(DB::table('site_app_push_subscriptions')->where('id', $id)->value('nickname'))->toBeNull()
        ->and(DB::table('push_sends')->count())->toBe(0);
    Http::assertSentCount(0);
});

it('is reached from the Devices tab and the campaign editor, drawn as text, once', function () {
    /* The finished state, not its absence (CLAUDE.md): one Devices tab, one
       route each, and nothing the server sends set as markup. */
    $view = file_get_contents(resource_path('views/admin/partials/push-notifications-screen.blade.php'));
    $routes = file_get_contents(base_path('routes/push-admin.php'));
    expect(substr_count($view, "['devices', 'Devices']"))->toBe(1)
        ->and(substr_count($view, "text: 'Test on chosen devices'"))->toBe(1)
        ->and($view)->not->toContain('innerHTML')
        ->and($view)->not->toContain('getBoundingClientRect')
        ->and($view)->not->toContain('setInterval')
        ->and(substr_count($routes, "PushDevicesController::class, 'index'"))->toBe(1)
        ->and(substr_count($routes, "PushDevicesController::class, 'test'"))->toBe(1)
        ->and(substr_count($routes, "PushDevicesController::class, 'update'"))->toBe(1);
});
