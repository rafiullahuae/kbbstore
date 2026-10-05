<?php

declare(strict_types=1);

/*
 * My store's sales hero and top sellers (Lane OA4).
 *
 * The owner: "the main preview of the analytics is not working, there should
 * be date range, daily or past 7 days records, the net gross revenue etc not
 * working, but turn that off by default, just the total revenu should display
 * at the moment, from backend i will enable the net/gross etc when i need it.
 * also i need the 7 days and monthly records to rank the top sellers".
 *
 * What was wrong (OwnerAppSales docblock): "Gross" was orders.subtotal, so it
 * read BELOW Total on any order with shipping; "Net" took off the refunds made
 * today rather than the refunds of today's orders, and the VAT and shipping
 * besides; demo orders counted; the bars and the comparison ignored the chip.
 * Pinned here against Store → Analytics' own endpoint for the same period.
 *
 * The clock is fixed at 20 October 2026, 12:00 UTC (16:00 in Dubai), so "this
 * month", "last 7 days" and "yesterday" are the same on every run.
 */

use App\Models\Order;
use App\Models\Product;
use App\Services\OwnerApp\OwnerAppSales;
use App\Services\OwnerApp\OwnerAppUi;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\OwnerAppRoutes as OA;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-20 12:00:00');
    \Illuminate\Support\Carbon::setTestNow('2026-10-20 12:00:00');
    OA::wire($this->app);
    OwnerAppUi::forget();
    Cache::flush();
    \Illuminate\Support\Facades\RateLimiter::clear('owner-app-pin:127.0.0.1');
    \Illuminate\Support\Facades\RateLimiter::clear('owner-app-enrol:127.0.0.1');
    $this->owner = OA::admin();
    OA::member($this->owner);
});

afterEach(function () {
    CarbonImmutable::setTestNow();
    \Illuminate\Support\Carbon::setTestNow();
    OwnerAppUi::forget();
});

/** An order of whole dirhams, $daysAgo days back at 08:00 UTC (12:00 in Dubai). */
function oa4Order(int $n, string $status, int $daysAgo, array $aed, ?int $product = null, int $qty = 1): int
{
    $at = CarbonImmutable::parse('2026-10-20 08:00:00')->subDays($daysAgo);
    $o = Order::query()->create(['order_number' => (string) (52000 + $n), 'email' => "s$n@example.com", 'status' => $status,
        'subtotal' => $aed['sub'] * 100, 'discount_total' => ($aed['disc'] ?? 0) * 100, 'shipping_total' => ($aed['ship'] ?? 0) * 100,
        'tax_total' => ($aed['tax'] ?? 0) * 100, 'total' => ($aed['sub'] - ($aed['disc'] ?? 0) + ($aed['ship'] ?? 0)) * 100,
        'payment_method' => $aed['pay'] ?? 'tabby', 'billing_address' => ['first_name' => 'S', 'last_name' => (string) $n]]);
    DB::table('orders')->where('id', $o->id)->update(['created_at' => $at, 'updated_at' => $at]);
    if ($product !== null) {
        DB::table('order_items')->insert(['order_id' => $o->id, 'product_id' => $product, 'name' => Product::query()->whereKey($product)->value('name'),
            'quantity' => $qty, 'unit_price' => $aed['sub'] * 100 / $qty, 'subtotal' => $aed['sub'] * 100, 'total' => $aed['sub'] * 100, 'created_at' => $at, 'updated_at' => $at]);
    }

    return (int) $o->id;
}

function oa4Product(string $name): int
{
    return (int) Product::query()->create(['slug' => \Illuminate\Support\Str::slug($name), 'name' => $name, 'sku' => strtoupper(substr(md5($name), 0, 6)),
        'price' => 5000, 'manage_stock' => true, 'stock' => 50, 'stock_status' => 'instock'])->id;
}

