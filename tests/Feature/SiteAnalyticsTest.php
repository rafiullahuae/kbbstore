<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\SiteAnalyticsApiController;
use App\Models\AdminUser;
use App\Models\Order;
use App\Services\Analytics\Attribution;
use App\Services\Analytics\Report;
use App\Services\Analytics\Rollup;
use App\Services\Analytics\Tracker;
use App\Services\SettingsService;
use App\Support\StoreTime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\SiteAnalyticsRoutes;

/*
 * Analytics (Lane AN): the beacon, the rollup, the board's reads, the order's
 * source. Each case says what the defect would look like on the shop.
 */

const AN_UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';

function anBeacon(array $fields, array $headers = [], string $ip = '203.0.113.9')
{
    return test()->flushHeaders()->withHeaders($headers + ['User-Agent' => AN_UA])
        ->withServerVariables(['REMOTE_ADDR' => $ip])
        ->post('/api/viewed', $fields);
}

function anHit(array $over = []): void
{
    DB::table('an_hits')->insert($over + [
        'm' => intdiv(time(), 60), 'v' => 'v000000000000001', 's' => 's000000000000001', 'k' => 0, 'e' => 0,
        'path' => '/', 'title' => 'Home', 'ref' => '', 'ch' => 'direct', 'src' => '', 'med' => '', 'cmp' => '',
        'dev' => 'mobile', 'br' => 'Safari', 'os' => 'iOS', 'cc' => 'AE', 'lang' => 'en',
    ]);
}

function anAdmin(string $role): AdminUser
{
    return AdminUser::create(['name' => 'AN '.$role, 'email' => 'an-'.$role.'-'.uniqid().'@example.test', 'password' => 'secret-secret', 'role' => $role]);
}

beforeEach(function () {
    Route::middleware('web')->group(base_path('routes/instant-nav.php'));
    DB::table('an_hits')->delete();
    DB::table('an_days')->delete();
    DB::table('an_dims')->delete();
    \Illuminate\Support\Facades\Cache::forget('kbb:an:wm');
});

/* ── the beacon ─────────────────────────────────────────────────────── */

it('records one page view with every field allowlisted and capped, and no IP or user agent', function () {
    // MUTATION: drop the mb_substr in Tracker::text() and the title row is
    // 500 characters; drop the preg_replace in path() and the query string
    // (?utm_source=…&email=…) is stored with the path.
    anBeacon([
        'p' => '/product/snail-essence/?utm_source=x&email=a@b.c', 't' => str_repeat('T', 500),
        'r' => 'l.instagram.com', 'us' => 'Instagram', 'um' => 'PAID', 'uc' => str_repeat('c', 300).'X',
        'ck' => 'f', 'l' => 'ar', 'n' => '1', 'ss' => (string) intdiv(time(), 60),
        'evil' => 'DROP TABLE', 'ip' => '1.2.3.4',
    ])->assertNoContent();

    $row = (array) DB::table('an_hits')->first();
    expect(DB::table('an_hits')->count())->toBe(1)
        ->and($row['path'])->toBe('/product/snail-essence/')
        ->and(mb_strlen($row['title']))->toBe(120)
        ->and($row['ref'])->toBe('l.instagram.com')
        ->and($row['src'])->toBe('instagram')->and($row['med'])->toBe('paid')
        ->and(mb_strlen($row['cmp']))->toBe(100)
        ->and($row['ch'])->toBe('instagram_ads')
        ->and($row['lang'])->toBe('ar')->and($row['e'])->toBe(1)
        ->and($row['dev'])->toBe('mobile')->and($row['br'])->toBe('Safari')->and($row['os'])->toBe('iOS')
        ->and(array_keys($row))->toBe(['id', 'm', 'v', 's', 'k', 'e', 'path', 'title', 'ref', 'ch', 'src', 'med', 'cmp', 'dev', 'br', 'os', 'cc', 'lang']);

    // Nothing of the address or the browser string anywhere in the row.
    $flat = implode('|', array_map('strval', $row));
    expect($flat)->not->toContain('203.0.113.9')->not->toContain('iPhone')->not->toContain('DROP')
        ->and($row['v'])->toMatch('/^[0-9a-f]{16}$/')
        ->and($row['v'])->not->toBe(substr(hash('sha256', '203.0.113.9|'.AN_UA), 0, 16));
});

