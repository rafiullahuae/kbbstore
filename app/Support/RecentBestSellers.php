<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * /best-sellers/ by what sold LATELY. (2.60.660)
 *
 * The owner, 11 Nov 2026: "we want only the best sellers from last 3 months on
 * this page, around 150 products, from top to low."
 *
 * The page used to be ordered by `products.total_sales` -- a lifetime figure
 * carried over from WooCommerce -- so a product that sold well two years ago sat
 * above one selling every day now. It is now the products with the most UNITS
 * sold in real orders (Order::REAL_STATUSES, not deleted) placed in the last
 * DAYS days, highest first, at most MAX of them. Gifts are not sales.
 *
 * SPEED: one grouped statement over orders in the window (the orders table has
 * a (status, created_at) index), cached for TTL seconds -- so a shopper's page
 * costs the same queries as before. The ids are written into a CASE as
 * integers, as RepeatPurchase does, so the paginator's COUNT carries no
 * placeholders.
 *
 * A shop with no sale in the window (a fresh install, the test suite) gets an
 * empty list, and the page keeps the ordering it had -- never an empty page.
 */
final class RecentBestSellers
{
    /** "last 3 months". */
    public const DAYS = 90;

    /** "around 150 products". */
    public const MAX = 150;

    public const CACHE_KEY = 'kbb.best_sellers.recent.v1';

    /** An hour: today's orders reach the page within the hour. */
    public const TTL = 3600;

    /**
     * product id => units sold in the window, highest first, at most MAX.
     *
     * @return array<int, int>
     */
    public static function ranked(): array
    {
        $cached = Cache::remember(self::CACHE_KEY, self::TTL, static fn (): array => self::measure());

        return is_array($cached) ? $cached : [];
    }

    /**
     * Keep only the ranked products, in rank order; ends on `id` for stable
     * pages. Returns null when there is nothing ranked, so the caller keeps
     * its own ordering.
     *
     * @param  Builder<\App\Models\Product>  $query
     * @return Builder<\App\Models\Product>|null
     */
    public static function applyTo(Builder $query): ?Builder
    {
        $ranked = self::ranked();

        if ($ranked === []) {
            return null;
        }

        $table = $query->getModel()->getTable();
        $ids = array_map('intval', array_keys($ranked));
        $case = 'CASE ' . $table . '.id';
        $rank = 0;

        foreach ($ids as $id) {
            $case .= ' WHEN ' . $id . ' THEN ' . $rank++;
        }

        return $query
            ->whereIn($table . '.id', $ids)
            ->orderByRaw($case . ' ELSE ' . $rank . ' END')
            ->orderByDesc($table . '.id');
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** @return array<int, int> */
    private static function measure(): array
    {
        if (! Schema::hasTable('order_items') || ! Schema::hasTable('orders')) {
            return [];
        }

        $rows = DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            // Only products the shop still shows, so 150 ranked is ~150 on the page.
            ->join('products as p', 'p.id', '=', 'oi.product_id')
            ->where('p.status', 'publish')
            ->where('p.is_visible', true)
            ->whereIn('o.status', Order::REAL_STATUSES)
            ->where('o.created_at', '>=', now()->subDays(self::DAYS))
            ->whereNull('o.deleted_at')
            ->whereNotNull('oi.product_id')
            ->where('oi.is_gift', false)
            ->groupBy('p.id')
            ->select('p.id as product_id')
            ->selectRaw('SUM(oi.quantity) as units')
            ->orderByDesc('units')
            ->orderBy('p.id') // ties broken the same way on every run
            ->limit(self::MAX)
            ->get();

        $out = [];

        foreach ($rows as $row) {
            if ((int) $row->units > 0) {
                $out[(int) $row->product_id] = (int) $row->units;
            }
        }

        return $out;
    }
}
