<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Models\Order;
use Illuminate\Http\JsonResponse;

/**
 * The "Uncaptured orders" screen's data — READ ONLY.
 *
 * Tabby AUTHORISES at checkout and pays nothing until the order is CAPTURED, and
 * Tabby voids an authorisation nobody captures after its window (default 30
 * days). This shop has no automatic Tabby capture — Tamara has the hourly
 * TamaraCaptureSweep, Tabby was never given one — so an uncaptured Tabby order
 * never pays out. This lists them so the owner can capture each one from the
 * admin (the screen's Capture button posts the existing, idempotent
 * /admin-api/orders/{id}/capture — PaymentSettlementController::capture).
 *
 * This method reads and returns; it never captures and writes no order row. It
 * is behind `orders.money` (App\Support\AdminCapabilities), the same capability
 * the capture endpoint carries, so only an account that can capture can see it.
 */
final class UncapturedOrdersController
{
    /** Statuses that are not a live, payable order. */
    private const DEAD = ['cancelled', 'refunded', 'failed', 'draft'];

    /** Tabby's default capture window, in days — advisory, for the "expired" flag. */
    private const WINDOW_DAYS = 30;

    /** A ceiling so a damaged catalogue cannot build an unbounded payload. */
    private const LIMIT = 500;

    public function index(): JsonResponse
    {
        // READ ONLY. paid_at set = authorised; captured_at null = never captured;
        // status still live. Oldest-authorised first so the ones nearest to
        // lapsing are at the top.
        $orders = Order::query()
            ->where('payment_method', 'tabby')
            ->whereNotNull('paid_at')
            ->whereNull('captured_at')
            ->whereNotIn('status', self::DEAD)
            ->orderBy('paid_at')
            // `paid_at` can tie (one busy minute, an import) and the list is
            // sliced at LIMIT, so `id` makes the order total: without it two
            // reads could hand back different orders at the cut. StableOrderingTest.
            ->orderBy('id')
            ->limit(self::LIMIT)
            ->get(['id', 'order_number', 'status', 'total', 'currency', 'paid_at']);

        $now = now();
        $rows = [];
        $capturableFils = 0;
        $expiredFils = 0;

        foreach ($orders as $o) {
            $days = $o->paid_at !== null ? (int) $o->paid_at->diffInDays($now) : 0;
            $expired = $days > self::WINDOW_DAYS;
            $rows[] = [
                'id' => (int) $o->id,
                'order_number' => (string) $o->order_number,
                'amount' => number_format(((int) $o->total) / 100, 2),
                'currency' => (string) $o->currency,
                'status' => (string) $o->status,
                'authorised_at' => $o->paid_at?->format('Y-m-d H:i'),
                'days_since_auth' => $days,
                'expired' => $expired,
            ];
            $expired ? $expiredFils += (int) $o->total : $capturableFils += (int) $o->total;
        }

        return response()->json([
            'window_days' => self::WINDOW_DAYS,
            'count' => count($rows),
            'currency' => (string) ($orders->first()->currency ?? 'AED'),
            'capturable_total' => number_format($capturableFils / 100, 2),
            'expired_total' => number_format($expiredFils / 100, 2),
            'orders' => $rows,
        ]);
    }
}