it('writes exactly one INSERT and reads nothing from an_hits', function () {
    // MUTATION: look up the visitor's last hit to find the session (a SELECT
    // on an_hits per page view) and the select count is 1.
    $q = [];
    DB::listen(function ($e) use (&$q) { $q[] = strtolower($e->sql); });
    anBeacon(['p' => '/shop/', 't' => 'Shop', 'n' => '0', 'ss' => (string) intdiv(time(), 60)])->assertNoContent();

    $touch = array_values(array_filter($q, fn ($s) => str_contains($s, 'an_hits')));
    expect($touch)->toHaveCount(1)->and($touch[0])->toStartWith('insert into "an_hits"');
});

it('does not count bots, signed-in admins, prefetches, the owner\'s addresses, or anything when switched off', function () {
    // MUTATION: remove any one guard in Tracker::row() and its line here writes a row.
    anBeacon(['p' => '/'], ['User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'])->assertNoContent();
    anBeacon(['p' => '/'], ['User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/120.0 Safari/537.36'])->assertNoContent();
    anBeacon(['p' => '/'], ['User-Agent' => ''])->assertNoContent();
    anBeacon(['p' => '/'], ['Sec-Purpose' => 'prefetch'])->assertNoContent();

    app(SettingsService::class)->set(Tracker::SETTING_EXCLUDE, "198.51.100.0/24\n203.0.113.50");
    anBeacon(['p' => '/'], [], '198.51.100.7')->assertNoContent();
    anBeacon(['p' => '/'], [], '203.0.113.50')->assertNoContent();
    expect(DB::table('an_hits')->count())->toBe(0);

    anBeacon(['p' => '/'], [], '203.0.113.51')->assertNoContent();
    expect(DB::table('an_hits')->count())->toBe(1);

    app(SettingsService::class)->set(Tracker::SETTING_ON, false);
    anBeacon(['p' => '/'], [], '203.0.113.52')->assertNoContent();
    expect(DB::table('an_hits')->count())->toBe(1);
    app(SettingsService::class)->set(Tracker::SETTING_ON, true);
    anBeacon(['p' => '/'], [], '203.0.113.53')->assertNoContent();
    expect(DB::table('an_hits')->count())->toBe(2);

    // The console's hint cookie (set at admin sign-in). Last: a test cookie sticks.
    test()->flushHeaders()->withHeaders(['User-Agent' => AN_UA])->withUnencryptedCookie('kbb_ah', '1')->post('/api/viewed', ['p' => '/'])->assertNoContent();
    expect(DB::table('an_hits')->count())->toBe(2);
});

it('refuses a path that is not this site\'s, and keeps the old product-id contract', function () {
    anBeacon(['p' => 'https://evil.example/x'])->assertNoContent();
    anBeacon(['p' => '//evil.example/x'])->assertNoContent();
    expect(DB::table('an_hits')->count())->toBe(0);

    // The pre-AN shape: an id alone that is not a visible product is still 404.
    anBeacon(['id' => 999999])->assertNotFound();
});

it('keeps one session across pages and starts a new one when the browser says so', function () {
    $m = intdiv(time(), 60);
    anBeacon(['p' => '/', 'n' => '1', 'ss' => (string) ($m - 3)]);
    anBeacon(['p' => '/shop/', 'n' => '0', 'ss' => (string) ($m - 3)]);
    anBeacon(['p' => '/', 'n' => '1', 'ss' => (string) $m]);
    // A start the browser could not have had falls back to one per day.
    anBeacon(['p' => '/x/', 'n' => '0', 'ss' => '12']);

    $s = DB::table('an_hits')->orderBy('id')->pluck('s')->all();
    $v = DB::table('an_hits')->distinct()->count('v');
    expect($s[0])->toBe($s[1])->and($s[2])->not->toBe($s[0])->and($s[3])->not->toBe($s[0])->and($v)->toBe(1);
});

it('rotates the salt by day, so yesterday\'s visitor hash cannot be recomputed today', function () {
    $this->travelTo(StoreTime::now()->setTime(12, 0));
    $a = Tracker::visitor('203.0.113.9', AN_UA);
    $this->travelTo(StoreTime::now()->addDay()->setTime(12, 0));
    $b = Tracker::visitor('203.0.113.9', AN_UA);
    expect($a)->not->toBe($b);

    $this->travelTo(StoreTime::now()->addDays(3));
    Tracker::visitor('203.0.113.9', AN_UA);
    Rollup::prune();
    $files = glob(storage_path(Tracker::SALT_DIR).'/salt-*.key') ?: [];
    expect(count($files))->toBeLessThanOrEqual(2);
});

