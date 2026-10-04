<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * "Automatic by query" for every homepage product section (Lane HC).
 *
 * The owner: "data queries to choose brand, category or mixed categories, or
 * manual section with search function properly."
 *
 * ONE SHAPE, READ BY ONE QUERY BUILDER. A section whose source is `query`
 * carries four things — brands (any of), categories (any of), a sort, and an
 * in-stock switch — and every place that stores one (the three Row 55 rails'
 * settings, the four older rails' settings, a Grid section's `source_query`
 * column) is turned into this array and handed to GridSections::pool(), which
 * already owns the visibility scope, the eager load and the tie-breaks. So the
 * new source costs the shop what the old ones cost: one SELECT per section,
 * whatever the size of the catalogue.
 *
 * THE OLD SOURCES ARE NOT REPLACED. `bestsellers`, `brand`, `category` and the
 * rest still mean exactly what they meant; a section nobody touches reads the
 * same query it read before. The editor writes the OLD value whenever the
 * owner's choice is one of them (canonical() in the screen does it), so the
 * other screens that draw the same select keep showing a word they know.
 */
final class ProductSource
{
    /** sort => label. Keys are what a select stores; the labels are the screen's. */
    public const SORTS = [
        'bestselling' => 'Best selling',
        'trending' => 'Trending this week',
        'newest' => 'Newest first',
        'price_asc' => 'Price: low to high',
        'price_desc' => 'Price: high to low',
        'onsale' => 'On sale only',
        'featured' => 'Featured only',
        'random' => 'Random — reshuffled once a day',
    ];

    /** The most brands, and the most categories, one query may name. */
    public const CAP_TERMS = 20;

    /** The most products a manual list may hold. */
    public const CAP_PICKS = 24;

    /**
     * The tables an `ids` control may point into. A key outside this list is
     * never turned into a table name — the map IS the allowlist.
     */
    public const TABLES = ['products' => 'products', 'brands' => 'brands', 'categories' => 'categories', 'posts' => 'posts'];

    /**
     * The query part of a selection, cleaned: positive unique ids, capped; a
     * sort that is one of SORTS or the default; a real boolean.
     *
     * @return array{brands: list<int>, cats: list<int>, sort: string, stock: bool}
     */
    public static function clean(mixed $raw): array
    {
        $raw = is_array($raw) ? $raw : [];
        $sort = (string) ($raw['sort'] ?? '');

        return [
            'brands' => self::ids($raw['brands'] ?? [], self::CAP_TERMS),
            'cats' => self::ids($raw['cats'] ?? [], self::CAP_TERMS),
            'sort' => isset(self::SORTS[$sort]) ? $sort : 'bestselling',
            'stock' => filter_var($raw['stock'] ?? false, FILTER_VALIDATE_BOOL),
        ];
    }

    /**
     * Positive unique ints from a comma list or an array, at most $cap.
     *
     * @return list<int>
     */
    public static function ids(mixed $raw, int $cap): array
    {
        $out = [];

        foreach (is_array($raw) ? $raw : explode(',', (string) $raw) as $one) {
            $id = is_scalar($one) ? (int) trim((string) $one) : 0;

            if ($id > 0 && ! in_array($id, $out, true)) {
                $out[] = $id;
            }

            if (count($out) >= $cap) {
                break;
            }
        }

        return $out;
    }

    /**
     * The ids that name a real row, in the order given. One SELECT on the
     * primary key; nothing for an empty list. An unknown `$of` keeps nothing —
     * the safe direction.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    public static function existing(string $of, array $ids): array
    {
        if ($ids === [] || ! isset(self::TABLES[$of])) {
            return [];
        }

        $found = array_map('intval', DB::table(self::TABLES[$of])->whereIn('id', $ids)->pluck('id')->all());

        return array_values(array_filter($ids, fn (int $id) => in_array($id, $found, true)));
    }

    /**
     * The spec string GridSections caches a pool under — stable for equal
     * selections, so two sections asking for the same thing share one query.
     */
    public static function spec(array $q): string
    {
        $q = self::clean($q);

        return 'b='.implode(',', $q['brands']).';c='.implode(',', $q['cats']).';s='.$q['sort'].';i='.($q['stock'] ? '1' : '0');
    }

    /** spec() read back. */
    public static function parse(string $spec): array
    {
        $parts = [];

        foreach (explode(';', $spec) as $pair) {
            [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
            $parts[$k] = $v;
        }

        return self::clean([
            'brands' => $parts['b'] ?? '',
            'cats' => $parts['c'] ?? '',
            'sort' => $parts['s'] ?? '',
            'stock' => ($parts['i'] ?? '0') === '1',
        ]);
    }

    /**
     * The day's shuffle seed. Integer arithmetic in ORDER BY, so MySQL and
     * SQLite agree and no RAND() defeats the cache: the order holds all day
     * and moves at midnight.
     */
    public static function daySeed(): int
    {
        return ((int) now()->format('Y')) * 1000 + (int) now()->format('z');
    }
}
