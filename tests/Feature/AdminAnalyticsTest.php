<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Refund;
use Illuminate\Support\Facades\DB;

/**
 * Overview → Dashboard and Store → Analytics.
 *
 * These are the screens the owner reads to decide things, and nobody fills them
 * in, so they fail differently from a form: not with a validation error but
 * with a number that is quietly wrong. Everything here is seeded to exact fils
 * and asserted to exact fils.
 *
 * The defects pinned hardest, each of which shipped:
 *
 *   PARTIAL REFUNDS WERE REVENUE. PaymentRefunder moves an order to 'refunded'
 *   only once the refund covers the whole captured amount — a partial refund
 *   deliberately leaves the order 'completed'. Revenue summed orders.total for
 *   every REAL_STATUSES order and never looked at `refunds`, so AED 400 handed
 *   back to a customer stayed in the revenue figure, in the average order
 *   value and in the daily chart, for ever.
 *
 *   THE 30-DAY WINDOW WAS AN ISO-8601 STRING. now()->subDays(30)->toISOString()
 *   is '2026-08-17T10:00:00.000000Z'. SQLite compares that to a stored
 *   '2026-08-17 11:00:00' as text, and ' ' (0x20) sorts below 'T' (0x54), so
 *   the whole boundary day fell out of the window. MySQL parses it, but emits
 *   warning 1292 'Incorrect datetime value' every time the dashboard loads.
 *
 *   TOP PRODUCTS IGNORED DISCOUNTS. Revenue per product was
 *   SUM(unit_price * quantity) — the list price, not the line total. A product
 *   only ever sold at a coupon discount reported revenue the store never took,
 *   and the Top products table could not be reconciled with the Revenue KPI.
 *
 *   'CONVERSION' WAS NOT A CONVERSION RATE. paid orders / all orders, which
 *   measures how many orders get cancelled and has no relationship to the share
 *   of visitors who buy. Nothing in this application tracks sessions.
 */

/* ------------------------------------------------------------------ fixtures */

/*
 * Guarded, because Pest declares test-file functions in the GLOBAL namespace.
 *
 * AdminQuizLeadsTest calls anAdminUser() but does not define it — it passed
 * only because this file happened to load first in a full run, and every one
 * of its ten tests errored with "Call to undefined function anAdminUser()"
 * when run on its own. A test file that needs a sibling loaded first is a test
 * file that reports differently depending on what else ran, which is the
 * property this suite has already lost time to.
 *
 * Declaring it if-not-already-declared, in both places, makes each file
 * runnable alone without either owning the other.
 */
