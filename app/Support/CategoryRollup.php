<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Category;
use App\Models\Product;
use App\Services\SiteLayout;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * A PARENT CATEGORY ALSO LISTS ITS SUB-CATEGORIES' PRODUCTS. (Lane SC)
 *
 * The owner: "we have sub categories, but i want that all the products of sub
 * categories should also show in the main parent category too, automatically.
 * give this option on backend also."
 *
 * What it looked like before: a category page listed exactly the products with
 * a `category_product` row for THAT category (ScopeOrder::inCategory(), one
 * join on the pivot's key). Skincare with Toners and Serums under it, and its
 * products filed only under those, showed one card -- or "No products".
 *
 * ── WHERE THE CHOICE LIVES ──────────────────────────────────────────────────
 *
 *   the shop     Appearance → Site layout → Product grid → "Sub-category
 *                products on a parent category" (`layout_sub_products`).
 *                Ships "include", because he asked for it.
 *   a category   `categories.sub_products`; NULL = follow the shop.
 *
 * The choice is the PARENT's: a parent that includes lists everything below
 * it at any depth, whatever each child chose for its own page.
 *
 * ── WHAT IT COSTS ───────────────────────────────────────────────────────────
 *
 * Nothing per request while warm. The descendants come from one cached map of
 * the tree (id => parent, slug, choice), read once per request and evicted by
 * Category's own model hooks and CategoryTree::flushCaches(). The page's SQL
 * goes from `category_id = ?` to `category_id IN (...)` on the pivot's primary
 * key (category_id, product_id) with a GROUP BY product_id, so each product is
 * one row however many of the subtree's categories it is filed in. Same number
 * of statements for one child or thirty, three products or three hundred.
 */
final class CategoryRollup
{
    /** The column on `categories`; NULL means "use the shop setting". */
    public const COLUMN = 'sub_products';

    public const INCLUDE = 'include';

    public const OWN = 'own';

    /** Every stored value. A select stores one of these or nothing. */
    public const MODES = [self::INCLUDE, self::OWN];

    /** The per-category select, '' = follow the shop. */
    public const SCOPE_OPTIONS = [
        '' => 'Use the shop setting',
        self::INCLUDE => 'Include sub-categories',
        self::OWN => 'Only this category\'s own products',
    ];

    public const TREE_CACHE = 'kbb.cat.rollup.tree';

    public const COUNTS_CACHE = 'kbb.cat.rollup.counts';

    /** The /shop filter rail and the homepage tiles while anything rolls up. */
    public const SIDEBAR_CACHE = 'kbb.shop.cats.rollup';

    public const HOME_CACHE = 'kbb.home.cats.rollup';

    /**
     * The tree and its child index, held for ONE request: a scoped container
     * instance, not a static, so a queue worker, Octane or a test that runs
     * several requests in one process reads the cache afresh for each one
     * instead of keeping the first request's tree for good -- the
     * Setting::map() trap CLAUDE.md names.
     */
    private const MEMO = 'kbb.cat.rollup.memo';

    /** The shop-wide choice, always one of MODES. */
    public static function shopDefault(): string
    {
        $mode = app(SiteLayout::class)->get('sub_products');

        return in_array($mode, self::MODES, true) ? $mode : self::INCLUDE;
    }

    /** What a value posted from Catalog → Categories → Edit stores. */
    public static function clean(mixed $raw): ?string
    {
        return is_string($raw) && in_array($raw, self::MODES, true) ? $raw : null;
    }

    /** One category's effective choice, from the row the page already holds. */
    public static function modeFor(Category $category): string
    {
        $own = $category->getAttribute(self::COLUMN);

        return in_array($own, self::MODES, true) ? $own : self::shopDefault();
    }

    /**
     * The ids a category's page lists products from: its own id first, then
     * every descendant when it includes them.
     *
     * @return list<int>
     */
    public static function idsFor(Category $category): array
    {
        $id = (int) $category->id;

        return self::modeFor($category) === self::INCLUDE ? self::subtree($id) : [$id];
    }

    /**
     * The category ids a ?filter_cat= of these slugs stands for, each
     * expanded by its own choice -- or null when none of them lists anything
     * below it, so the caller keeps its slug query exactly as it was.
     * Unknown slugs contribute nothing.
     *
     * @param  list<string>  $slugs
     * @return list<int>|null
     */
    public static function expandSlugs(array $slugs): ?array
    {
        $bySlug = [];
        foreach (self::tree() as $id => [, $slug]) {
            $bySlug[$slug] = $id;
        }

        $ids = [];
        $grew = false;
        foreach ($slugs as $slug) {
            if (! isset($bySlug[$slug])) {
                continue;
            }
            $id = $bySlug[$slug];
            $set = self::rollsUp($id) ? self::subtree($id) : [$id];
            $grew = $grew || count($set) > 1;
            foreach ($set as $one) {
                $ids[$one] = true;
            }
        }

        return $grew ? array_keys($ids) : null;
    }

    /**
     * The category and everything below it, at any depth, the category first.
     * Cycle-safe: imported WooCommerce rows were never checked for one.
     *
     * @return list<int>
     */
    public static function subtree(int $id): array
    {
        $children = self::children();
        $out = [$id];
        $seen = [$id => true];

        for ($i = 0; $i < count($out); $i++) {
            foreach ($children[$out[$i]] ?? [] as $kid) {
                if (! isset($seen[$kid])) {
                    $seen[$kid] = true;
                    $out[] = $kid;
                }
            }
        }

        return $out;
    }

    /**
     * Visible-product counts for every category that lists more than its own
     * products: id => the number of distinct products its page lists before
     * filters. Categories missing from the map list only their own, and the
     * caller's own count stands. ONE query for the whole tree, cached like
     * the sidebar it feeds; no query at all when nothing rolls up.
     *
     * @return array<int, int>
     */
    public static function counts(): array
    {
        $parents = [];
        foreach (array_keys(self::tree()) as $id) {
            if (self::rollsUp($id) && isset(self::children()[$id])) {
                $parents[$id] = self::subtree($id);
            }
        }

        if ($parents === []) {
            return [];
        }

        return Cache::remember(self::COUNTS_CACHE.'.'.md5(json_encode($parents)), 900, static function () use ($parents): array {
            $all = array_values(array_unique(array_merge(...array_values($parents))));
            $q = Product::query()->visible()
                ->join('category_product', 'category_product.product_id', '=', 'products.id')
                ->whereIn('category_product.category_id', $all);

            foreach ($parents as $id => $ids) {
                // Integers from the cached tree, cast here: nothing a setting
                // or a request holds ever reaches this SQL.
                $list = implode(',', array_map('intval', $ids));
                $q->selectRaw('COUNT(DISTINCT CASE WHEN category_product.category_id IN ('.$list.') THEN products.id END) AS r'.(int) $id);
            }

            $row = (array) ($q->toBase()->first() ?? []);
            $out = [];
            foreach ($parents as $id => $ids) {
                $out[$id] = (int) ($row['r'.$id] ?? 0);
            }

            return $out;
        });
    }

    /**
     * Put the rolled-up count on each of these categories' `products_count`,
     * then drop the empty ones, re-sort and cut to $limit -- what the caller's
     * own HAVING / ORDER BY / LIMIT did on own counts. The caller passes its
     * query WITHOUT those three when anything rolls up (see hasRollups()).
     *
     * @param  callable(Category, Category): int  $order
     */
    public static function applyCounts(Collection $cats, callable $order, ?int $limit): Collection
    {
        $counts = self::counts();

        foreach ($cats as $c) {
            if (isset($counts[(int) $c->id])) {
                $c->setAttribute('products_count', $counts[(int) $c->id]);
            }
        }

        $out = $cats->filter(static fn ($c) => (int) $c->products_count > 0)->sort($order)->values();

        return $limit === null ? $out : $out->take($limit)->values();
    }

    /** True when at least one category with children lists them. No query while warm. */
    public static function hasRollups(): bool
    {
        foreach (array_keys(self::children()) as $id) {
            if (self::rollsUp($id)) {
                return true;
            }
        }

        return false;
    }

    /** Whether categories carry the column yet (admin screens only). */
    public static function columnReady(): bool
    {
        try {
            return \Illuminate\Support\Facades\Schema::hasColumn('categories', self::COLUMN);
        } catch (\Throwable) {
            return false;
        }
    }

    /** A category was saved, deleted or re-parented: rebuilt on next read. */
    public static function flush(): void
    {
        try {
            app()->forgetInstance(self::MEMO);
        } catch (\Throwable) {
        }

        try {
            Cache::forget(self::TREE_CACHE);
        } catch (\Throwable) {
            // An unreachable cache is not a failed category save.
        }

        // The rolled-up lists: a re-parented child or a changed choice moves
        // a parent's count, so they go with the tree.
        self::forgetLists();
    }

    /**
     * The rolled-up list caches are keyed by the shop's choice as well, so
     * flipping it in Site layout is seen at once rather than 15 minutes on.
     */
    public static function cacheKey(string $base): string
    {
        return $base.'.'.self::shopDefault();
    }

    public static function forgetLists(): void
    {
        try {
            foreach ([self::SIDEBAR_CACHE, self::HOME_CACHE] as $base) {
                foreach (self::MODES as $mode) {
                    Cache::forget($base.'.'.$mode);
                }
            }
        } catch (\Throwable) {
            // An unreachable cache is not a failed category save.
        }
    }

    private static function rollsUp(int $id): bool
    {
        $own = self::tree()[$id][2] ?? null;

        return (in_array($own, self::MODES, true) ? $own : self::shopDefault()) === self::INCLUDE;
    }

    /** @return array<int, list<int>> */
    private static function children(): array
    {
        $memo = self::memo();
        if (isset($memo['children'])) {
            return $memo['children'];
        }

        $out = [];
        foreach (self::tree() as $id => [$parent]) {
            if ($parent !== null && $parent !== $id) {
                $out[$parent][] = $id;
            }
        }

        return $memo['children'] = $out;
    }

    /** @return array<int, array{0:int|null, 1:string, 2:string|null}> */
    private static function tree(): array
    {
        $memo = self::memo();
        if (isset($memo['tree'])) {
            return $memo['tree'];
        }

        try {
            $rows = Cache::rememberForever(self::TREE_CACHE, static function (): array {
                try {
                    $rows = Category::query()->get(['id', 'parent_id', 'slug', self::COLUMN]);
                } catch (\Throwable) {
                    // A package applied before its migration: no column yet,
                    // every category follows the shop.
                    $rows = Category::query()->get(['id', 'parent_id', 'slug']);
                }

                $out = [];
                foreach ($rows as $r) {
                    $mode = $r->getAttribute(self::COLUMN);
                    $out[(int) $r->id] = [
                        $r->parent_id === null ? null : (int) $r->parent_id,
                        (string) $r->slug,
                        in_array($mode, self::MODES, true) ? $mode : null,
                    ];
                }

                return $out;
            });
        } catch (\Throwable) {
            $rows = [];
        }

        return $memo['tree'] = is_array($rows) ? $rows : [];
    }

    private static function memo(): \ArrayObject
    {
        $app = app();

        if (! $app->bound(self::MEMO)) {
            $app->scoped(self::MEMO, static fn () => new \ArrayObject);
        }

        return $app->make(self::MEMO);
    }
}
