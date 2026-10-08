<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Category;
use App\Models\Product;

/**
 * Which of a product's categories is ITS category, and the path to it. (Lane BC)
 *
 * ONE answer for the whole product page: "More {category}" — the tab, or
 * the block (App\Services\ProductRecs) — the visible breadcrumb and the BreadcrumbList
 * JSON-LD all ask this class, so they can never name two different shelves.
 * The owner, of block 2: "if the product is from the toner category, then the
 * 2nd block will pick the products from that category" — and then yes to the
 * breadcrumb following the same rule: Home › Skincare › Toner › Product.
 *
 * ── THE RULE ───────────────────────────────────────────────────────────────
 *
 *   1  never a category that is the parent of another of the product's
 *      categories (holds even before CategoryTree has resynced `depth`);
 *   2  then the greatest `depth`;
 *   3  equally deep: ProductRecs settles it by in-stock products besides this
 *      one, counted inside its own union, and hands the id over (primary()'s
 *      $chosen); with no such count — block 2 off — the lowest id.
 *
 * Sorted by id before anything else, so the order the pivot rows were written
 * in — which is all `categories->first()` used to reflect — cannot matter.
 *
 * ── NO QUERY ───────────────────────────────────────────────────────────────
 *
 * Everything comes from the `categories` relation the product page already
 * loads (id, name, slug, path, parent_id, depth). trail() walks parent_id
 * through THOSE rows: a parent the product is also filed under joins the path
 * (Home › Skincare › Toner); a parent it is not filed under is not known
 * without a query, so the path starts at the first category the product has.
 * That is exactly today's crumb for a product filed under one category.
 */
final class ProductCategory
{
    /** A trail longer than this is a cycle in imported data, not a shop. */
    private const MAX_DEPTH = 8;

    /**
     * The product's most specific categories — usually one; more only when
     * equally deep, in id order.
     *
     * @return list<Category>
     */
    public static function candidates(Product $product): array
    {
        if (! $product->relationLoaded('categories') || $product->categories->isEmpty()) {
            return [];
        }

        $cats = $product->categories->sortBy(fn ($cat) => (int) $cat->id)->values();
        $parents = $cats->pluck('parent_id')->filter()->map(fn ($id) => (int) $id)->flip();
        $leaves = $cats->reject(fn ($cat) => isset($parents[(int) $cat->id]));
        $leaves = $leaves->isEmpty() ? $cats : $leaves; // a cycle: every one is a parent
        $deepest = (int) $leaves->max(fn ($cat) => (int) $cat->getAttribute('depth'));

        return $leaves->filter(fn ($cat) => (int) $cat->getAttribute('depth') === $deepest)->values()->all();
    }

    /**
     * The product's category: $chosen when it is one of the candidates (the
     * id ProductRecs settled a tie with), else the first candidate.
     */
    public static function primary(Product $product, ?int $chosen = null): ?Category
    {
        $candidates = self::candidates($product);

        foreach ($candidates as $cat) {
            if ($chosen !== null && (int) $cat->id === $chosen) {
                return $cat;
            }
        }

        return $candidates[0] ?? null;
    }

    /**
     * Root first, the product's category last, through the categories the
     * product is filed under. Empty when it has none.
     *
     * @return list<Category>
     */
    public static function trail(Product $product, ?int $chosen = null): array
    {
        $leaf = self::primary($product, $chosen);

        if ($leaf === null) {
            return [];
        }

        $byId = $product->categories->keyBy(fn ($cat) => (int) $cat->id);
        $trail = [$leaf];
        $seen = [(int) $leaf->id => true];
        $node = $leaf;

        while (count($trail) < self::MAX_DEPTH && $node->parent_id !== null) {
            $up = $byId->get((int) $node->parent_id);

            if ($up === null || isset($seen[(int) $up->id])) {
                break;
            }

            array_unshift($trail, $up);
            $seen[(int) $up->id] = true;
            $node = $up;
        }

        return $trail;
    }
}
