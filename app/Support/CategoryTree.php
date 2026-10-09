<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\Controllers\Store\ShopController;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The one rule for a category's cached `path` and `depth`. (Lane CH)
 *
 * `path` is the category's ADDRESS -- the sitemap, the menus, canonical tags,
 * the import's redirect map and the admin all read it as the thing after
 * /collections/. `depth` is how deep it sits in the tree. They used to be the
 * same walk; since `categories.short_url` they are not:
 *
 *   short_url = 1   path = its own slug, whatever its parents are
 *   short_url = 0   path = its parent's path + '/' + its own slug (the old rule)
 *
 * so the owner can put Toners under Skincare and /collections/toners/ stays
 * /collections/toners/. A legacy (short_url = 0) tree composes exactly as it
 * always did, which is why every nested-address test in the suite still holds.
 *
 * Whole-table, one SELECT, written with the query builder so `updated_at` --
 * the sitemap's <lastmod> -- is left alone. The tree is tens of rows.
 */
final class CategoryTree
{
    public const MAX_DEPTH = 10;

    /**
     * path and depth for every row, from the rows themselves.
     *
     * @param  iterable<object{id:int|string, slug:string, parent_id:int|string|null, short_url?:mixed}>  $rows
     * @return array<int, array{path:string, depth:int}>
     */
    public static function compute(iterable $rows): array
    {
        $byId = [];

        foreach ($rows as $row) {
            $byId[(int) $row->id] = $row;
        }

        $out = [];

        foreach ($byId as $id => $row) {
            $out[$id] = self::walk($id, $byId);
        }

        return $out;
    }

    /**
     * Rewrite every row whose cached path or depth is stale. Returns how many.
     */
    public static function resync(): int
    {
        $rows = DB::table('categories')->select('id', 'slug', 'parent_id', 'path', 'depth', 'short_url')->get();
        $computed = self::compute($rows);
        $written = 0;

        foreach ($rows as $row) {
            $want = $computed[(int) $row->id];

            if ($want['path'] === (string) ($row->path ?? '') && $want['depth'] === (int) ($row->depth ?? -1)) {
                continue;
            }

            DB::table('categories')->where('id', $row->id)->update($want);
            $written++;
        }

        return $written;
    }

    /**
     * Every cache that holds a picture of the category tree.
     *
     * Model hooks evict ProductTabs and BuyTogetherPairs on an Eloquent save;
     * a query-builder write (resync(), the hierarchy apply) saves no model, so
     * they are evicted here by hand.
     */
    public static function flushCaches(): void
    {
        ShopController::flushSidebarCache();
        Cache::forget('kbb.home.cats');
        Cache::forget('kbb.home.rails');
        ProductTabs::flush();
        \App\Services\BuyTogetherPairs::forget();
        CategoryRollup::flush();
    }

    /**
     * @param  array<int, object>  $byId
     * @return array{path:string, depth:int}
     */
    private static function walk(int $id, array $byId): array
    {
        $row = $byId[$id];
        $segments = [(string) $row->slug];
        $depth = 0;
        $prefixClosed = ! empty($row->short_url);
        $parent = $row->parent_id === null ? null : (int) $row->parent_id;
        $guard = 0;

        // The guard is belt and braces: every writer refuses a cycle, but rows
        // imported from WooCommerce were never checked.
        while ($parent !== null && isset($byId[$parent]) && $guard++ < self::MAX_DEPTH) {
            $node = $byId[$parent];
            $depth++;

            if (! $prefixClosed) {
                array_unshift($segments, (string) $node->slug);
                // A short ancestor's address is its own slug, so the prefix it
                // lends a legacy child stops there.
                $prefixClosed = ! empty($node->short_url);
            }

            $parent = $node->parent_id === null ? null : (int) $node->parent_id;
        }

        return ['path' => implode('/', $segments), 'depth' => $depth];
    }
}
