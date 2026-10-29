<?php

declare(strict_types=1);

namespace App\Services\CartTracking;

use App\Services\FreeGifts;
use App\Support\StoreTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How often shoppers use the free gift box's controls.        (2.60.627)
 *
 * The owner, 29 October 2026: "I want to track the view all and Gift criteria
 * buttons ... to know what people like ... a report page day, weekly or
 * monthly wise ... and these tracking numbers will be shown ... in each cart.
 * ... i don't want to compromise on speed."
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHAT IT COSTS
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Nothing on any page. The taps are Cart Tracking's own (trail.js codes
 * 60-74), so they travel in the beacon a basket-holder's page already sends
 * and land in CartTrail::client() with the rest of the batch. For a batch that
 * holds gift taps, record() adds:
 *   - one indexed SELECT of this cart's gift rows today (cart_trail's own
 *     cart_id index), to know whether a tap is the cart's first of its kind
 *     today -- the "carts" column, people rather than taps;
 *   - one UPSERT into gift_clicks, one row per day x control x place x range;
 *   - no write of its own on the cart: the per-cart counts ride the UPDATE
 *     CartTrail::summarise() already makes (carts.ct_gifts).
 * A batch with no gift tap costs nothing extra at all.
 *
 * The report reads gift_clicks -- at most a few hundred rows a month however
 * busy the shop gets, so a year costs what a week costs -- plus ONE grouped
 * count over carts for "went on to order". Cart Tracking's own pruning never
 * touches gift_clicks, so the report keeps every day.
 *
 * WHAT IS RECORDED: which control, where (bag panel, cart page, checkout) and,
 * for a range, which range -- by id, turned into its label HERE from the
 * shop's own ranges, never text from the browser. Nothing typed, nothing that
 * identifies anybody beyond the cart Cart Tracking already keeps.
 */
final class GiftClicks
{
    /** The five controls, in trail.js giftAction() order: [key, what the owner reads]. */
    public const ACTIONS = [
        ['va', 'View all'],
        ['hd', 'Hide'],
        ['cr', 'Gifts Criteria'],
        ['rg', 'Range tapped'],
        ['ph', 'Gift photo tapped'],
    ];

    /** Where the box was: [first code, key, what the owner reads]. */
    public const PLACES = [
        [60, 'bag', 'Bag panel'],
        [65, 'cart', 'Cart page'],
        [70, 'checkout', 'Checkout'],
    ];

    public const FIRST = 60;

    public const LAST = 74;

    /** The report's groupings, and the longest period it will read in one go. */
    public const BY = ['day', 'week', 'month'];

    public const MAX_DAYS = 400;

    private static ?bool $ready = null;

    public static function isGift(int $code): bool
    {
        return $code >= self::FIRST && $code <= self::LAST;
    }

    /** 0..4: View all, Hide, Gifts Criteria, range, photo. */
    public static function action(int $code): int
    {
        return ($code - self::FIRST) % 5;
    }

    /** 0..2: bag panel, cart page, checkout. */
    public static function place(int $code): int
    {
        return intdiv($code - self::FIRST, 5);
    }

    public static function isRange(int $code): bool
    {
        return self::isGift($code) && self::action($code) === 3;
    }

    /** The CartTrail::CODES rows for 60-74: [title, stage, kind]. */
    public static function codes(): array
    {
        $out = [];

        foreach (self::PLACES as $p => [$first, $kind, $where]) {
            foreach (self::ACTIONS as $a => [, $label]) {
                $out[$first + $a] = ['Gift box: ' . $label . ' (' . strtolower($where) . ')', $p === 2 ? 3 : 2, $kind];
            }
        }

        return $out;
    }

    /**
     * "r12" -> "AED 500 – 999", from the shop's own ranges (the settings memo:
     * no query). Null for anything that is not a range today.
     */
    public static function rangeLabel(string $detail): ?string
    {
        if (preg_match('/^r(\d{1,9})$/', $detail, $m) !== 1) {
            return null;
        }

        try {
            foreach (app(FreeGifts::class)->ranges() as $r) {
                if ((int) $r['id'] === (int) $m[1]) {
                    return mb_substr(FreeGifts::rangeLabel($r), 0, 40);
                }
            }
        } catch (\Throwable) {
        }

        return null;
    }

    /** Whether the migration has run: a package's files land seconds before it does. */
    public static function ready(): bool
    {
        try {
            return self::$ready ??= Schema::hasTable('gift_clicks') && Schema::hasColumn('carts', 'ct_gifts');
        } catch (\Throwable) {
            return false;
        }
    }

