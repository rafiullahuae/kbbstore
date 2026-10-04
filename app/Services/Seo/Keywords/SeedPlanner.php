<?php

declare(strict_types=1);

namespace App\Services\Seo\Keywords;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What to ask the outside world, and the two sources that need no asking.
 *
 * SEEDS — the phrases Autocomplete is asked about — are planned once, at the
 * start of a Run, from this shop's real catalogue, most valuable first, and
 * hard-capped (KeywordConfig::MAX_SEEDS, 300):
 *   1. the lexicon's industry seeds (korean skincare uae, …)
 *   2. every brand, by how many products it has
 *   3. "korean <type>" for every product type the categories hold
 *   4. "korean skincare for <concern>"
 *   5. what shoppers typed most into this shop's search box
 *   6. best sellers, as "<brand> <name>"
 * and, with Arabic on, the Arabic lexicon seeds and Arabic type names.
 */
final class SeedPlanner
{
    /** @return list<array{0: string, 1: string}> [seed, locale] */
    public static function plan(array $options, array $locales): array
    {
        $cap = max(0, min(KeywordConfig::MAX_SEEDS, (int) ($options['seed_cap'] ?? KeywordConfig::MAX_SEEDS)));
        if ($cap === 0 || ! ($options['autocomplete'] ?? true)) {
            return [];
        }

        $en = Lexicon::SEEDS['en'];

        foreach (DB::table('brands')->leftJoin('products', 'products.brand_id', '=', 'brands.id')
            ->groupBy('brands.id', 'brands.name')->orderByDesc(DB::raw('count(products.id)'))->orderBy('brands.id')->limit(60)
            ->pluck('brands.name') as $b) {
            $en[] = (string) $b;
        }

        $kinds = [];
        foreach (DB::table('categories')->limit(300)->pluck('name') as $c) {
            $k = Lexicon::recognise((string) $c)['kind'];
            if ($k !== null) {
                $kinds[$k] = true;
            }
        }
        foreach (array_keys($kinds) as $k) {
            $en[] = 'korean '.$k;
        }

        foreach (Lexicon::CONCERNS as [$for]) {
            $en[] = 'korean skincare for '.$for;
        }

        foreach (self::siteTerms(40) as [$term]) {
            if (! preg_match('/\p{Arabic}/u', $term)) {
                $en[] = $term;
            }
        }

        foreach (DB::table('products')->leftJoin('brands', 'brands.id', '=', 'products.brand_id')
            ->where('products.status', 'publish')->where('products.is_visible', true)
            ->orderByDesc('products.total_sales')->orderBy('products.id')->limit(80)
            ->get(['products.name', 'brands.name as brand']) as $p) {
            $en[] = trim((string) $p->brand.' '.EntityCatalog::core((string) $p->name, (string) $p->brand));
        }

        $seeds = [];
        $seen = [];
        $push = static function (string $seed, string $locale) use (&$seeds, &$seen): void {
            $s = KeywordText::clean($seed);
            if ($s !== null && ! isset($seen[$locale.$s])) {
                $seen[$locale.$s] = true;
                $seeds[] = [$s, $locale];
            }
        };

        if (in_array('ar', $locales, true)) {
            // Arabic first within its own small budget, so a full English plan
            // cannot crowd it out entirely.
            foreach (Lexicon::SEEDS['ar'] as $s) {
                $push($s, 'ar');
            }
            foreach (array_keys($kinds) as $k) {
                $push(Lexicon::typeAr($k).' كوري', 'ar');
            }
        }
        foreach ($en as $s) {
            $push($s, 'en');
        }

        return array_slice($seeds, 0, $cap);
    }

    /** Site search terms and the lexicon into the bank. Returns rows written. */
    public static function bankSiteAndLexicon(): int
    {
        $byLocale = ['en' => [], 'ar' => []];

        foreach (self::siteTerms(300) as [$term, $hits]) {
            $loc = preg_match('/\p{Arabic}/u', $term) ? 'ar' : 'en';
            $byLocale[$loc][] = ['term' => $term, 'source' => 'site', 'score' => KeywordBank::scoreSite($hits), 'metrics' => ['hits' => $hits]];
        }

        foreach (Lexicon::SEEDS['en'] as $s) {
            $byLocale['en'][] = ['term' => $s, 'source' => 'lexicon', 'score' => KeywordBank::SCORE_LEXICON];
        }
        foreach (Lexicon::SEEDS['ar'] as $s) {
            $byLocale['ar'][] = ['term' => $s, 'source' => 'lexicon', 'score' => KeywordBank::SCORE_LEXICON];
        }
        foreach (Lexicon::INDUSTRY as [$en, $ar]) {
            $byLocale['en'][] = ['term' => $en, 'source' => 'lexicon', 'score' => KeywordBank::SCORE_LEXICON];
            $byLocale['ar'][] = ['term' => $ar, 'source' => 'lexicon', 'score' => KeywordBank::SCORE_LEXICON];
        }

        return KeywordBank::put('en', $byLocale['en']) + KeywordBank::put('ar', $byLocale['ar']);
    }

    /**
     * Search Console rows, one per query+page, folded to one per query with
     * summed impressions and clicks, an impression-weighted position and its
     * top three pages.
     */
    public static function bankSearchConsole(array $rows): int
    {
        $agg = [];
        foreach ($rows as $r) {
            $t = $r['term'];
            $a = $agg[$t] ?? ['i' => 0, 'c' => 0, 'pw' => 0.0, 'pages' => []];
            $a['i'] += $r['impressions'];
            $a['c'] += $r['clicks'];
            $a['pw'] += $r['position'] * max(1, $r['impressions']);
            if ($r['page'] !== '') {
                $a['pages'][$r['page']] = ($a['pages'][$r['page']] ?? 0) + $r['impressions'];
            }
            $agg[$t] = $a;
        }

        $byLocale = ['en' => [], 'ar' => []];
        foreach ($agg as $term => $a) {
            arsort($a['pages']);
            $pos = round($a['pw'] / max(1, $a['i']), 1);
            $loc = preg_match('/\p{Arabic}/u', $term) ? 'ar' : 'en';
            $byLocale[$loc][] = [
                'term' => $term, 'source' => 'gsc',
                'score' => KeywordBank::scoreGsc($a['i'], $a['c'], $pos),
                'metrics' => ['impressions' => $a['i'], 'clicks' => $a['c'], 'position' => $pos, 'pages' => array_slice(array_keys($a['pages']), 0, 3)],
            ];
        }

        return KeywordBank::put('en', $byLocale['en']) + KeywordBank::put('ar', $byLocale['ar']);
    }

    /** @return list<array{0: string, 1: int}> [term, hits] over the last 180 days, two searches or more */
    private static function siteTerms(int $limit): array
    {
        if (! Schema::hasTable('search_terms')) {
            return [];
        }

        $out = [];
        foreach (DB::table('search_terms')->where('day', '>=', now()->subDays(180)->toDateString())
            ->groupBy('term')->havingRaw('sum(hits) >= 2')->orderByDesc(DB::raw('sum(hits)'))->orderBy('term')->limit($limit)
            ->get(['term', DB::raw('sum(hits) as h')]) as $r) {
            $t = KeywordText::clean($r->term);
            if ($t !== null) {
                $out[] = [$t, (int) $r->h];
            }
        }

        return $out;
    }
}
