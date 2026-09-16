<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Refund;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Store -> Analytics: the date filter, and every box on the page obeying it.
 *
 * WHAT THE OWNER ASKED FOR. "Revenue by All time, date, weeks, month and year
 * and with custom date range too, AND all the boxes on that page should work as
 * per the filters."
 *
 * WHY THE BOUNDARIES ARE THE WHOLE TEST. A date filter is not hard in the
 * middle of a range; it is hard at the two ends, and a filter that is wrong at
 * the ends is wrong quietly. Every case here sits ON an edge: the first and the
 * last second of a day, a week, a month and a year, plus the second either side
 * of each. A test that seeds an order in the comfortable middle of February and
 * asserts it shows up in "this month" proves nothing at all.
 *
 * THE DEFECTS THIS FILE EXISTS TO STOP, each of which this codebase has already
 * paid for once:
 *
 *   A DATE COMPARED AS A STRING. now()->toISOString() is
 *   '2026-02-10T08:00:00.000000Z'. SQLite compares that to a stored
 *   '2026-02-10 08:00:00' as TEXT, and ' ' (0x20) sorts below 'T' (0x54), so
 *   every order on the boundary day falls out of the window. MySQL parses it
 *   but warns 1292 'Incorrect datetime value' every single load. Hence
 *   `it('hands the filter no date binding MySQL has to coerce')`.
 *
 *   "THIS MONTH" MEANING THIRTY DAYS. February 2026 has 28. The test clock is
 *   frozen inside February precisely so that a subDays(30) implementation
 *   reaches back into January and is caught.
 *
 *   A BOX THAT IGNORES THE FILTER. Worse than no filter: the owner cannot tell
 *   which figure they are reading. Every box is asserted against the same
 *   seeded set, in the same test, so one of them drifting is a failure.
 *
 *   AN EMPTY RANGE DIVIDING BY ZERO for the average order value.
 *
 * TIME IS FROZEN. Carbon::setTestNow() pins "now" to Tuesday 10 February 2026,
 * 08:00 UTC. February is a 28-day month, 1 February 2026 is a SUNDAY (so a week
 * that started on Sunday would give visibly different answers from one that
 * starts on Monday), and the year has a clean 1 January edge. None of this
 * works on a clock that moves under the test.
 */

/* ------------------------------------------------------------------ fixtures */

/** Tuesday. February 2026 has 28 days and 1 Feb 2026 is a Sunday. */
const AF_NOW = '2026-02-10 08:00:00';

/*
 * AND SO IS THE SHOP'S CLOCK, for the same reason time is frozen.
 *
 * AnalyticsRange::timezone() reads App\Support\StoreTime, whose default is
 * Asia/Dubai because that is where this shop trades. Every boundary in this
 * file is written and reasoned about in UTC — "Tuesday 10 February 2026, 08:00
 * UTC", the first and last second of a UTC day, week, month and year — so the
 * shop clock is pinned to UTC here and the edges mean exactly what they say.
 * Left to the default, every one of these boundaries would silently move four
 * hours and the file would be testing arithmetic it never states.
 *
 * That the boundaries FOLLOW the shop's clock when it is not UTC is a separate
 * property with its own test at the foot of this file, and
 * tests/Feature/StoreTimezoneTest.php pins the same seam from the other side.
 */
beforeEach(function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse(AF_NOW, 'UTC'));
    \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse(AF_NOW, 'UTC'));

    app(\App\Services\SettingsService::class)->set(\App\Support\StoreTime::SETTING_KEY, 'UTC');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
    \Carbon\Carbon::setTestNow();
});

if (! function_exists('afAdmin')) {
    function afAdmin(): AdminUser
    {
        return AdminUser::create([
            'name' => 'Filter Owner',
            'email' => 'af-owner-' . uniqid() . '@example.test',
            'password' => 'secret-secret',
            'role' => 'owner',
        ]);
    }
}

function asFilterAdmin(): void
{
    test()->actingAs(afAdmin(), 'admin');
}

/**
 * An order at an exact number of fils, stamped at an exact second.
 *
 * created_at is written with a plain UPDATE rather than through the model,
 * because Eloquent's timestamps would overwrite it, and the whole point of
 * every row in this file is the second it carries.
 */
function afOrder(int $fils, string $at, string $status = 'completed'): Order
{
    static $n = 0;
    $n++;

    $order = Order::create([
        'order_number' => 'AF-' . $n . '-' . uniqid(),
        'email' => 'af-guest-' . $n . '@example.test',
        'status' => $status,
        'subtotal' => $fils,
        'total' => $fils,
        'paid_at' => $at,
    ]);

    DB::table('orders')->where('id', $order->id)->update(['created_at' => $at, 'updated_at' => $at]);

    return $order->refresh();
}

