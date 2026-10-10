<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Services\Analytics\Report;
use App\Services\Analytics\Rollup;
use App\Support\StoreTime;
use Illuminate\Support\Facades\DB;
use Tests\Support\SiteAnalyticsRoutes;

/*
 * "Time on site & engagement" on the Analytics board (Lane AT). The owner:
 * "i need a new block in my analytics page, for average time spend per
 * customer and give some more features if u can and it should be valueable".
 *
 * Time comes from the minute stamps the shop already records (an_hits.m) --
 * no new script, no new request on any shop page. Each case says what the
 * defect would read like on the board.
 */

function atHit(array $over): void
{
    DB::table('an_hits')->insert($over + [
        'v' => 'v000000000000001', 's' => 's000000000000001', 'k' => 0, 'e' => 0,
        'path' => '/', 'title' => 'Home', 'ref' => '', 'ch' => 'direct', 'src' => '', 'med' => '', 'cmp' => '',
        'dev' => 'mobile', 'br' => 'Safari', 'os' => 'iOS', 'cc' => 'AE', 'lang' => 'en',
    ]);
}

function atDay(string $day, array $over = []): void
{
    DB::table('an_days')->insert($over + ['day' => $day, 'views' => 10, 'visitors' => 5, 'sessions' => 6, 'bounces' => 2,
        'carts' => 1, 'checkouts' => 1, 'timed' => 4, 'secs' => 480, 'tvis' => 3, 'vsecs' => 540]);
}

function atDim(string $day, string $dim, string $val, array $over = []): void
{
    DB::table('an_dims')->insert($over + ['day' => $day, 'dim' => $dim, 'val' => $val, 'label' => '',
        'views' => 0, 'visitors' => 0, 'sessions' => 0, 'bounces' => 0, 'timed' => 0, 'secs' => 0]);
}

beforeEach(function () {
    DB::table('an_hits')->delete();
    DB::table('an_days')->delete();
    DB::table('an_dims')->delete();
    \Illuminate\Support\Facades\Cache::forget('kbb:an:wm');
});

/*
 * The fixture every rollup case reads. Five visitors today, from m0:
 *   A  Instagram Ads: pages at 0, 2 and 5 (the last is the checkout), a cart at 3
 *   B  one page and gone (a bounce: nothing to time)
 *   C  Google: pages at 10, 11, then 50 and 52 -- the 39-minute gap is a break
 *   D  pages at 20, 22, then a hit stamped 21 (racing a minute boundary), a cart at 23
 *   E  a cart FIRST at 30 (no page before it), a page at 31, a cart at 33
 */
function atFixture(): array
{
    [$from] = Rollup::minutes(StoreTime::now()->format('Y-m-d'));
    $m = $from + 10;
    atHit(['m' => $m, 'v' => 'va', 's' => 'sa', 'e' => 1, 'ch' => 'instagram_ads']);
    atHit(['m' => $m + 2, 'v' => 'va', 's' => 'sa', 'path' => '/product/a/']);
    atHit(['m' => $m + 3, 'v' => 'va', 's' => 'sa-cart', 'k' => 1, 'path' => '']);
    atHit(['m' => $m + 5, 'v' => 'va', 's' => 'sa', 'k' => 2, 'path' => '/checkout/']);
    atHit(['m' => $m, 'v' => 'vb', 's' => 'sb', 'e' => 1]);
    atHit(['m' => $m + 10, 'v' => 'vc', 's' => 'sc', 'e' => 1, 'ch' => 'google', 'dev' => 'desktop']);
    atHit(['m' => $m + 11, 'v' => 'vc', 's' => 'sc', 'dev' => 'desktop']);
    atHit(['m' => $m + 50, 'v' => 'vc', 's' => 'sc', 'dev' => 'desktop']);
    atHit(['m' => $m + 52, 'v' => 'vc', 's' => 'sc', 'dev' => 'desktop']);
    atHit(['m' => $m + 20, 'v' => 'vd', 's' => 'sd', 'e' => 1]);
    atHit(['m' => $m + 22, 'v' => 'vd', 's' => 'sd']);
    atHit(['m' => $m + 21, 'v' => 'vd', 's' => 'sd']);
    atHit(['m' => $m + 23, 'v' => 'vd', 's' => 'sd-cart', 'k' => 1, 'path' => '']);
    atHit(['m' => $m + 30, 'v' => 've', 's' => 'se-cart', 'k' => 1, 'path' => '']);
    atHit(['m' => $m + 31, 'v' => 've', 's' => 'se', 'e' => 1]);
    atHit(['m' => $m + 33, 'v' => 've', 's' => 'se-cart', 'k' => 1, 'path' => '']);

    $day = StoreTime::now()->format('Y-m-d');
    Rollup::rollDay($day);

    return [$day, $m];
}

