<?php

declare(strict_types=1);

namespace App\Services\OwnerApp;

use App\Http\Controllers\Admin\AdminController;
use App\Models\Order;
use App\Support\AnalyticsRange;
use App\Support\DemoSeed;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * My store's sales figures and top sellers (Lane OA4) — the SAME numbers as
 * Store → Analytics, for the same period, from the same building blocks.
 *
 * THE DEFECT THIS REPLACES. The hero's three chips were defined on their own:
 * "Gross" was orders.subtotal (product prices before discount, WITHOUT
 * shipping) and "Net" was the total less shipping, fees, VAT and the refunds
 * made TODAY (by refund date). So on the seeded shop "Gross" (AED 569) read
 * LOWER than "Total" (AED 605); neither matched any figure the admin shows;
 * a refund for last week's order was taken off today; demo orders counted;
 * and the bars and the "vs last week" pill stayed on Total whichever chip was
 * chosen. Now, for the chosen range:
 *
 *   Gross  orders.total over Order::REAL_STATUSES, demo orders excluded —
 *          Analytics' "Gross before refunds" (gross_revenue_aed)
 *   Total  Gross less the refunds OF THOSE ORDERS (AdminController::
 *          countedRefunds(), keyed on the order's date) — Analytics' headline
 *          "Net revenue" (revenue_total_aed). What the shop actually kept.
 *   Net    Total less the VAT inside those orders — Analytics' "VAT
 *          collected" (tax_collected_aed) taken off its Net revenue: what is
 *          left after refunds AND the tax owed onward.
 *
 * so Gross ≥ Total ≥ Net always, and the bars and the comparison follow the
 * chip. OwnerAppSalesTest holds the three figures equal to /admin-api/analytics
 * for the same period.
 *
 * RANGES. Today, Yesterday, Last 7 days, This month, Last month — each an
 * AnalyticsRange (today / month natively, the rest as its custom window), so
 * the boundaries, the shop's time zone and the bars (hourly up to two days,
 * daily beyond) are the Analytics screen's own. No custom range: two date
 * pickers on a phone for a figure the admin already answers is not light.
 *
 * COST. Four statements whatever the shop's size — the paid orders grouped by
 * stored hour, their refunds grouped the same way, and one aggregate each for
 * the comparison window — cached for CACHE_SECONDS per range and per what the
 * member may see. Top sellers: one GROUP BY, cached the same way.
 */
final class OwnerAppSales
{
    public const RANGES = ['today' => 'Today', 'yesterday' => 'Yesterday', '7d' => 'Last 7 days', 'month' => 'This month', 'last_month' => 'Last month'];

    public const TOP_PERIODS = ['7d' => '7 days', 'month' => 'This month'];

    public const CACHE_SECONDS = 30;

    /** Bumped on every order write (OwnerAppServiceProvider), so a new sale is never hidden behind the cache. */
    public const VERSION_KEY = 'oa.sales.v';

    public static function rangeKey(mixed $key): string
    {
        return is_string($key) && isset(self::RANGES[$key]) ? $key : 'today';
    }

    public static function topKey(mixed $key): string
    {
        return is_string($key) && isset(self::TOP_PERIODS[$key]) ? $key : 'month';
    }

    /** The period as the Analytics screen resolves it. */
    public static function range(string $key): AnalyticsRange
    {
        $today = CarbonImmutable::now(AnalyticsRange::timezone())->startOfDay();
        $d = fn (CarbonImmutable $c) => $c->format('Y-m-d');

        return match ($key) {
            'yesterday' => AnalyticsRange::resolve('custom', $d($today->subDay()), $d($today->subDay())),
            '7d' => AnalyticsRange::resolve('custom', $d($today->subDays(6)), $d($today)),
            'month' => AnalyticsRange::resolve('month'),
            'last_month' => AnalyticsRange::resolve('custom', $d($today->subMonthNoOverflow()->startOfMonth()), $d($today->startOfMonth()->subDay())),
            default => AnalyticsRange::resolve('today'),
        };
    }

    private static function version(): int
    {
        return (int) Cache::get(self::VERSION_KEY, 0);
    }

    public static function bump(): void
    {
        try {
            Cache::forever(self::VERSION_KEY, self::version() + 1);
        } catch (\Throwable) {
        }
    }

