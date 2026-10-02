<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * How often each product is looked at, one row per product per day. (Lane RB)
 *
 * "Buy these together" offers MOST VIEWED as a rule because the owner asked
 * for "best visits", and this shop recorded no views at all — the only trace
 * of a visit was the `kbb_viewed` cookie, which lives in the shopper's browser.
 *
 * ── WHAT IT COSTS, AND WHERE ────────────────────────────────────────────────
 *
 *   the product page   NOTHING. The page renders no query for this. A small
 *                      script sends one beacon AFTER the page has loaded
 *                      (navigator.sendBeacon — it never delays anything the
 *                      shopper sees), at most once per product per browser
 *                      per day, and only on a page that draws the section.
 *   the beacon         one UPSERT on its own request: `views = views + 1` on
 *                      the (product, day) row. Throttled per address, refused
 *                      for a product that is not visible, CSRF-checked like
 *                      every other POST on the storefront. A crawler that
 *                      runs no JavaScript is never counted.
 *   the ranking        a SUM over the last 30 days, inside the cold query of
 *                      App\Services\BuyTogether, which is cached ten minutes.
 *
 * Rows older than 90 days are pruned, now and then, by the beacon itself —
 * there is no scheduler on this host.
 */
final class ProductViews
{
    public const TABLE = 'product_view_days';

    /** The window "most viewed" ranks over. */
    public const WINDOW_DAYS = 30;

    public const KEEP_DAYS = 90;

    /** Count one view of a product today. Never throws. */
    public static function record(int $productId): void
    {
        if ($productId <= 0) {
            return;
        }

        try {
            DB::table(self::TABLE)->upsert(
                [['product_id' => $productId, 'day' => now()->toDateString(), 'views' => 1]],
                ['product_id', 'day'],
                ['views' => DB::raw('views + 1')],
            );

            if (random_int(1, 200) === 1) {
                DB::table(self::TABLE)->where('day', '<', now()->subDays(self::KEEP_DAYS)->toDateString())->delete();
            }
        } catch (\Throwable) {
            // A counter is not worth a 500 — a missing table before the
            // migration has run, a locked database, any of it.
        }
    }

    /** The views a product had in the window, as a correlated subquery. */
    public static function windowSum(): Builder
    {
        return DB::table(self::TABLE.' as pvd')
            ->selectRaw('COALESCE(SUM(pvd.views), 0)')
            ->whereColumn('pvd.product_id', 'products.id')
            ->where('pvd.day', '>=', now()->subDays(self::WINDOW_DAYS)->toDateString());
    }
}