    public static function forgetReady(): void
    {
        self::$ready = null;
    }

    /**
     * Count one batch's gift taps: the day's totals, and the cart's own five
     * counts to write with the cart's UPDATE. Never throws.
     *
     * @param  list<array{code:int,path:?string}>  $taps
     * @return string|null the cart's new ct_gifts, or null to leave it alone
     */
    public static function record(int $cartId, ?string $current, array $taps): ?string
    {
        // No schema check here: it would be queries on every batch. Before the
        // migration has run the upsert throws, the catch returns null, and the
        // cart's UPDATE goes out without ct_gifts.
        if ($taps === []) {
            return null;
        }

        try {
            $day = StoreTime::today();
            $key = $day->format('Y-m-d');

            // What this cart already did today: its first tap of a kind is a "cart".
            $seen = [];
            foreach (DB::table('cart_trail')->where('cart_id', $cartId)->whereBetween('code', [self::FIRST, self::LAST])
                ->where('created_at', '>=', StoreTime::startOfDayUtc($day))->get(['code', 'path']) as $r) {
                $seen[(int) $r->code . '|' . (self::isRange((int) $r->code) ? (string) $r->path : '')] = true;
            }

            $rows = [];
            $mine = self::counts($current);

            foreach ($taps as $t) {
                $label = self::isRange($t['code']) ? (string) ($t['path'] ?? '') : '';
                $k = $t['code'] . '|' . $label;
                $rows[$k] ??= ['day' => $key, 'code' => $t['code'], 'label' => $label, 'clicks' => 0, 'carts' => isset($seen[$k]) ? 0 : 1];
                $rows[$k]['clicks']++;
                $mine[self::action($t['code'])]++;
            }

            $driver = DB::connection()->getDriverName();
            $inc = static fn (string $c) => DB::raw($driver === 'mysql' ? "`$c` + VALUES(`$c`)" : "\"$c\" + excluded.\"$c\"");
            DB::table('gift_clicks')->upsert(array_values($rows), ['day', 'code', 'label'], ['clicks' => $inc('clicks'), 'carts' => $inc('carts')]);

            return implode(',', array_map(static fn (int $n) => (string) min($n, 99999), $mine));
        } catch (\Throwable) {
            return null;
        }
    }

    /** "2,0,1,3,1" -> [2,0,1,3,1]; anything else -> five zeros. */
    public static function counts(?string $stored): array
    {
        $out = [0, 0, 0, 0, 0];

        if ($stored !== null && preg_match('/^\d{1,5}(,\d{1,5}){4}$/', $stored) === 1) {
            foreach (explode(',', $stored) as $i => $n) {
                $out[$i] = (int) $n;
            }
        }

        return $out;
    }

    /**
     * One cart's counts for the Carts row and the owner app, from the column
     * already selected with it: no query. Null when the cart never touched
     * the box.
     *
     * @return array{view_all:int,hide:int,criteria:int,ranges:int,photos:int,total:int}|null
     */
    public static function forCart(?string $stored): ?array
    {
        $c = self::counts($stored);

        if (array_sum($c) === 0) {
            return null;
        }

        return ['view_all' => $c[0], 'hide' => $c[1], 'criteria' => $c[2], 'ranges' => $c[3], 'photos' => $c[4], 'total' => array_sum($c)];
    }

    /**
     * The report: totals, one bucket per day / week / month, the split by
     * place, the ranges looked at most, and how many carts that used the box
     * went on to order against the carts that did not.
     *
     * @return array<string, mixed>
     */
    public static function report(string $from, string $to, string $by): array
    {
        $tz = StoreTime::timezone();
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $from, $tz);
        $end = CarbonImmutable::createFromFormat('!Y-m-d', $to, $tz);

        if ($start === false || $end === false) {
            $end = StoreTime::today();
            $start = $end->subDays(6);
        }

        if ($end->lt($start)) {
            [$start, $end] = [$end, $start];
        }

        if ($start->diffInDays($end) > self::MAX_DAYS) {
            $start = $end->subDays(self::MAX_DAYS);
        }

        $by = in_array($by, self::BY, true) ? $by : 'day';
        $actions = array_map(static fn (array $a) => $a[1], self::ACTIONS);
        $empty = ['from' => $start->format('Y-m-d'), 'to' => $end->format('Y-m-d'), 'by' => $by, 'actions' => $actions,
            'places' => array_map(static fn (array $p) => $p[2], self::PLACES), 'ready' => self::ready(), 'today' => StoreTime::today()->format('Y-m-d')];

