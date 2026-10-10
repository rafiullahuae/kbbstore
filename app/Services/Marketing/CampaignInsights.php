<?php

declare(strict_types=1);

namespace App\Services\Marketing;

use App\Models\Order;
use App\Support\Money;
use App\Support\StoreTime;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Marketing Emails → Reports, the full picture.                     (Lane ER)
 *
 * The owner, 10 October: "for marketing emails i need the full report like
 * how many opened, how many clicked and came to the website etc etc."
 *
 * CampaignReport (Lane MK) keeps what it computes — sends, clicks, the 7-day
 * order attribution — and this adds, per campaign and for the list:
 *
 *   delivery   sent (handed to a mail server: accepted + refused), bounced
 *              hard / soft (a refusal at sending, or a bounce report Lane EB
 *              read back for that send), delivered = sent − bounced,
 *              unsubscribed, spam complaints
 *   opens      an ESTIMATE (OpenPixel): unique opens (pixel loads that are not
 *              scanners, plus every clicker), of which Apple Mail auto-opens;
 *              open rate on delivered
 *   clicks     unique and total, click rate on delivered, click-to-open, top
 *              links, clicks per product, the device of each click
 *   website    visits and pages viewed from the campaign's UTM-tagged links
 *              (an_dims, dim `campaign`), add-to-carts and checkouts started
 *              by those visitors (dims `cmp_cart` / `cmp_chk`, Rollup), and
 *              the orders site analytics credits to the campaign (last click)
 *   timeline   opens and clicks per hour for 48 hours, then per day
 *
 * EVERY NUMBER IS AN AGGREGATE. One grouped query per figure family, never a
 * query per recipient or per campaign: the report costs the same number of
 * queries for 3 recipients or 3,000 (MarketingEmailReportTest proves it the
 * way BrandPageOwnerAsksTest does). The only part that grows is the order
 * look-up, in slices of 500 addresses, exactly as CampaignReport does it.
 */
final class CampaignInsights
{
    public const PER_PAGE = 50;

    public const TIMELINE_DAYS = 30;

    public const FILTERS = ['all', 'opened', 'not_opened', 'clicked', 'ordered', 'bounced', 'unsubscribed'];

    /** @return array<string, mixed> */
    public function campaign(int $id, ?array $base = null): array
    {
        $c = DB::table('mkt_campaigns')->where('id', $id)->first(['id', 'name', 'started_at', 'created_at']);

        if ($c === null) {
            return [];
        }

        $base ??= app(CampaignReport::class)->campaign($id) ?? [];
        $t = $this->totals([$id])[$id] ?? $this->blank();

        $clicks = DB::table('mkt_clicks as k')->join('mkt_sends as s', 's.id', '=', 'k.send_id')
            ->where('s.campaign_id', $id)->groupBy('k.dev')
            ->selectRaw('k.dev as dev, COUNT(*) as n, COUNT(DISTINCT k.send_id) as people')->get();
        $totalClicks = (int) $clicks->sum('n');
        $devices = ['mobile' => 0, 'tablet' => 0, 'desktop' => 0, 'bot' => 0, 'unknown' => 0];

        foreach ($clicks as $r) {
            $devices[isset($devices[$r->dev]) && $r->dev !== '' ? $r->dev : 'unknown'] += (int) $r->n;
        }

        $links = $this->links($id);
        $start = $c->started_at ?? $c->created_at;

        return [
            'delivery' => [
                'sent' => $t['sent'], 'delivered' => $t['delivered'], 'bounced' => $t['bounced'],
                'hard' => $t['hard'], 'soft' => $t['bounced'] - $t['hard'],
                'skipped' => $t['skipped'], 'pending' => $t['pending'],
                'unsubscribed' => $t['unsubs'], 'complaints' => (int) ($base['complaints'] ?? 0),
            ],
            'opens' => [
                'tracking' => OpenPixel::enabled(),
                'unique' => $t['opened'], 'apple' => $t['apple'], 'proxy' => $t['proxy'],
                'scanner' => $t['scanner'], 'loads' => $t['loads'],
                'rate' => self::pct($t['opened'], $t['delivered']),
                'rate_without_apple' => self::pct($t['opened'] - $t['apple'], $t['delivered']),
            ],
            'clicks' => [
                'unique' => $t['clickers'], 'total' => $totalClicks,
                'rate' => self::pct($t['clickers'], $t['delivered']),
                'cto' => self::pct($t['clickers'], $t['opened']),
                'devices' => $devices,
                'links' => array_slice($links['links'], 0, 25),
                'products' => $links['products'],
            ],
            'site' => $this->site([$id])[$id] ?? ['visits' => 0, 'views' => 0, 'visitors' => 0, 'carts' => 0, 'checkouts' => 0, 'orders' => 0, 'revenue_fils' => 0, 'revenue' => Money::plain(0)],
            'orders' => [
                'orders' => (int) ($base['orders'] ?? 0), 'revenue_fils' => (int) ($base['revenue_fils'] ?? 0),
                'revenue' => Money::plain((int) ($base['revenue_fils'] ?? 0)), 'window_days' => CampaignReport::WINDOW_DAYS,
            ],
            'utm' => CampaignLinks::utmCampaign($id, (string) $c->name),
            'timeline' => $this->timeline($id, $start),
        ];
    }

