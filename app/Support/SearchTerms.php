<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Query expansion for catalogue search.
 *
 * Two separate problems, handled together because they produce the same thing:
 * a small set of strings to match instead of one.
 *
 * SYNONYMS. Shoppers do not type the words on the label. Someone wanting SPF
 * types "sunscreen", "sun cream", "suncream" or "uv"; a product called
 * "Moisturizer" is searched for as "moisturiser" by anyone taught British
 * spelling, which in this market is most people. A literal LIKE returns
 * nothing and the shopper concludes the shop does not stock it.
 *
 * MISSPELLING TOLERANCE. Kept deliberately narrow: spacing and hyphen
 * variants, and a plural trim. No edit-distance matching -- on MySQL that
 * means either a full table scan per query or a fulltext index this schema
 * does not have, and both are a poor trade for a catalogue of 671 products.
 * The cheap variants below cover the common cases ("sun screen" vs
 * "sunscreen", "serums" vs "serum") at the cost of a few extra OR clauses.
 *
 * Everything returned is a plain string. Escaping for LIKE is the caller's
 * job, so this stays usable anywhere.
 */
final class SearchTerms
{
    /**
     * Groups of interchangeable words. Every member matches every other
     * member, so the direction they are listed in does not matter.
     *
     * Kept small on purpose. A large synonym list quietly widens every
     * search until results stop feeling deliberate; these are the ones that
     * come up in a K-beauty catalogue.
     */
    private const GROUPS = [
        ['moisturiser', 'moisturizer', 'cream', 'lotion'],
        ['sunscreen', 'sun cream', 'suncream', 'sun screen', 'spf', 'uv protection'],
        ['cleanser', 'face wash', 'facewash', 'cleansing foam'],
        ['serum', 'ampoule', 'essence'],
        // 'skin' deliberately absent: it is the Korean label for toner, but as
        // a LIKE pattern %skin% matches a large share of a K-beauty catalogue.
        ['toner', 'tonic'],
        ['mask', 'sheet mask', 'face mask'],
        ['lip balm', 'lipbalm', 'lip mask'],
        ['eye cream', 'eyecream', 'eye care'],
        ['exfoliant', 'exfoliator', 'peeling', 'scrub'],
        ['acne', 'blemish', 'pimple', 'breakout'],
        ['brightening', 'whitening', 'radiance'],
        ['hydrating', 'moisturising', 'moisturizing', 'hydration'],
        ['anti ageing', 'anti aging', 'antiaging', 'wrinkle', 'firming'],
        ['sensitive', 'soothing', 'calming'],
        ['cushion', 'bb cream', 'cc cream'],
        ['snail mucin', 'snail', 'mucin'],
        ['vitamin c', 'vitamin-c', 'vitc', 'ascorbic'],
        ['hyaluronic', 'hyaluronic acid'],
        ['niacinamide', 'vitamin b3'],
        ['centella', 'cica', 'madecassoside'],
        ['retinol', 'retinoid'],
        ['sleeping mask', 'sleeping pack', 'overnight mask'],
    ];

    /** Highest number of variants any one query may expand to. */
    private const LIMIT = 8;

    /**
     * Shortest an expanded term may be.
     *
     * Everything here is matched as %term%, and a two or three letter
     * fragment matches far too much: %ha% pulls in Shampoo and Charcoal,
     * %uv% pulls in anything with those letters mid-word. The original query
     * is exempt -- someone searching "spf" means it -- but nothing expands
     * TO a fragment that short.
     */
    private const MIN_EXPANDED = 4;

    /**
     * Every string worth matching for this query, most specific first.
     *
     * The original always comes first so the caller can weight it if it ever
     * wants to; the rest are deduplicated and capped.
     *
     * @return list<string>
     */
    public static function expand(string $query): array
    {
        $query = trim($query);

        if ($query === '') {
            return [];
        }

        $terms = [$query];
        $key = self::normalise($query);

        foreach (self::GROUPS as $group) {
            $hit = false;

            foreach ($group as $word) {
                if (self::normalise($word) === $key) {
                    $hit = true;
                    break;
                }
            }

            if ($hit) {
                foreach ($group as $word) {
                    $terms[] = $word;
                }
            }
        }

        foreach (self::variants($query) as $variant) {
            $terms[] = $variant;
        }

        $seen = [];
        $out = [];

        foreach ($terms as $term) {
            $term = trim($term);
            $fold = mb_strtolower($term);

            if ($term === '' || isset($seen[$fold])) {
                continue;
            }

            // Index 0 is the shopper's own words and is always kept.
            if ($out !== [] && mb_strlen($term) < self::MIN_EXPANDED) {
                continue;
            }

            $seen[$fold] = true;
            $out[] = $term;

            if (count($out) >= self::LIMIT) {
                break;
            }
        }

        return $out;
    }

