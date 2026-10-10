<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Models\Order;
use App\Support\DemoSeed;
use App\Support\StoreTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the Analytics board reads.                                     (Lane AN)
 *
 * AGGREGATES ONLY. Nothing here returns a visitor or session hash, an
 * address, an email or an order's customer: counts, labels and paths.
 *
 * summary(): the range's totals, deltas against the period before, the
 *   per-day series and each dimension's top rows -- all from an_days /
 *   an_dims, plus orders by source and campaign from `orders`, on-site
 *   searches from `search_terms` and Google queries from `seo_keywords`
 *   when the SEO keyword sync has banked any. A fixed number of queries
 *   whatever the range or the catalogue (SiteAnalyticsTest pins it).
 *
 * live(): "Online now" from an_online (App\Services\Analytics\Online: who is
 *   on the shop this moment, and the pages they are reading); then the last N
 *   minutes (5, 10, 15 or 25; default 10) for the secondary figures, read from
 *   an_hits on its minute index, plus a "Happening now" feed of the last 30
 *   minutes' views, carts and orders after `since` ids. Computed per request,
 *   never cached or precomputed: nobody looking means nobody pays.
 */
final class Report
{
    public const WINDOWS = [5, 10, 15, 25];

    public const DEFAULT_WINDOW = 10;

    public const TOP = 10;

    public const DIMS = ['page', 'entry', 'channel', 'source', 'medium', 'campaign', 'referrer', 'device', 'browser', 'os', 'country', 'lang'];

    /**
     * The y-axis of the "visitors per minute" chart, as Google Analytics'
     * realtime chart draws it: 0 at the base and round steps of 1, 2 or 5
     * (times a power of ten), about four intervals, the top tick at or above
     * the tallest bar. 7 -> 0,2,4,6,8; 1 -> 0,1; 13 -> 0,5,10,15; 0 -> 0,1.
     *
     * @return list<int>
     */
    public static function ticks(int $max): array
    {
        $max = max(1, $max);
        $raw = $max / 4;
        $pow = 10 ** (int) floor(log10($raw));
        $f = $raw / $pow;
        $step = max(1, (int) round(($f <= 1 ? 1 : ($f <= 2 ? 2 : ($f <= 5 ? 5 : 10))) * $pow));
        $top = (int) (ceil($max / $step) * $step);

        return range(0, $top, $step);
    }

    public static function window(mixed $w): int
    {
        $w = is_numeric($w) ? (int) $w : 0;

        return in_array($w, self::WINDOWS, true) ? $w : self::DEFAULT_WINDOW;
    }

    /**
     * The range as two shop dates, inclusive.
     *
     * @return array{0: string, 1: string, 2: string}  from, to, key
     */
    public static function range(string $key, ?string $from = null, ?string $to = null): array
    {
        $today = StoreTime::now()->startOfDay();

        return match ($key) {
            'yesterday' => [$today->subDay()->format('Y-m-d'), $today->subDay()->format('Y-m-d'), 'yesterday'],
            '7d' => [$today->subDays(6)->format('Y-m-d'), $today->format('Y-m-d'), '7d'],
            '30d' => [$today->subDays(29)->format('Y-m-d'), $today->format('Y-m-d'), '30d'],
            '90d' => [$today->subDays(89)->format('Y-m-d'), $today->format('Y-m-d'), '90d'],
            'custom' => self::custom($from, $to, $today),
            default => [$today->format('Y-m-d'), $today->format('Y-m-d'), 'today'],
        };
    }

    private static function custom(?string $from, ?string $to, CarbonImmutable $today): array
    {
        $ok = static fn (?string $d): bool => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1 && strtotime($d) !== false;

        if (! $ok($from) || ! $ok($to)) {
            return [$today->format('Y-m-d'), $today->format('Y-m-d'), 'today'];
        }

        [$a, $b] = $from <= $to ? [$from, $to] : [$to, $from];
        // At most a year at once, and never after today.
        $b = min($b, $today->format('Y-m-d'));
        $a = max($a, CarbonImmutable::parse($b)->subDays(365)->format('Y-m-d'));

        return [$a, $b, 'custom'];
    }

