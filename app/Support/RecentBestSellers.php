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
        if (self::ranked() === []) {
            return null;
        }

        $table = $query->getModel()->getTable();
        $ids = self::ids();
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

    /*
     * ── THE OWNER'S HAND ON THE LIST (2.60.661) ───────────────────────────
     * The owner, 12 Nov 2026: "allow us to adjust the position of any product
     * on this page manually with drag n drop function, and with cross icon if
     * any product we want to remove. same like on the new arrival backend".
     *
     * Two settings, read from the settings map the page already loads (no
     * query): ORDER, the ids as he arranged them, and REMOVED, the ids he
     * crossed out. The shop's list is the ranking without REMOVED, in ORDER;
     * a product that joins the ranking after he arranged it comes after his
     * arrangement, by rank, until he places it. Catalog -> Best Sellers.
     */
    public const KEY_ORDER = 'best_sellers_order';

    public const KEY_REMOVED = 'best_sellers_removed';

    /** The ids the shop shows, in the order it shows them. @return list<int> */
    public static function ids(): array
    {
        $ranked = array_map('intval', array_keys(self::ranked()));
        $removed = array_flip(self::listSetting(self::KEY_REMOVED));
        $live = array_values(array_filter($ranked, static fn (int $id): bool => ! isset($removed[$id])));
        $in = array_flip($live);
        $out = [];

        foreach (self::listSetting(self::KEY_ORDER) as $id) {
            if (isset($in[$id])) {
                $out[$id] = $id;
            }
        }

        foreach ($live as $id) {
            $out[$id] = $id;
        }

        return array_values($out);
    }

    /**
     * The admin grid: the shop's list, then the crossed-out products still in
     * the ranking (grey), each with its units sold.
     *
     * @return list<array{id:int,name:string,image:string,units:int,excluded:bool,sold_out:bool,url:string}>
     */
    public static function grid(): array
    {
        $ranked = self::ranked();
        $shown = self::ids();
        $flip = array_flip($shown);
        $removed = array_values(array_filter(array_map('intval', array_keys($ranked)), static fn (int $id): bool => ! isset($flip[$id])));
        $all = array_merge($shown, $removed);

        if ($all === []) {
            return [];
        }

        $rows = \App\Models\Product::query()->whereIn('id', $all)
            ->get(['id', 'slug', 'name', 'image', 'created_at', 'stock_status'])->keyBy('id');
        $out = [];

        foreach ($all as $id) {
            if (! isset($rows[$id])) {
                continue;
            }
            $card = \App\Services\NewArrivals::card($rows[$id], ! isset($flip[$id]));
            unset($card['date']);
            $out[] = $card + ['units' => (int) ($ranked[$id] ?? 0)];
        }

        return $out;
    }

    /**
     * The grid's Save: every id on the grid in the grid's order, and the ones
     * crossed out. A removal of a product the grid did not show stays.
     *
     * @param  list<int>  $order
     * @param  list<int>  $excluded
     */
    public static function save(array $order, array $excluded): void
    {
        $order = array_values(array_unique(array_filter(array_map('intval', $order), static fn (int $i): bool => $i > 0)));
        $onGrid = array_flip($order);
        $marked = array_values(array_intersect(array_unique(array_map('intval', $excluded)), $order));
        $keep = array_values(array_filter(self::listSetting(self::KEY_REMOVED), static fn (int $id): bool => ! isset($onGrid[$id])));
        $flip = array_flip($marked);
        $settings = app(\App\Services\SettingsService::class);

        $settings->set(self::KEY_REMOVED, array_values(array_unique(array_merge($keep, $marked))));
        $settings->set(self::KEY_ORDER, array_values(array_filter($order, static fn (int $id): bool => ! isset($flip[$id]))));
    }

    /** Back to the plain ranking: no arrangement, nothing removed. */
    public static function reset(): void
    {
        $settings = app(\App\Services\SettingsService::class);
        $settings->set(self::KEY_ORDER, []);
        $settings->set(self::KEY_REMOVED, []);
    }

    /** @return list<int> a stored list of ids, whatever shape it was saved in */
    private static function listSetting(string $key): array
    {
        $raw = app(\App\Services\SettingsService::class)->get($key, []);

        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        return is_array($raw)
            ? array_values(array_filter(array_map('intval', $raw), static fn (int $i): bool => $i > 0))
            : [];
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
