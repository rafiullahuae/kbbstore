<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\SiteAnalyticsApiController;
use App\Services\Analytics\Online;
use App\Services\Analytics\Report;
use App\Services\Analytics\Rollup;
use App\Services\Analytics\Tracker;
use App\Support\StoreTime;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
 * "Online now" (Lane AN2). The owner: "this number only a total number of
 * visitors present on the website at same time!" -- plus the GA-style axis on
 * the per-minute chart, today's figures riding on the live poll, and the
 * country hint. Each case says what the defect would look like on the board.
 */

const ON_UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';

function onPost(string $uri, array $fields, string $ip = '203.0.113.20', array $headers = [])
{
    return test()->flushHeaders()->withHeaders($headers + ['User-Agent' => ON_UA])
        ->withServerVariables(['REMOTE_ADDR' => $ip])->post($uri, $fields);
}

beforeEach(function () {
    Route::middleware('web')->group(base_path('routes/instant-nav.php'));
    foreach (['an_online', 'an_hits', 'an_days', 'an_dims'] as $t) {
        DB::table($t)->delete();
    }
    Cache::forget('kbb:an:wm');
    Cache::forget(Rollup::TICK_KEY);
});

it('marks a visitor online with the page they opened, and the leave beacon drops them at once', function () {
    // MUTATION: make x=left a no-op in Online::ping() and the second count is 1.
    onPost('/api/viewed', ['p' => '/product/a/', 't' => 'A', 'n' => '1'])->assertNoContent();
    expect(Online::now())->toBe(['online' => 1, 'pages' => 1])
        ->and(Online::pages()[0]['path'])->toBe('/product/a/');

    onPost('/api/online', ['p' => '/product/a/', 'x' => 'left'])->assertNoContent();
    expect(Online::now()['online'])->toBe(0);
});

it('keeps a reader on a long page online with the heartbeat, and drops a silent one after 90 s', function () {
    // MUTATION: change WINDOW to 900 and the silent visitor never drops off;
    // drop the heartbeat's upsert and the reader drops off at 100 s.
    onPost('/api/viewed', ['p' => '/blog/long-read/', 'n' => '1'], '203.0.113.21');
    onPost('/api/viewed', ['p' => '/shop/', 'n' => '1'], '203.0.113.22');

    $this->travel(80)->seconds();
    onPost('/api/online', ['p' => '/blog/long-read/', 'x' => 'hb'], '203.0.113.21')->assertNoContent();
    $this->travel(80)->seconds();

    // 160 s after opening: the reader beat at 80 s, the other said nothing.
    expect(Online::now()['online'])->toBe(1)->and(Online::pages()[0]['path'])->toBe('/blog/long-read/');
});

it('counts a visitor with two tabs once, and keeps them online across a page change', function () {
    // MUTATION: key an_online by (visitor, path) and two tabs count 2.
    onPost('/api/viewed', ['p' => '/a/', 'n' => '1']);
    onPost('/api/online', ['p' => '/b/', 'x' => 'hb']);   // a second tab on another page
    expect(Online::now()['online'])->toBe(1);

    // Page A -> page B: B's view lands, then A's leave beacon arrives late.
    // MUTATION: drop the path match from the leave UPDATE and they read offline.
    onPost('/api/viewed', ['p' => '/c/', 'n' => '0']);
    onPost('/api/online', ['p' => '/a/', 'x' => 'left']);
    expect(Online::now()['online'])->toBe(1)->and(Online::pages()[0]['path'])->toBe('/c/');
});

