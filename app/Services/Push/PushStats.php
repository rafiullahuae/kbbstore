<?php

declare(strict_types=1);

namespace App\Services\Push;

use App\Services\Marketing\Audience;
use App\Support\Locale;
use App\Support\StoreTime;
use Illuminate\Support\Facades\DB;

/**
 * Growth & Marketing → Push Notifications → Subscribers & analytics (Lane PN).
 *
 * The owner: "we will have all analytics, so we can precise more our push
 * notifications as per the target cities, it will reduce the spam too".
 *
 * FLAT COST, PROVED: every figure is one aggregate statement — GROUP BY over
 * the subscription table, never a loop over its rows — so the screen costs
 * the same number of queries with three phones or three hundred thousand
 * (PushAnalyticsTest renders it with 3 and with 40 and compares the count).
 * The only PHP loop is over DISTINCT place spellings, which is bounded by how
 * many ways people write "Dubai", not by how many phones there are.
 */
final class PushStats
{
    /** A per-day counter (opt-outs, retired endpoints): one upsert. Never throws. */
    public static function bump(string $kind, int $n = 1): void
    {
        if ($n < 1) {
            return;
        }
        try {
            $day = StoreTime::now()->format('Y-m-d');
            $hit = DB::table('push_daily')->where('day', $day)->where('kind', $kind)->increment('n', $n);
            if ($hit === 0) {
                DB::table('push_daily')->insertOrIgnore(['day' => $day, 'kind' => $kind, 'n' => $n]);
            }
        } catch (\Throwable) {
        }
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        $t = 'site_app_push_subscriptions';

        $totals = DB::table($t)->selectRaw(
            "SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,"
            ." SUM(CASE WHEN status <> 'active' THEN 1 ELSE 0 END) as gone,"
            ." SUM(CASE WHEN status = 'active' AND customer_id IS NOT NULL THEN 1 ELSE 0 END) as customers,"
            ." SUM(CASE WHEN status = 'active' AND customer_id IS NULL THEN 1 ELSE 0 END) as guests"
        )->first();

        $places = DB::table("$t as s")->where('s.status', 'active')
            ->selectRaw(PushAudience::placeSql().' as place, s.country as country, COUNT(*) as n')
            ->groupByRaw(PushAudience::placeSql().', s.country')->get();
        $emirates = array_fill_keys(array_keys(Audience::EMIRATES), 0) + ['unknown' => 0];
        foreach ($places as $p) {
            $key = PushAudience::emirateOf((string) $p->place, null, $p->country) ?? 'unknown';
            $emirates[$key] = ($emirates[$key] ?? 0) + (int) $p->n;
        }
        $byEmirate = [];
        foreach ($emirates as $key => $n) {
            $byEmirate[] = ['key' => $key, 'label' => $key === 'unknown' ? 'Place unknown' : Audience::EMIRATES[$key][0], 'n' => $n];
        }

        $group = static fn (string $col, int $limit = 0) => DB::table($t)->where('status', 'active')
            ->selectRaw("COALESCE($col, '') as k, COUNT(*) as n")->groupBy($col)->orderByDesc('n')->orderBy($col)
            ->when($limit > 0, fn ($q) => $q->limit($limit))->get()
            ->map(fn ($r) => ['k' => (string) $r->k, 'n' => (int) $r->n])->all();

        $cities = array_values(array_filter($group('city', 21), fn ($r) => $r['k'] !== ''));
        $languages = array_map(fn ($r) => $r + ['label' => Locale::LOCALES[$r['k']]['name'] ?? ($r['k'] ?: 'Unknown')], $group('locale'));
        $platforms = array_map(fn ($r) => $r + ['label' => PushAudience::PLATFORMS[$r['k']] ?? 'Unknown'], $group('platform'));
        $sources = array_map(fn ($r) => $r + ['label' => self::SOURCES[$r['k']] ?? 'Not known yet'], $group('location_source'));

        // Growth: new subscriptions per day, last 30 days, shop time.
        $since = StoreTime::windowStartUtc(29);
        $min = (int) round(StoreTime::now()->getOffset() / 60);
        $dayExpr = DB::connection()->getDriverName() === 'sqlite'
            ? "DATE(created_at, '".($min >= 0 ? '+' : '').$min." minutes')"
            : 'DATE(DATE_ADD(created_at, INTERVAL '.$min.' MINUTE))';
        $daily = DB::table($t)->where('created_at', '>=', $since)
            ->selectRaw("$dayExpr as d, COUNT(*) as n")->groupByRaw($dayExpr)->pluck('n', 'd')->all();
        $counters = DB::table('push_daily')->where('day', '>=', StoreTime::now()->subDays(29)->format('Y-m-d'))
            ->get(['day', 'kind', 'n']);
        $growth = [];
        foreach (StoreTime::recentDayKeys(30) as $day) {
            $growth[$day] = ['day' => $day, 'new' => (int) ($daily[$day] ?? 0), 'optout' => 0, 'gone' => 0];
        }
        foreach ($counters as $c) {
            $day = is_string($c->day) ? substr($c->day, 0, 10) : (string) $c->day;
            if (isset($growth[$day]) && in_array($c->kind, ['optout', 'gone'], true)) {
                $growth[$day][$c->kind] += (int) $c->n;
            }
        }

        $top = DB::table('push_campaigns')->whereIn('status', ['sent', 'sending'])->where('delivered', '>', 0)
            ->orderByRaw('(clicks * 1.0 / delivered) DESC')->orderByDesc('delivered')->orderByDesc('id')->limit(5)
            ->get(['id', 'title', 'delivered', 'clicks', 'started_at']);

        $optouts = (int) DB::table('push_daily')->where('kind', 'optout')->sum('n');

        return [
            'active' => (int) ($totals->active ?? 0),
            'gone' => (int) ($totals->gone ?? 0),
            'customers' => (int) ($totals->customers ?? 0),
            'guests' => (int) ($totals->guests ?? 0),
            'optouts' => $optouts,
            'emirates' => $byEmirate,
            'cities' => array_slice($cities, 0, 20),
            'languages' => $languages,
            'platforms' => $platforms,
            'sources' => $sources,
            'growth' => array_values($growth),
            'top' => $top->map(fn ($c) => [
                'id' => (int) $c->id, 'title' => (string) $c->title, 'delivered' => (int) $c->delivered, 'clicks' => (int) $c->clicks,
                'ctr' => $c->delivered > 0 ? round($c->clicks * 100 / $c->delivered, 1) : 0.0, 'at' => StoreTime::iso($c->started_at),
            ])->all(),
        ];
    }