/** A refund against an order, stamped at its own second — often another month. */
function afRefund(Order $order, int $fils, string $at, string $status = 'succeeded'): Refund
{
    $refund = Refund::create([
        'order_id' => $order->id,
        'amount' => $fils,
        'status' => $status,
        'reason' => 'test',
    ]);

    DB::table('refunds')->where('id', $refund->id)->update(['created_at' => $at, 'updated_at' => $at]);

    return $refund->refresh();
}

function afItem(Order $order, string $name, int $qty, int $lineTotalFils): OrderItem
{
    return OrderItem::create([
        'order_id' => $order->id,
        'name' => $name,
        'brand' => 'Filter Brand',
        'quantity' => $qty,
        'unit_price' => intdiv($lineTotalFils, max(1, $qty)),
        'subtotal' => $lineTotalFils,
        'total' => $lineTotalFils,
    ]);
}

/** GET the endpoint with a filter. */
function afGet(array $query = []): array
{
    return test()->getJson('/admin-api/analytics' . ($query ? '?' . http_build_query($query) : ''))
        ->assertOk()
        ->json();
}

/* ------------------------------------------------------- 1. the control itself */

it('offers exactly the six periods the owner asked for, and defaults to all time', function () {
    asFilterAdmin();

    $a = afGet();

    expect($a)->toHaveKey('period');
    expect($a['period']['key'])->toBe('all', 'the unfiltered page must keep meaning what it means today');

    $keys = array_column($a['period']['options'], 'key');

    expect($keys)->toBe(
        ['all', 'today', 'week', 'month', 'year', 'custom'],
        'the control must offer All time, Today, This week, This month, This year and a custom range'
    );
});

it('states the period it is showing, in words and in dates', function () {
    asFilterAdmin();

    $a = afGet(['period' => 'month']);

    expect($a['period']['label'])->toBe('This month');
    expect($a['period']['from'])->toBe('2026-02-01', 'February starts on the 1st, not thirty days ago');
    expect($a['period']['to'])->toBe('2026-02-28', 'February 2026 has 28 days');
    expect(trim((string) $a['period']['range_label']))->not->toBe('', 'a screenshot of the page must say what it covers');
});

/* --------------------------------------------------------------- 2. Today */

it('counts the first and the last second of today, and neither neighbour', function () {
    asFilterAdmin();

    afOrder(10000, '2026-02-09 23:59:59');   // yesterday, last second  — out
    afOrder(20000, '2026-02-10 00:00:00');   // today, first second     — in
    afOrder(40000, '2026-02-10 23:59:59');   // today, last second      — in
    afOrder(80000, '2026-02-11 00:00:00');   // tomorrow, first second  — out

    $a = afGet(['period' => 'today']);

    expect($a['revenue_total_aed'])->toBe(600, 'today is 00:00:00 to 23:59:59 inclusive, and nothing either side');
    expect($a['paid_orders'])->toBe(2);
});

/* ----------------------------------------------------------- 3. This week */

it('runs the week from Monday to Sunday inclusive', function () {
    asFilterAdmin();

    // Now is Tuesday 10 Feb 2026, so the week is Mon 9 Feb - Sun 15 Feb.
    afOrder(10000, '2026-02-08 23:59:59');   // Sunday before, last second — out
    afOrder(20000, '2026-02-09 00:00:00');   // Monday, first second       — in
    afOrder(40000, '2026-02-15 23:59:59');   // Sunday, last second        — in
    afOrder(80000, '2026-02-16 00:00:00');   // next Monday, first second  — out

    $a = afGet(['period' => 'week']);

    expect($a['period']['from'])->toBe('2026-02-09', 'the UAE working week starts on Monday');
    expect($a['period']['to'])->toBe('2026-02-15');
    expect($a['revenue_total_aed'])->toBe(600, 'Monday 00:00:00 to Sunday 23:59:59, and nothing either side');
});

/* ---------------------------------------------------------- 4. This month */

it('means the calendar month and not the last thirty days', function () {
    asFilterAdmin();

    afOrder(10000, '2026-01-31 23:59:59');   // last second of January — out
    afOrder(20000, '2026-02-01 00:00:00');   // first second of February — in
    afOrder(40000, '2026-02-28 23:59:59');   // last second of February — in
    afOrder(80000, '2026-03-01 00:00:00');   // first second of March — out

    // 29 days before "now", so a subDays(30) window would swallow it whole.
    afOrder(160000, '2026-01-12 10:00:00');

    $a = afGet(['period' => 'month']);

    expect($a['revenue_total_aed'])->toBe(600, '"this month" is February, which is 28 days, not 30');
});

/* ----------------------------------------------------------- 5. This year */

it('runs the year from 1 January to 31 December inclusive', function () {
    asFilterAdmin();

    afOrder(10000, '2025-12-31 23:59:59');   // out
    afOrder(20000, '2026-01-01 00:00:00');   // in
    afOrder(40000, '2026-12-31 23:59:59');   // in
    afOrder(80000, '2027-01-01 00:00:00');   // out

    $a = afGet(['period' => 'year']);

    expect($a['period']['from'])->toBe('2026-01-01');
    expect($a['period']['to'])->toBe('2026-12-31');
    expect($a['revenue_total_aed'])->toBe(600);
});