it('times sessions and visitors from the gaps between their hits, a bounce as not measured, never as zero', function () {
    /*
     * The board's headline: "4m 12s avg per visitor · measured for 4 of 5".
     *   sessions  A 2+3 = 5 min, C 1 + 2 = 3 (the 39-min break adds nothing),
     *             D 2 (the late-stamped hit adds nothing); B and E are one page.
     *   visitors  A 5, C 3, D 2+1 = 3, E 1+2 = 3 (its carts are hits too); B one hit.
     * MUTATIONS, each run:
     *   - count a one-page session as timed at 0      -> timed 5, and the
     *     average falls by 40% on a shop that has nothing wrong with it;
     *   - drop the IDLE_MIN cap                        -> C reads 42 minutes;
     *   - let a late-stamped hit move the clock back   -> D's visitor reads 4 min.
     */
    [$day] = atFixture();
    $d = DB::table('an_days')->where('day', $day)->first();

    expect((int) $d->sessions)->toBe(5)->and((int) $d->bounces)->toBe(2)
        ->and((int) $d->timed)->toBe(3)->and((int) $d->secs)->toBe(600)
        ->and((int) $d->tvis)->toBe(4)->and((int) $d->vsecs)->toBe(840)
        // The rollup's existing figures did not move.
        ->and((int) $d->views)->toBe(12)->and((int) $d->visitors)->toBe(5)->and((int) $d->carts)->toBe(3)->and((int) $d->checkouts)->toBe(1);

    // Per source: the session's ENTRY channel carries its time.
    $ch = DB::table('an_dims')->where('day', $day)->where('dim', 'channel')->get()->keyBy('val');
    expect((int) $ch['instagram_ads']->timed)->toBe(1)->and((int) $ch['instagram_ads']->secs)->toBe(300)
        ->and((int) $ch['google']->secs)->toBe(180)
        ->and((int) $ch['direct']->sessions)->toBe(3)->and((int) $ch['direct']->timed)->toBe(1)->and((int) $ch['direct']->secs)->toBe(120);

    // Idempotent, like every other rollup figure.
    Rollup::rollDay($day);
    expect((int) DB::table('an_days')->where('day', $day)->value('vsecs'))->toBe(840);
});

it('splits visitors into checkout, cart and browse, and times the first add to cart only after a page', function () {
    /*
     * "Shoppers vs browsers" and "first add to cart after a median of N min".
     * A reached checkout (it also carted: checkout wins). D and E carted.
     * B and C neither -- and B, one hit, is in the segment but not timed.
     * Time to cart: A 3 min; D 3 min (2 reading, the late stamp nothing, 1 to
     * the cart). E's first hit WAS a cart, so there is no page before it to
     * time from, and its later cart must not be timed from the first either.
     * MUTATION: drop the -1 marker for a cart-first visitor, or the
     * `$x[3] === null` guard, and E's second cart is timed from its first:
     * t_cart reads three visitors at 3 minutes, not two.
     */
    [$day] = atFixture();
    $seg = DB::table('an_dims')->where('day', $day)->where('dim', 'tseg')->get()->keyBy('val');
    expect((int) $seg['chk']->visitors)->toBe(1)->and((int) $seg['chk']->secs)->toBe(300)
        ->and((int) $seg['cart']->visitors)->toBe(2)->and((int) $seg['cart']->timed)->toBe(2)->and((int) $seg['cart']->secs)->toBe(360)
        ->and((int) $seg['browse']->visitors)->toBe(2)->and((int) $seg['browse']->timed)->toBe(1)->and((int) $seg['browse']->secs)->toBe(180);

    $hist = fn (string $dim) => DB::table('an_dims')->where('day', $day)->where('dim', $dim)->pluck('timed', 'val')->map(fn ($n) => (int) $n)->all();
    expect($hist('t_cart'))->toBe(['003' => 2])
        ->and($hist('t_vis'))->toBe(['003' => 3, '005' => 1]);
});