    /** @return array<string, mixed> */
    public static function summary(string $from, string $to): array
    {
        $days = DB::table('an_days')->whereBetween('day', [$from, $to])->orderBy('day')
            ->get(['day', 'views', 'visitors', 'sessions', 'bounces', 'carts', 'checkouts', 'rolled_at', 'timed', 'secs', 'tvis', 'vsecs']);

        $span = (int) CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) + 1;
        $pFrom = CarbonImmutable::parse($from)->subDays($span)->format('Y-m-d');
        $pTo = CarbonImmutable::parse($from)->subDay()->format('Y-m-d');
        $prev = DB::table('an_days')->whereBetween('day', [$pFrom, $pTo])
            ->selectRaw('COALESCE(SUM(views),0) views, COALESCE(SUM(visitors),0) visitors, COALESCE(SUM(sessions),0) sessions, COALESCE(SUM(bounces),0) bounces, '
                .'COALESCE(SUM(timed),0) timed, COALESCE(SUM(secs),0) secs, COALESCE(SUM(tvis),0) tvis, COALESCE(SUM(vsecs),0) vsecs, '
                // A day summarised before time on site was recorded (Lane AT):
                // it had multi-page sessions and no timed ones.
                .'COALESCE(SUM(CASE WHEN sessions > bounces AND timed = 0 THEN 1 ELSE 0 END),0) untimed')
            ->first();

        $sum = static fn (string $f): int => (int) $days->sum($f);
        $totals = [
            'visitors' => $sum('visitors'), 'views' => $sum('views'), 'sessions' => $sum('sessions'),
            'bounces' => $sum('bounces'), 'carts' => $sum('carts'), 'checkouts' => $sum('checkouts'),
        ];

        $dims = [];
        foreach (self::DIMS as $dim) {
            $order = in_array($dim, ['page'], true) ? 'views' : 'sessions';
            $dims[$dim] = DB::table('an_dims')
                ->where('dim', $dim)->whereBetween('day', [$from, $to])
                ->groupBy('val')
                ->selectRaw('val, MAX(label) label, SUM(views) views, SUM(visitors) visitors, SUM(sessions) sessions, SUM(bounces) bounces')
                ->orderByDesc($order)->orderBy('val')->limit(self::TOP)
                ->get()
                ->map(static fn ($r): array => [
                    'val' => (string) $r->val,
                    'label' => self::labelFor($dim, (string) $r->val, (string) $r->label),
                    'views' => (int) $r->views, 'visitors' => (int) $r->visitors,
                    'sessions' => (int) $r->sessions, 'bounces' => (int) $r->bounces,
                ])->all();
        }

        [$orders, $byChannel, $byCampaign] = self::orders($from, $to);

        // Conversion rate per channel: orders / sessions that arrived by it.
        $sessionsBy = DB::table('an_dims')->where('dim', 'channel')->whereBetween('day', [$from, $to])
            ->groupBy('val')->selectRaw('val, SUM(sessions) n')->pluck('n', 'val');
        foreach ($byChannel as &$c) {
            $s = (int) ($sessionsBy[$c['key']] ?? 0);
            $c['sessions'] = $s;
            $c['rate'] = $s > 0 ? round($c['orders'] / $s * 100, 1) : null;
        }
        unset($c);

        $rolled = $days->max('rolled_at');

