<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Product::effectivePrice(), expressed in SQL.
 *
 * WHY THIS EXISTS. Two places decided what a product costs and they did not
 * agree. Every card, rail and product page prints Product::effectivePrice(),
 * which honours `sale_price` and its scheduling window. The shop's own price
 * SORT and price FILTER ordered and bucketed on the raw `price` column, which
 * is the pre-sale price. So a product marked down from AED 200 to AED 50:
 *
 *   - showed AED 50 on its card,
 *   - was filed under "AED 150 – 300" and was absent from "AED 54 – 150",
 *   - and sorted under "Price: low to high" as though it cost AED 200,
 *     landing near the end of the list.
 *
 * A shopper filtering by budget therefore never saw the store's discounted
 * stock — which is the stock the owner most wants to move — and the sale badge
 * on the card contradicted the filter that had just excluded it.
 *
 * MIRRORS Product::effectivePrice() CONDITION FOR CONDITION, deliberately:
 *
 *     sale_price IS NULL                -> price      (no sale at all)
 *     now < sale_starts_at              -> price      (not started)
 *     now > sale_ends_at                -> price      (finished)
 *     otherwise                         -> sale_price
 *
 * Note it does NOT treat `sale_price = 0` as "no sale". Neither does
 * effectivePrice(), and the whole point of this file is that the two answer the
 * same question the same way; a nicety added here and not there would put the
 * disagreement straight back. (`Support\Shortcodes` used to write its own
 * `COALESCE(NULLIF(sale_price, 0), price)`, which differed on that point AND
 * ignored the scheduling window entirely — a sale that had not started yet was
 * already discounting the filter.)
 *
 * THE WINDOW IS A BOUND PARAMETER, NOT NOW(). SQLite has no NOW(), and this
 * suite runs on SQLite while production runs MySQL. Binding a formatted
 * timestamp is the same thing ProductVisibility::schedule() does, for the same
 * reason, and it also means the comparison cannot drift across statements
 * inside one request.
 *
 * SAFE UNDER ->count(). Laravel's Builder::setAggregate() clears `orders` and
 * the `order` binding group whenever the query has no GROUP BY, so the
 * `orderByRaw` below and its two bindings are both dropped before
 * ShopController's `(clone $query)->count()` runs — no stray placeholder, and
 * no ORDER BY on an aggregate for MySQL's ONLY_FULL_GROUP_BY to object to.
 */
final class EffectivePrice
{
    /** The CASE expression, with two `?` placeholders for the window. */
    public static function sql(string $table = 'products'): string
    {
        $c = static fn (string $column): string => $table === '' ? $column : $table . '.' . $column;

        return 'CASE WHEN ' . $c('sale_price') . ' IS NOT NULL'
            . ' AND (' . $c('sale_starts_at') . ' IS NULL OR ' . $c('sale_starts_at') . ' <= ?)'
            . ' AND (' . $c('sale_ends_at') . ' IS NULL OR ' . $c('sale_ends_at') . ' >= ?)'
            . ' THEN ' . $c('sale_price') . ' ELSE ' . $c('price') . ' END';
    }

    /**
     * The bindings for sql(). Both are the same instant — the expression asks
     * about one moment from two directions.
     *
     * @return array{0: string, 1: string}
     */
    public static function bindings(): array
    {
        $now = now()->format('Y-m-d H:i:s');

        return [$now, $now];
    }

    /** Order by what the shopper is actually charged. */
    public static function orderBy(mixed $query, string $direction = 'asc', string $table = 'products'): mixed
    {
        $direction = strtolower($direction) === 'desc' ? 'desc' : 'asc';

        return $query->orderByRaw(self::sql($table) . ' ' . $direction, self::bindings());
    }

    /**
     * Keep only rows that are ON SALE RIGHT NOW — Product::isOnSale() in SQL.
     *
     * isOnSale() is `effectivePrice() < price`, so it is false for a sale whose
     * window has not opened and for one that has closed. The shop's "On sale"
     * facet asked the raw columns instead — `sale_price IS NOT NULL AND
     * sale_price < price` — which is true from the moment a markdown is
     * scheduled. A sale set up for next week therefore listed today, at full
     * price, with no Sale badge on the card, because ProductLabels draws that
     * badge from isOnSale() and the two did not agree.
     */
    public static function whereOnSale(mixed $query, string $table = 'products'): mixed
    {
        $price = $table === '' ? 'price' : $table . '.price';

        return $query->whereRaw(self::sql($table) . ' < ' . $price, self::bindings());
    }

    /**
     * Keep only rows whose charged price falls in [$minFils, $maxFils].
     * Either bound may be null for "no limit at that end".
     */
    public static function whereRange(mixed $query, ?int $minFils, ?int $maxFils, string $table = 'products'): mixed
    {
        if ($minFils !== null) {
            $query->whereRaw(self::sql($table) . ' >= ?', [...self::bindings(), $minFils]);
        }

        if ($maxFils !== null) {
            $query->whereRaw(self::sql($table) . ' <= ?', [...self::bindings(), $maxFils]);
        }

        return $query;
    }
}