    /**
     * Cheap spelling variants: spacing, hyphenation and a plural trim.
     *
     * @return list<string>
     */
    private static function variants(string $query): array
    {
        $out = [];
        $lower = mb_strtolower($query);

        // "sun-screen" and "sun screen" both reach "sunscreen", and vice versa.
        $tight = preg_replace('/[\s\-]+/u', '', $lower) ?? $lower;

        if ($tight !== '' && $tight !== $lower) {
            $out[] = $tight;
        }

        $spaced = preg_replace('/-+/u', ' ', $lower) ?? $lower;

        if ($spaced !== $lower) {
            $out[] = $spaced;
        }

        // Plural trim, on the whole query and on its last word. Guarded at 4
        // characters so "ha" and "spf" are left alone.
        foreach ([$lower, $tight] as $candidate) {
            if (mb_strlen($candidate) > 4 && str_ends_with($candidate, 's') && ! str_ends_with($candidate, 'ss')) {
                $out[] = mb_substr($candidate, 0, -1);
            }
        }

        return $out;
    }

    /** Lowercased, with spacing and hyphens removed, for group matching. */
    private static function normalise(string $value): string
    {
        return preg_replace('/[\s\-]+/u', '', mb_strtolower(trim($value))) ?? '';
    }

    /**
     * The LIKE escape character.
     *
     * Deliberately not a backslash. Backslash is MySQL's *default* LIKE escape
     * but means nothing to SQLite, so `\%` was a real pattern on MySQL and a
     * literal backslash on SQLite -- the same search returned different rows
     * on the test database and in production, which is exactly the kind of
     * divergence that lets a bug through CI.
     *
     * Saying `ESCAPE` explicitly fixes that, but the character has to survive
     * being written as a SQL string literal in both dialects, and a backslash
     * does not: MySQL reads `'\'` as an escaped quote and fails to parse,
     * while SQLite reads `'\\'` as two characters and rejects it for being
     * longer than one. `!` is ordinary in both and needs no doubling.
     */
    public const ESCAPE = '!';

    /**
     * Escape the LIKE wildcards `%` and `_` so a query containing them is
     * matched literally rather than acting as a pattern.
     *
     * Must be paired with an `ESCAPE` clause -- use whereLike()/orWhereLike()
     * rather than passing this to a bare `where(..., 'like', ...)`, which
     * would leave the escape character sitting in the pattern as a literal.
     */
    public static function like(string $term): string
    {
        $e = self::ESCAPE;

        // The escape character itself goes first, or it would double-escape
        // the sequences introduced for % and _.
        return '%' . str_replace([$e, '%', '_'], [$e . $e, $e . '%', $e . '_'], $term) . '%';
    }

    /**
     * `AND <column> LIKE <pattern> ESCAPE '!'`, with the pattern bound.
     *
     * The column is never shopper-supplied -- every caller passes a literal
     * from its own class -- and the term is always a binding, so the raw
     * fragment carries no user input at all.
     */
    public static function whereLike($query, string $column, string $term)
    {
        return $query->whereRaw(
            self::clause($query, $column),
            [self::like($term)]
        );
    }

    /** As whereLike(), joined with OR. */
    public static function orWhereLike($query, string $column, string $term)
    {
        return $query->orWhereRaw(
            self::clause($query, $column),
            [self::like($term)]
        );
    }

    /** `"name" like ? escape '!'`, with the column quoted for the dialect. */
    private static function clause($query, string $column): string
    {
        // Eloquent builders, relation builders and plain query builders all
        // reach this; only the first two wrap an underlying query builder.
        $base = method_exists($query, 'getQuery') ? $query->getQuery() : $query;

        // A relation builder's getQuery() is itself an Eloquent builder.
        if (method_exists($base, 'getQuery')) {
            $base = $base->getQuery();
        }

        return $base->getGrammar()->wrap($column) . " like ? escape '" . self::ESCAPE . "'";
    }
}