/* ── order attribution ───────────────────────────────────────────────── */

it('stamps an order with first and last touch, last touch ignoring direct', function () {
    // MUTATION: let a direct arrival replace `l` in Attribution::fromBeacon()
    // and src_channel reads direct instead of instagram_ads.
    $m = intdiv(time(), 60);
    $day = Attribution::dayNumber();
    $first = json_encode(['r' => 'google.com', 's' => '', 'm' => '', 'c' => '', 'k' => '', 'p' => '/', 'd' => $day - 3]);
    $last = json_encode(['r' => '', 's' => 'instagram', 'm' => 'paid', 'c' => 'eid_sale', 'k' => 'f', 'p' => '/product/x/', 'd' => $day - 1]);

    // Today's visit is DIRECT, carrying what the browser remembered.
    anBeacon(['p' => '/', 'n' => '1', 'ss' => (string) $m, 'af' => $first, 'al' => $last])->assertNoContent();
    $at = session(Attribution::KEY);
    $cols = Attribution::columns($at);

    expect($cols['src_channel'])->toBe('instagram_ads')
        ->and($cols['src_campaign'])->toBe('eid_sale');
    $j = json_decode($cols['src_attr'], true);
    expect($j['first']['ch'])->toBe('google')->and($j['last']['k'])->toBe('f')
        ->and($j['days'])->toBe(3)->and($j['first']['p'])->toBe('/');

    // A NEW non-direct arrival does replace the last touch.
    anBeacon(['p' => '/', 'n' => '1', 'ss' => (string) $m, 'r' => 'tiktok.com', 'ck' => 't', 'af' => $first, 'al' => $last]);
    expect(Attribution::columns(session(Attribution::KEY))['src_channel'])->toBe('tiktok_ads');
});

it('writes the touches onto the order the checkout places, and leaves an import Unknown', function () {
    $order = Order::create(['order_number' => 'AN-'.uniqid(), 'email' => 'a@b.c', 'status' => 'processing', 'currency' => 'AED', 'subtotal' => 10000, 'total' => 10000]);
    $import = Order::create(['order_number' => 'AN-I-'.uniqid(), 'email' => 'i@b.c', 'status' => 'completed', 'currency' => 'AED', 'subtotal' => 5000, 'total' => 5000]);

    $req = \Illuminate\Http\Request::create('/checkout/', 'POST');
    $req->setLaravelSession($s = app('session.store'));
    $s->put(Attribution::KEY, ['f' => Attribution::touch('google_ads', 'google', 'cpc', 'ramadan', 'g', '/', Attribution::dayNumber()), 'l' => null]);
    Attribution::stamp($order, $req);

    $o = Order::find($order->id);
    expect($o->src_channel)->toBe('google_ads')->and($o->src_campaign)->toBe('ramadan')
        ->and(Attribution::panel($o->src_channel, $o->src_campaign, $o->src_attr)['chip'])->toBe('Google Ads · ramadan')
        ->and(Order::find($import->id)->src_channel)->toBeNull()
        ->and(Attribution::chip(null, null))->toBe('Unknown');

    // A hand-edited session cannot store anything but a known channel key.
    $s->put(Attribution::KEY, ['f' => ['ch' => '<script>', 's' => str_repeat('x', 500)], 'l' => null]);
    expect(Attribution::columns($s->get(Attribution::KEY))['src_channel'])->toBe('direct');
});

/* ── rollup and prune ────────────────────────────────────────────────── */