        return [
            'from' => $from, 'to' => $to,
            // When the newest summary row was rebuilt, for "Updated 13:59".
            'updated' => $rolled === null ? null : StoreTime::iso((string) $rolled),
            'totals' => $totals + $orders,
            'previous' => ['visitors' => (int) ($prev->visitors ?? 0), 'views' => (int) ($prev->views ?? 0),
                'sessions' => (int) ($prev->sessions ?? 0), 'bounces' => (int) ($prev->bounces ?? 0)],
            'series' => $days->map(static fn ($d): array => ['day' => (string) $d->day, 'visitors' => (int) $d->visitors, 'views' => (int) $d->views])->values()->all(),
            'dims' => $dims,
            'orders_by_channel' => $byChannel,
            'orders_by_campaign' => $byCampaign,
            'search' => self::searches($from, $to),
            'google' => self::google(),
            'engagement' => self::engagement($to, $days, $prev),
        ];
    }

    /**
     * "Time on site & engagement" (Lane AT), from what the rollup banked.
     * ONE query of its own: the time rows of an_dims, plus the channel and
     * device rows with their timed/secs, over the TIMED part of the range.
     * The rest comes from rows summary() already holds (the days and the
     * previous period's sums).
     *
     * THE TIMED PART: days summarised before this feature carry sessions but
     * no time. Every denominator here (visitors, sessions, a source's
     * sessions) runs from the first timed day, so a week that is half
     * pre-feature reads "measured for 430 of 860", not "430 of 6,511".
     *
     * WHAT IS MEASURED, AND WHAT IS NOT. A hit has a minute stamp and nothing
     * else, so time is the sum of the gaps between someone's hits, each gap
     * counted when it is 1 to Rollup::IDLE_MIN minutes. That means:
     *   - one page and nothing after it has no second stamp: not measurable,
     *     and counted as such (measured / of), never as zero;
     *   - the minutes on the LAST page are never seen, so every figure here is
     *     a floor of real time on site, the same way GA's old session time was;
     *   - each gap is whole minutes, but the error is as often up as down, so
     *     an average over many visitors is not biased by it.
     * Visitors are per shop day (the salt rotates daily), so "per visitor" is
     * per visitor per day, the same unit the Visitors tile counts.
     *
     * @param  \Illuminate\Support\Collection<int, object>  $days
     * @return array<string, mixed>
     */
    private static function engagement(string $to, $days, ?object $prev): array
    {
        $avg = static fn (int $secs, int $n): ?int => $n > 0 ? (int) round($secs / $n) : null;

        // Days in the range summarised before time was recorded: say so, and
        // say from when the figures run, rather than average them in as zero.
        $untimed = $days->filter(static fn ($d): bool => (int) $d->sessions > (int) $d->bounces && (int) $d->timed === 0);
        $firstTimed = $days->first(static fn ($d): bool => (int) $d->timed > 0 || (int) $d->tvis > 0);
        $since = $firstTimed !== null ? (string) $firstTimed->day : null;
        $timedDays = $since === null ? collect() : $days->filter(static fn ($d): bool => (string) $d->day >= $since);

        // Always the one query, timed days or none, so the board's cost is a
        // fixed count; with no timed day there is nothing in it to keep.
        $rows = DB::table('an_dims')
            ->whereIn('dim', ['tseg', 't_vis', 't_cart', 'channel', 'device'])->whereBetween('day', [$since ?? $to, $since === null ? '0000-00-00' : $to])
            ->groupBy('dim', 'val')->selectRaw('dim, val, SUM(visitors) visitors, SUM(sessions) sessions, SUM(timed) timed, SUM(secs) secs')
            ->get();

        $hist = ['t_vis' => [], 't_cart' => []];
        $seg = [];
        $per = ['channel' => [], 'device' => []];
        foreach ($rows as $r) {
            $dim = (string) $r->dim;
            if ($dim === 'tseg') {
                $seg[(string) $r->val] = $r;
            } elseif (isset($per[$dim])) {
                $per[$dim][] = ['val' => (string) $r->val, 'label' => self::labelFor($dim, (string) $r->val, ''),
                    'sessions' => (int) $r->sessions, 'timed' => (int) $r->timed, 'secs' => (int) $r->secs];
            } else {
                $hist[$dim][(int) $r->val] = (int) $r->timed;
            }
        }
        foreach ($per as &$list) {
            usort($list, static fn (array $a, array $b): int => $b['sessions'] <=> $a['sessions'] ?: strcmp($a['val'], $b['val']));
        }
        unset($list);

        $tvis = (int) $timedDays->sum('tvis');
        $timed = (int) $timedDays->sum('timed');

        $prevOk = $prev !== null && (int) ($prev->untimed ?? 1) === 0 && (int) ($prev->tvis ?? 0) > 0;

        $labels = ['chk' => 'Reached checkout', 'cart' => 'Added to cart', 'browse' => 'Browsed only'];
        $segments = [];
        foreach (Rollup::SEGMENTS as $key) {
            $r = $seg[$key] ?? null;
            $segments[] = [
                'key' => $key, 'label' => $labels[$key],
                'visitors' => (int) ($r->visitors ?? 0), 'timed' => (int) ($r->timed ?? 0),
                'avg_s' => $avg((int) ($r->secs ?? 0), (int) ($r->timed ?? 0)),
            ];
        }

        $timeOf = static fn (array $list, int $max): array => array_values(array_map(static fn (array $r): array => [
            'key' => $r['val'], 'label' => $r['label'], 'sessions' => $r['sessions'], 'timed' => $r['timed'],
            'avg_s' => $avg($r['secs'], $r['timed']),
            'engaged_pct' => $r['sessions'] > 0 ? (int) round($r['timed'] / $r['sessions'] * 100) : 0,
        ], array_slice($list, 0, $max)));

        return [
            'visitors' => (int) $timedDays->sum('visitors'),
            'visitors_timed' => $tvis,
            'visitor_avg_s' => $avg((int) $timedDays->sum('vsecs'), $tvis),
            'visitor_median_s' => self::median($hist['t_vis']),
            'sessions' => (int) $timedDays->sum('sessions'),
            'sessions_timed' => $timed,
            'session_avg_s' => $avg((int) $timedDays->sum('secs'), $timed),
            'previous' => $prevOk ? [
                'visitor_avg_s' => $avg((int) $prev->vsecs, (int) $prev->tvis),
                'session_avg_s' => $avg((int) $prev->secs, (int) $prev->timed),
            ] : null,
            'segments' => $segments,
            'to_cart' => ['n' => array_sum($hist['t_cart']), 'median_s' => self::median($hist['t_cart'])],
            'by_channel' => $timeOf($per['channel'], 5),
            'by_device' => $timeOf($per['device'], 3),
            'measured_from' => $untimed->isNotEmpty() ? $since : null,
            'untimed_days' => $untimed->count(),
            'idle_min' => Rollup::IDLE_MIN,
            'over_s' => (Rollup::HIST_MAX + 1) * 60,
        ];
    }

    /**
     * The median of a whole-minute histogram (minute => count), in seconds,
     * or null with nothing in it. The over bucket reads as HIST_MAX + 1
     * minutes, which the board prints as "over 2 h".
     *
     * @param  array<int, int>  $hist
     */
    public static function median(array $hist): ?int
    {
        $n = array_sum($hist);
        if ($n <= 0) {
            return null;
        }

        ksort($hist);
        $half = $n / 2;
        $seen = 0;
        foreach ($hist as $min => $c) {
            $seen += $c;
            if ($seen >= $half) {
                return $min * 60;
            }
        }

        return array_key_last($hist) * 60;
    }

    private static function labelFor(string $dim, string $val, string $label): string
    {
        return match ($dim) {
            'channel' => Channels::label($val),
            'lang' => $val === 'ar' ? 'Arabic' : ($val === 'en' ? 'English' : 'Other'),
            'device' => ucfirst($val),
            default => $label !== '' ? $label : $val,
        };
    }

    /**
     * Orders and revenue in the range, by channel and by campaign. Counts the
     * statuses the shop counts as sales (Order::REAL_STATUSES), demo rows out.
     *
     * @return array{0: array{orders: int, revenue_fils: int}, 1: list<array<string, mixed>>, 2: list<array<string, mixed>>}
     */
    public static function orders(string $from, string $to): array
    {
        $zone = StoreTime::timezone();
        $start = CarbonImmutable::parse($from.' 00:00:00', $zone)->setTimezone('UTC');
        $end = CarbonImmutable::parse($to.' 00:00:00', $zone)->addDay()->setTimezone('UTC');
        $demo = array_keys(DemoSeed::idsFor(Order::class));

        $base = static fn () => DB::table('orders')
            ->whereNull('deleted_at')
            ->whereIn('status', Order::REAL_STATUSES)
            ->where('created_at', '>=', $start)->where('created_at', '<', $end)
            ->when($demo !== [], static fn ($q) => $q->whereNotIn('id', $demo));

        $rows = $base()->groupBy('src_channel', 'src_campaign')
            ->selectRaw('src_channel ch, src_campaign cmp, COUNT(*) n, COALESCE(SUM(total),0) fils')
            ->get();

        $by = [];
        $camp = [];
        $orders = 0;
        $fils = 0;
        foreach ($rows as $r) {
            $key = (string) ($r->ch ?? '');
            $by[$key] ??= ['key' => $key, 'label' => Channels::label($r->ch), 'orders' => 0, 'revenue_fils' => 0];
            $by[$key]['orders'] += (int) $r->n;
            $by[$key]['revenue_fils'] += (int) $r->fils;
            $orders += (int) $r->n;
            $fils += (int) $r->fils;
            if ((string) $r->cmp !== '') {
                $ck = $key.'|'.$r->cmp;
                $camp[$ck] ??= ['campaign' => (string) $r->cmp, 'channel' => Channels::label($r->ch), 'orders' => 0, 'revenue_fils' => 0];
                $camp[$ck]['orders'] += (int) $r->n;
                $camp[$ck]['revenue_fils'] += (int) $r->fils;
            }
        }

        $sort = static fn (array $a, array $b): int => $b['revenue_fils'] <=> $a['revenue_fils'];
        $by = array_values($by);
        usort($by, $sort);
        $camp = array_values($camp);
        usort($camp, $sort);

        return [['orders' => $orders, 'revenue_fils' => $fils], $by, array_slice($camp, 0, self::TOP)];
    }

    /** @return list<array{term: string, hits: int, results: int}> */
    private static function searches(string $from, string $to): array
    {
        if (! Schema::hasTable('search_terms')) {
            return [];
        }

        return DB::table('search_terms')->whereBetween('day', [$from, $to])
            ->groupBy('term')->selectRaw('term, SUM(hits) hits, MIN(results) results')
            ->orderByDesc('hits')->orderBy('term')->limit(self::TOP)->get()
            ->map(static fn ($r): array => ['term' => mb_strtolower((string) $r->term), 'hits' => (int) $r->hits, 'results' => (int) $r->results])
            ->all();
    }

    /**
     * Google queries the SEO keyword sync banked from Search Console (last 90
     * days, as Google reports them), or connected:false for the placeholder.
     *
     * @return array{connected: bool, rows: list<array{term: string, clicks: int, impressions: int}>}
     */
    private static function google(): array
    {
        if (! Schema::hasTable('seo_keywords')) {
            return ['connected' => false, 'rows' => []];
        }

        $rows = DB::table('seo_keywords')->where('source', 'gsc')->orderByDesc('score')->orderBy('id')->limit(self::TOP)
            ->get(['term', 'metrics'])
            ->map(static function ($r): array {
                $m = json_decode((string) $r->metrics, true);

                return ['term' => (string) $r->term, 'clicks' => (int) ($m['clicks'] ?? 0), 'impressions' => (int) ($m['impressions'] ?? 0)];
            })->all();

        return ['connected' => $rows !== [], 'rows' => $rows];
    }

    /**
     * The live board for the last $window minutes, and the feed after $since.
     *
     * @return array<string, mixed>
     */
    public static function live(int $window, int $since = 0, int $orderSince = 0): array
    {
        $now = intdiv(time(), 60);
        $wFrom = $now - $window + 1;
        $barsFrom = $now - 29;

        // Who is here: one row per visitor in the window (a few hundred at most).
        $here = DB::table('an_hits')->where('m', '>=', $wFrom)->where('k', '!=', 1)
            ->groupBy('v')->selectRaw('v, MAX(dev) dev, MAX(lang) lang, MAX(cc) cc, COUNT(*) n')->get();

        $active = $here->count();
        $views = (int) $here->sum('n');
        $count = static function (iterable $rows, string $f): array {
            $out = [];
            foreach ($rows as $r) {
                $k = (string) $r->{$f};
                $out[$k] = ($out[$k] ?? 0) + 1;
            }
            arsort($out);

            return $out;
        };

        $carts = (int) DB::table('an_hits')->where('m', '>=', $wFrom)->where('k', 1)->count();

        $bars = DB::table('an_hits')->where('m', '>=', $barsFrom)->where('k', '!=', 1)
            ->groupBy('m')->selectRaw('m, COUNT(DISTINCT v) n')->pluck('n', 'm');
        $series = [];
        for ($m = $barsFrom; $m <= $now; $m++) {
            $series[] = (int) ($bars[$m] ?? 0);
        }

        // ONLINE NOW (Lane AN2): who is on the shop this moment, and what they
        // are reading -- an_online, not the window. Two tabs are one visitor.
        $online = Online::now();
        $pages = Online::pages(8);

        // Sources now: how each session in the window ARRIVED (its entry page,
        // up to two hours back), so a shopper who came from Instagram twenty
        // minutes ago still counts as Instagram.
        $src = DB::table('an_hits')->where('e', 1)->where('m', '>=', $now - 120)
            ->whereIn('s', DB::table('an_hits')->select('s')->where('m', '>=', $wFrom))
            ->groupBy('ch')->selectRaw('ch, COUNT(DISTINCT s) n')->orderByDesc('n')->orderBy('ch')->limit(8)->get()
            ->map(static fn ($r): array => ['key' => (string) $r->ch, 'label' => Channels::label($r->ch), 'n' => (int) $r->n])->all();

        $feed = DB::table('an_hits')->where('m', '>=', $now - 29)->where('id', '>', max(0, $since))
            ->orderByDesc('id')->limit(30)
            ->get(['id', 'm', 'k', 'path', 'title', 'ch', 'e', 'cc', 'dev'])
            ->map(static fn ($r): array => [
                'id' => 'h'.$r->id, 'at' => (int) $r->m * 60, 'type' => ((int) $r->k === 1 ? 'cart' : 'view'),
                'path' => (string) $r->path, 'title' => (string) $r->title,
                'source' => (int) $r->e === 1 ? Channels::label($r->ch) : '',
                'cc' => (string) $r->cc, 'dev' => (string) $r->dev,
            ])->all();

        $orders = DB::table('orders')->whereNull('deleted_at')
            ->where('created_at', '>=', now('UTC')->subMinutes(30))->where('id', '>', max(0, $orderSince))
            ->orderByDesc('id')->limit(10)
            ->get(['id', 'total', 'created_at', 'src_channel', 'src_campaign'])
            ->map(static fn ($r): array => [
                'id' => 'o'.$r->id, 'n' => (int) $r->id,
                'at' => CarbonImmutable::parse((string) $r->created_at, 'UTC')->getTimestamp(), 'type' => 'order',
                'aed' => round(((int) $r->total) / 100, 2),
                'source' => Attribution::chip($r->src_channel, $r->src_campaign),
            ])->all();

        $maxHit = 0;
        foreach ($feed as $f) {
            $maxHit = max($maxHit, (int) substr($f['id'], 1));
        }
        $maxOrder = 0;
        foreach ($orders as $o) {
            $maxOrder = max($maxOrder, $o['n']);
        }

        $devices = $count($here, 'dev');
        $langs = $count($here, 'lang');

        return [
            'window' => $window,
            'now' => $now * 60,
            'online' => $online['online'],
            'online_pages' => $online['pages'],
            // Countries need the firewall's country file (or Cloudflare's
            // header); without either the board says how to get it.
            'country_db' => is_file(\App\Services\Security\CountryDb::path()),
            'active' => $active,
            'views' => $views,
            'carts' => $carts,
            'bars' => $series,
            'bar_ticks' => self::ticks($series === [] ? 0 : max($series)),
            'mobile_pct' => $active > 0 ? (int) round(($devices['mobile'] ?? 0) / $active * 100) : 0,
            'langs' => ['en' => (int) ($langs['en'] ?? 0), 'ar' => (int) ($langs['ar'] ?? 0)],
            'countries' => array_slice(array_map(static fn ($k, $v): array => ['cc' => $k, 'n' => $v], array_keys($c = $count($here, 'cc')), $c), 0, 8),
            'pages' => $pages,
            'sources' => $src,
            'feed' => $feed,
            'orders' => $orders,
            'since' => max($since, $maxHit),
            'order_since' => max($orderSince, $maxOrder),
        ];
    }
}