    /** How a phone's place was learnt, most accurate first. */
    public const SOURCES = [
        'order' => 'Delivery address (accurate)',
        'ip-header' => 'Network header (good)',
        'ip-db' => 'IP lookup (approximate)',
        '' => 'Not known yet',
    ];

    /**
     * One campaign's report by emirate: one grouped query.
     *
     * @return list<array{key:string, label:string, sent:int, delivered:int, clicks:int}>
     */
    public function byEmirate(int $campaignId): array
    {
        $rows = DB::table('push_sends')->where('campaign_id', $campaignId)->where('status', '<>', 'held')
            ->groupBy('emirate')
            ->selectRaw("COALESCE(emirate, '') as e, COUNT(*) as sent,"
                ." SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered,"
                .' SUM(CASE WHEN clicked_at IS NOT NULL THEN 1 ELSE 0 END) as clicks')
            ->get();
        $out = [];
        foreach ($rows as $r) {
            $key = $r->e === '' ? 'unknown' : (string) $r->e;
            $out[] = ['key' => $key, 'label' => $key === 'unknown' ? 'Place unknown' : (Audience::EMIRATES[$key][0] ?? $key),
                'sent' => (int) $r->sent, 'delivered' => (int) $r->delivered, 'clicks' => (int) $r->clicks];
        }
        usort($out, fn ($a, $b) => $b['sent'] <=> $a['sent']);

        return $out;
    }
}