        $rows = collect();

        if (self::ready()) {
            try {
                $rows = DB::table('gift_clicks')->whereBetween('day', [$start->format('Y-m-d'), $end->format('Y-m-d')])
                    ->get(['day', 'code', 'label', 'clicks', 'carts']);
            } catch (\Throwable) {
            }
        }

        // Buckets: every day / week / month in the period, empty ones included.
        $buckets = [];
        $bucketOf = static function (CarbonImmutable $d) use ($by): array {
            return match ($by) {
                'week' => [$d->startOfWeek(CarbonImmutable::MONDAY)->format('Y-m-d'), 'Week of ' . $d->startOfWeek(CarbonImmutable::MONDAY)->format('j M')],
                'month' => [$d->format('Y-m'), $d->format('M Y')],
                default => [$d->format('Y-m-d'), $d->format('D j M')],
            };
        };

        for ($d = $start; $d->lte($end); $d = $d->addDay()) {
            [$k, $label] = $bucketOf($d);
            $buckets[$k] ??= ['key' => $k, 'label' => $label, 'clicks' => array_fill(0, 5, 0), 'total' => 0];
        }

        $totals = array_fill(0, 5, 0);
        $carts = array_fill(0, 5, 0);
        $places = array_fill(0, 3, array_fill(0, 5, 0));
        $ranges = [];

        foreach ($rows as $r) {
            $code = (int) $r->code;

            if (! self::isGift($code)) {
                continue;
            }

            $a = self::action($code);
            $n = (int) $r->clicks;
            $day = CarbonImmutable::createFromFormat('!Y-m-d', substr((string) $r->day, 0, 10), $tz);
            [$k] = $bucketOf($day);

            if (isset($buckets[$k])) {
                $buckets[$k]['clicks'][$a] += $n;
                $buckets[$k]['total'] += $n;
            }

            $totals[$a] += $n;
            $carts[$a] += (int) $r->carts;
            $places[self::place($code)][$a] += $n;

            if ($a === 3 && $r->label !== '') {
                $ranges[$r->label] = ($ranges[$r->label] ?? 0) + $n;
            }
        }

        arsort($ranges);

        return $empty + [
            'totals' => $totals,
            'carts' => $carts,
            'total' => array_sum($totals),
            'buckets' => array_values($buckets),
            'by_place' => $places,
            'ranges' => array_map(static fn ($label, $n) => ['label' => $label, 'clicks' => $n], array_keys($ranges), array_values($ranges)),
            'orders' => self::orders($start, $end),
        ];
    }

    /**
     * Carts last seen in the period (people, not bots): how many used the box
     * and how many of those ordered, against the rest. ONE aggregate query on
     * carts, on its ct_last_at index.
     *
     * @return array{used:int,used_ordered:int,others:int,others_ordered:int}|null
     */
    private static function orders(CarbonImmutable $start, CarbonImmutable $end): ?array
    {
        if (! self::ready()) {
            return null;
        }

        try {
            // People, not bots: Cart Tracking's own line.
            $threshold = (int) app(CartTrackingSettings::class)->get('bot_threshold');
            $r = DB::table('carts as c')
                ->where('c.ct_bot_score', '<', $threshold)
                ->where('c.ct_last_at', '>=', StoreTime::startOfDayUtc($start))
                ->where('c.ct_last_at', '<', StoreTime::startOfDayUtc($end->addDay()))
                ->selectRaw('SUM(CASE WHEN c.ct_gifts IS NOT NULL THEN 1 ELSE 0 END) AS used,'
                    . ' SUM(CASE WHEN c.ct_gifts IS NOT NULL AND ' . CartTrail::boughtSql() . ' THEN 1 ELSE 0 END) AS used_ordered,'
                    . ' SUM(CASE WHEN c.ct_gifts IS NULL THEN 1 ELSE 0 END) AS others,'
                    . ' SUM(CASE WHEN c.ct_gifts IS NULL AND ' . CartTrail::boughtSql() . ' THEN 1 ELSE 0 END) AS others_ordered')
                ->first();
        } catch (\Throwable) {
            return null;
        }

        return ['used' => (int) ($r->used ?? 0), 'used_ordered' => (int) ($r->used_ordered ?? 0),
            'others' => (int) ($r->others ?? 0), 'others_ordered' => (int) ($r->others_ordered ?? 0)];
    }
}
