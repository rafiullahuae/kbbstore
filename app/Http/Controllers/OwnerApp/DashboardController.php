<?php

declare(strict_types=1);

namespace App\Http\Controllers\OwnerApp;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\OwnerApp\OwnerAppSales;
use App\Services\OwnerApp\OwnerAppSettings;
use App\Services\OwnerApp\OwnerAppUi;
use App\Support\Money;
use App\Support\StoreTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * "My store" (Lane MAC, Petal design): the shop at a glance.
 *
 * The sales hero — Total, Gross, Net, the bars, the comparison, over Today,
 * Yesterday, Last 7 days, This month or Last month — is OwnerAppSales (Lane
 * OA4): Store → Analytics' own definitions for the same period, cached
 * briefly. Its docblock carries the defect it replaced (Gross was
 * orders.subtotal and read below Total; Net took off today's refunds).
 * Gross and Net are only computed when Owner App → Customise app → "Show
 * Gross and Net revenue" is on (off by default: the owner asked for Total).
 *
 * Paid orders are Order::REAL_STATUSES, the definition every revenue figure in
 * the admin uses. Money is shown only to a member holding analytics.view (the
 * capability that guards revenue in the console); orders.view gets the counts.
 *
 * "Product views" are the shop's own beacon counts (product_view_days) and
 * conversion is paid orders over baskets started today. The shop has no
 * visitor counter, and the app says what it counts rather than inventing one.
 *
 * "Needs you" is what a phone is for: failed payments, orders waiting on
 * payment or on hold, and the shelves that are low or empty.
 *
 * A fixed number of queries (about a dozen) whatever the size of the shop:
 * DashboardQueryBudget in OwnerAppDataTest renders it with 3 orders and 40.
 */
final class DashboardController extends Controller
{
    use Concerns;

    public function __invoke(Request $request): JsonResponse
    {
        if ($r = $this->refuse($request, 'orders.view')) {
            return $r;
        }

        $money = $this->may($request, 'analytics.view');
        $catalog = $this->may($request, 'catalog.view');
        $now = StoreTime::now();

        // The hero's range and the top sellers' period (Lane OA4). Each switch
        // off under Owner App → Customise app pins its default, whatever is asked.
        $range = OwnerAppUi::functionOn('range') ? OwnerAppSales::rangeKey($request->query('range')) : 'today';
        $topKey = OwnerAppUi::functionOn('top_period') ? OwnerAppSales::topKey($request->query('top')) : 'month';
        $part = (string) $request->query('part', '');

        // One light answer per tap: the top sellers alone, or the hero alone.
        if ($part === 'top') {
            return response()->json(['ok' => true, 'top_period' => $topKey, 'top_label' => OwnerAppSales::TOP_PERIODS[$topKey],
                'top' => OwnerAppUi::sectionOn('top') ? OwnerAppSales::top($topKey, $money) : []]);
        }

        $sales = $this->sales($range, $money, $now);
        if ($part === 'sales') {
            return response()->json(['ok' => true, 'updated_at' => $now->toIso8601String(), 'money' => $money, 'currency' => Money::currency()] + $sales);
        }

        $statusCounts = DB::table('orders')->whereNull('deleted_at')->whereIn('status', ['pending', 'processing', 'onhold'])
            ->groupBy('status')->selectRaw('status as s, COUNT(*) as n')->pluck('n', 's')->map(fn ($n) => (int) $n);
        $monthStart = $now->startOfMonth()->utc();

        return response()->json([
            'ok' => true,
            'updated_at' => $now->toIso8601String(),
            'date_label' => $now->format('l, j F'),
            'month_label' => $now->format('F'),
            'money' => $money,
            'currency' => Money::currency(),
        ] + $sales + [
            'counts' => $statusCounts,
            'needs' => $this->needs($statusCounts->all(), $catalog),
            // A section switched off under Customise app (Lane OA4) is not computed:
            // the returning-customers EXISTS and the top-sellers GROUP BY are the
            // two heaviest queries here.
            'tiles' => OwnerAppUi::sectionOn('avg') || OwnerAppUi::sectionOn('returning') ? $this->tiles($monthStart, $money) : ['avg_order' => null, 'returning_pct' => null],
            'top_period' => $topKey,
            'top_label' => OwnerAppSales::TOP_PERIODS[$topKey],
            'top' => OwnerAppUi::sectionOn('top') ? OwnerAppSales::top($topKey, $money) : [],
        ]);
    }

    /**
     * The hero for one range: Store → Analytics' own figures (OwnerAppSales),
     * plus product views and conversion over the same days. Gross and Net are
     * computed only when Customise app → "Show Gross and Net revenue" is on.
     *
     * @return array<string,mixed>
     */
    private function sales(string $range, bool $money, $now): array
    {
        $s = OwnerAppSales::sales($range, $money, OwnerAppUi::functionOn('gross_net'));
        $tz = \App\Support\AnalyticsRange::timezone();
        $from = \Carbon\CarbonImmutable::createFromFormat('Y-m-d', (string) $s['views_from'], $tz)->startOfDay();
        $to = \Carbon\CarbonImmutable::createFromFormat('Y-m-d', (string) $s['views_to'], $tz)->startOfDay()->addDay();
        // product_view_days.day is the UTC date (ProductViews): every UTC day the range touches.
        $views = (int) DB::table('product_view_days')->where('day', '>=', $from->utc()->toDateString())->where('day', '<=', $to->subSecond()->utc()->toDateString())->sum('views');
        $carts = (int) DB::table('carts')->where('created_at', '>=', $from->utc())->where('created_at', '<', $to->utc())->count();
        unset($s['views_from'], $s['views_to']);

        return $s + [
            'product_views' => $views,
            'conversion' => $carts > 0 ? round($s['paid_orders'] / $carts * 100, 1) : null,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function needs(array $counts, bool $catalog): array
    {
        $out = [];

        $failed = DB::table('orders as o')->leftJoin('customers as c', 'c.id', '=', 'o.customer_id')
            ->whereNull('o.deleted_at')->where('o.status', 'failed')->where('o.created_at', '>=', now()->subDays(2))
            ->orderByDesc('o.id')->limit(1)
            ->get(['o.id', 'o.order_number', 'o.total', 'o.payment_method_title', 'o.payment_method', 'o.billing_address', 'c.name as c_name']);
        $failedCount = $failed->isEmpty() ? 0 : DB::table('orders')->whereNull('deleted_at')->where('status', 'failed')->where('created_at', '>=', now()->subDays(2))->count();
        if ($f = $failed->first()) {
            $row = OrdersController::row((object) ((array) $f + ['status' => 'failed', 'created_at' => null]));
            $out[] = ['tone' => 'bad', 'icon' => 'alert', 'href' => '#/orders/'.$row['id'],
                'title' => $failedCount > 1 ? $failedCount.' failed payments' : 'Payment failed · '.$row['total_display'],
                'sub' => '#'.$row['number'].' '.$row['name'].($row['payment_title'] ? ' · '.$row['payment_title'] : '')];
        }

        if (($counts['pending'] ?? 0) > 0) {
            $p = DB::table('orders as o')->leftJoin('customers as c', 'c.id', '=', 'o.customer_id')
                ->whereNull('o.deleted_at')->where('o.status', 'pending')->orderByDesc('o.id')->limit(1)
                ->first(['o.id', 'o.order_number', 'o.total', 'o.payment_method_title', 'o.payment_method', 'o.billing_address', 'c.name as c_name']);
            $row = OrdersController::row((object) ((array) $p + ['status' => 'pending', 'created_at' => null]));
            $out[] = ['tone' => 'warn', 'icon' => 'clock', 'href' => $counts['pending'] > 1 ? '#/orders?pending' : '#/orders/'.$row['id'],
                'title' => $counts['pending'] > 1 ? $counts['pending'].' awaiting payment' : 'Awaiting payment',
                'sub' => '#'.$row['number'].' '.$row['name'].($row['payment_title'] ? ' · '.$row['payment_title'] : '')];
        }

        if (($counts['onhold'] ?? 0) > 0) {
            $out[] = ['tone' => 'warn', 'icon' => 'clock', 'href' => '#/orders?onhold',
                'title' => $counts['onhold'].' order'.($counts['onhold'] === 1 ? '' : 's').' on hold', 'sub' => 'Waiting for you to move them on'];
        }

        if ($catalog) {
            $at = OwnerAppSettings::lowStock();
            $low = DB::table('products')->whereNull('deleted_at')->where('manage_stock', true)
                ->where('stock', '>', 0)->where('stock', '<=', $at)->orderBy('stock')->orderBy('id')->limit(3)->get(['id', 'name', 'stock']);
            if ($low->isNotEmpty()) {
                $n = DB::table('products')->whereNull('deleted_at')->where('manage_stock', true)->where('stock', '>', 0)->where('stock', '<=', $at)->count();
                $out[] = ['tone' => 'warn', 'icon' => 'stack', 'href' => '#/products?low',
                    'title' => $n.' product'.($n === 1 ? '' : 's').' low on stock',
                    'sub' => $low->map(fn ($p) => self::short((string) $p->name).' '.$p->stock)->implode(' · ')];
            }

            $out_ = DB::table('products')->whereNull('deleted_at')->where('status', 'publish')->where('stock_status', 'outofstock')
                ->orderByDesc('updated_at')->orderByDesc('id')->limit(2)->get(['name']);
            if ($out_->isNotEmpty()) {
                $n = DB::table('products')->whereNull('deleted_at')->where('status', 'publish')->where('stock_status', 'outofstock')->count();
                $out[] = ['tone' => 'acc', 'icon' => 'box', 'href' => '#/products?out',
                    'title' => $n.' product'.($n === 1 ? '' : 's').' out of stock',
                    'sub' => $out_->map(fn ($p) => self::short((string) $p->name, 40))->implode(' · ')];
            }
        }

        return $out;
    }

    /** @return array<string,mixed> */
    private function tiles($monthStart, bool $money): array
    {
        $m = DB::table('orders as o')->whereNull('o.deleted_at')->whereIn('o.status', Order::REAL_STATUSES)
            ->where('o.created_at', '>=', $monthStart)
            ->selectRaw('COUNT(*) as n, SUM(o.total) as t,
                SUM(CASE WHEN EXISTS (SELECT 1 FROM orders p WHERE p.deleted_at IS NULL AND p.id < o.id
                    AND p.status IN (?,?,?,?) AND ((o.customer_id IS NOT NULL AND p.customer_id = o.customer_id) OR p.email = o.email)) THEN 1 ELSE 0 END) as back',
                Order::REAL_STATUSES)
            ->first();
        $n = (int) ($m->n ?? 0);

        return [
            'avg_order' => $money && $n > 0 ? Money::plain((int) round(((int) $m->t) / $n)) : null,
            'returning_pct' => $n > 0 ? (int) round(((int) $m->back) / $n * 100) : null,
        ];
    }

    private static function short(string $name, int $max = 22): string
    {
        $name = trim($name);

        return mb_strlen($name) > $max ? rtrim(mb_substr($name, 0, $max - 1)).'…' : $name;
    }
}