/** Today: four paid orders (Tabby, Tamara, card, COD; shipping, a discount, VAT), one refunded in part, one pending, one failed. */
function oa4Day(): void
{
    oa4Order(1, 'processing', 0, ['sub' => 300, 'disc' => 20, 'ship' => 20, 'tax' => 14, 'pay' => 'tabby']);
    $refunded = oa4Order(2, 'completed', 0, ['sub' => 200, 'ship' => 0, 'tax' => 10, 'pay' => 'tamara']);
    oa4Order(3, 'onhold', 0, ['sub' => 100, 'ship' => 15, 'tax' => 5, 'pay' => 'stripe']);
    oa4Order(4, 'processing', 0, ['sub' => 80, 'ship' => 20, 'tax' => 4, 'pay' => 'cod']);
    oa4Order(5, 'pending', 0, ['sub' => 999, 'pay' => 'tamara']);
    oa4Order(6, 'failed', 0, ['sub' => 777, 'pay' => 'tabby']);
    DB::table('refunds')->insert(['order_id' => $refunded, 'amount' => 5000, 'status' => 'succeeded', 'created_at' => now(), 'updated_at' => now()]);
    // A refund made TODAY for an order placed ten days ago: belongs to that order's day, not to today.
    $old = oa4Order(7, 'completed', 10, ['sub' => 400, 'pay' => 'tabby']);
    DB::table('refunds')->insert(['order_id' => $old, 'amount' => 30000, 'status' => 'succeeded', 'created_at' => now(), 'updated_at' => now()]);
}

function oa4Dash($test, array $cookies, string $query = ''): array
{
    return OA::get($test, 'dashboard'.($query !== '' ? '?'.$query : ''), $cookies)->assertOk()->json();
}

it('reports Gross, Total and Net exactly as Store → Analytics does for the same period', function (string $range, string $analytics) {
    // DEFECT (the one he saw): "Gross" was orders.subtotal and read BELOW
    // "Total" whenever an order carried shipping; "Net" subtracted today's
    // refunds and the VAT and the shipping; neither matched any admin figure.
    // MUTATION: put `$todayRows->sum('subtotal')` back as gross, or key the
    // refunds on refunds.created_at, and this is red.
    oa4Day();
    oa4Order(8, 'completed', 3, ['sub' => 150, 'ship' => 20, 'tax' => 7]);
    oa4Order(9, 'completed', 25, ['sub' => 90, 'tax' => 4]);
    OwnerAppUi::put(['functions' => array_fill_keys(array_keys(OwnerAppUi::FUNCTIONS), true)]);
    [$c] = OA::enrol($this);

    $app = oa4Dash($this, $c, 'range='.$range);
    $a = $this->actingAs($this->owner, 'admin')->getJson('/admin-api/analytics?'.$analytics)->assertOk()->json();
    $num = fn (string $s) => (float) str_replace(',', '', $s);

    expect($num($app['figs']['gross']))->toBe((float) $a['gross_revenue_aed'])
        ->and($num($app['figs']['total']))->toBe((float) $a['revenue_total_aed'])
        ->and($num($app['figs']['net']))->toBe((float) ($a['revenue_total_aed'] - $a['tax_collected_aed']))
        ->and($app['paid_orders'])->toBe($a['paid_orders']);
    expect($num($app['figs']['gross']))->toBeGreaterThanOrEqual($num($app['figs']['total']))
        ->and($num($app['figs']['total']))->toBeGreaterThanOrEqual($num($app['figs']['net']));
})->with([
    'today' => ['today', 'period=today'],
    'this month' => ['month', 'period=month'],
    'last 7 days' => ['7d', 'period=custom&from=2026-10-14&to=2026-10-20'],
    'yesterday' => ['yesterday', 'period=custom&from=2026-10-19&to=2026-10-19'],
    'last month' => ['last_month', 'period=custom&from=2026-09-01&to=2026-09-30'],
]);

it('gives today\'s figures in dirhams the owner can check by hand', function () {
    // Paid today: 300 (320 less a 20 discount, plus 20 shipping... 300 - 20 + 20)
    // + 200 + 115 + 100 = 715 gross; 50 refunded off the Tamara order = 665;
    // VAT 14 + 10 + 5 + 4 = 33, so 632 net. The pending and failed orders
    // are not sales; the 300 refunded today belongs to 10 October.
    oa4Day();
    OwnerAppUi::put(['functions' => ['gross_net' => true]]);
    [$c] = OA::enrol($this);
    $d = oa4Dash($this, $c);
    expect($d['figs'])->toBe(['total' => '665', 'gross' => '715', 'net' => '632'])
        ->and($d['paid_orders'])->toBe(4)
        ->and($d['range'])->toBe('today')
        ->and(count($d['bars']))->toBe(24)
        ->and($d['bars'][12])->toMatchArray(['total' => 665.0, 'gross' => 715.0, 'net' => 632.0])   // 08:00 UTC = 12:00 Dubai
        ->and($d['mark'])->toBe(16);                                                                  // now: 16:00 Dubai
});