    /**
     * The hero for one range. $money false: counts only (no analytics.view).
     * $grossNet false: Total alone — Gross and Net are not computed into the
     * answer at all (Customise app → "Show Gross and Net revenue", off by default).
     *
     * @return array<string,mixed>
     */
    public static function sales(string $key, bool $money, bool $grossNet): array
    {
        $key = self::rangeKey($key);
        $grossNet = $grossNet && $money;

        return Cache::remember('oa.sales.'.self::version().'.'.$key.'.'.(int) $money.(int) $grossNet, self::CACHE_SECONDS,
            fn () => self::compute($key, $money, $grossNet));
    }

    /** @return array<string,mixed> */
    private static function compute(string $key, bool $money, bool $grossNet): array
    {
        $range = self::range($key);
        $hour = 'substr(orders.created_at, 1, 13)';

        $paid = $range->apply(DemoSeed::exclude(Order::query()->whereIn('status', Order::REAL_STATUSES), Order::class));
        $byHour = (clone $paid)->from('orders')->groupByRaw($hour)
            ->selectRaw($hour.' as bucket_hour, COUNT(*) as n, COALESCE(SUM(orders.total), 0) as g, COALESCE(SUM(orders.tax_total), 0) as t')
            ->toBase()->get();
        $refByHour = $range->apply(AdminController::countedRefunds(), 'orders.created_at')->groupByRaw($hour)
            ->selectRaw($hour.' as bucket_hour, COALESCE(SUM(refunds.amount), 0) as r')->get();

        $buckets = $range->buckets();
        $acc = array_fill_keys(array_keys($buckets), ['g' => 0, 't' => 0, 'r' => 0]);
        $n = 0;
        foreach ($byHour as $row) {
            $n += (int) $row->n;
            if (($k = $range->bucketKeyForStoredHour((string) $row->bucket_hour)) !== null && isset($acc[$k])) {
                $acc[$k]['g'] += (int) $row->g;
                $acc[$k]['t'] += (int) $row->t;
            }
        }
        foreach ($refByHour as $row) {
            if (($k = $range->bucketKeyForStoredHour((string) $row->bucket_hour)) !== null && isset($acc[$k])) {
                $acc[$k]['r'] += (int) $row->r;
            }
        }
        $sum = ['g' => array_sum(array_column($acc, 'g')), 't' => array_sum(array_column($acc, 't')), 'r' => array_sum(array_column($acc, 'r'))];

        [$prevFrom, $prevTo, $vs] = self::previous($key, $range);
        $prev = null;
        if ($money) {
            $storage = AnalyticsRange::storageTimezone();
            $window = fn ($q, string $col) => $q->where($col, '>=', $prevFrom->setTimezone($storage))->where($col, '<', $prevTo->setTimezone($storage));
            $p = $window(DemoSeed::exclude(Order::query()->whereIn('status', Order::REAL_STATUSES), Order::class), 'created_at')
                ->toBase()->selectRaw('COALESCE(SUM(total), 0) as g, COALESCE(SUM(tax_total), 0) as t')->first();
            $pr = (int) $window(AdminController::countedRefunds(), 'orders.created_at')->sum('refunds.amount');
            $prev = ['g' => (int) ($p->g ?? 0), 't' => (int) ($p->t ?? 0), 'r' => $pr];
        }

        $measures = $grossNet ? ['total', 'gross', 'net'] : ['total'];
        $now = CarbonImmutable::now(AnalyticsRange::timezone());
        $hourly = $range->bucket() === 'hour';
        $count = count($buckets);
        $bars = [];
        $current = null;
        foreach (array_values($buckets) as $i => $b) {
            $at = CarbonImmutable::parse($b['start'], AnalyticsRange::timezone());
            $end = $hourly ? $at->addHour() : $at->addDay();
            if ($now->gte($at) && $now->lt($end)) {
                $current = $i;
            }
            $v = $acc[$b['key']];
            $bar = ['label' => $hourly ? $at->format('ga') : ($count <= 7 ? ($i === $count - 1 && $key === '7d' ? 'Today' : $at->format('D')) : $at->format('j')), 'future' => (bool) $b['future']];
            if ($money) {
                foreach ($measures as $m) {
                    $bar[$m] = round(Money::toMajor(self::measure($m, $v)), 2);
                }
            }
            $bars[] = $bar;
        }

        $figs = null;
        $delta = null;
        if ($money) {
            $figs = [];
            $delta = [];
            foreach ($measures as $m) {
                $figs[$m] = self::fig(self::measure($m, $sum));
                $was = self::measure($m, $prev);
                $delta[$m] = $was > 0 ? (int) round((self::measure($m, $sum) - $was) / $was * 100) : null;
            }
        }

        // The bar that carries its figure: the one we are in now, else the best one.
        $mark = $current;
        if ($mark === null && $money) {
            $best = -1.0;
            foreach ($bars as $i => $b) {
                if ($b['total'] > $best) {
                    $best = $b['total'];
                    $mark = $i;
                }
            }
        }

        return [
            'range' => $key,
            'range_label' => self::RANGES[$key],
            'span_label' => $range->rangeLabel(),
            'vs' => $vs,
            'hourly' => $hourly,
            'figs' => $figs,
            'delta' => $delta,
            'paid_orders' => $n,
            'bars' => $bars,
            'mark' => $mark,
            'views_from' => $range->fromDate(),
            'views_to' => $range->toDate(),
        ];
    }

