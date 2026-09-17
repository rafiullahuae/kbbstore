<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How many customers bought this product AGAIN — measured, not asserted.
 *
 * ── WHY THIS EXISTS ─────────────────────────────────────────────────────────
 *
 * Store\CollectionController's /best-sellers/ page introduced itself with:
 *
 *     "The products our customers keep coming back for."
 *
 * and selected its products with `ORDER BY total_sales DESC`. `total_sales` is
 * a UNIT COUNT — how many of the thing left the shelf, imported from
 * WooCommerce and incremented per item sold. It says nothing whatever about
 * whether anybody came back. A product bought once by three hundred different
 * people and a product bought three hundred times by one devoted customer are
 * the same number in that column, and only one of them is what the sentence
 * describes. The home page makes the same claim in its own words
 * (store/home.blade.php: "The products customers keep coming back for.", under
 * a heading that also says "This month" over a lifetime figure).
 *
 * The claim was not wrong because nobody could compute it. It was wrong because
 * nobody had. `orders` and `order_items` carry everything needed:
 * `order_items.product_id`, `orders.email`, `orders.status` and one order id per
 * purchase occasion. So this class answers the question the sentence asks.
 *
 * ── WHAT "CAME BACK" IS TAKEN TO MEAN ───────────────────────────────────────
 *
 * ONE BUYER, ONE PRODUCT, MORE THAN ONE ORDER. Two of the same item in a single
 * basket is not coming back — it is one shopping trip — so the count is over
 * DISTINCT order ids, never over line quantities.
 *
 * THE BUYER IS THE EMAIL, LOWER-CASED. `orders.customer_id` is null for every
 * guest checkout, and this shop takes guest orders; grouping on it would
 * discard most of the history. The email is the only identity a guest and a
 * registered customer share, it is present on every row (the column is indexed
 * and not nullable), and it is what the shop itself uses to find a person's
 * orders. Two addresses belonging to one human read as two people — that
 * UNDERCOUNTS repeat purchase, which is the safe direction for a number that
 * backs a claim.
 *
 * ONLY ORDERS THAT HAPPENED. Order::REAL_STATUSES — the shop's own definition
 * of a completed order, already shared by the revenue figures and the admin's
 * product order-counts — plus a soft-delete check, because a deleted order is
 * one the owner decided did not count.
 *
 * ── WHAT THIS DELIBERATELY DOES NOT DO ──────────────────────────────────────
 *
 * IT DOES NOT MAKE THE CLAIM UNCONDITIONALLY. intro() hands back the "keep
 * coming back for" sentence only when the measurement actually found somebody
 * coming back; otherwise it says what the page is really sorted by. A shop with
 * no order history yet — a fresh install, a staging copy, the day before the
 * WooCommerce import lands — must not print a sentence about customer loyalty
 * it has no evidence for, and that is the common case, not the exotic one.
 *
 * IT IS NOT AN OWNER-EDITABLE CLAIM. App\Support\TrustClaims is for things only
 * the owner can know. This is a COUNT, and TrustClaims says in as many words
 * that counts are measured from the catalogue and never typed into a box,
 * because a typed number is a number free to drift from the truth.
 *
 * ── COST ────────────────────────────────────────────────────────────────────
 *
 * The measurement scans the order history, so it is cached and the ORDER of a
 * storefront page is decided from the cached map rather than from a join. That
 * matters on this host: /best-sellers/ is a public page on shared hosting, and
 * a derived table over every order line, twice per request (the paginator counts
 * as well as selects), is not a page-load cost worth paying for a sort.
 *
 * The map is capped, because only the head of the list can be ordered by it
 * anyway — anything past the cap ties at zero and falls through to the ordering
 * the page has always had, which is exactly the behaviour a shop with no repeat
 * purchases gets.
 */
final class RepeatPurchase
{
    /** Where the measured map is kept. */
    public const CACHE_KEY = 'kbb.repeat_purchase.counts';

    /**
     * Fifteen minutes, matching the product page's related-products cache.
     *
     * Nothing invalidates this when an order lands, on purpose: an order that
     * has just been placed cannot be a repeat purchase yet in any sense a
     * shopper would notice, and hooking cache eviction into the checkout to
     * reorder a listing page would put a write on the one path that must not
     * grow one.
     */
    public const TTL = 900;

    /**
     * How many products the map may carry.
     *
     * A collection page shows 24 at a time. Ordering the first few pages by a
     * measured figure and letting the tail fall back to units sold is the whole
     * of the benefit; carrying ten thousand ids to sort a page nobody scrolls
     * to would be a long ORDER BY for nothing.
     */
    public const MAX_PRODUCTS = 60;