it('sends Total alone by default — Gross and Net are not even in the answer until switched on', function () {
    // The owner: "turn that off by default, just the total revenu should
    // display". MUTATION: default gross_net to true in OwnerAppUi::defaults().
    oa4Day();
    [$c] = OA::enrol($this);
    $d = oa4Dash($this, $c);
    expect(array_keys($d['figs']))->toBe(['total'])
        ->and(array_keys($d['delta']))->toBe(['total'])
        ->and(collect($d['bars'])->every(fn ($b) => ! array_key_exists('gross', $b) && ! array_key_exists('net', $b)))->toBeTrue()
        ->and(OwnerAppUi::defaults()['functions']['gross_net'])->toBeFalse()
        ->and(OwnerAppUi::defaults()['functions']['range'])->toBeTrue()
        ->and(OwnerAppUi::defaults()['functions']['top_period'])->toBeTrue();

    OwnerAppUi::put(['functions' => ['gross_net' => true]]);
    expect(array_keys(oa4Dash($this, $c)['figs']))->toBe(['total', 'gross', 'net']);
});

it('follows the range: figures, bars hourly or daily, and the comparison', function () {
    // MUTATION: ignore ?range in DashboardController and every range answers today.
    oa4Day();
    oa4Order(8, 'completed', 1, ['sub' => 150]);
    oa4Order(9, 'completed', 3, ['sub' => 50]);
    [$c] = OA::enrol($this);

    $shape = fn (string $r) => (fn ($d) => [$d['figs']['total'], count($d['bars']), $d['hourly']])(oa4Dash($this, $c, 'range='.$r));
    // 7 days: 665 + 150 + 50. This month adds 10 October's 400, less the 300
    // refunded off it today — counted against ITS day, as Analytics does.
    expect($shape('today'))->toBe(['665', 24, true])
        ->and($shape('yesterday'))->toBe(['150', 24, true])
        ->and($shape('7d'))->toBe(['865', 7, false])
        ->and($shape('month'))->toBe(['965', 31, false])
        ->and($shape('last_month'))->toBe(['0', 30, false])
        ->and($shape('nonsense'))->toBe(['665', 24, true]);
    // Product views follow the range too (the defect seen in the preview: a
    // half-open UTC window dropped today's own views to 0).
    $pv = oa4Product('Viewed');
    DB::table('product_view_days')->insert([['product_id' => $pv, 'day' => '2026-10-20', 'views' => 10], ['product_id' => $pv, 'day' => '2026-10-15', 'views' => 5]]);
    Cache::flush();
    expect(oa4Dash($this, $c)['product_views'])->toBe(10)->and(oa4Dash($this, $c, 'range=7d')['product_views'])->toBe(15);
    expect(oa4Dash($this, $c, 'range=7d')['bars'][6]['label'])->toBe('Today')
        ->and(oa4Dash($this, $c, 'range=7d')['vs'])->toBe('vs the 7 days before');

    // Switched off under Customise app: the server answers today whatever is asked.
    OwnerAppUi::put(['functions' => ['range' => false]]);
    expect(oa4Dash($this, $c, 'range=7d')['range'])->toBe('today');
});