it('reads the median from summed whole-minute counts, so one marathon tab cannot drag it', function () {
    /*
     * Nine visitors at 2 minutes and one tab left open for hours: the mean
     * reads 13m 54s, the median 2 min -- which is why the board shows both.
     * MUTATION: compute the median from the mean, or from the days' medians,
     * and this is red.
     */
    $day = StoreTime::now()->format('Y-m-d');
    atDay($day, ['tvis' => 10, 'vsecs' => 9 * 120 + 121 * 60]);
    atDim($day, 't_vis', '002', ['visitors' => 9, 'timed' => 9]);
    atDim($day, 't_vis', sprintf('%03d', Rollup::HIST_MAX + 1), ['visitors' => 1, 'timed' => 1]);

    $e = Report::summary($day, $day)['engagement'];
    expect($e['visitor_avg_s'])->toBe(834)->and($e['visitor_median_s'])->toBe(120)
        ->and(Report::median([]))->toBeNull()
        ->and(Report::median([0 => 3, 7 => 1]))->toBe(0)
        ->and(Report::median([1 => 1, 4 => 1, 9 => 1]))->toBe(240);
});

it('compares with the previous period only when that period was timed, and says from when the figures run', function () {
    /*
     * The package lands with weeks of summaries that never recorded time.
     * Averaging those in as zero would make the first week read "+400%";
     * comparing against them would too. So: no comparison until the previous
     * period is timed, and "Time is recorded from <day>" while the range
     * still holds untimed days.
     * MUTATION: drop the `untimed` check from $prevOk -> previous is non-null
     * on the first assertion; drop $untimed -> measured_from is null.
     */
    $today = StoreTime::now();
    // This range: days 0-3 timed, 4-6 predate time on site. The period before
    // it: day 7 timed (the package landed mid-way through it), 8-13 not.
    for ($i = 0; $i < 14; $i++) {
        $old = $i >= 4 && $i !== 7;
        atDay($today->subDays($i)->format('Y-m-d'), $old ? ['timed' => 0, 'secs' => 0, 'tvis' => 0, 'vsecs' => 0] : []);
    }
    [$f, $t] = Report::range('7d');
    $e = Report::summary($f, $t)['engagement'];

    expect($e['previous'])->toBeNull()
        ->and($e['untimed_days'])->toBe(3)
        ->and($e['measured_from'])->toBe($today->subDays(3)->format('Y-m-d'))
        // Denominators from the timed days only: "measured for 12 of 20", not
        // "12 of 35". MUTATION: sum $days instead of $timedDays -> 35 and 42.
        ->and($e['visitors'])->toBe(20)->and($e['sessions'])->toBe(24)
        ->and($e['visitors_timed'])->toBe(12)->and($e['visitor_avg_s'])->toBe(180)
        ->and($e['sessions_timed'])->toBe(16)->and($e['session_avg_s'])->toBe(120);

    // Yesterday fully timed: Today compares with it.
    $y = Report::summary($today->format('Y-m-d'), $today->format('Y-m-d'))['engagement'];
    expect($y['previous'])->toBe(['visitor_avg_s' => 180, 'session_avg_s' => 120])->and($y['measured_from'])->toBeNull();
});

