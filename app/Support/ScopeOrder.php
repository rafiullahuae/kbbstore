<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * EVERY CATEGORY AND EVERY BRAND KEEPS ITS OWN ORDER. (Lane SO)
 *
 * The owner, 5 October: "I have fixed the sorting on Medicube brand. and then
 * i did the super sale category. The medicube products are also in super sale
 * category ... when i did the sorting of Super Sale category, then it also
 * effected the Medicube brand's sorting too ... NO ANY CATEGORY OR BRAND should
 * disturb the sorting of each other in any case."
 *
 * Catalog → Reorder wrote ONE number per product, `products.position`, and
 * every category page, every brand page and /super-sale/ sorted on it. So
 * saving Super Sale renumbered the Medicube products it shares with the brand,
 * and the brand page moved.
 *
 * ── WHERE EACH ORDER LIVES, AND WHY THERE ───────────────────────────────────
 *
 *   category   `category_product.category_position` -- a column on the row
 *              that already says "this product is in this category".
 *   brand      `products.brand_position` -- a product has exactly one brand
 *              (`products.brand_id`), so the brand's order is one more column
 *              on the row that already says which brand it is in.
 *
 * Not a separate (scope_type, scope_id, product_id) table, because both of
 * these places DELETE THEMSELVES with the membership they describe: untick a
 * category and its pivot row -- position and all -- is gone; delete a product
 * and the FK cascade takes its pivot rows; and Product's `saving` hook clears
 * `brand_position` when `brand_id` changes. A separate table would need every
 * place that writes `category_product` to remember it, and the one that
 * forgot would leave a stale order waiting for the day the
 * product came back. Here nothing can forget. It is also the lighter read: a
 * brand page sorts on a column of the rows it already reads, with no join, and
 * a category page replaces the EXISTS it already ran with one join on the
 * pivot's own primary key (category_id, product_id).
 *
 * NULL means "never ordered here": a product added to a category after its
 * order was set, or a product new to a brand. It goes AFTER the ordered ones
 * (`IS NULL` sorts false first on MySQL and SQLite alike), and the page's own
 * tie-break decides among them -- featured, then name, then id on a category
 * page, as before.
 *
 * Column names nobody else uses, on purpose: `category_product` sits in joins
 * all over this codebase, and a second column called `position` there would
 * turn every unqualified `orderBy('position')` across such a join into
 * "Column 'position' is ambiguous" on MySQL.
 *
 * /shop/, search, concern collections and the [kbb_products] shortcode keep
 * the global `products.position` exactly as before: none of them is a
 * category or a brand, and Reorder has no scope for them.
 */
final class ScopeOrder
{
    public const CATEGORY_COLUMN = 'category_position';

    public const BRAND_COLUMN = 'brand_position';

    /** The derived table's alias, and the only two names it exposes. */
    public const ALIAS = 'kso';

    /**
     * Narrow to one category's products and bring its own order alongside: ONE
     * join, on the pivot's primary key (category_id, product_id).
     *
     * A DERIVED table rather than a plain join, so the only columns it adds to
     * the query are `kso_pid` and `kso_pos`. Several callers select their card
     * columns unqualified (`id`, `position`, ...), and a joined
     * `category_product` would make `category_id` ambiguous there. MySQL and
     * SQLite both merge a derived table this simple into the outer join.
     *
     * @param  int|string  $category  the category's id, or its slug
     */
    public static function inCategory(Builder $query, int|string $category): Builder
    {
        $rows = DB::table('category_product')
            ->select(['category_product.product_id as kso_pid', 'category_product.'.self::CATEGORY_COLUMN.' as kso_pos']);

        if (is_int($category)) {
            $rows->where('category_product.category_id', $category);
        } else {
            // categories.slug is unique, so this is still one row per product.
            $rows->whereIn('category_product.category_id', static fn (QueryBuilder $q) => $q
                ->select('categories.id')->from('categories')->where('categories.slug', $category));
        }

        return $query->joinSub($rows, self::ALIAS, self::ALIAS.'.kso_pid', '=', 'products.id');
    }

    /** The category's own order, never-ordered products last. Needs inCategory(). */
    public static function orderInCategory(Builder $query): Builder
    {
        return $query->orderByRaw(self::ALIAS.'.kso_pos IS NULL')->orderBy(self::ALIAS.'.kso_pos');
    }

    /** The brand's own order, never-ordered products last. */
    public static function orderInBrand(Builder $query): Builder
    {
        return $query->orderByRaw('products.'.self::BRAND_COLUMN.' IS NULL')->orderBy('products.'.self::BRAND_COLUMN);
    }
}