    /** The wording when the measurement supports it — the sentence that shipped. */
    public const INTRO_MEASURED = 'The products our customers keep coming back for.';

    /** The wording when it does not: what the page is actually sorted by. */
    public const INTRO_BY_UNITS = 'Our best sellers, by the number of units sold.';

    /**
     * product id => how many separate customers bought it in more than one
     * order. Highest first, capped at MAX_PRODUCTS.
     *
     * @return array<int, int>
     */
    public static function counts(): array
    {
        $cached = Cache::remember(self::CACHE_KEY, self::TTL, static fn (): array => self::measure());

        return is_array($cached) ? $cached : [];
    }

    /** Has anybody, anywhere in the history, bought the same product twice? */
    public static function any(): bool
    {
        return self::counts() !== [];
    }

    /**
     * The intro /best-sellers/ can actually stand behind.
     *
     * Two sentences, one condition, and the condition is the measurement. This
     * is the whole public point of the class: a page may only say customers keep
     * coming back if some of them did.
     */
    public static function intro(): string
    {
        return self::any() ? self::INTRO_MEASURED : self::INTRO_BY_UNITS;
    }

    /**
     * Order a product query the way the "keep coming back" sentence describes:
     * repeat buyers first, then the units-sold ordering the page has always had.
     *
     * WITH NO REPEAT PURCHASES ANYWHERE this is byte for byte the ordering
     * /best-sellers/ already applies, so a shop with no order history sees no
     * change at all — and intro() is telling it the units-sold sentence, so the
     * page and its own description still agree.
     *
     * THE IDS ARE INTERPOLATED, NOT BOUND, and that is safe here rather than
     * sloppy: every value in the CASE comes back out of the database as an
     * integer and is cast to one again on the way in, so there is no string to
     * escape. Sixty bound parameters on a listing query would also be sixty
     * placeholders the paginator repeats on its COUNT — see Tests\Support\SqlShape
     * on what happens when placeholders and bindings stop agreeing.
     *
     * @param  Builder<\App\Models\Product>  $query
     * @return Builder<\App\Models\Product>
     */
    public static function applyTo(Builder $query): Builder
    {
        $counts = self::counts();

        if ($counts !== []) {
            $case = 'CASE ' . $query->getModel()->getTable() . '.id';

            foreach ($counts as $productId => $buyers) {
                $case .= ' WHEN ' . (int) $productId . ' THEN ' . (int) $buyers;
            }

            $query->orderByRaw($case . ' ELSE 0 END DESC');
        }

        return $query->orderByDesc('total_sales')->orderByDesc('review_count');
    }

    /** Throw the cached map away — for tests, and for anything that backfills orders. */
    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * The measurement itself.
     *
     * Two levels: group by (product, buyer) and keep the pairs with more than
     * one order, then count the surviving pairs per product. Written as a
     * derived table rather than as two round trips so the whole thing is one
     * statement on either engine.
     *
     * PORTABILITY, because the suite runs on SQLite and the shop runs MySQL:
     * LOWER() and COUNT(DISTINCT ...) exist on both, the outer query carries a
     * GROUP BY so no aggregate sits beside a bare column, and the alias
     * `repeat_buyers` is used only in ORDER BY — never in a WHERE, which MySQL
     * would refuse.
     *
     * @return array<int, int>
     */
    private static function measure(): array
    {
        // A tree mid-migration, or a package applied to a database that has not
        // caught up, must not take a storefront page down over a sort order.
        if (! Schema::hasTable('order_items') || ! Schema::hasTable('orders')) {
            return [];
        }

        $pairs = DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->whereNull('o.deleted_at')
            ->whereIn('o.status', Order::REAL_STATUSES)
            ->whereNotNull('oi.product_id')
            ->where('o.email', '<>', '')
            ->groupBy('oi.product_id', DB::raw('LOWER(o.email)'))
            // Separate ORDERS, not line quantities: two jars in one basket is
            // one shopping trip, not a customer coming back.
            ->havingRaw('COUNT(DISTINCT o.id) > 1')
            ->select('oi.product_id as product_id');

        $rows = DB::query()
            ->fromSub($pairs, 'rp')
            ->select('rp.product_id')
            ->selectRaw('COUNT(*) as repeat_buyers')
            ->groupBy('rp.product_id')
            ->orderByDesc('repeat_buyers')
            ->limit(self::MAX_PRODUCTS)
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $out[(int) $row->product_id] = (int) $row->repeat_buyers;
        }

        return $out;
    }
}
