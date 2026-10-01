<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * What everyone has been searching for lately.
 *
 * The panel's "recent searches" are the shop's most-used terms over the last
 * seven days, not one visitor's history — more useful on a first visit, and it
 * needs nothing stored about the person.
 */
class SearchInsights
{
    private const WINDOW_DAYS = 7;
    private const MIN_LENGTH = 2;
    private const MAX_LENGTH = 60;

    /**
     * Count a search, once per term per day.
     *
     * ▲ TERMS THAT FOUND NOTHING ARE COUNTED NOW (1 October 2026). They were
     *   dropped as "noise, not insight" -- but on Growth -> Search Terms they
     *   are the most useful rows there are: what shoppers want and this shop
     *   does not show them. They still never reach the panel's "most searched"
     *   list; popular() reads only terms that found something.
     *
     * ▲ AND THE WRITE WORKS ON BOTH DIALECTS. The update spelled GREATEST() and
     *   NOW(), which SQLite does not have, inside a catch that swallows
     *   everything -- so on SQLite nothing was ever counted and no test could
     *   see it. max() is SQLite's two-argument form of GREATEST().
     */
    public function record(string $term, int $results): void
    {
        $term = trim(preg_replace('/\s+/', ' ', $term));

        if (mb_strlen($term) < self::MIN_LENGTH || mb_strlen($term) > self::MAX_LENGTH) {
            return;
        }

        $term = mb_strtolower($term);
        $results = max(0, $results);
        $greatest = DB::connection()->getDriverName() === 'sqlite' ? 'max' : 'GREATEST';

        try {
            DB::table('search_terms')->upsert(
                [['term' => $term, 'day' => now()->toDateString(), 'hits' => 1,
                  'results' => $results, 'created_at' => now(), 'updated_at' => now()]],
                ['term', 'day'],
                ['hits' => DB::raw('hits + 1'), 'results' => DB::raw($greatest . '(results, ' . $results . ')'),
                 'updated_at' => now()]
            );
        } catch (\Throwable $e) {
            // Counting a search must never break the search itself.
        }
    }

    /**
     * The most-searched terms of the last seven days.
     *
     * @return string[]
     */
    public function popular(int $limit = 6): array
    {
        $limit = max(1, min(12, $limit));

        return Cache::remember("kbb.search.popular.{$limit}", 600, function () use ($limit) {
            try {
                return DB::table('search_terms')
                    ->select('term', DB::raw('SUM(hits) as total'))
                    ->where('day', '>=', now()->subDays(self::WINDOW_DAYS)->toDateString())
                    // Never offer back a term nobody could find.
                    ->where('results', '>', 0)
                    ->groupBy('term')
                    // Most searched terms in the window have been searched
                    // once, so this LIMIT is taken over one large tie; `term`
                    // is the group key and cannot tie.
                    ->orderByDesc('total')
                    ->orderBy('term')
                    ->limit($limit)
                    ->pluck('term')
                    ->all();
            } catch (\Throwable $e) {
                return [];
            }
        });
    }
}