    /**
     * The list's columns for these campaigns: one grouped query for the sends,
     * one for the site, and the order attribution in slices.
     *
     * @param  list<int>  $ids
     * @return array<int, array<string, mixed>>
     */
    public function overview(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        if ($ids === []) {
            return [];
        }

        $totals = $this->totals($ids);
        $site = $this->site($ids);
        $attr = $this->attributeByCampaign($ids);
        $out = [];

        foreach ($ids as $id) {
            $t = $totals[$id] ?? $this->blank();
            $a = $attr[$id] ?? ['orders' => 0, 'revenue_fils' => 0];
            $out[$id] = [
                'delivered' => $t['delivered'], 'bounced' => $t['bounced'],
                'opened' => $t['opened'], 'apple' => $t['apple'], 'open_rate' => self::pct($t['opened'], $t['delivered']),
                'clickers' => $t['clickers'], 'click_rate' => self::pct($t['clickers'], $t['delivered']),
                'visits' => (int) ($site[$id]['visits'] ?? 0),
                'orders' => $a['orders'], 'revenue_fils' => $a['revenue_fils'], 'revenue' => Money::plain($a['revenue_fils']),
            ];
        }

        return $out;
    }

    /**
     * One page of the people a campaign went to, searched and filtered.
     *
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, pages: int, per_page: int}
     */
    public function recipients(int $id, string $q = '', string $filter = 'all', int $page = 1): array
    {
        $filter = in_array($filter, self::FILTERS, true) ? $filter : 'all';
        $ordered = $filter === 'ordered' ? array_keys($this->attributePerEmail($this->clicked([$id]))) : null;
        $query = $this->recipientQuery($id, $q, $filter, $ordered);

        $total = (int) (clone $query)->count();
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $page), $pages);

        $rows = $query->orderBy('s.id')->offset(($page - 1) * self::PER_PAGE)->limit(self::PER_PAGE)->get();

        return ['rows' => $this->describe($rows), 'total' => $total, 'page' => $page, 'pages' => $pages, 'per_page' => self::PER_PAGE];
    }

    /** Every recipient, in chunks, for the CSV. @param callable(list<array<string,mixed>>):void $each */
    public function eachRecipient(int $id, callable $each): void
    {
        $this->recipientQuery($id, '', 'all', null)->orderBy('s.id')
            ->chunk(1000, function ($rows) use ($each): void {
                $each($this->describe($rows));
            });
    }

    /* ---------------------------------------------------------------- inside */

    /** @param  list<int>|null  $ordered  addresses with an attributed order */
    private function recipientQuery(int $id, string $q, string $filter, ?array $ordered): Builder
    {
        $query = DB::table('mkt_sends as s')
            ->leftJoinSub($this->bounceSub([$id]), 'b', 'b.send_id', '=', 's.id')
            ->where('s.campaign_id', $id)
            ->select(['s.id', 's.email', 's.status', 's.error', 's.sent_at', 's.first_open_at', 's.open_count', 's.open_class',
                's.first_click_at', 's.unsubscribed_at', 'b.send_id as b_send', 'b.hard as b_hard']);

        $q = trim(mb_substr($q, 0, 100));

        if ($q !== '') {
            $query->where('s.email', 'like', '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($q)) . '%');
        }

        match ($filter) {
            'opened' => $query->where(fn ($w) => $w->whereIn('s.open_class', OpenPixel::COUNTED)->orWhereNotNull('s.first_click_at')),
            'not_opened' => $query->where('s.status', 'sent')->whereNull('s.first_click_at')
                ->where(fn ($w) => $w->whereNull('s.open_class')->orWhere('s.open_class', 'scanner')),
            'clicked' => $query->whereNotNull('s.first_click_at'),
            'ordered' => $query->whereIn(DB::raw('LOWER(TRIM(s.email))'), $ordered === [] ? ['-'] : (array) $ordered),
            'bounced' => $query->where(fn ($w) => $w->where('s.status', 'failed')->orWhereNotNull('b.send_id')),
            'unsubscribed' => $query->whereNotNull('s.unsubscribed_at'),
            default => null,
        };

        return $query;
    }

    /**
     * A page (or a chunk) of send rows as the screen shows them: two grouped
     * queries for the whole page, whatever its length.
     *
     * @return list<array<string, mixed>>
     */
    private function describe($rows): array
    {
        $ids = $rows->pluck('id')->map(fn ($v) => (int) $v)->all();

        if ($ids === []) {
            return [];
        }

        $clicks = DB::table('mkt_clicks')->whereIn('send_id', $ids)->groupBy('send_id')
            ->selectRaw('send_id, COUNT(*) as n')->pluck('n', 'send_id');
        $orders = $this->attributePerEmail($rows->filter(fn ($r) => $r->first_click_at !== null)
            ->map(fn ($r) => (object) ['email' => $r->email, 'first_click_at' => $r->first_click_at]));

        return $rows->map(function ($r) use ($clicks, $orders) {
            $bounced = $r->status === 'failed' || $r->b_send !== null;
            $hard = $bounced && ((int) ($r->b_hard ?? 0) === 1 || ($r->b_send === null && str_starts_with((string) $r->error, 'HARD ')));
            $clicked = $r->first_click_at !== null;
            $class = (string) ($r->open_class ?? '');
            $o = $orders[mb_strtolower(trim((string) $r->email))] ?? null;

            return [
                'email' => (string) $r->email,
                'status' => (string) $r->status,
                'bounce' => $bounced ? ($hard ? 'hard' : 'soft') : null,
                'opened' => $clicked || in_array($class, OpenPixel::COUNTED, true),
                'open_kind' => $clicked && $class !== 'human' && $class !== 'proxy' ? 'click' : ($class !== '' ? $class : null),
                'first_open_at' => StoreTime::iso($r->first_open_at),
                'opens' => (int) $r->open_count,
                'clicked' => $clicked,
                'clicks' => (int) ($clicks[$r->id] ?? 0),
                'first_click_at' => StoreTime::iso($r->first_click_at),
                'orders' => $o['orders'] ?? 0,
                'revenue' => $o !== null ? Money::plain($o['revenue_fils']) : '',
                'revenue_fils' => $o['revenue_fils'] ?? 0,
                'unsubscribed' => $r->unsubscribed_at !== null,
            ];
        })->values()->all();
    }

    /** @param list<int> $ids */
    private function bounceSub(array $ids): Builder
    {
        return DB::table('email_bounces')->whereIn('campaign_id', $ids)->whereIn('kind', ['hard', 'soft'])->whereNotNull('send_id')
            ->groupBy('send_id')->selectRaw("send_id, MAX(CASE WHEN kind = 'hard' THEN 1 ELSE 0 END) as hard");
    }

    /**
     * Every send-level count of these campaigns, in ONE grouped query.
     *
     * @param  list<int>  $ids
     * @return array<int, array<string, int>>
     */
    private function totals(array $ids): array
    {
        $counted = "'" . implode("','", OpenPixel::COUNTED) . "'";
        $bounced = "(s.status = 'failed' OR b.send_id IS NOT NULL)";

        $rows = DB::table('mkt_sends as s')
            ->leftJoinSub($this->bounceSub($ids), 'b', 'b.send_id', '=', 's.id')
            ->whereIn('s.campaign_id', $ids)->groupBy('s.campaign_id')
            ->selectRaw(implode(', ', [
                's.campaign_id as cid',
                "SUM(CASE WHEN s.status IN ('sent', 'failed') THEN 1 ELSE 0 END) as sent",
                "SUM(CASE WHEN s.status IN ('sent', 'failed') AND {$bounced} THEN 1 ELSE 0 END) as bounced",
                "SUM(CASE WHEN s.status IN ('sent', 'failed') AND {$bounced} AND (b.hard = 1 OR (b.send_id IS NULL AND s.error LIKE 'HARD %')) THEN 1 ELSE 0 END) as hard",
                "SUM(CASE WHEN s.status = 'skipped' THEN 1 ELSE 0 END) as skipped",
                "SUM(CASE WHEN s.status IN ('pending', 'claimed') THEN 1 ELSE 0 END) as pending",
                "SUM(CASE WHEN s.open_class IN ({$counted}) OR s.first_click_at IS NOT NULL THEN 1 ELSE 0 END) as opened",
                "SUM(CASE WHEN s.open_class = 'apple' AND s.first_click_at IS NULL THEN 1 ELSE 0 END) as apple",
                "SUM(CASE WHEN s.open_class = 'proxy' THEN 1 ELSE 0 END) as proxy",
                "SUM(CASE WHEN s.open_class = 'scanner' AND s.first_click_at IS NULL THEN 1 ELSE 0 END) as scanner",
                'SUM(s.open_count) as loads',
                'SUM(CASE WHEN s.first_click_at IS NOT NULL THEN 1 ELSE 0 END) as clickers',
                'SUM(CASE WHEN s.unsubscribed_at IS NOT NULL THEN 1 ELSE 0 END) as unsubs',
            ]))->get();

        $out = [];

        foreach ($rows as $r) {
            $t = [];

            foreach (array_keys($this->blank()) as $k) {
                $t[$k] = (int) ($r->{$k} ?? 0);
            }

            $t['delivered'] = max(0, $t['sent'] - $t['bounced']);
            $out[(int) $r->cid] = $t;
        }

        return $out;
    }

    /** @return array<string, int> */
    private function blank(): array
    {
        return array_fill_keys(['sent', 'bounced', 'hard', 'skipped', 'pending', 'opened', 'apple', 'proxy', 'scanner', 'loads', 'clickers', 'unsubs', 'delivered'], 0);
    }

    /**
     * Every link with its clicks and clickers, and the product cards among them.
     *
     * @return array{links: list<array<string, mixed>>, products: list<array<string, mixed>>}
     */
    private function links(int $id): array
    {
        $rows = DB::table('mkt_links as l')
            ->leftJoin('mkt_clicks as k', 'k.link_id', '=', 'l.id')
            ->where('l.campaign_id', $id)
            ->groupBy('l.id', 'l.n', 'l.label', 'l.url')
            ->selectRaw('l.n as n, l.label as label, l.url as url, COUNT(k.id) as clicks, COUNT(DISTINCT k.send_id) as people')
            ->orderByDesc('clicks')->orderBy('l.n')->orderBy('l.id')->limit(300)->get();

        $links = [];
        $bySlug = [];

        foreach ($rows as $r) {
            $links[] = ['n' => (int) $r->n, 'label' => (string) ($r->label ?: $r->url), 'url' => (string) $r->url, 'clicks' => (int) $r->clicks, 'people' => (int) $r->people];

            if (preg_match('#/product/([a-z0-9][a-z0-9\-_.%]{0,190})/?(?:[?\#]|$)#i', (string) $r->url, $m) === 1) {
                $slug = rawurldecode($m[1]);
                $bySlug[$slug] ??= ['clicks' => 0, 'people' => 0, 'label' => (string) $r->label];
                $bySlug[$slug]['clicks'] += (int) $r->clicks;
                $bySlug[$slug]['people'] += (int) $r->people;
            }
        }

        $products = [];

        if ($bySlug !== []) {
            $named = DB::table('products')->whereIn('slug', array_keys($bySlug))->pluck('name', 'slug');

            foreach ($bySlug as $slug => $p) {
                $products[] = ['name' => (string) ($named[$slug] ?? $p['label'] ?: $slug), 'slug' => (string) $slug, 'clicks' => $p['clicks'], 'people' => $p['people']];
            }

            usort($products, fn ($a, $b) => $b['clicks'] <=> $a['clicks'] ?: strcmp($a['name'], $b['name']));
        }

        return ['links' => $links, 'products' => $products];
    }

    /**
     * "Came to the website", per campaign: one query on an_dims and one on
     * orders, both matched by the utm_campaign prefix `mkt-<id>`.
     *
     * @param  list<int>  $ids
     * @return array<int, array<string, mixed>>
     */
    private function site(array $ids): array
    {
        $out = [];
        $blank = ['visits' => 0, 'views' => 0, 'visitors' => 0, 'carts' => 0, 'checkouts' => 0, 'orders' => 0, 'revenue_fils' => 0];
        $match = function ($w, string $col) use ($ids): void {
            if (count($ids) === 1) {
                $w->where($col, 'mkt-' . $ids[0])->orWhere($col, 'like', 'mkt-' . $ids[0] . '-%');
            } else {
                $w->where($col, 'like', 'mkt-%');
            }
        };

        $dims = DB::table('an_dims')->whereIn('dim', ['campaign', 'cmp_cart', 'cmp_chk'])->where(fn ($w) => $match($w, 'val'))
            ->groupBy('dim', 'val')->selectRaw('dim, val, SUM(sessions) as sessions, SUM(views) as views, SUM(visitors) as visitors')->get();

        foreach ($dims as $r) {
            $cid = CampaignLinks::campaignId((string) $r->val);

            if ($cid === null || ! in_array($cid, $ids, true)) {
                continue;
            }

            $out[$cid] ??= $blank;

            if ($r->dim === 'campaign') {
                $out[$cid]['visits'] += (int) $r->sessions;
                $out[$cid]['views'] += (int) $r->views;
                $out[$cid]['visitors'] += (int) $r->visitors;
            } elseif ($r->dim === 'cmp_cart') {
                $out[$cid]['carts'] += (int) $r->visitors;
            } else {
                $out[$cid]['checkouts'] += (int) $r->visitors;
            }
        }

        $orders = DB::table('orders')->whereIn('status', Order::REAL_STATUSES)->whereNull('deleted_at')
            ->where(fn ($w) => $match($w, 'src_campaign'))
            ->groupBy('src_campaign')->selectRaw('src_campaign as cmp, COUNT(*) as n, COALESCE(SUM(total), 0) as fils')->get();

        foreach ($orders as $r) {
            $cid = CampaignLinks::campaignId((string) $r->cmp);

            if ($cid === null || ! in_array($cid, $ids, true)) {
                continue;
            }

            $out[$cid] ??= $blank;
            $out[$cid]['orders'] += (int) $r->n;
            $out[$cid]['revenue_fils'] += (int) $r->fils;
        }

        foreach ($out as &$s) {
            $s['revenue'] = Money::plain($s['revenue_fils']);
        }

        return $out;
    }

    /**
     * Opens (first open of each counted send) and clicks (every click), per
     * hour from the start for 48 hours, then per day to TIMELINE_DAYS. The
     * bucketing is done by the database: two grouped queries, at most
     * 24 × TIMELINE_DAYS rows each, whatever the list size.
     *
     * @return array{hours: list<array<string,int>>, days: list<array<string,int>>, start: ?string}
     */
    private function timeline(int $id, mixed $start): array
    {
        if ($start === null) {
            return ['hours' => [], 'days' => [], 'start' => null];
        }

        $from = date('Y-m-d H:i:s', (int) strtotime((string) $start));
        $to = date('Y-m-d H:i:s', (int) strtotime((string) $start) + self::TIMELINE_DAYS * 86400);
        $zero = (int) strtotime(substr($from, 0, 13) . ':00:00');

        /*
         * Bucketed by the CLOCK HOUR, SUBSTR(timestamp, 1, 13) = 'Y-m-d H', the
         * same on SQLite and MySQL (SqlDialectGuardTest refuses strftime()), and
         * hour 0 is the clock hour the campaign started in. It used to be
         * julianday() floats on SQLite, which put an open at exactly +1:00 in
         * hour 0 (0.9999… cut down by CAST).
         */
        $opens = DB::table('mkt_sends')->where('campaign_id', $id)->whereIn('open_class', OpenPixel::COUNTED)
            ->whereBetween('first_open_at', [$from, $to])
            ->selectRaw("SUBSTR(first_open_at, 1, 13) as hk, COUNT(*) as n, SUM(CASE WHEN open_class = 'apple' THEN 1 ELSE 0 END) as apple")
            ->groupBy('hk')->get();

        $clicks = DB::table('mkt_clicks as k')->join('mkt_sends as s', 's.id', '=', 'k.send_id')->where('s.campaign_id', $id)
            ->whereBetween('k.clicked_at', [$from, $to])
            ->selectRaw('SUBSTR(k.clicked_at, 1, 13) as hk, COUNT(*) as n')
            ->groupBy('hk')->get();

        $hourOf = static fn ($r): int => intdiv((int) strtotime((string) $r->hk . ':00:00') - $zero, 3600);

        $hours = array_fill(0, 48, ['opens' => 0, 'apple' => 0, 'clicks' => 0]);
        $days = [];
        $put = function (int $h, string $k, int $n) use (&$hours, &$days): void {
            if ($h < 0) {
                return;
            }

            if ($h < 48) {
                $hours[$h][$k] += $n;
            } else {
                $d = intdiv($h, 24);
                $days[$d] ??= ['opens' => 0, 'apple' => 0, 'clicks' => 0];
                $days[$d][$k] += $n;
            }
        };

        foreach ($opens as $r) {
            $put($hourOf($r), 'opens', (int) $r->n);
            $put($hourOf($r), 'apple', (int) $r->apple);
        }

        foreach ($clicks as $r) {
            $put($hourOf($r), 'clicks', (int) $r->n);
        }

        ksort($days);
        $dayList = [];

        foreach ($days as $d => $v) {
            $dayList[] = ['day' => $d + 1] + $v;
        }

        $hourList = [];

        foreach ($hours as $h => $v) {
            $hourList[] = ['hour' => $h] + $v;
        }

        return ['hours' => $hourList, 'days' => $dayList, 'start' => StoreTime::iso($start)];
    }

    /** @param list<int> $ids */
    private function clicked(array $ids)
    {
        return DB::table('mkt_sends')->whereIn('campaign_id', $ids)->whereNotNull('first_click_at')->get(['campaign_id', 'email', 'first_click_at']);
    }

    /**
     * CampaignReport::attribute(), per campaign, for many campaigns at once.
     *
     * @param  list<int>  $ids
     * @return array<int, array{orders:int, revenue_fils:int}>
     */
    private function attributeByCampaign(array $ids): array
    {
        $clicked = $this->clicked($ids)->groupBy('campaign_id');
        $out = [];

        // One look-up for every address that clicked any of these campaigns.
        $all = $this->ordersFor($clicked->flatten(1));

        foreach ($clicked as $cid => $rows) {
            $per = $this->attributePerEmail($rows, $all);
            $out[(int) $cid] = [
                'orders' => array_sum(array_column($per, 'orders')),
                'revenue_fils' => array_sum(array_column($per, 'revenue_fils')),
            ];
        }

        return $out;
    }

    /**
     * The same rule as CampaignReport::attribute() — paid orders by the same
     * address within WINDOW_DAYS after that address's first click — kept per
     * address so the recipient list can say who ordered.
     *
     * @param  iterable<object{email:string, first_click_at:string}>  $clicked
     * @param  list<object>|null  $orders  pre-fetched orders (ordersFor())
     * @return array<string, array{orders:int, revenue_fils:int}>
     */
    private function attributePerEmail(iterable $clicked, ?array $orders = null): array
    {
        $first = $this->firstClicks($clicked);

        if ($first === []) {
            return [];
        }

        $orders ??= $this->ordersFor($clicked);
        $out = [];
        $seen = [];

        foreach ($orders as $o) {
            $email = mb_strtolower(trim((string) $o->email));
            $at = strtotime((string) $o->created_at);
            $click = $first[$email] ?? null;

            if ($click === null || $at === false || isset($seen[$o->id])) {
                continue;
            }

            if ($at >= $click && $at <= $click + CampaignReport::WINDOW_DAYS * 86400) {
                $seen[$o->id] = true;
                $out[$email] ??= ['orders' => 0, 'revenue_fils' => 0];
                $out[$email]['orders']++;
                $out[$email]['revenue_fils'] += (int) $o->total;
            }
        }

        return $out;
    }

    /** @return array<string, int> email => first click time */
    private function firstClicks(iterable $clicked): array
    {
        $first = [];

        foreach ($clicked as $s) {
            $email = mb_strtolower(trim((string) $s->email));
            $at = strtotime((string) $s->first_click_at);

            if ($email !== '' && $at !== false && (! isset($first[$email]) || $at < $first[$email])) {
                $first[$email] = $at;
            }
        }

        return $first;
    }

    /** Paid orders of these clickers since their earliest click, in slices of 500. @return list<object> */
    private function ordersFor(iterable $clicked): array
    {
        $first = $this->firstClicks($clicked);
        $out = [];

        foreach (array_chunk(array_keys($first), 500) as $chunk) {
            foreach (DB::table('orders')
                ->whereIn('status', Order::REAL_STATUSES)
                ->whereNull('deleted_at')
                ->whereIn(DB::raw('LOWER(TRIM(email))'), $chunk)
                ->where('created_at', '>=', date('Y-m-d H:i:s', min(array_intersect_key($first, array_flip($chunk)))))
                ->get(['id', 'email', 'total', 'created_at']) as $o) {
                $out[] = $o;
            }
        }

        return $out;
    }

    private static function pct(int $n, int $of): ?float
    {
        return $of > 0 ? round($n * 100 / $of, 1) : null;
    }
}