/* -------------------------------------------------------- 6. All time */

it('counts everything, including rows outside every other period', function () {
    asFilterAdmin();

    afOrder(10000, '2019-06-01 12:00:00');
    afOrder(20000, '2026-02-10 09:00:00');

    $a = afGet(['period' => 'all']);

    expect($a['revenue_total_aed'])->toBe(300, 'all time has no lower bound');
});

/* ------------------------------------------------------ 7. A custom range */

it('includes both named days of a custom range whole, and neither neighbour', function () {
    asFilterAdmin();

    afOrder(10000, '2026-02-02 23:59:59');   // day before from — out
    afOrder(20000, '2026-02-03 00:00:00');   // from, first second — in
    afOrder(40000, '2026-02-05 23:59:59');   // to, last second — in
    afOrder(80000, '2026-02-06 00:00:00');   // day after to — out

    $a = afGet(['period' => 'custom', 'from' => '2026-02-03', 'to' => '2026-02-05']);

    expect($a['revenue_total_aed'])->toBe(600, 'a custom range names two DAYS, both of them whole');
    expect($a['period']['from'])->toBe('2026-02-03');
    expect($a['period']['to'])->toBe('2026-02-05');
});

it('accepts a single-day custom range', function () {
    asFilterAdmin();

    afOrder(50000, '2026-02-03 00:00:00');
    afOrder(50000, '2026-02-03 23:59:59');
    afOrder(10000, '2026-02-04 00:00:00');

    $a = afGet(['period' => 'custom', 'from' => '2026-02-03', 'to' => '2026-02-03']);

    expect($a['revenue_total_aed'])->toBe(1000);
});

it('reads a backwards custom range as the range the owner meant', function () {
    asFilterAdmin();

    afOrder(50000, '2026-02-04 12:00:00');

    $a = afGet(['period' => 'custom', 'from' => '2026-02-05', 'to' => '2026-02-03']);

    expect($a['period']['from'])->toBe('2026-02-03', 'from and to were handed over the wrong way round');
    expect($a['period']['to'])->toBe('2026-02-05');
    expect($a['revenue_total_aed'])->toBe(500);
});

it('refuses a custom range with no dates rather than answering a different question', function () {
    asFilterAdmin();

    test()->getJson('/admin-api/analytics?period=custom')->assertStatus(422);
    test()->getJson('/admin-api/analytics?period=custom&from=2026-02-03')->assertStatus(422);
    test()->getJson('/admin-api/analytics?period=custom&from=nonsense&to=2026-02-03')->assertStatus(422);
    test()->getJson('/admin-api/analytics?period=fortnight')->assertStatus(422);
});

/* ------------------------------------------- 8. WHICH PERIOD A REFUND FALLS IN */

/**
 * THE DECISION: a refund counts in the period of the ORDER it came off, never
 * in the period the money physically went back.
 *
 * WHY. Every other figure on this page is keyed on the order — units sold, best
 * sellers, where the orders are, the average order value, and the chart, which
 * has netted refunds into the order's own day since the screen was rebuilt. If
 * the Refunded box alone followed the refund's date then in a month with one
 * late refund the page would show Net revenue and Refunded that do not add up
 * to Gross, an average order value computed from a revenue figure that does not
 * belong to those orders, and a chart that disagrees with the KPI directly
 * above it. It can also go NEGATIVE: a quiet month carrying a refund against a
 * busy month's order would report revenue below zero.
 *
 * Read the box as: "of what these orders brought in, this much went back".
 */
it('counts a refund in the period of its order, not the period of the refund', function () {
    asFilterAdmin();

    // AED 1,000 taken in January. AED 400 handed back in February.
    $january = afOrder(100000, '2026-01-20 10:00:00');
    afRefund($january, 40000, '2026-02-05 10:00:00');

    $jan = afGet(['period' => 'custom', 'from' => '2026-01-01', 'to' => '2026-01-31']);

    expect($jan['revenue_total_aed'])->toBe(600, "January's revenue is what January's orders finally kept");
    expect($jan['refunds_total_aed'])->toBe(400, 'the refund belongs to the order it came off');
    expect($jan['gross_revenue_aed'])->toBe(1000);

    $feb = afGet(['period' => 'month']);

    expect($feb['revenue_total_aed'])->toBe(0, 'February sold nothing');
    expect($feb['refunds_total_aed'])->toBe(0, "a refund against January's order is not a February figure");
});

it('never reports negative revenue for a period whose only event was a refund', function () {
    asFilterAdmin();

    $january = afOrder(100000, '2026-01-20 10:00:00');
    afRefund($january, 90000, '2026-02-05 10:00:00');

    $feb = afGet(['period' => 'month']);

    expect($feb['revenue_total_aed'])->toBe(0);
});

