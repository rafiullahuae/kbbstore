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
    /**
     * The charged price of a row in `products`, with FOUR `?` placeholders —
     * two for its own sale window and two for the same window applied to its
     * variations. Always paired with bindings(), which returns exactly four.
     *
     * ── WHY THERE IS A SECOND HALF AT ALL ───────────────────────────────────
     *
     * ownSql() below is what this class used to be, and for a simple product it
     * is still the whole answer. For a VARIABLE parent it answers NULL: a
     * WooCommerce variable product keeps its money on its variations and
     * `products.price` is NULL on the parent row. NULL is not a small number,
     * it is an absent one, and SQL treats the two differently in the two places
     * this expression is used — which is why the same NULL produced two
     * different wrong behaviours on the live shop:
     *
     *   - ORDER BY: NULL sorts FIRST ascending on both SQLite and MySQL, so
     *     "Price: low to high" opened with every variable product in the
     *     catalogue, ahead of a genuine AED 30 toner. Measured before the fix:
     *     a variable parent whose options run AED 120–190 sorted ahead of an
     *     AED 30 product.
     *   - WHERE: every comparison against NULL is NULL, which is not true, so
     *     `whereRange()` filed a variable product in NO price bucket at all.
     *     Measured before the fix: the same parent appeared under none of
     *     "Under AED 54", "AED 54 – 150", "AED 150 – 300" or "AED 300+", so a
     *     shopper who touched the price filter stopped being able to see it.
     *
     * So the parent's own figure is COALESCEd with the cheapest price its
     * variations actually charge — the "from" price, which is the single number
     * a range has to collapse to for an ORDER BY or a bucket, and the same
     * number Seo::aggregateOffer() publishes as `lowPrice`.
     *
     * COALESCE SHORT-CIRCUITS, on both engines: the subquery is evaluated only
     * for a row whose own price is NULL. A catalogue of simple products pays
     * for it exactly nowhere, and it is one statement either way — no N+1, no
     * join for a caller to remember, and every existing caller of orderBy(),
     * whereRange() and whereOnSale() picked the fix up without changing.
     *
     * A PARENT WHOSE VARIATIONS ARE ALL UN-PRICED STILL ANSWERS NULL, because
     * MIN() ignores NULLs and returns NULL over an empty set. That is
     * deliberate and it is the same fallback App\Services\VariantPricing takes
     * for that row: un-priced data is not a free product, and the honest answer
     * to "what does this cost" is still nothing-known. Such a row behaves
     * exactly as every variable parent did before this change, which is the
     * conservative direction.
     */
    public static function sql(string $table = 'products'): string
    {
        return 'COALESCE(' . self::ownSql($table) . ', ' . self::variantSql($table) . ')';
    }

    /**
     * The row's OWN charged price — this class's original CASE, unchanged, with
     * two `?` placeholders for the window.
     */
    public static function ownSql(string $table = 'products'): string
    {
        $c = static fn (string $column): string => $table === '' ? $column : $table . '.' . $column;

        return 'CASE WHEN ' . $c('sale_price') . ' IS NOT NULL'
            . ' AND (' . $c('sale_starts_at') . ' IS NULL OR ' . $c('sale_starts_at') . ' <= ?)'
            . ' AND (' . $c('sale_ends_at') . ' IS NULL OR ' . $c('sale_ends_at') . ' >= ?)'
            . ' THEN ' . $c('sale_price') . ' ELSE ' . $c('price') . ' END';
    }

    /**
     * The cheapest price this row's variations charge, as a correlated scalar
     * subquery. Two `?` placeholders, for the PARENT's window.
     *
     * The alias is `kbbv` rather than `v` because /shop already LEFT JOINs
     * `brands` when the shopper searches, and a one-letter alias is how two
     * unrelated pieces of SQL collide in a query neither of them wrote.
     */
    public static function variantSql(string $table = 'products'): string
    {
        $parent = $table === '' ? 'products' : $table;

        return '(SELECT MIN(' . self::variantChargedSql('kbbv', $parent) . ')'
            . ' FROM product_variants kbbv WHERE kbbv.product_id = ' . $parent . '.id)';
    }

    /**
     * ProductVariant::effectivePrice() in SQL: one variation's charged price,
     * with two `?` placeholders for its PARENT's window.
     *
     * ONE DEFINITION, TWO SHAPES. App\Services\VariantPricing needs this
     * expression grouped over a join (it builds the low/high range every tile
     * prints); variantSql() above needs it inside a correlated MIN (the sort
     * and the facet need one number per row). Those are different queries and
     * they must not be different ANSWERS — a tile saying "from AED 120" beside
     * a sort that thinks the product costs something else is precisely the
     * class of disagreement this file's header was written about. So the CASE
     * itself lives here once and both callers take it from here.
     *
     * `product_variants` carries no dates of its own: a variable product's
     * markdown is scheduled once, on its `products` row, for every variation
     * under it. Hence the window columns are the parent's.
     */
    public static function variantChargedSql(string $variant = 'kbbv', string $parent = 'products'): string
    {
        return 'CASE WHEN ' . $variant . '.sale_price IS NOT NULL'
            . ' AND (' . $parent . '.sale_starts_at IS NULL OR ' . $parent . '.sale_starts_at <= ?)'
            . ' AND (' . $parent . '.sale_ends_at IS NULL OR ' . $parent . '.sale_ends_at >= ?)'
            . ' THEN ' . $variant . '.sale_price ELSE ' . $variant . '.price END';
    }

    /**
     * The bindings for sql() — FOUR values, all the same instant: two for
     * ownSql()'s window and two for variantSql()'s.
     *
     * One instant asked about from four directions. Taking now() once matters
     * for the reason the header gives: the comparison must not drift between
     * the two halves of a single COALESCE, or a sale could be open on one side
     * of it and closed on the other.
     *
     * @return array{0: string, 1: string, 2: string, 3: string}
     */
    public static function bindings(): array
    {
        $now = now()->format('Y-m-d H:i:s');

        return [$now, $now, $now, $now];
    }

    /**
     * The window pair on its own — two values, for a caller that uses
     * variantChargedSql() or ownSql() directly rather than the whole of sql().
     *
     * @return array{0: string, 1: string}
     */
    public static function window(): array
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