it('rolls a fixture minute set into exact daily totals and dimensions', function () {
    // Three sessions today: A reads 3 pages (from Instagram Ads, campaign eid),
    // B bounces (direct), C reads 2 (Google). Plus one add to cart by A.
    // MUTATION: count a session's bounce on any 1-page DIMENSION row instead of
    // the session, or take sources from every hit instead of the entry, and
    // the numbers below move.
    [$from] = Rollup::minutes(StoreTime::now()->format('Y-m-d'));
    $m = max($from + 5, intdiv(time(), 60) - 30);
    anHit(['m' => $m, 'v' => 'va', 's' => 'sa', 'e' => 1, 'path' => '/', 'ch' => 'instagram_ads', 'src' => 'ig', 'med' => 'paid', 'cmp' => 'eid']);
    anHit(['m' => $m + 1, 'v' => 'va', 's' => 'sa', 'path' => '/product/a/', 'title' => 'Product A']);
    anHit(['m' => $m + 2, 'v' => 'va', 's' => 'sa', 'path' => '/checkout/', 'k' => 2]);
    anHit(['m' => $m + 2, 'v' => 'va', 's' => 'sa', 'k' => 1, 'path' => '']);
    anHit(['m' => $m + 3, 'v' => 'vb', 's' => 'sb', 'e' => 1, 'path' => '/product/a/', 'title' => 'Product A', 'dev' => 'desktop', 'lang' => 'ar']);
    anHit(['m' => $m + 4, 'v' => 'vc', 's' => 'sc', 'e' => 1, 'path' => '/', 'ch' => 'google', 'ref' => 'google.com']);
    anHit(['m' => $m + 5, 'v' => 'vc', 's' => 'sc', 'path' => '/product/a/', 'title' => 'Product A']);
    // Yesterday's hit must not count today.
    anHit(['m' => $from - 10, 'v' => 'vz', 's' => 'sz', 'e' => 1]);

    Rollup::rollDay(StoreTime::now()->format('Y-m-d'));

    $d = DB::table('an_days')->where('day', StoreTime::now()->format('Y-m-d'))->first();
    expect((int) $d->views)->toBe(6)->and((int) $d->visitors)->toBe(3)->and((int) $d->sessions)->toBe(3)
        ->and((int) $d->bounces)->toBe(1)->and((int) $d->carts)->toBe(1)->and((int) $d->checkouts)->toBe(1);

    $dim = fn (string $dim) => DB::table('an_dims')->where('dim', $dim)->get()->keyBy('val');
    $pages = $dim('page');
    expect((int) $pages['/product/a/']->views)->toBe(3)->and((int) $pages['/product/a/']->visitors)->toBe(3)
        ->and($pages['/product/a/']->label)->toBe('Product A');
    $ch = $dim('channel');
    expect((int) $ch['instagram_ads']->sessions)->toBe(1)->and((int) $ch['instagram_ads']->views)->toBe(3)
        ->and((int) $ch['direct']->bounces)->toBe(1)->and((int) $ch['google']->views)->toBe(2);
    expect((int) $dim('campaign')['eid']->sessions)->toBe(1)
        ->and((int) $dim('entry')['/']->sessions)->toBe(2)
        ->and((int) $dim('lang')['ar']->sessions)->toBe(1);

    // Idempotent: a second run gives the same rows, not double.
    Rollup::rollDay(StoreTime::now()->format('Y-m-d'));
    expect((int) DB::table('an_days')->where('day', StoreTime::now()->format('Y-m-d'))->value('views'))->toBe(6)
        ->and(DB::table('an_dims')->where('dim', 'page')->where('val', '/product/a/')->count())->toBe(1);
});

it('caps each dimension per day and folds the rest into (other)', function () {
    $rows = [];
    for ($i = 0; $i < Rollup::CAP + 5; $i++) {
        $rows[] = ['val' => '/p'.$i, 'label' => '', 'views' => $i + 1, 'visitors' => 1, 'sessions' => 0, 'bounces' => 0];
    }
    $out = Rollup::cap('page', $rows, 'views');
    expect($out)->toHaveCount(Rollup::CAP + 1)->and(end($out)['val'])->toBe('(other)')->and(end($out)['views'])->toBe(15);
});

it('prunes raw hits older than 48 hours and keeps the rest', function () {
    $now = intdiv(time(), 60);
    anHit(['m' => $now - 49 * 60]);
    anHit(['m' => $now - 47 * 60]);
    anHit(['m' => $now]);
    expect(Rollup::prune())->toBe(1)->and(DB::table('an_hits')->count())->toBe(2);
});

it('does nothing in a minute with no new hits', function () {
    anHit();
    expect(Rollup::runDue())->toBeGreaterThan(0)->and(Rollup::runDue())->toBe(0);
});

/* ── the board ───────────────────────────────────────────────────────── */

