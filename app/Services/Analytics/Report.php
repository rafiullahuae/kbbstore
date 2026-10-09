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
 * live(): the last N minutes (5, 10, 15 or 25; default 10), read from
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
            ->get(['day', 'views', 'visitors', 'sessions', 'bounces', 'carts', 'checkouts']);

        $span = (int) CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) + 1;
        $pFrom = CarbonImmutable::parse($from)->subDays($span)->format('Y-m-d');
        $pTo = CarbonImmutable::parse($from)->subDay()->format('Y-m-d');
        $prev = DB::table('an_days')->whereBetween('day', [$pFrom, $pTo])
            ->selectRaw('COALESCE(SUM(views),0) views, COALESCE(SUM(visitors),0) visitors, COALESCE(SUM(sessions),0) sessions, COALESCE(SUM(bounces),0) bounces')
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

        return [
            'from' => $from, 'to' => $to,
            'totals' => $totals + $orders,
            'previous' => ['visitors' => (int) ($prev->visitors ?? 0), 'views' => (int) ($prev->views ?? 0),
                'sessions' => (int) ($prev->sessions ?? 0), 'bounces' => (int) ($prev->bounces ?? 0)],
            'series' => $days->map(static fn ($d): array => ['day' => (string) $d->day, 'visitors' => (int) $d->visitors, 'views' => (int) $d->views])->values()->all(),
            'dims' => $dims,
            'orders_by_channel' => $byChannel,
            'orders_by_campaign' => $byCampaign,
            'search' => self::searches($from, $to),
            'google' => self::google(),
        ];
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

        $pages = DB::table('an_hits')->where('m', '>=', $wFrom)->where('k', '!=', 1)
            ->groupBy('path')->selectRaw('path, MAX(title) title, COUNT(DISTINCT v) n')
            ->orderByDesc('n')->orderBy('path')->limit(8)->get()
            ->map(static fn ($r): array => ['path' => (string) $r->path, 'title' => (string) $r->title, 'n' => (int) $r->n])->all();

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
            'active' => $active,
            'views' => $views,
            'carts' => $carts,
            'bars' => $series,
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
