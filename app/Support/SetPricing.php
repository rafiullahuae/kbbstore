<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * What a Set costs, when the set's price is a RULE rather than a number.
 * (Lane SP)
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * A SET'S PRICE IS DERIVED ON EVERY READ, SO A MEMBER'S PRICE DROP REACHES IT
 * WITH NOTHING TO SYNCHRONISE AND THEREFORE NOTHING TO DRIFT.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * The owner asked for three things that are one thing done properly: a button
 * that prices the set at its parts total, a discount box that prices it from
 * that total, and a member's price drop flowing through to the set by the same
 * amount. Only the last one decides the shape — see the migration
 * 2027_04_02_000000_set_pricing_columns for why a stored absolute price cannot
 * do it — and once the price is derived, the first two are the same feature
 * with the discount set to zero and to N.
 *
 * ── THE THREE MODES ────────────────────────────────────────────────────────
 *
 *   fixed              `products.price` / `products.sale_price`, exactly as
 *                      every other product in this shop. THE DEFAULT, and what
 *                      a NULL column reads as, so no set that exists moves.
 *   discount_percent   parts total, less N% of it. `set_discount` is BASIS
 *                      POINTS (percent × 100), the same unit `coupons.amount`
 *                      uses for the same reason.
 *   discount_amount    parts total, less a fixed number of fils. Zero is the
 *                      owner's "use this price" button: the set costs exactly
 *                      what its parts cost.
 *
 * ── MONEY IS INTEGER FILS AND THE PERCENTAGE IS ROUNDED ONCE ───────────────
 *
 * There is not one float on this path, in either direction. The percentage is
 * applied to the integer parts total as
 *
 *     intdiv($parts * (10000 - $bp) + 5000, 10000)
 *
 * — integer multiplication, one integer division, half rounded up, once. The
 * shape this replaces is `(int) ($parts * (1 - $pct / 100))`, which is a float
 * chain: 30% off is 0.69999999999999995559 and lands a fil light, which is a
 * defect this repository has already paid for once on the bulk price action.
 *
 * ── THE TRAP A DERIVED PRICE HAS, AND WHY IT IS NOT ONE HERE ───────────────
 *
 * A price that moves on its own must never move UNDER A SHOPPER. It does not,
 * and that is a property of this application rather than of this class:
 *
 *   `cart_items.unit_price` is written when the line is added and rewritten
 *   only when its quantity changes — CartService::add() and updateQuantity(),
 *   and the column's own comment in the schema says "Snapshot so a price change
 *   mid-session cannot silently alter the cart".
 *
 *   `order_items.unit_price` is copied FROM that snapshot when the order is
 *   placed (CheckoutController, `'unit_price' => $item->unit_price`), beside
 *   the name, brand and sku snapshots on the same row.
 *
 * Both were read first-hand before this shipped and both are pinned by
 * SetPricingTest: a set in a basket, a member's price dropped underneath it,
 * and the basket and the placed order still charge what was agreed.
 *
 * ── AND IT DOES NOT COST ONE QUERY PER MEMBER ──────────────────────────────
 *
 * partsTotal() answers from the LOADED relation when there is one, which is
 * every surface that draws a set: App\Support\SetEagerLoad has already batched
 * the members for the product page, the cart, the checkout, the API and the
 * admin screen. With nothing loaded it runs ONE aggregate statement for the
 * whole set — a join summing quantity × price in SQL — never one per member.
 *
 * The per-request memo is what stops a single page render asking that question
 * three times for the same set (the price, the schema offer and the saving all
 * read it). It is a process-level static and therefore carries exactly the trap
 * CLAUDE.md records against Setting::map(), so forget() exists and is called by
 * SetApiController the moment a set or its members are saved.
 */
final class SetPricing
{
    public const MODE_FIXED = 'fixed';

    public const MODE_PERCENT = 'discount_percent';

    public const MODE_AMOUNT = 'discount_amount';

    /** The only three values `products.set_price_mode` may ever read as. */
    public const MODES = [self::MODE_FIXED, self::MODE_PERCENT, self::MODE_AMOUNT];

    /** 100% in basis points, and the ceiling a discount is clamped to. */
    public const FULL_BP = 10000;

    /** @var array<int, int> set id => parts total in fils, for this request. */
    private static array $memo = [];

    /**
     * Which rule prices this set.
     *
     * ANYTHING THAT IS NOT ONE OF THE THREE IS `fixed`, including NULL (every
     * row written before the column existed), an empty string, and whatever a
     * hand-edited row or a future importer puts there. A set that priced itself
     * unexpectedly because a string in a column was unexpected is the worst
     * shape this could take, and `fixed` is the shape that cannot surprise
     * anyone: the number in `products.price`.
     */
    public static function mode(?Product $set): string
    {
        if ($set === null) {
            return self::MODE_FIXED;
        }

        $stored = (string) ($set->getAttributes()['set_price_mode'] ?? '');

        return in_array($stored, self::MODES, true) ? $stored : self::MODE_FIXED;
    }