it('answers the live endpoint in its shape, with the window allowlisted and 10 by default', function () {
    $now = intdiv(time(), 60);
    anHit(['m' => $now, 'v' => 'v1']);
    anHit(['m' => $now - 7, 'v' => 'v2']);
    anHit(['m' => $now - 20, 'v' => 'v3']);
    anHit(['m' => $now - 1, 'v' => 'v1', 'k' => 1, 'path' => '']);

    expect(Report::window(null))->toBe(10)->and(Report::window('7'))->toBe(10)->and(Report::window('25'))->toBe(25)
        ->and(Report::window('99999'))->toBe(10)->and(Report::window('5'))->toBe(5);

    $five = Report::live(5);
    $ten = Report::live(Report::window(null));
    $tf = Report::live(25);
    expect($five['active'])->toBe(1)->and($ten['active'])->toBe(2)->and($tf['active'])->toBe(3)
        ->and($ten['carts'])->toBe(1)
        ->and($ten['bars'])->toHaveCount(30)
        ->and(array_keys($ten))->toBe(['window', 'now', 'active', 'views', 'carts', 'bars', 'mobile_pct', 'langs', 'countries', 'pages', 'sources', 'feed', 'orders', 'since', 'order_since']);

    // Aggregates only: no hash of anybody leaves the server.
    $json = json_encode($tf);
    expect($json)->not->toContain('"v1"')->not->toContain('s000000000000001');
});

it('back-fills hits recorded while nobody watched, then merges `since` without duplicates', function () {
    // MUTATION: drop the `id > since` condition in Report::live() and the
    // second read returns the first rows again.
    $now = intdiv(time(), 60);
    for ($i = 0; $i < 5; $i++) {
        anHit(['m' => $now - 20 + $i, 'path' => '/p'.$i.'/']);
    }
    $first = Report::live(10);
    expect($first['feed'])->toHaveCount(5);

    anHit(['m' => $now, 'path' => '/new/']);
    $next = Report::live(10, $first['since']);
    expect(array_column($next['feed'], 'path'))->toBe(['/new/'])
        ->and(array_intersect(array_column($first['feed'], 'id'), array_column($next['feed'], 'id')))->toBe([]);
});

it('opens the board for analytics.view, refuses the rest, and keeps the settings write to analytics.manage', function () {
    // MUTATION: drop the four site-analytics lines from AdminCapabilities and
    // the routes resolve to no capability -- only a Full Admin passes.
    SiteAnalyticsRoutes::wire($this->app);
    anHit();

    $this->actingAs(anAdmin('owner'), 'admin')->getJson('/admin-api/site-analytics?range=7d')->assertOk()
        ->assertJsonStructure(['ok', 'range', 'totals', 'previous', 'series', 'dims' => ['page', 'channel'], 'orders_by_channel', 'orders_by_campaign', 'search', 'google' => ['connected']]);
    $this->actingAs(anAdmin('manager'), 'admin')->getJson('/admin-api/site-analytics/live?w=5')->assertOk()->assertJsonPath('window', 5);
    $this->actingAs(anAdmin('support'), 'admin')->getJson('/admin-api/site-analytics')->assertForbidden();
    $this->actingAs(anAdmin('editor'), 'admin')->getJson('/admin-api/site-analytics/live')->assertForbidden();

    $this->actingAs(anAdmin('owner'), 'admin')->postJson('/admin-api/site-analytics/settings', ['tracking' => true, 'exclude' => "10.0.0.1\nnot-an-ip"])->assertStatus(422);
    $this->actingAs(anAdmin('owner'), 'admin')->postJson('/admin-api/site-analytics/settings', ['tracking' => false, 'exclude' => '10.0.0.1'])->assertOk()->assertJsonPath('tracking', false);
    $this->actingAs(anAdmin('support'), 'admin')->postJson('/admin-api/site-analytics/settings', ['tracking' => true])->assertForbidden();

    expect(\App\Support\AdminCapabilities::forPath('GET', 'admin-api/site-analytics'))->toBe('analytics.view')
        ->and(\App\Support\AdminCapabilities::forPath('GET', 'admin-api/site-analytics/live'))->toBe('analytics.view')
        ->and(\App\Support\AdminCapabilities::forPath('POST', 'admin-api/site-analytics/settings'))->toBe('analytics.manage')
        ->and(\App\Support\AdminCapabilities::forPath('DELETE', 'admin-api/site-analytics/settings'))->toBeNull();
});

it('gives the owner app the same reads behind analytics.view, failing closed', function () {
    $c = new \App\Http\Controllers\OwnerApp\AnalyticsController();
    $req = fn (?AdminUser $a) => tap(\Illuminate\Http\Request::create('/x', 'GET', ['w' => 25]), fn ($r) => $r->attributes->set('oa.admin', $a));

    expect($c->live($req(anAdmin('owner')))->getStatusCode())->toBe(200)
        ->and($c->live($req(anAdmin('owner')))->getData(true)['window'])->toBe(25)
        ->and($c->summary($req(anAdmin('support')))->getStatusCode())->toBe(403)
        ->and($c->live($req(null))->getStatusCode())->toBe(403);
});