if (! function_exists('anAdminUser')) {
function anAdminUser(): AdminUser
{
    return AdminUser::create([
        'name' => 'A Owner',
        'email' => 'a-owner-' . uniqid() . '@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
}
}

function asAnalyticsAdmin(): void
{
    test()->actingAs(anAdminUser(), 'admin');
}

/** An order at an exact number of fils, optionally back-dated. */
function anOrder(int $fils, string $status = 'completed', ?string $at = null, ?Customer $customer = null): Order
{
    static $n = 0;
    $n++;

    $order = Order::create([
        'customer_id' => $customer?->id,
        'order_number' => 'A-' . $n . '-' . uniqid(),
        'email' => $customer?->email ?? 'a-guest-' . $n . '@example.test',
        'status' => $status,
        'subtotal' => $fils,
        'total' => $fils,
        'paid_at' => now(),
    ]);

    if ($at !== null) {
        DB::table('orders')->where('id', $order->id)->update(['created_at' => $at, 'updated_at' => $at]);
        $order->refresh();
    }

    return $order;
}

/** A refund against an order. Fils, like every money column in this schema. */
function aRefund(Order $order, int $fils, string $status = 'succeeded'): Refund
{
    return Refund::create([
        'order_id' => $order->id,
        'amount' => $fils,
        'status' => $status,
        'reason' => 'test',
    ]);
}

function anItem(Order $order, string $name, int $qty, int $unitPriceFils, ?int $lineTotalFils = null): OrderItem
{
    return OrderItem::create([
        'order_id' => $order->id,
        'name' => $name,
        'brand' => 'Test Brand',
        'quantity' => $qty,
        'unit_price' => $unitPriceFils,
        'subtotal' => $unitPriceFils * $qty,
        'total' => $lineTotalFils ?? ($unitPriceFils * $qty),
    ]);
}

/* ---------------------------------------------------------------- the guard */

it('refuses an anonymous caller on the analytics and stats endpoints', function () {
    foreach (['/admin-api/stats', '/admin-api/analytics', '/admin-api/quiz-leads'] as $uri) {
        expect(test()->getJson($uri)->getStatusCode())
            ->toBe(401, $uri . ' was not refused for an anonymous caller');
    }
});

/* ------------------------------------------------------- 1. partial refunds */

it('does not count a partial refund as revenue on Analytics', function () {
    asAnalyticsAdmin();

    // AED 1,000.00 taken, AED 400.00 handed back. The order stays 'completed'
    // because PaymentRefunder only moves status on a FULL refund.
    $order = anOrder(100000, 'completed');
    aRefund($order, 40000);

    expect($order->fresh()->status)
        ->toBe('completed', 'fixture assumption: a partial refund leaves the status alone');

    $a = test()->getJson('/admin-api/analytics')->json();

    expect($a['revenue_total_aed'])->toBe(600, 'revenue must be net of the AED 400 refunded');
    expect($a['refunds_total_aed'])->toBe(400, 'the refund must be reported, not merely subtracted');
    expect($a['gross_revenue_aed'])->toBe(1000, 'gross must still be available beside net');
});

it('computes the average order value from revenue net of refunds', function () {
    asAnalyticsAdmin();

    $a1 = anOrder(100000, 'completed');
    aRefund($a1, 40000);
    anOrder(20000, 'completed');

    // Net 60000 + 20000 = 80000 fils over 2 paid orders = 40000 fils = AED 400.
    $a = test()->getJson('/admin-api/analytics')->json();

    expect($a['paid_orders'])->toBe(2);
    expect($a['aov_aed'])->toBe(400, 'AOV must divide NET revenue by the paid order count');
});

it('does not count a failed refund attempt against revenue', function () {
    asAnalyticsAdmin();

    $order = anOrder(100000, 'completed');
    aRefund($order, 40000, 'failed');

    $a = test()->getJson('/admin-api/analytics')->json();

    expect($a['revenue_total_aed'])->toBe(1000, 'a refund that failed sent no money back');
    expect($a['refunds_total_aed'])->toBe(0);
});

it('nets partial refunds out of the revenue series too', function () {
    asAnalyticsAdmin();

    $today = now()->format('Y-m-d');
    $order = anOrder(100000, 'completed', $today . ' 09:00:00');
    aRefund($order, 40000);

    $a = test()->getJson('/admin-api/analytics')->json();

    // `daily` and its fixed 14-day window are gone: the chart follows the date
    // filter now and its buckets are days, weeks or months depending on the
    // span. See AdminAnalyticsFilterTest for the filter itself.
    $row = collect($a['series'])->firstWhere('key', $today);

    expect($row)->not->toBeNull('today must be present in the series');
    expect($row['revenue_aed'])->toBe(600, 'the chart must agree with the KPI above it');
});

it('does not count a partial refund as revenue on the dashboard', function () {
    asAnalyticsAdmin();

    $order = anOrder(100000, 'completed');
    aRefund($order, 40000);

    $s = test()->getJson('/admin-api/stats')->json();

    expect($s['revenue_30d_aed'])->toBe(600, 'the dashboard and Analytics must not disagree about revenue');
});

/* ---------------------------------------------------- 2. the 30-day window */

it('counts an order placed on the boundary day of the 30-day window', function () {
    asAnalyticsAdmin();

    // Placed 30 days ago but LATER in that day than the moment the window is
    // computed. Under the old ISO-8601-with-Z cutoff this row fell out of the
    // window on SQLite entirely, because ' ' sorts below 'T' as text.
    $at = now()->subDays(30)->setTime(23, 30)->format('Y-m-d H:i:s');
    anOrder(50000, 'completed', $at);

    $s = test()->getJson('/admin-api/stats')->json();

    expect($s['revenue_30d_aed'])->toBe(500, 'an order inside the last 30 days must be inside the window');
});

it('leaves an order older than the 30-day window out of the dashboard figure', function () {
    asAnalyticsAdmin();

    anOrder(50000, 'completed', now()->subDays(45)->format('Y-m-d H:i:s'));

    $s = test()->getJson('/admin-api/stats')->json();

    expect($s['revenue_30d_aed'])->toBe(0, 'a 45-day-old order is not in the last 30 days');
});

it('builds the 30-day window without an ISO-8601 cutoff string', function () {
    // php_strip_whitespace() drops comments, so the prose explaining the defect
    // in AdminController does not keep this test red for naming it.
    $code = php_strip_whitespace(base_path('app/Http/Controllers/Admin/AdminController.php'));

    expect(str_contains($code, 'subDays(30)->toISOString()'))
        ->toBeFalse('toISOString() produces a value MySQL rejects with warning 1292 and SQLite mis-sorts');
});

/* --------------------------------------------------- 3. top products revenue */

it('reports top-product revenue from the line total, not the list price', function () {
    asAnalyticsAdmin();

    $order = anOrder(60000, 'completed');
    // Two units at AED 500 list, sold for AED 600 the pair after a discount.
    anItem($order, 'Discounted Serum', 2, 50000, 60000);

    $a = test()->getJson('/admin-api/analytics')->json();
    $row = collect($a['top_products'])->firstWhere('name', 'Discounted Serum');

    expect($row)->not->toBeNull();
    expect($row['revenue_aed'])->toBe(600, 'unit_price * quantity is the list price, not what was taken');
});

it('counts units and revenue only from orders in a real status', function () {
    asAnalyticsAdmin();

    $cancelled = anOrder(50000, 'cancelled');
    anItem($cancelled, 'Cancelled Item', 3, 10000);

    $a = test()->getJson('/admin-api/analytics')->json();

    expect($a['units_sold'])->toBe(0, 'a cancelled order sold nothing');
    expect(collect($a['top_products'])->firstWhere('name', 'Cancelled Item'))->toBeNull();
});

/* -------------------------------------------------------- 4. empty and honest */

it('answers zero rather than dividing by an empty set', function () {
    asAnalyticsAdmin();

    $a = test()->getJson('/admin-api/analytics')->json();

    expect($a['aov_aed'])->toBe(0);
    expect($a['revenue_total_aed'])->toBe(0);
    expect($a['units_sold'])->toBe(0);

    // A shop with no orders has no history to span, so "all time" charts today
    // and nothing else — but it still draws an axis rather than an empty card.
    expect($a['series'])->not->toBe([], 'the chart draws an axis whether or not anything sold');
    expect($a['bucket'])->toBe('day', 'all time is a history view and never draws hours');
});

it('counts a shipped order as revenue, which the screen copy must not deny', function () {
    asAnalyticsAdmin();

    anOrder(30000, 'shipped');

    $a = test()->getJson('/admin-api/analytics')->json();

    expect($a['revenue_total_aed'])->toBe(300, 'shipped is in Order::REAL_STATUSES');

    // The screen used to say revenue "counts processing, on-hold and completed
    // orders" — three of the four statuses it actually counts.
    $console = file_get_contents(resource_path('views/admin/app.blade.php'));

    expect(str_contains($console, 'counts processing, on-hold and completed orders'))
        ->toBeFalse('the Analytics description named three of the four statuses it counts');
});

it('does not call the paid-to-total order ratio a conversion rate', function () {
    $console = file_get_contents(resource_path('views/admin/app.blade.php'));

    expect(str_contains($console, "setKpi('Conversion', conv+'%', 'paid / total')"))
        ->toBeFalse('paid orders / all orders is not a conversion rate; nothing here tracks sessions');
});

it('reports a peak the chart can be read against', function () {
    asAnalyticsAdmin();

    anOrder(100000, 'completed', now()->format('Y-m-d') . ' 09:00:00');

    $a = test()->getJson('/admin-api/analytics')->json();

    expect(array_key_exists('peak_aed', $a))
        ->toBeTrue('the chart needs a stated peak or its bars have no scale');
    expect($a['peak_aed'])->toBe(1000);
});

it('leaks no Blade comment into the rendered console', function () {
    /*
     * A Blade comment inside a verbatim region is not a comment. Blade never
     * sees it, so the delimiters and everything between them are served to the
     * browser as visible text — and most of this file's script IS a verbatim
     * region, which is exactly where somebody explaining a tricky line would
     * naturally put one. This lane wrote one beside the dashboard's fourth KPI
     * tile and it rendered as a paragraph of prose where the number should be,
     * caught only because a screenshot was taken.
     *
     * Asserting on the rendered output rather than the source, because the
     * source is allowed to contain Blade comments — outside verbatim they work
     * perfectly well.
     */
    $rendered = view('admin.app')->render();

    expect(str_contains($rendered, '{{--'))
        ->toBeFalse('a Blade comment is being rendered to the page; it is inside a verbatim region');
    expect(str_contains($rendered, '--}}'))
        ->toBeFalse('a Blade comment terminator is being rendered to the page');
});

/* ------------------------------------------------- dialect: the shape of it */

it('issues no SQLite-only SQL from the dashboard or Analytics', function () {
    asAnalyticsAdmin();

    $order = anOrder(100000, 'completed', now()->format('Y-m-d') . ' 09:00:00');
    anItem($order, 'Shaped Serum', 2, 25000, 40000);
    aRefund($order, 15000);

    /*
     * The suite runs on SQLite and production runs MySQL, and SQLite is the
     * more permissive of the two — a statement MySQL rejects outright still
     * comes back 200 here. Judge the statement, not the answer. See
     * Tests\Support\SqlShape, which exists because the 1140 that took the
     * Customers screen down was invisible to every test that called the
     * endpoint. This lane added a join and two grouped aggregates, which is
     * exactly the shape that failure had.
     */
    foreach (['/admin-api/stats', '/admin-api/analytics', '/admin-api/quiz-leads'] as $uri) {
        $sql = \Tests\Support\SqlShape::capture(function () use ($uri) {
            test()->getJson($uri)->assertOk();
        });

        expect(\Tests\Support\SqlShape::violations($sql))
            ->toBe([], $uri . ' issued dialect-specific SQL');
    }
});

it('does not hand a date window a value MySQL has to coerce', function () {
    asAnalyticsAdmin();

    anOrder(50000, 'completed');

    $sql = \Tests\Support\SqlShape::capture(function () {
        test()->getJson('/admin-api/stats')->assertOk();
    });

    /*
     * Every binding that looks like a date must be a plain 'Y-m-d H:i:s'. An
     * ISO-8601 string — 'T' separator, fractional seconds, trailing 'Z' — is
     * what MySQL answers with warning 1292 'Incorrect datetime value', and what
     * SQLite sorts BELOW every stored value on the boundary day, because ' '
     * (0x20) is less than 'T' (0x54).
     */
    foreach ($sql as $statement) {
        foreach ($statement['bindings'] as $binding) {
            if (!is_string($binding) || !preg_match('/^\d{4}-\d{2}-\d{2}/', $binding)) {
                continue;
            }

            expect((bool) preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $binding))
                ->toBeTrue('a date binding was "' . $binding . '", which is not a portable datetime literal');
        }
    }
});