it('ranks top sellers by units for 7 days or this month, net sales beside each, products with no sale left out', function () {
    // The owner: "i need the 7 days and monthly records to rank the top
    // sellers". MUTATION: drop ->apply() from OwnerAppSales::top() and the 7-day
    // list carries the 10-day-old sale.
    $a = oa4Product('Snail Essence');
    $b = oa4Product('Relief Sun');
    oa4Product('Never Sold Toner');
    oa4Order(1, 'completed', 0, ['sub' => 120], $a, 2);
    oa4Order(2, 'completed', 10, ['sub' => 250], $b, 5);
    oa4Order(3, 'failed', 0, ['sub' => 999], $b, 9);          // not a sale
    oa4Order(4, 'completed', 40, ['sub' => 999], $b, 9);      // last month
    [$c] = OA::enrol($this);

    $week = OA::get($this, 'dashboard?part=top&top=7d', $c)->assertOk()->json();
    expect($week['top_period'])->toBe('7d')
        ->and(collect($week['top'])->map(fn ($t) => [$t['name'], $t['qty'], $t['sales_display']])->all())->toBe([['Snail Essence', 2, 'AED 120']]);

    $month = oa4Dash($this, $c);
    expect($month['top_period'])->toBe('month')
        ->and(collect($month['top'])->map(fn ($t) => [$t['name'], $t['qty']])->all())->toBe([['Relief Sun', 5], ['Snail Essence', 2]])
        ->and(collect($month['top'])->pluck('name'))->not->toContain('Never Sold Toner');
});

it('answers a range or period change with one light request, cached, and flat as the shop grows', function () {
    // DEFECT: the whole dashboard recomputed for every tap, or a query per
    // order. MUTATION: drop Cache::remember() in OwnerAppSales::sales() and the
    // second request runs the sales statements again.
    $p = oa4Product('Toner');
    [$c] = OA::enrol($this);
    $count = function (string $q) use ($c) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        OA::get($this, 'dashboard?'.$q, $c)->assertOk();
        $log = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        // The app's own bookkeeping (session touch, pushing the new orders'
        // events) is not the dashboard's cost; the shop's tables are.
        $log = $log->reject(fn ($q) => str_contains($q, 'owner_app_'));

        return [$log->count(), $log->filter(fn ($s) => str_contains($s, 'order_items'))->count(), $log->filter(fn ($s) => str_contains($s, 'substr(orders.created_at'))->count()];
    };

    for ($i = 1; $i <= 3; $i++) {
        oa4Order($i, 'completed', $i, ['sub' => 50], $p);
    }
    $count('range=7d&part=sales');                                // warm the per-process memos, then measure uncached
    Cache::flush();
    [$small] = $count('range=7d&part=sales');
    for ($i = 4; $i <= 40; $i++) {
        oa4Order($i, 'completed', $i % 9, ['sub' => 50], $p);
    }
    Cache::flush();
    [$big, , $grouped] = $count('range=7d&part=sales');
    expect($big)->toBe($small)->and($grouped)->toBe(2);          // orders and refunds, grouped by hour

    [, , $again] = $count('range=7d&part=sales');
    expect($again)->toBe(0);                                      // cached

    Cache::flush();
    [, $items, $sales] = $count('part=top&top=7d');
    expect($items)->toBe(1)->and($sales)->toBe(0);                // top sellers alone: one statement

    // A new order is never hidden behind the cache.
    $before = oa4Dash($this, $c, 'range=7d')['figs']['total'];
    oa4Order(99, 'completed', 0, ['sub' => 1000], $p);
    expect(oa4Dash($this, $c, 'range=7d')['figs']['total'])->not->toBe($before);
});

it('draws the range picker, Gross/Net chips only when sent, and the top-sellers switch — one request per change', function () {
    // MUTATION: render the chips whatever the server sent, or refetch the
    // whole dashboard (no `part=`) on a range change.
    $js = (string) preg_replace(['#/\*.*?\*/#s', '#(^|\s)//[^\n]*#'], ['', '$1'], (string) file_get_contents(resource_path('js/owner-app/store.js')));
    expect($js)->toContain("d.figs && d.figs.gross !== undefined ? '<div class=\"seg\"")
        ->toContain("fn('range') ? '<button type=\"button\" class=\"rng\" data-range")
        ->toContain("(fn('top_period')\n    ? 'Top performers<div class=\"seg sm\" role=\"group\" aria-label=\"Top sellers period\">")
        ->toContain("'dashboard?part=sales&range=' + D.range")
        ->toContain("'dashboard?part=top&top=' + D.top");
    foreach (['today', 'yesterday', '7d', 'month', 'last_month'] as $k) {
        expect(OwnerAppSales::RANGES)->toHaveKey($k);
    }
});
