<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Product;
use App\Services\CartPanel;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The cart panel's two product lists -- Browsed and the heart tab -- in ONE
 * query. (Lane HLB, 17 October 2026)
 *
 * The heart tab is the wishlist moved into the panel on phones (the owner:
 * "in mobile the wishlist will be move to the cart panel beside browsed tab").
 * Its products are read in the SAME statement as Browsed's: one whereIn over
 * both id lists, one brand eager load, one SetEagerLoad sweep. So a shopper
 * with a browsing history costs exactly the queries the panel cost before, a
 * wishlist of 3 and one of 20 cost the same, and a first visit or a crawler --
 * no cookies -- still runs NO query at all (Rule 27).
 *
 * The wishlist is the `kbb_wishlist` cookie WishlistController writes (most
 * recent first). The panel lists the newest WISH_MAX of them; the full list is
 * /my-wishlist/, which the tab links to when there are more.
 */
final class CartPanelLists
{
    public const WISHLIST_COOKIE = 'kbb_wishlist';

    /** How many saved products the panel lists. The wishlist page shows all. */
    public const WISH_MAX = 20;

    /*
     * The heart tab's only content (the owner: "only the heart icon"): outlined
     * while the wishlist is empty, filled once it holds something -- no count
     * (round 2's "no count" choice). Constants, printed unescaped; wishlist.js
     * swaps the class and the fill when a heart is pressed.
     */
    public const HEART_EMPTY = '<svg class="kc-wh" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linejoin="round" aria-hidden="true"><path d="M12 20.5s-7.5-4.6-9.2-9.4A5 5 0 0 1 12 6a5 5 0 0 1 9.2 5.1C19.5 15.9 12 20.5 12 20.5z"/></svg>';

    public const HEART_FULL = '<svg class="kc-wh on" width="18" height="18" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="1.9" stroke-linejoin="round" aria-hidden="true"><path d="M12 20.5s-7.5-4.6-9.2-9.4A5 5 0 0 1 12 6a5 5 0 0 1 9.2 5.1C19.5 15.9 12 20.5 12 20.5z"/></svg>';

    /**
     * The saved product ids, newest first, at most WISH_MAX -- or [] when the
     * panel has no heart tab, so a shop that switched it off asks nothing.
     *
     * @return int[]
     */
    public static function wishIds(Request $request): array
    {
        // The cookie first: a visitor without one (every first visit, every
        // crawler) is answered without even reading a setting (Rule 27).
        $ids = self::allWishIds($request);

        if ($ids === [] || ! app(CartPanel::class)->wishTab()) {
            return [];
        }

        return array_slice($ids, 0, self::WISH_MAX);
    }

    /** How many products the cookie holds in all (the tab's "see all" line). */
    public static function wishCount(Request $request): int
    {
        return count(self::allWishIds($request));
    }

    /** @return int[] */
    private static function allWishIds(Request $request): array
    {
        $raw = (string) $request->cookie(self::WISHLIST_COOKIE, '');

        return array_values(array_unique(array_filter(array_map('intval', explode(',', $raw)), static fn (int $id): bool => $id > 0)));
    }

    /**
     * Both lists from one statement, each in its own order.
     *
     * @param  int[]  $browsedIds
     * @param  int[]  $wishIds
     * @param  string[]  $columns
     * @return array{0: Collection, 1: Collection}  [browsed, wish]
     */
    public static function fetch(array $browsedIds, array $wishIds, array $columns): array
    {
        $all = array_values(array_unique(array_merge($browsedIds, $wishIds)));

        if ($all === []) {
            return [collect(), collect()];
        }

        $rows = Product::query()->select($columns)->visible()
            ->whereIn('id', $all)->with('brand:id,name,slug')->get()
            ->keyBy('id');

        // A SET in either list draws its contents too, and costs nothing when
        // none of them is one. (Lane SET)
        SetEagerLoad::on($rows->values());

        // Each list in its own cookie's order (the SQL IN() keeps none), each
        // product once, hidden or deleted ones dropped -- what the two
        // sortBy(array_search) copies this replaces answered.
        $pick = static fn (array $ids): Collection => new EloquentCollection(array_values(array_filter(array_map(
            static fn (int $id) => $rows->get($id),
            array_values(array_unique($ids)),
        ))));

        return [$pick($browsedIds), $pick($wishIds)];
    }
}
