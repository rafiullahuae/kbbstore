<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\OwnerApp\OwnerAppSales;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Dashboard -> Revenue (Lane REV). The owner, 24 October: "in the dashboard,
 * and in the owner app dashboard, i need the total revenue as This month, last
 * 30 days, Month wise selection and custom date, and also year wise".
 *
 * ONE DEFINITION FOR BOTH SCREENS: the owner app's own Total
 * (OwnerAppSales::sales) -- paid orders (Order::REAL_STATUSES, demo orders
 * left out) less the refunds counted against them, in the shop's timezone,
 * the Analytics screen's figure for the same days. The range is checked by
 * OwnerAppSales::rangeKey(); an unknown one is today. Cached 30 seconds and
 * bumped by every order write, so a new sale is never hidden. analytics.view
 * (AdminCapabilities) guards the route.
 */
final class RevenueReportController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $key = OwnerAppSales::rangeKey($request->query('range', 'month'));
        $s = OwnerAppSales::sales($key, true, false);
        $total = (int) ($s['total_fils'] ?? 0);
        $orders = (int) ($s['paid_orders'] ?? 0);

        return response()->json([
            'ok' => true,
            'range' => $key,
            'label' => $s['range_label'],
            'span' => $s['span_label'],
            'currency' => Money::currency(),
            'total' => $s['figs']['total'] ?? '0',
            'orders' => $orders,
            'average' => $orders > 0 ? Money::plain(intdiv($total, $orders)) : null,
            'delta' => $s['delta']['total'] ?? null,
            'vs' => $s['vs'],
            'bars' => array_map(static fn (array $b) => ['label' => $b['label'], 'value' => $b['future'] ? null : (float) ($b['total'] ?? 0)], $s['bars']),
        ]);
    }
}