it('gives time and engaged share per source and device from the dims it already reads', function () {
    // "Engaged time by source": Instagram Ads 3 of 4 sessions timed at 5 min
    // each; Google 1 of 4 at 1 min. MUTATION: divide by sessions instead of
    // timed sessions and Instagram reads 3m 45s -- bounces dragging a figure
    // that is about the people who stayed.
    $day = StoreTime::now()->format('Y-m-d');
    atDay($day);
    atDim($day, 'channel', 'instagram_ads', ['sessions' => 4, 'timed' => 3, 'secs' => 900]);
    atDim($day, 'channel', 'google', ['sessions' => 4, 'timed' => 1, 'secs' => 60]);
    atDim($day, 'device', 'mobile', ['sessions' => 5, 'timed' => 2, 'secs' => 240]);

    $e = Report::summary($day, $day)['engagement'];
    $by = collect($e['by_channel'])->keyBy('key');
    expect($by['instagram_ads']['avg_s'])->toBe(300)->and($by['instagram_ads']['engaged_pct'])->toBe(75)
        ->and($by['instagram_ads']['label'])->toBe('Instagram Ads')
        ->and($by['google']['avg_s'])->toBe(60)->and($by['google']['engaged_pct'])->toBe(25)
        ->and($e['by_device'][0])->toMatchArray(['label' => 'Mobile', 'avg_s' => 120, 'engaged_pct' => 40]);
});

it('reads engagement in a flat number of queries, one more than before, however many days and values', function () {
    /*
     * The board's cost must not grow with the shop. 3 days of 3 channels and
     * 3 histogram minutes against 40 days of 40 channels and 40 minutes:
     * the same count. The engagement block adds exactly ONE query (the time
     * rows) -- the rest rides on rows summary() already reads.
     * MUTATION: read the histogram per day, or per channel, and $large > $small.
     */
    $seed = function (int $days, int $vals): void {
        DB::table('an_dims')->delete();
        DB::table('an_days')->delete();
        for ($d = 0; $d < $days; $d++) {
            $day = StoreTime::now()->subDays($d)->format('Y-m-d');
            atDay($day);
            foreach (Rollup::SEGMENTS as $sg) {
                atDim($day, 'tseg', $sg, ['visitors' => 3, 'timed' => 2, 'secs' => 300]);
            }
            for ($v = 0; $v < $vals; $v++) {
                atDim($day, 'channel', 'ch'.$v, ['sessions' => 3, 'timed' => 1, 'secs' => 60]);
                atDim($day, 't_vis', sprintf('%03d', $v), ['visitors' => 1, 'timed' => 1]);
                atDim($day, 't_cart', sprintf('%03d', $v), ['visitors' => 1, 'timed' => 1]);
            }
        }
    };
    $count = function (): array {
        $q = [];
        DB::listen(function ($e) use (&$q) { $q[] = $e->sql; });
        [$f, $t] = Report::range('90d');
        Report::summary($f, $t);
        $n = count($q);
        $time = count(array_filter($q, fn ($s) => str_contains($s, 'an_dims') && preg_match('/["`]dim["`] in/', $s) === 1));

        return [$n, $time];
    };
    Report::summary(...array_slice(Report::range('today'), 0, 2));
    $seed(3, 3);
    [$small, $smallTime] = $count();
    $seed(40, 40);
    [$large] = $count();

    expect($large)->toBe($small)->and($smallTime)->toBe(1);
});

it('serves the block only behind analytics.view, as aggregates with no visitor or session hash', function () {
    // MUTATION: return the raw an_dims rows, or an_hits ids, and the key list
    // below changes; drop the capability and support reads the board.
    SiteAnalyticsRoutes::wire($this->app);
    atFixture();
    $owner = AdminUser::create(['name' => 'AT owner', 'email' => 'at-owner-'.uniqid().'@example.test', 'password' => 'secret-secret', 'role' => 'owner']);
    $support = AdminUser::create(['name' => 'AT support', 'email' => 'at-support-'.uniqid().'@example.test', 'password' => 'secret-secret', 'role' => 'support']);

    $e = $this->actingAs($owner, 'admin')->getJson('/admin-api/site-analytics?range=today')->assertOk()->json('engagement');
    expect(array_keys($e))->toBe(['visitors', 'visitors_timed', 'visitor_avg_s', 'visitor_median_s', 'sessions', 'sessions_timed', 'session_avg_s',
        'previous', 'segments', 'to_cart', 'by_channel', 'by_device', 'measured_from', 'untimed_days', 'idle_min', 'over_s'])
        ->and($e['visitor_avg_s'])->toBe(210)->and($e['session_avg_s'])->toBe(200)
        ->and($e['to_cart'])->toBe(['n' => 2, 'median_s' => 180])
        ->and(json_encode($e))->not->toContain('va')->and(json_encode($e))->not->toContain('"sa');

    $this->actingAs($support, 'admin')->getJson('/admin-api/site-analytics')->assertForbidden();
});