/**
 * The refund decision, asserted period by period with exact figures.
 *
 * "net equals gross minus refunded" on its own proves NOTHING here — net IS
 * defined as gross minus refunded, so the identity holds whichever date the
 * refunds were gathered by. Only naming the expected Refunded figure for each
 * period can tell the two rules apart, so each one is named.
 *
 * EVERY refund below is dated into a different period from the order it came
 * off, which is the whole point:
 *
 *   order 4 Feb  AED 1,000   refunded AED 250 on 20 Feb   (later that month)
 *   order 4 Jan  AED   500   refunded AED 200 on 11 Feb   (the next month)
 *   order 10 Feb AED   300   refunded AED  50 on  2 Mar   (the month after)
 */
it('gathers refunds by their order for every period, and keeps gross and net in step', function () {
    asFilterAdmin();

    $feb = afOrder(100000, '2026-02-04 10:00:00');
    afRefund($feb, 25000, '2026-02-20 10:00:00');

    $jan = afOrder(50000, '2026-01-04 10:00:00');
    afRefund($jan, 20000, '2026-02-11 10:00:00');

    $today = afOrder(30000, '2026-02-10 06:00:00');
    afRefund($today, 5000, '2026-03-02 10:00:00');

    // period => [gross, refunded, net]
    foreach ([
        // Only the 10 Feb order; its refund follows it, though it happened in March.
        'today' => [300, 50, 250],
        // Mon 9 - Sun 15 Feb: same single order.
        'week' => [300, 50, 250],
        // February: the 4th and the 10th. NOT the 11th and 20th refunds' own
        // dates, which would gather AED 450 including January's order's refund.
        'month' => [1300, 300, 1000],
        'year' => [1800, 500, 1300],
        'all' => [1800, 500, 1300],
    ] as $period => [$gross, $refunded, $net]) {
        $a = afGet(['period' => $period]);

        expect($a['gross_revenue_aed'])->toBe($gross, 'gross is wrong for period ' . $period);
        expect($a['refunds_total_aed'])
            ->toBe($refunded, 'refunds must follow their ORDER into period ' . $period);
        expect($a['revenue_total_aed'])->toBe($net, 'net is wrong for period ' . $period);
        expect($a['revenue_total_aed'])
            ->toBe($gross - $refunded, 'net + refunded must equal gross for period ' . $period);
    }
});

/* ------------------------------------ 9. EVERY BOX ON THE PAGE OBEYS THE FILTER */

it('filters every figure on the page, not just the revenue', function () {
    asFilterAdmin();

    // In February: one paid order, 3 units, AED 600 net of a AED 100 refund.
    $feb = afOrder(70000, '2026-02-04 10:00:00');
    afItem($feb, 'February Serum', 3, 70000);
    afRefund($feb, 10000, '2026-02-06 10:00:00');

    // In February but cancelled: counts in "where the orders are", never in sales.
    afOrder(99900, '2026-02-05 10:00:00', 'cancelled');

    // In January: must not appear anywhere in a February answer.
    $jan = afOrder(500000, '2026-01-04 10:00:00');
    afItem($jan, 'January Cream', 9, 500000);

    $a = afGet(['period' => 'month']);

    expect($a['revenue_total_aed'])->toBe(600, 'net revenue must be February only');
    expect($a['gross_revenue_aed'])->toBe(700);
    expect($a['refunds_total_aed'])->toBe(100);
    expect($a['paid_orders'])->toBe(1, 'the cancelled order is not a paid order');
    expect($a['orders_total'])->toBe(2, '"orders" counts every February order whatever its status');
    expect($a['aov_aed'])->toBe(600, 'the average must divide the FILTERED net revenue by the FILTERED count');
    expect($a['units_sold'])->toBe(3, "January's nine units are not February's");

    $names = array_column($a['top_products'], 'name');

    expect(in_array('February Serum', $names, true))->toBeTrue('best sellers must include the February line');
    expect(in_array('January Cream', $names, true))->toBeFalse('best sellers must not reach outside the period');

    $status = $a['status_breakdown'];

    expect((int) ($status['completed'] ?? 0))->toBe(1, '"where the orders are" must count February only');
    expect((int) ($status['cancelled'] ?? 0))->toBe(1);

    $chartTotal = array_sum(array_column($a['series'], 'revenue_aed'));

    expect($chartTotal)->toBe(600, 'the chart must add up to the KPI directly above it');
});

it('shows an empty period as empty everywhere, without dividing by zero', function () {
    asFilterAdmin();

    $o = afOrder(100000, '2026-01-04 10:00:00');
    afItem($o, 'January Cream', 9, 100000);

    $a = afGet(['period' => 'today']);

    expect($a['revenue_total_aed'])->toBe(0);
    expect($a['gross_revenue_aed'])->toBe(0);
    expect($a['refunds_total_aed'])->toBe(0);
    expect($a['aov_aed'])->toBe(0, 'an empty period divides by zero unless it is guarded');
    expect($a['paid_orders'])->toBe(0);
    expect($a['orders_total'])->toBe(0);
    expect($a['units_sold'])->toBe(0);
    expect($a['top_products'])->toBe([]);
    expect($a['status_breakdown'])->toBe([]);
    expect($a['series'])->not->toBe([], 'an empty period still draws an axis');
});