it('ignores prefetches, bots, admins and anything outside its allowlist', function () {
    onPost('/api/online', ['p' => '/x/', 'x' => 'hb'], '203.0.113.30', ['Sec-Purpose' => 'prefetch'])->assertNoContent();
    onPost('/api/online', ['p' => '/x/', 'x' => 'hb'], '203.0.113.31', ['User-Agent' => 'Mozilla/5.0 (compatible; bingbot/2.0)'])->assertNoContent();
    onPost('/api/online', ['p' => 'https://evil.example/', 'x' => 'hb'], '203.0.113.32')->assertNoContent();
    expect(DB::table('an_online')->count())->toBe(0);

    onPost('/api/online', ['p' => '/x/?email=a@b.c', 't' => str_repeat('T', 400), 'x' => 'hb', 'v' => 'forged000000000', 'gone' => 1], '203.0.113.33');
    $row = (array) DB::table('an_online')->first();
    expect($row['path'])->toBe('/x/')->and(mb_strlen($row['title']))->toBe(120)->and($row['v'])->not->toBe('forged000000000')
        ->and((int) $row['gone'])->toBe(0)->and(array_keys($row))->toBe(['v', 't', 'gone', 'path', 'title', 'dev', 'cc']);

    // Stateless: no session cookie, no XSRF cookie, nothing written back.
    $res = onPost('/api/online', ['p' => '/x/', 'x' => 'hb'], '203.0.113.33');
    expect($res->headers->getCookies())->toBe([]);

    test()->flushHeaders()->withHeaders(['User-Agent' => ON_UA])->withUnencryptedCookie('kbb_ah', '1')
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.34'])->post('/api/online', ['p' => '/y/', 'x' => 'hb']);
    expect(DB::table('an_online')->where('path', '/y/')->count())->toBe(0);
});

it('rate-limits the heartbeat per address', function () {
    // MUTATION: drop throttle:120,1 from routes/analytics-online.php and this never sees 429.
    $codes = [];
    for ($i = 0; $i < 122; $i++) {
        $codes[] = onPost('/api/online', ['p' => '/z/', 'x' => 'hb'], '203.0.113.40')->getStatusCode();
    }
    expect(array_count_values($codes)[429] ?? 0)->toBeGreaterThanOrEqual(1)
        ->and(substr_count((string) file_get_contents(base_path('routes/api.php')), "require __DIR__.'/analytics-online.php';"))->toBe(1);
});

it('prunes online rows ten minutes after last seen', function () {
    DB::table('an_online')->insert([
        ['v' => 'old0000000000001', 't' => time() - 601, 'gone' => 0, 'path' => '/', 'title' => '', 'dev' => '', 'cc' => ''],
        ['v' => 'new0000000000001', 't' => time() - 30, 'gone' => 1, 'path' => '/', 'title' => '', 'dev' => '', 'cc' => ''],
    ]);
    Rollup::prune();
    expect(DB::table('an_online')->pluck('v')->all())->toBe(['new0000000000001']);
});

it('sends the heartbeat only from a visible, shown page and stops it when hidden or left', function () {
    // MUTATION: replace the setTimeout chain with setInterval, drop the
    // visibility check, or drop the left beacon, and this is red.
    $hit = (string) file_get_contents(resource_path('js/kbb/hit.js'));
    expect($hit)->toContain("const BEAT_MS = 45000;")
        ->and($hit)->toContain("if (!online || document.visibilityState !== 'visible') return;")
        ->and($hit)->toContain("if (document.visibilityState !== 'visible') { stopBeat(); ping('left'); return; }")
        ->and($hit)->toContain("window.addEventListener('pagehide', () => { stopBeat(); if (online) ping('left'); });")
        ->and($hit)->toContain("navigator.sendBeacon(base + '/api/online', b);")
        // Started only after the page view, which waits for prerender activation.
        ->and($hit)->toContain("if (document.prerendering) document.addEventListener('prerenderingchange', arm, { once: true });")
        ->and($hit)->not->toContain('setInterval(')
        ->and($hit)->not->toContain('document.cookie =');
});

it('draws the per-minute chart on round ticks from zero', function () {
    // MUTATION: drop the 2 from the 1-2-5 ladder and 7 gives 0/5/10.
    expect(Report::ticks(7))->toBe([0, 2, 4, 6, 8])
        ->and(Report::ticks(1))->toBe([0, 1])
        ->and(Report::ticks(0))->toBe([0, 1])
        ->and(Report::ticks(13))->toBe([0, 5, 10, 15])
        ->and(Report::ticks(40))->toBe([0, 10, 20, 30, 40])
        ->and(Report::ticks(250))->toBe([0, 100, 200, 300]);

    $admin = (string) file_get_contents(resource_path('views/admin/partials/site-analytics-screen.blade.php'));
    expect($admin)->toContain("[30, 25, 20, 15, 10, 5, 1].forEach")->and($admin)->toContain("' visitor' + (v === 1 ? '' : 's')")
        ->and((string) file_get_contents(resource_path('js/owner-app/analytics.js')))->toContain('[30, 25, 20, 15, 10, 5, 1].map');
});