it('paints the block once from the summary in hand, in the revenue-by-source cell, with nothing measured or polled', function () {
    /*
     * The owner marked the empty space under "Orders & revenue by source".
     * The card lives in that block's cell, exactly once, and CSS (flex:1)
     * lets it take what is left of the row -- no element is measured to size
     * it. Its painter makes no request and starts no timer.
     * MUTATION: build the card in its own grid block and it lands on a new
     * row, leaving the gap; call api() in engage() and this is red.
     */
    $src = (string) file_get_contents(resource_path('views/admin/partials/site-analytics-screen.blade.php'));
    expect(substr_count($src, '<div data-an="b-engage"></div>'))->toBe(1)
        ->and($src)->toContain("(id === 'revsrc' ? '<div data-an=\"b-engage\"></div>' : '')")
        ->and(substr_count($src, "set('b-engage', engage(s.engagement, engageRows("))->toBe(1)
        ->and($src)->toContain('.anb [data-an="b-engage"]{flex:1 1 auto;');

    $start = strpos($src, '  function dur(sec) {');
    // engageRows() sizes from row counts in the summary, never from the page.
    $end = strpos($src, '  function paintSummary() {');
    $body = substr($src, $start, $end - $start);
    expect($start)->toBeGreaterThan(0)->and($end)->toBeGreaterThan($start);
    foreach (['api(', 'fetch(', 'setTimeout(', 'setInterval(', 'getBoundingClientRect', 'offsetHeight', 'offsetWidth', 'clientHeight', 'scrollHeight', 'ResizeObserver', 'innerHTML ='] as $forbidden) {
        expect($body)->not->toContain($forbidden);
    }
    // Every value printed goes through esc(), dur(), mins() or fmt() -- labels from the server included.
    expect($body)->toContain('esc(r[0])')->and($body)->toContain('esc(r.label)');
});

it('backfills only the days whose raw hits are all still held when the package lands', function () {
    /*
     * The migration rebuilds yesterday and today so the board has figures and
     * a "vs yesterday" at once. The day before yesterday is already partly
     * pruned (hits live 48 h): rebuilding it would LOWER its visitors and
     * views. MUTATION: rebuild three days and the 2-days-ago row drops from
     * 900 views to the one hit left.
     */
    $now = StoreTime::now();
    [$yFrom] = Rollup::minutes($now->subDay()->format('Y-m-d'));
    [$oFrom] = Rollup::minutes($now->subDays(2)->format('Y-m-d'));
    atHit(['m' => $yFrom + 60, 'v' => 'vy', 's' => 'sy', 'e' => 1]);
    atHit(['m' => $yFrom + 64, 'v' => 'vy', 's' => 'sy']);
    atHit(['m' => $oFrom + 60, 'v' => 'vo', 's' => 'so', 'e' => 1]);
    atDay($now->subDays(2)->format('Y-m-d'), ['views' => 900, 'timed' => 0, 'secs' => 0, 'tvis' => 0, 'vsecs' => 0]);

    ob_start();
    (require database_path('migrations/2027_10_27_100000_add_engagement_time_to_site_analytics.php'))->up();
    ob_end_clean();

    $y = DB::table('an_days')->where('day', $now->subDay()->format('Y-m-d'))->first();
    expect((int) $y->timed)->toBe(1)->and((int) $y->secs)->toBe(240)
        ->and((int) DB::table('an_days')->where('day', $now->subDays(2)->format('Y-m-d'))->value('views'))->toBe(900)
        // Today had no hits: no empty row invented for it.
        ->and(DB::table('an_days')->where('day', $now->format('Y-m-d'))->exists())->toBeFalse();
});