/* ------------------------------------------- 10. THE CHART FOLLOWS THE RANGE */

it('buckets by the hour for a single day', function () {
    asFilterAdmin();

    afOrder(10000, '2026-02-10 00:00:00');
    afOrder(20000, '2026-02-10 23:59:59');

    $a = afGet(['period' => 'today']);

    expect($a['bucket'])->toBe('hour');
    expect($a['series'])->toHaveCount(24, 'a day has 24 hours');
    expect($a['series'][0]['revenue_aed'])->toBe(100, 'the first hour holds the 00:00:00 order');
    expect($a['series'][23]['revenue_aed'])->toBe(200, 'the last hour holds the 23:59:59 order');
});

it('buckets by the day for a week and for a month', function () {
    asFilterAdmin();

    $week = afGet(['period' => 'week']);

    expect($week['bucket'])->toBe('day');
    expect($week['series'])->toHaveCount(7, 'Monday to Sunday is seven days');

    $month = afGet(['period' => 'month']);

    expect($month['bucket'])->toBe('day');
    expect($month['series'])->toHaveCount(28, 'February 2026 has 28 days');
});

it('buckets a year by the week rather than drawing 365 bars', function () {
    asFilterAdmin();

    $a = afGet(['period' => 'year']);

    expect($a['bucket'])->toBe('week');
    expect(count($a['series']))->toBeLessThan(60, 'a year of daily bars is unreadable');
    expect(count($a['series']))->toBeGreaterThan(50);
});

it('buckets a multi-year span by the month', function () {
    asFilterAdmin();

    afOrder(10000, '2022-06-01 12:00:00');
    afOrder(20000, '2026-02-10 09:00:00');

    $a = afGet(['period' => 'all']);

    expect($a['bucket'])->toBe('month');
    expect(count($a['series']))->toBeGreaterThan(40);
    expect(array_sum(array_column($a['series'], 'revenue_aed')))
        ->toBe(300, 'every order in the period must land in exactly one bucket');
});

/**
 * THE MONTH THAT WAS NEVER DRAWN.
 *
 * PHP dates OVERFLOW rather than clamp, so 31 October + 1 month is 1 December
 * and November is skipped entirely. The bucket walk started at the first
 * order's own day and stepped with addMonth(), so a shop whose oldest order
 * happened to fall on a 31st lost every month with 30 days out of the chart —
 * and then every order placed in one of those months resolved to a key that had
 * never been generated, and Analytics answered 500 with "Undefined array key".
 *
 * Found on a preview with four years of real orders in it. Every unit test up
 * to that point had seeded tidy dates that never landed on a 31st, which is
 * exactly the shape of a filter test that only checks the happy middle.
 */
it('draws every month between two orders, even from a 31st', function () {
    asFilterAdmin();

    afOrder(10000, '2022-10-31 23:00:00');   // the 31st: +1 month overflows
    afOrder(20000, '2022-11-15 10:00:00');   // the month that used to vanish
    afOrder(40000, '2023-01-31 10:00:00');
    afOrder(80000, '2026-02-10 09:00:00');

    $a = afGet(['period' => 'all']);

    expect($a['bucket'])->toBe('month');

    $keys = array_column($a['series'], 'key');

    foreach (['2022-10-01', '2022-11-01', '2022-12-01', '2023-01-01', '2026-02-01'] as $month) {
        expect(in_array($month, $keys, true))->toBeTrue($month . ' has no bucket on the chart');
    }

    expect(count($keys))->toBe(count(array_unique($keys)), 'a month was bucketed twice');

    expect(array_sum(array_column($a['series'], 'revenue_aed')))
        ->toBe($a['revenue_total_aed'], 'money fell out of the chart between the KPI and the bars');
});

it('loses nothing off the chart when a range starts and ends on awkward days', function () {
    asFilterAdmin();

    // The 29th, 30th and 31st of four different months, plus a leap-day-adjacent
    // February, across a span long enough to force weekly buckets.
    foreach ([
        '2025-01-31 23:59:59', '2025-03-31 00:00:00', '2025-04-30 12:00:00',
        '2025-05-31 23:00:00', '2025-08-31 00:00:01', '2025-12-31 23:59:59',
        '2026-01-31 12:00:00', '2026-02-01 00:00:00',
    ] as $i => $at) {
        afOrder(10000 * ($i + 1), $at);
    }

    $a = afGet(['period' => 'custom', 'from' => '2025-01-31', 'to' => '2026-02-01']);

    expect($a['bucket'])->toBe('week');
    expect(array_sum(array_column($a['series'], 'revenue_aed')))
        ->toBe($a['revenue_total_aed'], 'every order in the range must land in exactly one bucket');
});

