<?php

declare(strict_types=1);

/*
 * Owner app → Orders (1 November). The owner:
 *
 *   "1: on the owner app, when we open any order and come back to list it went
 *    to top. we want to stick where we left the scroll, bcz we have 100s of
 *    orders ... 2 Hide the cities in the owner app orders page. and make new
 *    function, filter by today, yesterday, last 7 days and custom date range
 *    to filter the orders."
 *
 * What each defect looked like on his phone is said beside the test that
 * catches it. The JS is pinned by its source (the suite runs no browser); the
 * Chromium measurements are in the lane report.
 */

use App\Models\Order;
use App\Services\OwnerApp\OwnerAppUi;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\OwnerAppRoutes as OA;

beforeEach(function () {
    OA::wire($this->app);
    OwnerAppUi::forget();
    \Illuminate\Support\Facades\RateLimiter::clear('owner-app-pin:127.0.0.1');
    \Illuminate\Support\Facades\RateLimiter::clear('owner-app-enrol:127.0.0.1');
    OA::member(OA::admin());
});

afterEach(function () {
    Carbon::setTestNow();
    OwnerAppUi::forget();
});

function osdJs(): string
{
    return (string) preg_replace(['#/\*.*?\*/#s', '#(^|\s)//[^\n]*#'], ['', '$1'], (string) file_get_contents(resource_path('js/owner-app/orders.js')));
}

function osdFn(string $name): string
{
    preg_match('/(?:export\s+)?(?:async\s+)?function\s+'.preg_quote($name, '/').'\s*\([^)]*\)\s*\{(.*?)\n\}/s', osdJs(), $m);

    return $m[1] ?? '';
}

function osdOrder(string $utc, string $status = 'processing'): Order
{
    static $n = 0;
    $n++;
    $o = Order::create([
        'order_number' => 'OSD-'.$n, 'email' => 'osd'.$n.'@example.test', 'status' => $status,
        'total' => 10000, 'subtotal' => 10000, 'currency' => 'AED',
        'payment_method' => 'cod', 'payment_method_title' => 'Cash on delivery',
    ]);
    DB::table('orders')->where('id', $o->id)->update(['created_at' => $utc]);

    return $o;
}

/* ------------------------------------------------------------- the scroll */

it('keeps the list\'s scroll position when an order is opened and puts it back on return', function () {
    // DEFECT: open order #56xxx from far down the list, tap Back, and the list
    // is at the top again -- renderOrders() repainted the shell, so the new
    // .body scroller started at 0 and he scrolled back through 100s of orders.
    // MUTATION: drop `L.top = sc.scrollTop` or the `b.scrollTop = L.back`
    // write in paintList() and this is red.
    $render = osdFn('renderOrders');
    expect($render)
        ->toContain("if (scr === 'orders' && sc) L.top = sc.scrollTop;")          // leaving the list for an order
        ->toContain("L.back = scr === 'order' ? L.top : (scr === 'orders' && sc ? sc.scrollTop : null);")
        ->toContain("if (scr !== 'order') L.flash = 0;");                        // fresh from another tab: the top

    // The read happens BEFORE loadDetail() paints the order over the list.
    expect(strpos($render, 'L.top = sc.scrollTop'))->toBeLessThan(strpos($render, 'await loadDetail(view, id, false);'));

    $paint = osdFn('paintList');
    expect($paint)->toContain('if (L.back !== null) {')
        ->toContain('b.scrollTop = L.back;')
        ->toContain('L.back = null;');
    // ...after the rows exist: the write comes after the list's own paint.
    expect(strpos($paint, 'b.scrollTop = L.back'))->toBeGreaterThan(strpos($paint, "paint(\$('[data-list]', view)"));

    // The scroller is the list's own .body (the left pane's on a tablet).
    expect(osdJs())->toContain("const listBody = (view) => \$(':scope > .body', \$('[data-pl]', view) || view);");
});