it('says how to get countries when the country file is missing, and uses Cloudflare\'s header meanwhile', function () {
    // MUTATION: return true for country_db and the hint never shows.
    $live = Report::live(10);
    expect($live['country_db'])->toBeFalse();
    expect((string) file_get_contents(resource_path('views/admin/partials/site-analytics-screen.blade.php')))
        ->toContain('Countries need the country database: Store → Security → Firewall → Data → <b>Download country database</b>');

    onPost('/api/viewed', ['p' => '/', 'n' => '1'], '203.0.113.50', ['CF-IPCountry' => 'ae']);
    onPost('/api/viewed', ['p' => '/', 'n' => '1'], '203.0.113.51', ['CF-IPCountry' => 'XX']);
    expect(DB::table('an_hits')->orderBy('id')->pluck('cc')->all())->toBe(['AE', '']);
});

it('brings today\'s figures on the live poll: an add to cart after the board opened is in the funnel next poll', function () {
    // MUTATION: drop Rollup::freshToday() from livePayload() and carts stays 0.
    onPost('/api/viewed', ['p' => '/', 'n' => '1']);
    $req = fn () => \Illuminate\Http\Request::create('/x', 'GET', ['w' => 10, 'today' => '1']);
    expect(SiteAnalyticsApiController::livePayload($req())['today']['totals']['carts'])->toBe(0);

    Tracker::record(\Illuminate\Http\Request::create('/api/cart/add', 'POST', [], [], [], ['HTTP_USER_AGENT' => ON_UA, 'REMOTE_ADDR' => '203.0.113.20']), 1);
    $this->travel(61)->seconds();
    $p = SiteAnalyticsApiController::livePayload($req());
    expect($p['today']['totals']['carts'])->toBe(1)->and($p['today']['updated'])->not->toBeNull()
        ->and($p['cron']['alive'])->toBeFalse()->and($p['cron']['line'])->toContain('php artisan schedule:run');

    // Another range is not touched.
    expect(SiteAnalyticsApiController::livePayload(\Illuminate\Http\Request::create('/x', 'GET', ['w' => 10])))->not->toHaveKey('today');
});

it('rebuilds today once under a lock, never twice', function () {
    // MUTATION: drop the lock and the held-lock case rebuilds anyway.
    DB::table('an_hits')->insert(['m' => intdiv(time(), 60), 'v' => 'v1', 's' => 's1', 'k' => 0, 'e' => 1, 'path' => '/', 'title' => '', 'ref' => '', 'ch' => 'direct', 'src' => '', 'med' => '', 'cmp' => '', 'dev' => '', 'br' => '', 'os' => '', 'cc' => '', 'lang' => '']);

    $lock = Cache::lock('kbb:an:rollup', 30);
    expect($lock->get())->toBeTrue();
    expect(Rollup::freshToday(60))->toBeFalse()->and(DB::table('an_days')->count())->toBe(0);
    $lock->release();

    expect(Rollup::freshToday(60))->toBeTrue()->and(Rollup::freshToday(60))->toBeFalse();
});

it('keeps the poll\'s cost flat however many visitors are online', function () {
    $count = function (int $n): int {
        DB::table('an_online')->delete();
        $rows = [];
        for ($i = 0; $i < $n; $i++) {
            $rows[] = ['v' => sprintf('%016d', $i), 't' => time(), 'gone' => 0, 'path' => '/p'.($i % 7).'/', 'title' => '', 'dev' => 'mobile', 'cc' => 'AE'];
        }
        DB::table('an_online')->insert($rows);
        $q = 0;
        $on = true;
        DB::listen(function () use (&$q, &$on) { if ($on) { $q++; } });
        SiteAnalyticsApiController::livePayload(\Illuminate\Http\Request::create('/x', 'GET', ['w' => 10, 'today' => '1']));
        $on = false;

        return $q;
    };
    Rollup::runDue(true);
    $count(3);
    expect($count(40))->toBe($count(3));
});