it('names the bucket on screen so it is obvious which chart is being read', function () {
    asFilterAdmin();

    foreach (['today' => 'hour', 'week' => 'day', 'month' => 'day', 'year' => 'week'] as $period => $unit) {
        $a = afGet(['period' => $period]);

        expect($a['bucket'])->toBe($unit, 'wrong bucket for ' . $period);
        expect(str_contains(strtolower((string) $a['bucket_label']), $unit))
            ->toBeTrue('the chart must say what one bar is, for ' . $period);
    }
});

it('puts an order in the bucket its own day belongs to, on both edges of the month', function () {
    asFilterAdmin();

    afOrder(10000, '2026-02-01 00:00:00');
    afOrder(20000, '2026-02-28 23:59:59');

    $a = afGet(['period' => 'month']);

    $first = $a['series'][0];
    $last = $a['series'][count($a['series']) - 1];

    expect($first['revenue_aed'])->toBe(100, 'the 1st of the month is the first bar');
    expect($last['revenue_aed'])->toBe(200, 'the 28th is the last bar and it holds its last second');
});

it('marks buckets that have not happened yet rather than drawing them as a collapse', function () {
    asFilterAdmin();

    $a = afGet(['period' => 'month']);

    $future = array_values(array_filter($a['series'], fn ($b) => (bool) ($b['future'] ?? false)));
    $past = array_values(array_filter($a['series'], fn ($b) => ! ($b['future'] ?? false)));

    expect(count($past))->toBe(10, 'the 1st to the 10th of February have happened');
    expect(count($future))->toBe(18, 'the 11th to the 28th have not');
});

it('nets a refund into the bucket of its order, so no bar can go below the axis', function () {
    asFilterAdmin();

    $o = afOrder(100000, '2026-02-04 10:00:00');
    afRefund($o, 40000, '2026-02-20 10:00:00');

    $a = afGet(['period' => 'month']);

    $fourth = collect($a['series'])->firstWhere('key', '2026-02-04');
    $twentieth = collect($a['series'])->firstWhere('key', '2026-02-20');

    expect($fourth['revenue_aed'])->toBe(600, 'the refund comes off the day the order was placed');
    expect($twentieth['revenue_aed'])->toBe(0, 'the day the money went back is not a negative bar');
});

it('reports a peak that matches the tallest bar it drew', function () {
    asFilterAdmin();

    afOrder(100000, '2026-02-04 10:00:00');
    afOrder(30000, '2026-02-06 10:00:00');

    $a = afGet(['period' => 'month']);

    expect($a['peak_aed'])->toBe(1000);
    expect($a['period_total_aed'])->toBe(1300, 'the chart must state what it adds up to');
});

/**
 * TWO NUMBERS THAT ARE THE SAME NUMBER MUST PRINT THE SAME.
 *
 * The chart's stated total was summed from the bars AFTER each had been rounded
 * to whole AED, so it drifted by up to a dirham per bucket: a year of weekly
 * bars printed "This year AED 148,177" two cards below a Net revenue KPI of
 * AED 148,175. Caught on a preview with real money on it, not by any test that
 * seeded tidy round hundreds.
 *
 * Every amount below is deliberately NOT a whole number of dirhams.
 */
it('states a chart total that matches the revenue KPI to the dirham', function () {
    asFilterAdmin();

    foreach ([
        ['2026-02-02 10:00:00', 133_33],
        ['2026-02-03 10:00:00', 166_67],
        ['2026-02-04 10:00:00', 999_99],
        ['2026-02-05 10:00:00', 1_00],
        ['2026-02-06 10:00:00', 45_45],
        ['2026-02-09 10:00:00', 78_91],
        ['2026-02-10 07:00:00', 12_34],
    ] as [$at, $fils]) {
        afOrder($fils, $at);
    }

    foreach (['month', 'week', 'year', 'all'] as $period) {
        $a = afGet(['period' => $period]);

        expect($a['period_total_aed'])
            ->toBe($a['revenue_total_aed'], 'the chart total and the revenue KPI disagree for period ' . $period);
    }
});

/* --------------------------------------------- 11. dialect and date bindings */

it('hands the filter no date binding MySQL has to coerce', function () {
    asFilterAdmin();

    afOrder(50000, '2026-02-04 10:00:00');

    foreach ([
        ['period' => 'today'],
        ['period' => 'week'],
        ['period' => 'month'],
        ['period' => 'year'],
        ['period' => 'custom', 'from' => '2026-02-03', 'to' => '2026-02-05'],
    ] as $query) {
        $sql = \Tests\Support\SqlShape::capture(function () use ($query) {
            afGet($query);
        });

        foreach ($sql as $statement) {
            foreach ($statement['bindings'] as $binding) {
                if (! is_string($binding) || preg_match('/^\d{4}-\d{2}-\d{2}/', $binding) !== 1) {
                    continue;
                }

                expect(preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $binding) === 1)
                    ->toBeTrue('a date binding was "' . $binding . '", which is not a portable datetime literal');
            }
        }
    }
});

