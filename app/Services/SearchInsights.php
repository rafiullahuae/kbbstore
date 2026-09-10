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

    /** Count a search, once per term per day. */
    public function record(string $term, int $results): void
    {
        $term = trim(preg_replace('/\s+/', ' ', $term));

        if (mb_strlen($term) < self::MIN_LENGTH || mb_strlen($term) > self::MAX_LENGTH) {
            return;
        }

        // A term nobody could find is noise, not insight.
        if ($results < 1) {
            return;
        }

        $term = mb_strtolower($term);

        try {
            DB::table('search_terms')->upsert(
                [['term' => $term, 'day' => now()->toDateString(), 'hits' => 1,
                  'results' => $results, 'created_at' => now(), 'updated_at' => now()]],
                ['term', 'day'],
                ['hits' => DB::raw('hits + 1'), 'results' => DB::raw('GREATEST(results, ' . $results . ')'),
                 'updated_at' => DB::raw('NOW()')]
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
                    ->groupBy('term')
                    ->orderByDesc('total')
                    ->limit($limit)
                    ->pluck('term')
                    ->all();
            } catch (\Throwable $e) {
                return [];
            }
        });
    }
}