it('keeps the pages Show more opened and is not thrown off by the silent refresh that follows', function () {
    // DEFECT: back at the right place for a blink, then the refresh that lands
    // a moment later drops the extra pages (the row he was on is gone) or
    // restores a second time and jumps. MUTATION: remove `L.back = null;`
    // from paintList(), or make fetchOrders() rebuild the shell, and this is red.
    $fetch = osdFn('fetchOrders');
    expect($fetch)->toContain('const keep = q === L.lastQ && L.paged;')
        ->toContain('L.rows = merge(r.data.orders, L.rows, keep);')
        ->toContain('paintList(host)')
        ->not->toContain('listShell(');
    expect(substr_count(osdFn('paintList'), 'L.back = null;'))->toBe(1);
    // Same rows as on screen: not repainted, so the rows keep their drawn
    // heights and the restored position does not move when the refresh lands.
    expect(osdFn('paintList'))->toContain('if (list && list.oaHtml !== html) { paint(list, html); list.oaHtml = html; }');
    // A new filter is a new list: it starts at the top, never at an old position.
    expect(osdFn('loadList'))->toContain('if (!more) { L.back = null; await fetchOrders(false, true); return; }');
});

it('lands on the exact pixel, brings the order he opened into view and lights it once, with no layout measured', function () {
    // DEFECT (measured in Chromium before this fix): back at 1435 instead of
    // 1500, 2952 instead of 3000 -- rows not drawn since the repaint count at
    // content-visibility's 70px estimate, ~2px short each, so the deeper he
    // was the further off he came back. MUTATION: drop the cv-all class or
    // its CSS rule and the restored offset drifts again.
    $paint = osdFn('paintList');
    expect($paint)->toContain("b.classList.add('cv-all');")
        ->toContain("requestAnimationFrame(() => requestAnimationFrame(() => b.classList.remove('cv-all')));")
        ->toContain("if (cur) { cur.classList.add('cur'); cur.scrollIntoView({ block: 'nearest' }); }")
        ->toContain('L.flash = 0;');
    expect(strpos($paint, "classList.add('cv-all')"))->toBeLessThan(strpos($paint, 'b.scrollTop = L.back'));
    expect(osdFn('renderOrders'))->toContain('L.flash = +id || 0;');
    expect((string) file_get_contents(resource_path('css/owner-app/owner-app.css')))->toContain('.body.cv-all .list .row { content-visibility: visible; }');

    $css = (string) file_get_contents(resource_path('css/owner-app/owner-app.css'));
    expect($css)->toContain('.view.single .list .row.ord.cur::after {')
        ->toContain('@keyframes curglow { 0%, 35% { opacity: 1; } 100% { opacity: 0; } }')
        ->and($css)->toMatch('/\.row\.ord\.cur::after \{[^}]*pointer-events: none;/');

    foreach (['getBoundingClientRect', 'offsetTop', 'offsetHeight', 'clientHeight', 'scrollHeight', 'getComputedStyle', 'setInterval'] as $api) {
        expect(str_contains(osdJs(), $api))->toBeFalse("orders.js uses {$api}");
    }
});

/* ------------------------------------------------------------- the cities */

it('ships the emirate chips and row badges hidden, behind a Customise-app switch', function () {
    // DEFECT: the DXB 12 · AUH 4 · ALAIN 2 row and a DXB badge on every order
    // after he asked for the cities gone. MUTATION: drop 'emirates' from
    // FUNCTIONS_OFF, or the fn('emirates') guard in paintEmirates()/row().
    expect(OwnerAppUi::FUNCTIONS)->toHaveKey('emirates')
        ->and(OwnerAppUi::FUNCTIONS_OFF)->toContain('emirates')
        ->and(OwnerAppUi::defaults()['functions']['emirates'])->toBeFalse()
        ->and(OwnerAppUi::all()['functions']['emirates'])->toBeFalse();

    // A card saved before today (no 'emirates' key) still reads as off.
    OwnerAppUi::put(['functions' => ['bulk' => true]]);
    OwnerAppUi::forget();
    expect(OwnerAppUi::all()['functions']['emirates'])->toBeFalse();

    // The way back: switched on, it is on.
    OwnerAppUi::put(['functions' => ['emirates' => true]]);
    OwnerAppUi::forget();
    expect(OwnerAppUi::all()['functions']['emirates'])->toBeTrue();

    expect(osdFn('paintEmirates'))->toContain("if (!em || !fn('emirates')) { host.hidden = true; return; }");
    expect(osdFn('row'))->toContain("const ec = fn('emirates') ? o.emirate_code : '';")
        ->not->toContain("(o.emirate_code ? '<span class=\"em\">'");
    expect(osdJs())->toContain("if (b && b.hasAttribute('data-em')) { if (!fn('emirates')) return true;");
});