it('issues portable SQL for every filter option', function () {
    asFilterAdmin();

    $o = afOrder(100000, '2026-02-04 10:00:00');
    afItem($o, 'Shaped Serum', 2, 40000);
    afRefund($o, 15000, '2026-02-06 10:00:00');

    foreach (['all', 'today', 'week', 'month', 'year'] as $period) {
        $sql = \Tests\Support\SqlShape::capture(function () use ($period) {
            afGet(['period' => $period]);
        });

        expect(\Tests\Support\SqlShape::violations($sql))
            ->toBe([], 'period=' . $period . ' issued dialect-specific SQL');
    }
});

it('does not grow its statement count with the number of orders or of buckets', function () {
    asFilterAdmin();

    afOrder(10000, '2026-02-02 10:00:00');

    $small = count(\Tests\Support\SqlShape::capture(fn () => afGet(['period' => 'month'])));

    for ($d = 3; $d <= 9; $d++) {
        $o = afOrder(10000, '2026-02-0' . $d . ' 10:00:00');
        afItem($o, 'Serum ' . $d, 1, 10000);
        afRefund($o, 1000, '2026-02-0' . $d . ' 11:00:00');
    }

    $large = count(\Tests\Support\SqlShape::capture(fn () => afGet(['period' => 'month'])));

    expect($large)->toBeLessThanOrEqual($small, "statement count grew from {$small} to {$large}");
});

/* ------------------------------------------------- 12. the timezone hook */

it('reads every boundary and every bucket through one timezone hook', function () {
    /*
     * Lane CI is moving the shop's display timezone to Asia/Dubai. The store is
     * UTC in config/app.php with nothing setting APP_TIMEZONE, so "today" is
     * currently a UTC day and an order placed between midnight and 04:00 Dubai
     * time lands in the previous day's bar.
     *
     * This lane does not change the timezone. It makes the change a ONE-LINE
     * one: every boundary and every bucket in the filter is derived from
     * AnalyticsRange::timezone(), so pointing that at Asia/Dubai moves the whole
     * page together instead of moving half of it.
     */
    $code = php_strip_whitespace(base_path('app/Support/AnalyticsRange.php'));

    // Two reads and no more: the display clock and the clock the column is
    // already keeping. Scattering either through the boundaries and the buckets
    // is how half a screen moves timezone and the other half does not.
    expect(substr_count($code, 'config('))
        ->toBeLessThanOrEqual(2, 'the timezone must be read in one place, not scattered through the range');

    expect(str_contains($code, 'function timezone'))
        ->toBeTrue('AnalyticsRange::timezone() is the hook Lane CI moves');

    expect(str_contains($code, 'function storageTimezone'))
        ->toBeTrue('the clock the stored timestamps keep must be named, not assumed to be UTC');

    // Nothing may hard-code UTC as the tz a stored value is in.
    expect(preg_match("/createFromFormat\('Y-m-d H',[^)]*'UTC'\)/", $code) === 1)
        ->toBeFalse('a stored hour is parsed as UTC regardless of config; use storageTimezone()');
});

/**
 * The OTHER half of the hook: moving APP_TIMEZONE must not double-convert.
 *
 * (Storage is UTC in this application and StoreTime's header explains at length
 * why it stays that way. This case exists for the configuration, not as a
 * recommendation of it.)
 *
 * Laravel formats a Carbon for the connection using that Carbon's own timezone
 * and writes now() in config('app.timezone'), so app.timezone IS the clock a
 * datetime column keeps. If the range assumed UTC storage and the display
 * timezone were reached by moving APP_TIMEZONE instead, every boundary would be
 * shifted by the offset twice and "today" would start at 04:00.
 */
it('does not shift anything when the shop and its stored timestamps keep the same clock', function () {
    asFilterAdmin();

    config(['app.timezone' => 'Asia/Dubai']);
    app(\App\Services\SettingsService::class)->set(\App\Support\StoreTime::SETTING_KEY, 'Asia/Dubai');

    // Stored as Dubai wall-clock, shown as Dubai wall-clock: the same rows must
    // be in and out as in the all-UTC world.
    afOrder(10000, '2026-02-09 23:59:59');
    afOrder(20000, '2026-02-10 00:00:00');
    afOrder(40000, '2026-02-10 23:59:59');
    afOrder(80000, '2026-02-11 00:00:00');

    $a = afGet(['period' => 'today']);

    expect($a['period']['timezone'])->toBe('Asia/Dubai');
    expect($a['period']['from'])->toBe('2026-02-10');
    expect($a['revenue_total_aed'])->toBe(600, 'the boundaries were converted twice');
    expect($a['series'][0]['revenue_aed'])->toBe(200, 'the first bar is not midnight');
    expect($a['series'][23]['revenue_aed'])->toBe(400, 'the last bar is not 23:00');
});

