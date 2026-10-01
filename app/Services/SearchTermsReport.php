<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Growth & Marketing -> Search Terms: what shoppers type, ranked.
 *
 * The owner, 1 October 2026: "a page where i can see daily, weekly, monthly
 * etc. the searched terms and counts for each word/term so i will have idea
 * what people are looking for. list it ranked. real data, but super light."
 *
 * LIGHT BY CONSTRUCTION. It reads `search_terms`, which already holds ONE row
 * per term per day (SearchInsights::record() upserts; it never appends a row
 * per search), through its (day, hits) index. One grouped query per period,
 * capped at MAX_TERMS and cached for five minutes; filtering, the search box
 * and paging happen on that cached list in PHP. The change column is one more
 * grouped query over the previous period, for the 50 terms on the page only.
 *
 * "PARTIAL WORDS". Until this change the search box counted every keystroke
 * that returned something -- "me", "med", "medi", "medicube" -- so the days
 * before it carry fragments ranked above the word they lead to. A term is a
 * fragment here when another term in the same list starts with it AND carries
 * on with a letter or digit ("med" before "medicube"); "serum" before "serum
 * set" is a whole word and stays. Hidden by default, one switch to show them.
 * New counts are taken only when a search settles (resources/js/kbb/search.js),
 * so fragments stop arriving.
 */
final class SearchTermsReport
{
    /** key => [label, days back from today inclusive (null = everything), ends yesterday?] */
    public const PERIODS = [
        'today' => ['Today', 1, false],
        'yesterday' => ['Yesterday', 1, true],
        '7d' => ['Last 7 days', 7, false],
        '30d' => ['Last 30 days', 30, false],
        '90d' => ['Last 90 days', 90, false],
        '365d' => ['Last 12 months', 365, false],
        'all' => ['All time', null, false],
    ];

    public const SHOW = ['all', 'nothing', 'found'];

    public const PER_PAGE = 50;

    private const MAX_TERMS = 3000;

    /**
     * @return array<string, mixed>
     */
    public function build(string $period, string $show = 'all', string $find = '', int $page = 1, bool $partials = false): array
    {
        $period = array_key_exists($period, self::PERIODS) ? $period : '7d';
        $show = in_array($show, self::SHOW, true) ? $show : 'all';
        $find = mb_strtolower(trim(mb_substr($find, 0, 60)));
        [$from, $to] = $this->range($period);

        $all = Cache::remember('kbb.search_terms_report.'.$period.'.'.now()->toDateString(), 300,
            fn () => $this->grouped($from, $to));

        $rows = $partials ? $all : $this->withoutFragments($all);
        $fragments = count($all) - count($rows);

        $searches = array_sum(array_column($rows, 'searches'));
        $nothing = count(array_filter($rows, fn ($r) => $r['found'] === 0));

        $rows = array_values(array_filter($rows, function ($r) use ($show, $find) {
            if ($show === 'nothing' && $r['found'] !== 0) {
                return false;
            }

            if ($show === 'found' && $r['found'] === 0) {
                return false;
            }

            return $find === '' || str_contains($r['term'], $find);
        }));

        $pages = max(1, (int) ceil(count($rows) / self::PER_PAGE));
        $page = max(1, min($pages, $page));
        $slice = array_slice($rows, ($page - 1) * self::PER_PAGE, self::PER_PAGE);

        // Rank is the place in the filtered, ranked list.
        foreach ($slice as $i => &$row) {
            $row['rank'] = ($page - 1) * self::PER_PAGE + $i + 1;
        }
        unset($row);

        $slice = $this->withChange($slice, $period, $from, $to);

        return [
            'period' => $period,
            'periods' => array_map(fn ($p) => $p[0], self::PERIODS),
            'from' => $from?->toDateString(),
            'to' => $to->toDateString(),
            'show' => $show,
            'find' => $find,
            'partials' => $partials,
            'totals' => [
                'searches' => $searches,
                'terms' => count($partials ? $all : $this->withoutFragments($all)),
                'nothing' => $nothing,
                'fragments_hidden' => $partials ? 0 : $fragments,
                'capped' => count($all) >= self::MAX_TERMS,
            ],
            'rows' => $slice,
            'page' => $page,
            'pages' => $pages,
            'matching' => count($rows),
        ];
    }

    /** @return array{0: ?Carbon, 1: Carbon} */
    private function range(string $period): array
    {
        [, $days, $endsYesterday] = self::PERIODS[$period];
        $to = $endsYesterday ? now()->subDay()->startOfDay() : now()->startOfDay();

        return [$days === null ? null : $to->copy()->subDays($days - 1), $to];
    }

    /** @return list<array{term: string, searches: int, days: int, found: int, last: string}> */
    private function grouped(?Carbon $from, Carbon $to): array
    {
        try {
            return DB::table('search_terms')
                ->select('term')
                ->selectRaw('SUM(hits) as searches, COUNT(*) as days, MAX(results) as found, MAX(day) as last_day')
                ->when($from, fn ($q) => $q->where('day', '>=', $from->toDateString()))
                ->where('day', '<=', $to->toDateString())
                ->groupBy('term')
                ->orderByDesc('searches')
                ->orderBy('term')
                ->limit(self::MAX_TERMS)
                ->get()
                ->map(fn ($r) => [
                    'term' => (string) $r->term,
                    'searches' => (int) $r->searches,
                    'days' => (int) $r->days,
                    'found' => (int) $r->found,
                    'last' => substr((string) $r->last_day, 0, 10),
                ])
                ->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Drop "med" when "medicube" is in the same list. Sorted once; every term
     * that starts with T sits in one run straight after T.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function withoutFragments(array $rows): array
    {
        $terms = array_column($rows, 'term');
        sort($terms, SORT_STRING);
        $fragment = [];

        foreach ($terms as $i => $t) {
            for ($j = $i + 1, $n = count($terms); $j < $n && str_starts_with($terms[$j], $t); $j++) {
                $next = mb_substr($terms[$j], mb_strlen($t), 1);

                if ($next !== '' && preg_match('/[\p{L}\p{N}]/u', $next)) {
                    $fragment[$t] = true;
                    break;
                }
            }
        }

        return array_values(array_filter($rows, fn ($r) => ! isset($fragment[$r['term']])));
    }

    /**
     * The same terms over the period before, for the change column. Only the
     * page's own terms, so it is one small grouped query.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function withChange(array $rows, string $period, ?Carbon $from, Carbon $to): array
    {
        if ($rows === [] || $from === null) {
            return array_map(fn ($r) => $r + ['before' => null, 'change' => null], $rows);
        }

        $length = $from->diffInDays($to) + 1;
        $prevTo = $from->copy()->subDay();
        $prevFrom = $prevTo->copy()->subDays($length - 1);

        try {
            $before = DB::table('search_terms')
                ->select('term')
                ->selectRaw('SUM(hits) as searches')
                ->whereIn('term', array_column($rows, 'term'))
                ->where('day', '>=', $prevFrom->toDateString())
                ->where('day', '<=', $prevTo->toDateString())
                ->groupBy('term')
                ->pluck('searches', 'term')
                ->all();
        } catch (\Throwable $e) {
            $before = [];
        }

        return array_map(function ($r) use ($before) {
            $was = (int) ($before[$r['term']] ?? 0);

            return $r + [
                'before' => $was,
                'change' => $was === 0 ? 'new' : (int) round(($r['searches'] - $was) * 100 / $was),
            ];
        }, $rows);
    }
}
