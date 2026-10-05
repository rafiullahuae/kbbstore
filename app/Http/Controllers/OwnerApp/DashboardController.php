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
 * "My store" (Lane MAC): today, this week or this month at a glance.
 *
 *   Total   what customers paid: orders.total over paid orders
 *   Gross   product sales before discounts: orders.subtotal
 *   Net     Total less shipping, fees, VAT and refunds — what the products
 *           actually earned
 *
 * Paid orders are Order::REAL_STATUSES, the definition every revenue figure in
 * the admin uses. Money is shown only to a member holding analytics.view, the
 * capability that guards revenue in the console; everyone with orders.view
 * gets the counts.
 *
 * "Product views" are the shop's own beacon counts (product_view_days), and
 * "Carts" the baskets started in the range; conversion is paid orders over
 * carts. There is no visitor counter in this shop to quote, and the app says
 * so rather than inventing one.
 *
 * A fixed number of queries whatever the range or the size of the shop.
 */
final class DashboardController extends Controller
{
    use Concerns;

    public const RANGES = ['today', 'week', 'month'];

    public function __invoke(Request $request): JsonResponse
    {
        if ($r = $this->refuse($request, 'orders.view')) {
            return $r;
        }

        $range = in_array($request->query('range'), self::RANGES, true) ? (string) $request->query('range') : 'today';
        $now = StoreTime::now();
        $start = match ($range) {
            'week' => $now->startOfWeek(),
            'month' => $now->startOfMonth(),
            default => $now->startOfDay(),
        };
        $fromUtc = $start->utc();
        $money = $this->may($request, 'analytics.view');

        $paid = DB::table('orders')->whereNull('deleted_at')->whereIn('status', Order::REAL_STATUSES)
            ->where('created_at', '>=', $fromUtc)
            ->get(['created_at', 'total', 'subtotal', 'shipping_total', 'fee_total', 'tax_total']);

        $refunded = (int) DB::table('refunds')->where('created_at', '>=', $fromUtc)
            ->whereIn('status', \App\Services\Payments\PaymentRefunder::COUNTED)->sum('amount');

        $total = (int) $paid->sum('total');
        $gross = (int) $paid->sum('subtotal');
        $net = max(0, $total - (int) $paid->sum('shipping_total') - (int) $paid->sum('fee_total') - (int) $paid->sum('tax_total') - $refunded);

        // The chart: by hour for today, by day for a week or a month.
        $buckets = [];
        if ($range === 'today') {
            for ($h = 0; $h < 24; $h++) {
                $buckets[sprintf('%02d', $h)] = 0;
            }
        } else {
            for ($d = $start; $d->lte($now); $d = $d->addDay()) {
                $buckets[$d->format('Y-m-d')] = 0;
            }
        }
        foreach ($paid as $o) {
            $at = StoreTime::display($o->created_at);
            $key = $range === 'today' ? $at?->format('H') : $at?->format('Y-m-d');
            if ($key !== null && array_key_exists($key, $buckets)) {
                $buckets[$key] += (int) $o->total;
            }
        }

        $counts = DB::table('orders')->whereNull('deleted_at')->whereIn('status', ['pending', 'processing', 'onhold', 'shipped'])
            ->groupBy('status')->selectRaw('status as s, COUNT(*) as n')->pluck('n', 's')->map(fn ($n) => (int) $n);

        $at = OwnerAppSettings::lowStock();
        $stock = DB::table('products')->whereNull('deleted_at')->selectRaw(
            'SUM(CASE WHEN manage_stock = 1 AND stock > 0 AND stock <= ? THEN 1 ELSE 0 END) as low_n,
             SUM(CASE WHEN stock_status = ? AND status = ? THEN 1 ELSE 0 END) as out_n',
            [$at, 'outofstock', 'publish'],
        )->first();

        $latest = DB::table('orders as o')->leftJoin('customers as c', 'c.id', '=', 'o.customer_id')
            ->whereNull('o.deleted_at')->where('o.status', '!=', 'checkout-draft')
            ->orderByDesc('o.id')->limit(5)
            ->get(['o.id', 'o.order_number', 'o.status', 'o.total', 'o.created_at', 'o.payment_method', 'o.payment_method_title', 'o.billing_address', 'c.name as c_name']);

        $top = DB::table('order_items as i')->join('orders as o', 'o.id', '=', 'i.order_id')
            ->leftJoin('products as p', 'p.id', '=', 'i.product_id')
            ->whereNull('o.deleted_at')->whereIn('o.status', Order::REAL_STATUSES)
            ->where('o.created_at', '>=', $range === 'today' ? $now->startOfMonth()->utc() : $fromUtc)
            ->groupBy('i.product_id', 'i.name', 'p.image')
            ->orderByRaw('SUM(i.quantity) DESC')->limit(5)
            ->selectRaw('i.product_id as id, i.name as name, p.image as image, SUM(i.quantity) as qty, SUM(i.total) as sales')
            ->get();

        $views = (int) DB::table('product_view_days')->where('day', '>=', $fromUtc->toDateString())->sum('views');
        $carts = (int) DB::table('carts')->where('created_at', '>=', $fromUtc)->count();

        return response()->json([
            'ok' => true,
            'range' => $range,
            'from' => $start->toIso8601String(),
            'updated_at' => $now->toIso8601String(),
            'money' => $money,
            'sales' => $money ? [
                'total' => Money::plain($total),
                'gross' => Money::plain($gross),
                'net' => Money::plain($net),
                'refunded' => $refunded > 0 ? Money::plain($refunded) : null,
            ] : null,
            'chart' => [
                'unit' => $range === 'today' ? 'hour' : 'day',
                'labels' => array_keys($buckets),
                'values' => $money ? array_map(fn ($f) => round(Money::toMajor($f), 2), array_values($buckets)) : null,
                'currency' => Money::currency(),
            ],
            'paid_orders' => $paid->count(),
            'product_views' => $views,
            'carts' => $carts,
            'conversion' => $carts > 0 ? round($paid->count() / $carts * 100, 1) : null,
            'counts' => $counts,
            'stock' => ['low' => (int) ($stock->low_n ?? 0), 'out' => (int) ($stock->out_n ?? 0), 'low_at' => $at],
            'latest' => $latest->map(fn ($o) => OrdersController::row($o))->values(),
            'top' => $top->map(fn ($t) => [
                'id' => $t->id === null ? null : (int) $t->id,
                'name' => (string) $t->name,
                'thumb' => OrdersController::thumb($t->image),
                'qty' => (int) $t->qty,
                'sales_display' => $money ? Money::plain((int) $t->sales) : null,
            ])->values(),
            'top_range' => $range === 'today' ? 'month' : $range,
        ]);
    }
}