    /** Fils for one measure of an accumulator {g, t, r}. */
    private static function measure(string $m, ?array $v): int
    {
        if ($v === null) {
            return 0;
        }

        return match ($m) {
            'gross' => $v['g'],
            'net' => max(0, $v['g'] - $v['r'] - $v['t']),
            default => max(0, $v['g'] - $v['r']),
        };
    }

    /**
     * The window the pill compares with: today and yesterday against the same
     * weekday a week before (today up to this minute), seven days against the
     * seven before, this month against last month up to the same day, last
     * month against the month before it.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: string}
     */
    private static function previous(string $key, AnalyticsRange $range): array
    {
        $tz = AnalyticsRange::timezone();
        $now = CarbonImmutable::now($tz);
        $today = $now->startOfDay();

        return match ($key) {
            'yesterday' => [$today->subDays(8), $today->subDays(7), 'vs '.$today->subDays(8)->format('l').' the week before'],
            '7d' => [$today->subDays(13), $today->subDays(6), 'vs the 7 days before'],
            'month' => [$today->startOfMonth()->subMonthNoOverflow(), $now->subMonthNoOverflow(), 'vs '.$today->subMonthNoOverflow()->format('F').' to date'],
            'last_month' => [$today->startOfMonth()->subMonthsNoOverflow(2), $today->startOfMonth()->subMonthNoOverflow(), 'vs '.$today->startOfMonth()->subMonthsNoOverflow(2)->format('F')],
            default => [$today->subDays(7), $now->subDays(7), 'vs last '.$today->format('l')],
        };
    }

    /**
     * Top sellers for 7 days or this month: ranked by units, net sales (the
     * lines' own totals) beside each. Demo orders excluded, as on Analytics. A
     * product with no sale in the period has no row to group, so it is never
     * listed. One statement, cached.
     *
     * @return list<array<string,mixed>>
     */
    public static function top(string $key, bool $money): array
    {
        $key = self::topKey($key);

        return Cache::remember('oa.top.'.self::version().'.'.$key.'.'.(int) $money, self::CACHE_SECONDS, function () use ($key, $money) {
            $range = self::range($key);
            $rows = $range->apply(DemoSeed::exclude(
                \App\Models\OrderItem::query()->from('order_items as i')->join('orders', 'orders.id', '=', 'i.order_id')
                    ->leftJoin('products as p', 'p.id', '=', 'i.product_id')
                    ->whereNull('orders.deleted_at')->whereIn('orders.status', Order::REAL_STATUSES),
                Order::class,
                'orders',
            ), 'orders.created_at')
                ->groupBy('i.product_id', 'i.name', 'p.image')
                ->orderByRaw('SUM(i.quantity) DESC')->orderBy('i.name')->orderBy('i.product_id')->limit(4)
                ->selectRaw('i.product_id as id, i.name as name, p.image as image, SUM(i.quantity) as qty, SUM(i.total) as sales')
                ->toBase()->get();
            $max = max(1, (int) $rows->max('qty'));

            return $rows->filter(fn ($t) => (int) $t->qty > 0)->map(fn ($t) => [
                'id' => $t->id === null ? null : (int) $t->id,
                'name' => (string) $t->name,
                'thumb' => \App\Http\Controllers\OwnerApp\OrdersController::thumb($t->image),
                'qty' => (int) $t->qty,
                'pct' => (int) round(((int) $t->qty) / $max * 100),
                'sales_display' => $money ? Money::plain((int) $t->sales) : null,
            ])->values()->all();
        });
    }

    private static function fig(int $fils): string
    {
        $major = Money::toMajor($fils);

        return number_format($major, fmod($major, 1.0) === 0.0 ? 0 : 2);
    }
}