    /**
     * The set's price under its rule, or NULL when the rule is `fixed`.
     *
     * NULL IS THE IMPORTANT ANSWER. It means "this class has nothing to say
     * about this product", and Product::effectivePrice() then takes exactly the
     * branch it took before this class existed — which is what makes every
     * product in this shop, and every set built before today, byte-identical.
     */
    public static function derived(?Product $set): ?int
    {
        if ($set === null || ! $set->isSet()) {
            return null;
        }

        $mode = self::mode($set);

        if ($mode === self::MODE_FIXED) {
            return null;
        }

        $parts = self::partsTotal($set);
        $discount = (int) ($set->getAttributes()['set_discount'] ?? 0);

        if ($mode === self::MODE_PERCENT) {
            // Clamped to 0..100% before it is applied, so a row holding 20000
            // basis points prices the set at zero rather than at minus the
            // parts total.
            $bp = max(0, min(self::FULL_BP, $discount));

            return max(0, intdiv($parts * (self::FULL_BP - $bp) + (self::FULL_BP / 2), self::FULL_BP));
        }

        // MODE_AMOUNT. A discount larger than the parts total is a free set,
        // never a negative price.
        return max(0, $parts - max(0, $discount));
    }

    /**
     * What the members cost bought separately, in integer fils.
     *
     * TWO PATHS, AND THE FAST ONE IS THE ONE EVERY REAL SURFACE TAKES.
     *
     *   RELATION LOADED — no query at all. Every surface that draws a set has
     *   already been through App\Support\SetEagerLoad, which batches the
     *   membership rows, the member products and the chosen variants for the
     *   whole page in three statements.
     *
     *   NOT LOADED — ONE aggregate statement for this set, joining the pivot to
     *   `products` and to `product_variants` and summing quantity × price in
     *   SQL. One per set, never one per member, and memoised for the rest of
     *   the request.
     *
     * ▲ THE FALLBACK MIRRORS Product::ownPrice() RATHER THAN CALLING IT,
     *   because it is SQL and that is PHP — and the two must agree about what a
     *   sale price is worth, or a set's price would change when its members
     *   happened to be loaded. The sale window is compared against a BOUND
     *   INSTANT, never as text: a CASE takes the widest type of its branches
     *   and MySQL 8 widens DATETIME to DATETIME(6), so a string comparison
     *   misses on the server and matches on SQLite — the divergence that left
     *   every customer who had never ordered showing a last-active date of
     *   1 Jan 1970.
     */
    public static function partsTotal(?Product $set): int
    {
        if ($set === null || ! $set->isSet()) {
            return 0;
        }

        if ($set->relationLoaded('setItems')) {
            $total = 0;

            foreach ($set->setItems as $row) {
                $member = $row->member;

                if ($member === null) {
                    continue;
                }

                /*
                 * A MEMBER THAT IS ITSELF A SET IS SKIPPED, and that is the
                 * recursion guard. SetApiController refuses to put one there
                 * and the picker will not offer one, but this is the method a
                 * hand-written row would reach and a set inside itself is an
                 * infinite box.
                 */
                if ($member->isSet()) {
                    continue;
                }

                $unit = (int) ($row->variant?->effectivePrice() ?? $member->effectivePrice());
                $total += $unit * max(1, (int) $row->quantity);
            }

            return $total;
        }

        $id = (int) $set->getKey();

        if (array_key_exists($id, self::$memo)) {
            return self::$memo[$id];
        }

        $now = now()->toDateTimeString();

        /*
         * COALESCE down the chain, in one statement:
         *   the variant's own in-window sale price, else the variant's price,
         *   else the member's in-window sale price, else the member's price,
         *   else zero.
         * A variant's sale WINDOW is its parent product's — ProductVariant::
         * saleWindowOpen() reads `$this->product->sale_starts_at` — which is
         * why the window below is read off `products` for both branches.
         */
        $sql = 'CASE'
            .'  WHEN pv.id IS NOT NULL AND pv.sale_price IS NOT NULL'
            .'   AND (p.sale_starts_at IS NULL OR p.sale_starts_at <= ?)'
            .'   AND (p.sale_ends_at IS NULL OR p.sale_ends_at >= ?)'
            .'   THEN pv.sale_price'
            .'  WHEN pv.id IS NOT NULL THEN COALESCE(pv.price, 0)'
            .'  WHEN p.sale_price IS NOT NULL'
            .'   AND (p.sale_starts_at IS NULL OR p.sale_starts_at <= ?)'
            .'   AND (p.sale_ends_at IS NULL OR p.sale_ends_at >= ?)'
            .'   THEN p.sale_price'
            .'  ELSE COALESCE(p.price, 0)'
            .' END';

        $total = (int) (DB::table('product_set_items as psi')
            ->join('products as p', 'p.id', '=', 'psi.member_product_id')
            ->leftJoin('product_variants as pv', 'pv.id', '=', 'psi.member_variant_id')
            ->where('psi.set_product_id', '=', $id)
            // The same recursion guard as the loaded path, one layer down.
            ->where('p.type', '!=', 'set')
            ->whereNull('p.deleted_at')
            ->selectRaw(
                'COALESCE(SUM(('.$sql.') * CASE WHEN psi.quantity < 1 THEN 1 ELSE psi.quantity END), 0) as parts',
                [$now, $now, $now, $now]
            )
            ->value('parts') ?? 0);

        return self::$memo[$id] = $total;
    }

    /**
     * Forget the memo.
     *
     * `Setting::map()` memoises in a process-level static as well as in the
     * cache, and CLAUDE.md records that as a trap in tests and queue workers.
     * This is the same shape, so it gets the same escape hatch — and unlike
     * that one it is called: SetApiController calls it after every save, and
     * the tests call it after moving a member's price underneath a set.
     */
    public static function forget(?int $setId = null): void
    {
        if ($setId === null) {
            self::$memo = [];

            return;
        }

        unset(self::$memo[$setId]);
    }
}