it('reads the summary in a fixed number of queries, however many days and pages there are', function () {
    // Flat cost: 3 days of 3 pages against 40 days of 40 pages.
    $seed = function (int $days, int $pages): void {
        DB::table('an_dims')->delete();
        DB::table('an_days')->delete();
        for ($d = 0; $d < $days; $d++) {
            $day = StoreTime::now()->subDays($d)->format('Y-m-d');
            DB::table('an_days')->insert(['day' => $day, 'views' => 10, 'visitors' => 5, 'sessions' => 6, 'bounces' => 2, 'carts' => 1, 'checkouts' => 1]);
            for ($p = 0; $p < $pages; $p++) {
                DB::table('an_dims')->insert(['day' => $day, 'dim' => 'page', 'val' => '/p'.$p.'/', 'label' => 'P', 'views' => 3, 'visitors' => 1, 'sessions' => 0, 'bounces' => 0]);
            }
        }
    };
    $count = function (): int {
        $n = 0;
        $on = true;
        DB::listen(function () use (&$n, &$on) { if ($on) { $n++; } });
        [$f, $t] = Report::range('30d');
        Report::summary($f, $t);
        $on = false;

        return $n;
    };
    // Warm the one-time reads (DemoSeed's table check is memoised per process).
    Report::summary(...array_slice(Report::range('today'), 0, 2));
    $seed(3, 3);
    $small = $count();
    $seed(40, 40);
    $large = $count();
    expect($large)->toBe($small);
});

it('keeps the live display asleep unless the screen is open and the tab visible', function () {
    // The owner: "the real time function will be on sleep mode" -- the
    // display, not the recording. MUTATION: replace the setTimeout chain with
    // setInterval, or drop the visibility check, and this is red.
    $admin = (string) file_get_contents(resource_path('views/admin/partials/site-analytics-screen.blade.php'));
    $app = (string) file_get_contents(resource_path('js/owner-app/analytics.js'));

    foreach (['admin' => $admin, 'app' => $app] as $where => $js) {
        expect($js)->toContain("document.addEventListener('visibilitychange'")
            ->and($js)->toContain("document.visibilityState !== 'visible'")
            ->and($js)->toContain('clearTimeout(')
            ->and($js)->not->toContain('setInterval(')
            ->and(substr_count($js, 'setTimeout('))->toBe(1, $where.': one timer, re-armed after each answer')
            ->and($js)->toContain('since=');
    }
    // Leaving the screen tears it down: the console's go wrapper and the app's route change.
    expect($admin)->toContain('if (st.active) teardown();')
        ->and((string) file_get_contents(resource_path('js/owner-app/owner-app.js')))->toContain("if (route.name !== 'analytics') stopAnalytics();")
        // The window: four values, 10 by default, remembered per device.
        ->and($admin)->toContain('var WINDOWS = [5, 10, 15, 25];')->and($admin)->toContain("localStorage.setItem('kbb_an_win'")
        ->and($app)->toContain("store.set('oa.an.win'");
});

it('sends one beacon per opened page from the bundle, after load, never for a prerender', function () {
    $hit = (string) file_get_contents(resource_path('js/kbb/hit.js'));
    $fbt = (string) file_get_contents(resource_path('js/kbb/fbt.js'));
    $appJs = (string) file_get_contents(resource_path('js/kbb/app.js'));

    // MUTATION: send from boot() rather than after load, or drop the
    // prerendering guard, and these are red.
    expect(substr_count($hit, 'navigator.sendBeacon('))->toBe(1)
        ->and($hit)->toContain("window.addEventListener('load', go, { once: true })")
        ->and($hit)->toContain('document.prerendering')
        ->and($hit)->not->toContain('setInterval(')
        ->and($hit)->not->toContain('document.cookie =')
        ->and($hit)->not->toContain('getBoundingClientRect')
        // The referrer goes as its domain, never the whole address.
        ->and($hit)->toContain('new URL(doc.referrer).hostname')
        // "Most viewed" rides on it rather than a second request.
        ->and($fbt)->toContain("if (addToHit('pv', id))")
        ->and(substr_count($appJs, "import { initHit } from './hit.js';"))->toBe(1)
        ->and(substr_count($appJs, '    initHit,'))->toBe(1);
});
