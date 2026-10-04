<?php

declare(strict_types=1);

namespace App\Services\Seo\Keywords;

use Illuminate\Support\Facades\DB;

/**
 * The keyword bank: phrases real sources returned, one row per term+locale,
 * keeping the STRONGEST source a term has been seen from.
 *
 * Source strength, as the brief ranks it: Search Console (people already find
 * this shop with it) > Autocomplete (people type it) > this shop's own search
 * box (people here type it) > the curated lexicon (the trade's vocabulary).
 */
final class KeywordBank
{
    public const RANK = ['gsc' => 4, 'autocomplete' => 3, 'site' => 2, 'lexicon' => 1];

    public const SOURCES = ['gsc', 'autocomplete', 'site', 'lexicon'];

    public static function scoreGsc(int $impressions, int $clicks, float $position): int
    {
        return 600 + min(250, intdiv($impressions, 4)) + min(100, $clicks * 5) - (int) min(100, $position * 2);
    }

    public static function scoreAutocomplete(int $rank): int
    {
        return 400 - min(9, $rank) * 15;
    }

    public static function scoreSite(int $hits): int
    {
        return 250 + min(100, $hits);
    }

    public const SCORE_LEXICON = 150;

    /**
     * Upsert a batch. Two queries whatever its size: read what is there, write
     * what changes. A weaker source never overwrites a stronger one.
     *
     * @param  list<array{term: string, source: string, score: int, metrics?: array}>  $rows
     * @return int rows written
     */
    public static function put(string $locale, array $rows): int
    {
        $byTerm = [];
        foreach ($rows as $r) {
            $term = KeywordText::clean($r['term'] ?? null);
            if ($term === null || ! isset(self::RANK[$r['source'] ?? ''])) {
                continue;
            }
            $prev = $byTerm[$term] ?? null;
            if ($prev === null || self::stronger($r, $prev)) {
                $byTerm[$term] = ['source' => $r['source'], 'score' => (int) $r['score'], 'metrics' => $r['metrics'] ?? null];
            }
        }

        if ($byTerm === []) {
            return 0;
        }

        $existing = [];
        foreach (array_chunk(array_keys($byTerm), 500) as $slice) {
            foreach (DB::table('seo_keywords')->where('locale', $locale)->whereIn('term', $slice)->get(['term', 'source', 'score']) as $row) {
                $existing[$row->term] = ['source' => $row->source, 'score' => (int) $row->score];
            }
        }

        $now = now();
        $write = [];
        foreach ($byTerm as $term => $r) {
            $old = $existing[$term] ?? null;
            if ($old !== null && ! self::stronger($r, $old) && $old['source'] !== $r['source']) {
                continue;
            }
            $write[] = [
                'term' => $term,
                'locale' => $locale,
                'source' => $r['source'],
                'score' => max(0, $r['score']),
                'metrics' => $r['metrics'] !== null ? json_encode($r['metrics']) : null,
                'fetched_at' => $now,
            ];
        }

        foreach (array_chunk($write, 300) as $slice) {
            DB::table('seo_keywords')->upsert($slice, ['term', 'locale'], ['source', 'score', 'metrics', 'fetched_at']);
        }

        return count($write);
    }

    private static function stronger(array $a, array $b): bool
    {
        $ra = self::RANK[$a['source']] ?? 0;
        $rb = self::RANK[$b['source']] ?? 0;

        return $ra > $rb || ($ra === $rb && (int) $a['score'] > (int) $b['score']);
    }

    /**
     * The whole bank for a locale, as a compact in-memory index for one sync
     * step: term => [score, source], plus token => terms, plus page path =>
     * Search Console terms. One query.
     *
     * @return array{terms: array<string, array{0: int, 1: string}>, index: array<string, list<string>>, pages: array<string, list<string>>, brands: list<list<string>>}
     */
    public static function index(string $locale): array
    {
        $terms = [];
        $index = [];
        $pages = [];

        foreach (DB::table('seo_keywords')->where('locale', $locale)->orderByDesc('score')->orderBy('id')->limit(20000)
            ->get(['term', 'score', 'source', 'metrics']) as $row) {
            $terms[$row->term] = [(int) $row->score, (string) $row->source];
            foreach (KeywordText::tokens($row->term) as $tok) {
                if (mb_strlen($tok) >= 3 && ! in_array($tok, Lexicon::STOP, true)) {
                    $index[$tok][] = $row->term;
                }
            }
            if ($row->source === 'gsc' && is_string($row->metrics)) {
                $m = json_decode($row->metrics, true);
                foreach (is_array($m['pages'] ?? null) ? $m['pages'] : [] as $p) {
                    if (is_string($p) && $p !== '') {
                        $pages[rtrim($p, '/').'/'][] = $row->term;
                    }
                }
            }
        }

        // Every brand's distinctive words, so the composer can refuse a phrase
        // that names somebody else's brand.
        $brands = [];
        foreach (DB::table('brands')->limit(2000)->pluck('name') as $name) {
            $t = array_values(array_filter(KeywordText::tokens((string) $name), static fn ($w) => mb_strlen($w) >= 3 && ! in_array($w, Lexicon::STOP, true)));
            if ($t !== []) {
                $brands[] = $t;
            }
        }

        return ['terms' => $terms, 'index' => $index, 'pages' => $pages, 'brands' => $brands];
    }
}
