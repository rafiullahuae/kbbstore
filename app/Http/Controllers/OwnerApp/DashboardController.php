<?php

declare(strict_types=1);

namespace App\Http\Controllers\OwnerApp;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\OwnerApp\OwnerAppSettings;
use App\Support\Money;
use App\Support\StoreTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * "My store" (Lane MAC, Petal design): today at a glance.
 *
 *   Total   what customers paid today: orders.total over paid orders
 *   Gross   product sales before discounts: orders.subtotal
 *   Net     Total less shipping, fees, VAT and today's refunds
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

    private const BARS = 7;

    public function __invoke(Request $request): JsonResponse
    {
        if ($r = $this->refuse($request, 'orders.view')) {
            return $r;
        }

        $money = $this->may($request, 'analytics.view');
        $catalog = $this->may($request, 'catalog.view');
        $now = StoreTime::now();
        $today = $now->startOfDay();
        $since = $today->subDays(self::BARS)->utc();       // 8 days: the 7 bars and last week's same day

        $paid = DB::table('orders')->whereNull('deleted_at')->whereIn('status', Order::REAL_STATUSES)
            ->where('created_at', '>=', $since)
            ->get(['created_at', 'total', 'subtotal', 'shipping_total', 'fee_total', 'tax_total']);

        $todayRows = $paid->filter(fn ($o) => StoreTime::display($o->created_at)?->gte($today));
        $refunded = (int) DB::table('refunds')->where('created_at', '>=', $today->utc())
            ->whereIn('status', \App\Services\Payments\PaymentRefunder::COUNTED)->sum('amount');

        $total = (int) $todayRows->sum('total');
        $gross = (int) $todayRows->sum('subtotal');
        $net = max(0, $total - (int) $todayRows->sum('shipping_total') - (int) $todayRows->sum('fee_total') - (int) $todayRows->sum('tax_total') - $refunded);

        // Same weekday last week, up to this minute.
        $lastStart = $today->subDays(7);
        $lastEnd = $now->subDays(7);
        $lastWeek = (int) $paid->filter(function ($o) use ($lastStart, $lastEnd) {
            $at = StoreTime::display($o->created_at);

            return $at !== null && $at->gte($lastStart) && $at->lte($lastEnd);
        })->sum('total');

        $bars = [];
        for ($i = self::BARS - 1; $i >= 0; $i--) {
            $d = $today->subDays($i);
            $bars[$d->format('Y-m-d')] = ['label' => $i === 0 ? 'Today' : $d->format('D'), 'fils' => 0, 'today' => $i === 0];
        }
        foreach ($paid as $o) {
            $key = StoreTime::display($o->created_at)?->format('Y-m-d');
            if ($key !== null && isset($bars[$key])) {
                $bars[$key]['fils'] += (int) $o->total;
            }
        }

        $statusCounts = DB::table('orders')->whereNull('deleted_at')->whereIn('status', ['pending', 'processing', 'onhold'])
            ->groupBy('status')->selectRaw('status as s, COUNT(*) as n')->pluck('n', 's')->map(fn ($n) => (int) $n);

        $views = (int) DB::table('product_view_days')->where('day', '>=', $today->utc()->toDateString())->sum('views');
        $carts = (int) DB::table('carts')->where('created_at', '>=', $today->utc())->count();
        $monthStart = $now->startOfMonth()->utc();

        return response()->json([
            'ok' => true,
            'updated_at' => $now->toIso8601String(),
            'date_label' => $now->format('l, j F'),
            'month_label' => $now->format('F'),
            'money' => $money,
            'currency' => Money::currency(),
            'figs' => $money ? ['total' => self::fig($total), 'gross' => self::fig($gross), 'net' => self::fig($net)] : null,
            'delta_pct' => $money && $lastWeek > 0 ? (int) round(($total - $lastWeek) / $lastWeek * 100) : null,
            'paid_orders' => $todayRows->count(),
            'product_views' => $views,
            'conversion' => $carts > 0 ? round($todayRows->count() / $carts * 100, 1) : null,
            'bars' => array_values(array_map(fn ($b) => [
                'label' => $b['label'],
                'today' => $b['today'],
                'value' => $money ? round(Money::toMajor($b['fils']), 2) : null,
            ], $bars)),
            'counts' => $statusCounts,
            'needs' => $this->needs($statusCounts->all(), $catalog),
            'tiles' => $this->tiles($monthStart, $money),
            'top' => $this->top($monthStart, $money),
        ]);
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

    /** @return list<array<string,mixed>> */
    private function top($monthStart, bool $money): array
    {
        $rows = DB::table('order_items as i')->join('orders as o', 'o.id', '=', 'i.order_id')
            ->leftJoin('products as p', 'p.id', '=', 'i.product_id')
            ->whereNull('o.deleted_at')->whereIn('o.status', Order::REAL_STATUSES)->where('o.created_at', '>=', $monthStart)
            ->groupBy('i.product_id', 'i.name', 'p.image')
            ->orderByRaw('SUM(i.quantity) DESC')->orderBy('i.name')->orderBy('i.product_id')->limit(4)
            ->selectRaw('i.product_id as id, i.name as name, p.image as image, SUM(i.quantity) as qty, SUM(i.total) as sales')
            ->get();
        $max = max(1, (int) $rows->max('qty'));

        return $rows->map(fn ($t) => [
            'id' => $t->id === null ? null : (int) $t->id,
            'name' => (string) $t->name,
            'thumb' => OrdersController::thumb($t->image),
            'qty' => (int) $t->qty,
            'pct' => (int) round(((int) $t->qty) / $max * 100),
            'sales_display' => $money ? Money::plain((int) $t->sales) : null,
        ])->values()->all();
    }

    /** "1,284" — the hero figure without its currency, which the design prints small beside it. */
    private static function fig(int $fils): string
    {
        $major = Money::toMajor($fils);

        return number_format($major, fmod($major, 1.0) === 0.0 ? 0 : 2);
    }

    private static function short(string $name, int $max = 22): string
    {
        $name = trim($name);

        return mb_strlen($name) > $max ? rtrim(mb_substr($name, 0, $max - 1)).'…' : $name;
    }
}
