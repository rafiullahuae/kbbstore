<?php

declare(strict_types=1);

namespace App\Services\CartTracking;

use App\Models\IpBlock;
use App\Services\Security\IpBlockList;
use Illuminate\Support\Facades\DB;

/**
 * Retention for Cart Tracking.                                     (Lane CT)
 *
 * Events older than `keep_days` (default 180) are deleted, in batches of
 * BATCH rows, at most `$batches` batches per run — so one run is a bounded few
 * hundred milliseconds however far behind it is, and a backlog clears over
 * several runs rather than in one long lock.
 *
 * BEFORE A BATCH GOES, IT IS COUNTED. Per product: times added and removed,
 * the carts that did it and, of those, the carts that became orders — into
 * cart_product_totals, which the "All time" product rankings add back. So
 * retention shortens the timeline of an old cart, never the all-time counts.
 *
 * CARTS ARE KEPT FOR EVER: their summary columns (value, counts, address,
 * bot score, order) are on the cart row and are not touched here.
 *
 * Also lifts blocks that expired more than 30 days ago, and recompiles the
 * block list if any expired at all (an expired block is already ignored by
 * the request path; this only tidies the Blocked tab).
 *
 * Runs from CartTrackingTick (after a response, at most every six hours) and
 * from `php artisan kbb:cart-tracking-prune` on the scheduler. Never on a
 * shopper's request.
 */
final class CartTrackingPrune
{
    public const BATCH = 2000;

    public function __construct(private CartTrackingSettings $settings) {}

    /** @return array{events:int, blocks:int} */
    public function run(int $batches = 5): array
    {
        $cutoff = now()->subDays((int) $this->settings->get('keep_days'));
        $deleted = 0;

        for ($i = 0; $i < $batches; $i++) {
            $ids = DB::table('cart_events')
                ->whereIn('type', [CartTracker::ADD, CartTracker::REMOVE, CartTracker::QTY])
                ->where('created_at', '<', $cutoff)
                ->orderBy('id')
                ->limit(self::BATCH)
                ->pluck('id')
                ->all();

            if ($ids === []) {
                break;
            }

            DB::transaction(function () use ($ids) {
                $this->rollUp($ids);
                DB::table('cart_events')->whereIn('id', $ids)->delete();
            });

            $deleted += count($ids);

            if (count($ids) < self::BATCH) {
                break;
            }
        }

        $blocks = IpBlock::query()->whereNotNull('expires_at')->where('expires_at', '<', now()->subDays(30))->delete();

        if ($blocks > 0) {
            IpBlockList::rebuild();
        }

        return ['events' => $deleted, 'blocks' => (int) $blocks];
    }

    /** Count these events into cart_product_totals before they are deleted. */
    private function rollUp(array $ids): void
    {
        $rows = DB::table('cart_events as e')
            ->leftJoin('carts as c', 'c.id', '=', 'e.cart_id')
            ->whereIn('e.id', $ids)
            ->whereNotNull('e.product_id')
            ->whereIn('e.type', [CartTracker::ADD, CartTracker::REMOVE])
            ->selectRaw(
                'e.product_id,'
                .' SUM(CASE WHEN e.type = 1 THEN 1 ELSE 0 END) AS added,'
                .' COUNT(DISTINCT CASE WHEN e.type = 1 THEN e.cart_id END) AS added_carts,'
                .' COUNT(DISTINCT CASE WHEN e.type = 1 AND c.ct_order_id IS NOT NULL THEN e.cart_id END) AS ordered_carts,'
                .' SUM(CASE WHEN e.type = 2 THEN 1 ELSE 0 END) AS removed,'
                .' COUNT(DISTINCT CASE WHEN e.type = 2 THEN e.cart_id END) AS removed_carts'
            )
            ->groupBy('e.product_id')
            ->get();

        $now = now();

        foreach ($rows as $r) {
            $pid = (int) $r->product_id;
            $add = ['added' => (int) $r->added, 'added_carts' => (int) $r->added_carts,
                'ordered_carts' => (int) $r->ordered_carts, 'removed' => (int) $r->removed,
                'removed_carts' => (int) $r->removed_carts];

            $updated = DB::table('cart_product_totals')->where('product_id', $pid)->update([
                'added' => DB::raw('added + '.$add['added']),
                'added_carts' => DB::raw('added_carts + '.$add['added_carts']),
                'ordered_carts' => DB::raw('ordered_carts + '.$add['ordered_carts']),
                'removed' => DB::raw('removed + '.$add['removed']),
                'removed_carts' => DB::raw('removed_carts + '.$add['removed_carts']),
                'updated_at' => $now,
            ]);

            if ($updated === 0) {
                DB::table('cart_product_totals')->insert(['product_id' => $pid] + $add + ['updated_at' => $now]);
            }
        }
    }
}