it('applies the display timezone to the boundaries and to the buckets together', function () {
    asFilterAdmin();

    /*
     * The shop's clock, set where the owner sets it: Store -> Business Details
     * writes `store_timezone`, App\Support\StoreTime reads it, and
     * AnalyticsRange::timezone() delegates to StoreTime so this screen and the
     * rest of the admin cannot disagree about what day it is. APP_TIMEZONE is
     * deliberately left at UTC — moving it would reinterpret every stored
     * instant rather than convert it.
     */
    app(\App\Services\SettingsService::class)->set(\App\Support\StoreTime::SETTING_KEY, 'Asia/Dubai');

    /*
     * 2026-02-09 20:00 UTC is 2026-02-10 00:00 in Dubai — the first second of
     * "today" for the shop, and the previous day in UTC.
     * 2026-02-10 19:59:59 UTC is 2026-02-10 23:59:59 in Dubai — the last.
     * 2026-02-10 20:00:00 UTC is already tomorrow in Dubai.
     */
    afOrder(10000, '2026-02-09 19:59:59');   // still yesterday in Dubai — out
    afOrder(20000, '2026-02-09 20:00:00');   // midnight in Dubai — in
    afOrder(40000, '2026-02-10 19:59:59');   // last second in Dubai — in
    afOrder(80000, '2026-02-10 20:00:00');   // tomorrow in Dubai — out

    $a = afGet(['period' => 'today']);

    expect($a['period']['timezone'])->toBe('Asia/Dubai');
    expect($a['revenue_total_aed'])->toBe(600, 'the boundaries must move with the shop timezone');
    expect($a['series'][0]['revenue_aed'])->toBe(200, 'the first bar is 00:00 Dubai, not 00:00 UTC');
    expect($a['series'][23]['revenue_aed'])->toBe(400, 'the last bar is 23:00 Dubai');
});

/* ------------------------------------------------------ 13. the screen itself */

it('renders a filter control the owner can actually reach', function () {
    $console = view('admin.app')->render();

    expect(str_contains($console, 'an-filter'))
        ->toBeTrue('the Analytics screen has no filter control markup');

    foreach (['All time', 'Today', 'This week', 'This month', 'This year', 'Custom'] as $label) {
        expect(str_contains($console, $label))
            ->toBeTrue('the filter is missing the "' . $label . '" option');
    }

    /*
     * The two decisions an owner cannot check for themselves, printed rather
     * than assumed. A week that starts on the wrong day and a "month" that is
     * really thirty days both look like perfectly ordinary numbers.
     */
    expect(str_contains($console, 'Monday to Sunday'))
        ->toBeTrue('the screen must say which week "This week" means');

    expect(str_contains($console, 'The calendar month, not the last 30 days'))
        ->toBeTrue('the screen must say that "This month" is not a rolling 30 days');
});

it('agrees with the endpoint about what each period means', function () {
    asFilterAdmin();

    /*
     * The labels and hints are spelled in the console AND in AnalyticsRange, so
     * that the control can draw itself before any answer arrives and still work
     * when one never does. Two copies drift; this is the test that says so.
     */
    $console = view('admin.app')->render();

    foreach (afGet()['period']['options'] as $option) {
        expect(str_contains($console, "'" . $option['key'] . "','" . $option['label'] . "','" . $option['hint'] . "'"))
            ->toBeTrue('the screen and the endpoint disagree about the "' . $option['key'] . '" period');
    }
});

/**
 * THE STYLESHEET MUST NOT LIVE INSIDE THE THING IT STYLES.
 *
 * anStyle() returned a '<style>...' string that was concatenated onto the
 * screen's markup and guarded by "if the tag already exists, return nothing".
 * Those two halves fight the moment a screen re-renders itself, which is
 * precisely what a date filter makes this screen do:
 *
 *   render 1  tag absent  -> tag lands inside #content
 *   render 2  tag present -> '' returned, and innerHTML= wipes it away again
 *   render 3  tag absent  -> back it comes
 *
 * So every OTHER press of a filter button drew Analytics with no Analytics CSS:
 * no card padding, and `.an-scroll` without its overflow-x, which put the status
 * table 4px past the edge of #content at 390px. Caught by measuring #content in
 * a real browser across five filter presses, not by any assertion on one render.
 */
it('attaches the Analytics stylesheet to the document, not to the screen it styles', function () {
    $console = view('admin.app')->render();

    expect(str_contains($console, '\'<style id="\'+AN_CSS_ID+\'">\''))
        ->toBeFalse('the Analytics CSS is built into #content and a re-render deletes it');

    expect(str_contains($console, 'document.head.appendChild(tag)'))
        ->toBeTrue('the Analytics CSS must outlive the screen re-rendering itself');
});

it('no longer claims the Sales box covers every order ever placed', function () {
    $console = view('admin.app')->render();

    expect(str_contains($console, 'Every order ever placed in a status that counts as a sale'))
        ->toBeFalse('the Sales copy is hard-coded to all time and cannot describe a filtered page');

    expect(str_contains($console, 'last 14 days'))
        ->toBeFalse('the chart heading is hard-coded to 14 days and cannot describe a filtered range');
});