/* --------------------------------------------------------------- the dates */

it('draws Today, Yesterday, Last 7 days and Custom, which set the list\'s own from / to', function () {
    // DEFECT: no quick way to see today's or yesterday's orders -- the Filters
    // sheet's two date fields every time. MUTATION: change a range in
    // dateRange() (e.g. -7 for Last 7 days) and this is red.
    $js = osdJs();
    expect($js)->toContain("if (k === 'today') return [today, today];")
        ->toContain("if (k === 'yday') { const y = shiftDay(today, -1); return [y, y]; }")
        ->toContain("if (k === '7d') return [shiftDay(today, -6), today];")
        ->toContain("[['today', 'Today'], ['yday', 'Yesterday'], ['7d', 'Last 7 days'], ['custom', label]]")
        ->toContain("data-dchips' + (fn('date_chips') ? '>' + dateChips() : ' hidden>')");

    // Tapping the lit chip clears; Custom opens the sheet; one ordinary list call.
    expect($js)->toContain("[L.from, L.to] = on === k ? ['', ''] : dateRange(k, todayKey());")
        ->toContain("if (k === 'custom') { customSheet(view); return true; }")
        ->toContain("const todayKey = () => (isDay(L.today) ? L.today : ymd(new Date()));");
    expect(substr_count($js, "api('GET'"))->toBe(3);   // the list, Show more, the order: nothing new

    // The CSP refuses inline styles: none in the new markup.
    expect(osdFn('customSheet') . osdFn('dateChips'))->not->toContain('style=');

    expect(OwnerAppUi::FUNCTIONS)->toHaveKey('date_chips')
        ->and(OwnerAppUi::defaults()['functions']['date_chips'])->toBeTrue();
});

it('filters rows and status-chip counts by the chip\'s days in the shop\'s time zone, in the same request', function () {
    // DEFECT: "Today" counting a 1:30 AM Dubai order as yesterday (UTC days),
    // or the status chips still counting every order while the list shows
    // one day. MUTATION: build dayStart() in UTC in OrdersController and this
    // is red.
    Carbon::setTestNow('2026-11-01 08:00:00');                       // 12:00 in Dubai
    $today = osdOrder('2026-10-31 21:30:00');                         // 1 Nov 01:30 Dubai
    $yday = osdOrder('2026-10-31 19:00:00', 'completed');             // 31 Oct 23:00 Dubai
    $week = osdOrder('2026-10-26 10:00:00', 'onhold');                // 26 Oct, day 7 of 7
    $old = osdOrder('2026-10-25 10:00:00');                           // 25 Oct, outside

    [$c] = OA::enrol($this, 'owner@example.com', '482613');
    $ids = fn (string $q) => collect(OA::get($this, 'orders?' . $q, $c)->assertOk()->json('orders'))->pluck('id')->sort()->values()->all();

    $all = OA::get($this, 'orders', $c)->assertOk()->json();
    expect($all['today'])->toBe('2026-11-01')->and($all['yesterday'])->toBe('2026-10-31');

    expect($ids('from=2026-11-01&to=2026-11-01'))->toBe([$today->id])
        ->and($ids('from=2026-10-31&to=2026-10-31'))->toBe([$yday->id])
        ->and($ids('from=2026-10-26&to=2026-11-01'))->toBe(collect([$today->id, $yday->id, $week->id])->sort()->values()->all())
        ->and(count($ids('')))->toBe(4)
        ->and($ids('from=2026-11-01&to=2026-11-01&status=completed'))->toBe([]);

    $counts = OA::get($this, 'orders?from=2026-10-31&to=2026-10-31', $c)->json('counts');
    expect($counts)->toBe(['completed' => 1]);
    expect(OA::get($this, 'orders?from=2026-10-26&to=2026-11-01', $c)->json('counts'))
        ->toEqual(['completed' => 1, 'onhold' => 1, 'processing' => 1]);
});
