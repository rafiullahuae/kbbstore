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
 *                      ▲ AND, ONCE `set_price_basis` IS SET, THOSE TWO FIGURES
 *                      LESS adjustment() -- the owner's "reduce the set by what
 *                      I reduced the product by" for the one mode where the
 *                      price is a number a human typed. Off the regular price
 *                      AND off the sale price, by the same fils. See
 *                      adjustment() for the anchor, the down-only decision and
 *                      the three cases where it declines to move at all.
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
 * AND ONE STATEMENT FOR A WHOLE GRID OF THEM. (Lane SG) A product card does not
 * want the member ROWS — it prints a price, not the box — so a grid does not go
 * through SetEagerLoad's six relation loads. prime() takes the page's products,
 * keeps the sets it has not answered for, and fills the memo for all of them in
 * ONE grouped statement; handed a page with no set in it, it runs none at all.
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

    /**
     * THE THREE `products` COLUMNS A SET'S PRICE CANNOT BE READ WITHOUT.
     * (Lane SG)
     *
     * ═══════════════════════════════════════════════════════════════════════
     * A NARROW SELECT THAT OMITS THESE DOES NOT FAIL. IT PRICES THE SET WRONG.
     * ═══════════════════════════════════════════════════════════════════════
     *
     * mode() reads `set_price_mode` off getAttributes() and falls back to
     * `fixed` for anything it does not recognise — including an absent column —
     * and basis() reads `set_price_basis` and falls back to "no anchor". Both
     * fallbacks are right for a hand-edited row and right for a legacy set, and
     * both are SILENTLY WRONG for a query that simply did not ask: the set is
     * then priced at the number in `products.price`, which for a rule-priced
     * set is a stale snapshot and for an anchored one is the figure before the
     * reduction.
     *
     * That is what nine card-column lists did — Store\ShopController,
     * CollectionController, BrandController, HomeController, SearchController,
     * ProductController, WishlistController, Services\CartPage and
     * Support\Shortcodes — so a set showed one price on every grid in the shop
     * and a different one on its own page, in the cart, in the checkout, in the
     * API and in the admin. This constant is why there is now one list to add
     * to rather than nine to remember.
     *
     * ▲ NOT FOR /api/*. These three columns are asserted ABSENT from the public
     *   feed by tests/Feature/SetApiSecurityTest.php and must stay absent:
     *   Product::toApi() publishes an allowlist and a pricing RULE is not a
     *   thing the shop tells the internet. Selecting a column is not publishing
     *   it, and this constant is about the SELECT.
     *
     * @var list<string>
     */
    public const COLUMNS = ['set_price_mode', 'set_discount', 'set_price_basis'];

    /** 100% in basis points, and the ceiling a discount is clamped to. */
    public const FULL_BP = 10000;

    /**
     * THE FLOOR A SET'S PRICE MAY NOT FALL THROUGH, IN FILS.
     *
     * A set that follows its members down must not follow them to nothing. One
     * fil is the smallest amount this currency can express, and the point of
     * the clamp is not the number: it is that `max()` is applied at all, so a
     * basis anchored against an expensive box and a box that later became cheap
     * cannot produce a free -- or negative -- published product that anybody can
     * put in a basket and check out.
     *
     * ▲ IT NEVER RAISES A PRICE. afterAdjustment() returns `min($amount, ...)`
     *   against this floor, so a product genuinely priced at 0 stays at 0 rather
     *   than being lifted to one fil by a clamp that was meant to protect it.
     *   A clamp that can move a figure UP is not a clamp, it is a price change.
     */
    public const MIN_PRICE_FILS = 1;

    /** @var array<int, array{parts:int,rows:int,missing:int}> set id => tally. */
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
     * NULL IS THE IMPORTANT ANSWER. It means "this class has no PRICE to give
     * for this product", and Product::effectivePrice() then takes exactly the
     * branch it took before this class existed.
     *
     * ▲ WHICH IS WHY `fixed` STILL ANSWERS NULL even now that a hand-typed set
     *   can follow its members down. The sale WINDOW -- starts_at, ends_at, and
     *   which of the two columns wins today -- is Product::ownPrice()'s
     *   arithmetic and has been since long before sets existed. Answering a
     *   price here would mean copying that window logic into this class, and
     *   two copies of a sale window is how a set ends up on sale a day after
     *   every other product came off it. So the mode that reduces a TYPED
     *   figure reduces it where that figure is chosen: effectivePrice() asks
     *   ownPrice() which column applies, and hands the answer to
     *   afterAdjustment(). One subtraction, one place, both columns.
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
        return self::tally($set)['parts'];
    }

    /**
     * How many membership rows this set has, and how many of them no longer
     * name a product anybody can buy. (Lane SP2)
     *
     * ── WHY THE COUNT IS TAKEN AT ALL ──────────────────────────────────────
     *
     * Because a member DELETED FROM THE CATALOGUE looks exactly like a member
     * whose price fell to zero, and the two must not be treated alike. Deleting
     * a product removes its contribution from partsTotal(), which under the
     * fixed-price rule below reads as "the box got cheaper by the whole price
     * of that product" and marks the set down by it -- silently, with nobody
     * having touched the set, and with the box now missing an item.
     *
     * So `missing` is counted in the SAME statement as the total, and
     * adjustment() refuses to move a set that has one. The set holds the price
     * the operator typed until he fixes the box, and the editor says so in as
     * many words. Holding a price is a thing an operator can see and correct;
     * an automatic markdown nobody asked for is a margin leak with no signal.
     *
     * A member that is itself a set counts as missing too. It contributes
     * nothing to the total (the recursion guard) and would otherwise read as
     * the same reduction; SetApiController and the picker both refuse to create
     * one, so this is the door for a hand-written row.
     *
     * @return array{parts:int,rows:int,missing:int}
     */
    public static function tally(?Product $set): array
    {
        $none = ['parts' => 0, 'rows' => 0, 'missing' => 0];

        if ($set === null || ! $set->isSet()) {
            return $none;
        }

        if ($set->relationLoaded('setItems')) {
            $out = $none;

            foreach ($set->setItems as $row) {
                $out['rows']++;
                $member = $row->member;

                /*
                 * A MEMBER THAT IS ITSELF A SET IS SKIPPED, and that is the
                 * recursion guard. SetApiController refuses to put one there
                 * and the picker will not offer one, but this is the method a
                 * hand-written row would reach and a set inside itself is an
                 * infinite box.
                 *
                 * `$member === null` is the soft-deleted or hard-deleted one:
                 * the relation applies the SoftDeletes scope, so a member the
                 * owner deleted from Catalog → Products arrives here as null.
                 */
                if ($member === null || $member->isSet()) {
                    $out['missing']++;

                    continue;
                }

                $unit = (int) ($row->variant?->effectivePrice() ?? $member->effectivePrice());
                $out['parts'] += $unit * max(1, (int) $row->quantity);
            }

            return $out;
        }

        $id = (int) $set->getKey();

        /*
         * ONE SET IS THE ONE-ROW CASE OF prime(), NOT A SECOND SPELLING OF IT.
         * (Lane SG)
         *
         * This method used to carry the aggregate itself. A grid needs the same
         * figure for EVERY set on the page in ONE statement, and a second
         * spelling of a money aggregate is exactly how the tile and the product
         * page came to disagree about a set's price in the first place. So the
         * SQL lives in prime() now and there is one of it; this is the call
         * that fills the memo for a single set.
         *
         * `?? $none` rather than a second lookup: prime() skips a model with no
         * key at all, so that row costs no query and still answers the empty
         * tally it always answered.
         */
        if (! array_key_exists($id, self::$memo)) {
            self::prime([$set]);
        }

        return self::$memo[$id] ?? $none;
    }

    /**
     * FILL THE TALLY FOR EVERY SET ON A PAGE IN ONE STATEMENT — OR IN NONE.
     * (Lane SG)
     *
     * ═══════════════════════════════════════════════════════════════════════
     * THIS IS WHAT LETS A GRID PRINT A SET'S REAL PRICE WITHOUT AN N+1.
     * ═══════════════════════════════════════════════════════════════════════
     *
     * ── THE DEFECT THIS EXISTS FOR ─────────────────────────────────────────
     *
     * The shop's nine card-column lists select `price` and did not select
     * `set_price_mode`, `set_discount` or `set_price_basis`. mode() reads the
     * ATTRIBUTE and falls back to `fixed`, and basis() reads the attribute and
     * falls back to null, so on every grid a set was priced as though it had no
     * rule and no anchor: the figure a human typed, while its own product page,
     * the cart, the checkout, the API and the admin all showed the derived one.
     * Three columns fix the reading. This fixes the COST of reading it.
     *
     * ── WHY A BATCH AND NOT A CORRELATED SUBQUERY IN THE CARD SELECT ───────
     *
     * The parts total is a join over `product_set_items`, `products` and
     * `product_variants` with a sale window on it. It CAN be written as a
     * correlated scalar subquery beside App\Support\EffectivePrice's selects,
     * guarded by `CASE WHEN products.type = 'set'` so an ordinary row
     * short-circuits past it — and that would be zero extra statements. It was
     * rejected for two reasons, in this order:
     *
     *   IT PUTS THE MONEY RULE IN SQL, TWICE. derived()'s percentage is
     *   `intdiv($parts * (10000 - $bp) + 5000, 10000)`: integer multiply, one
     *   integer divide, half up. MySQL spells integer division `DIV` and SQLite
     *   spells it `/`; MySQL's `/` yields DECIMAL. So the same arithmetic needs
     *   two spellings, which is what App\Support\SqlDialectGuard exists to stop,
     *   and a money rule with two implementations is the shape this class's
     *   header was written against.
     *
     *   IT TAXES THE HOTTEST QUERY ON THE SHOP. That subquery would be attached
     *   to /shop, every category archive, every search and every rail — pages
     *   that hold no set at all, which today is nearly all of them.
     *
     * ▲ THE FIRST OF THOSE TWO WAS TAKEN UP AFTER ALL, AND IT IS BELOW.
     *   (Lane SORT) chargedSql() is the correlated subquery this paragraph
     *   rejected, with both objections answered rather than waived: the parts
     *   total is NOT spelled twice (unitSql() and goneSql() are shared with
     *   this method), the integer division needs no `DIV`, and the whole
     *   expression sits behind `CASE WHEN products.type = 'set'` so a page with
     *   no set never executes it. What it buys is the thing this method could
     *   not: the price SORT and the price FACETS seeing the rule. Read
     *   chargedSql()'s docblock before changing either of them — the two are
     *   now the batched and the in-statement halves of one answer.
     *
     * ── AND WHY NOT REFRESH A CACHED `products.price` ON A MEMBER'S SAVE ───
     *
     * Because that is the design the migration 2027_04_02_000000_set_pricing_-
     * columns rejected in writing, and the count in its note is real: a
     * product's price is written by the product editor, by Catalog → Products'
     * inline price cell, by the bulk price action, by the importer and by the
     * variation importer. Five writers, each of which would have to find every
     * set containing the row it just touched, and the one that is missed fails
     * silently and commercially.
     *
     * ── WHAT IT COSTS ─────────────────────────────────────────────────────
     *
     *   NO SET AMONG THE ROWS — not one query, and not one statement's worth of
     *   planning. It looks first, exactly as App\Support\SetEagerLoad does, and
     *   for the same reason: StorefrontQueryBudgetTest's ceilings may not move
     *   for a feature a page is not using.
     *
     *   ONE OR MORE SETS — ONE statement for all of them, grouped by set, and
     *   flat: a grid of one set and a grid of twenty-five cost the same one.
     *
     *   ALREADY MEMOISED, OR MEMBERS ALREADY LOADED — skipped. A page that has
     *   been through SetEagerLoad answers from the relation with no query at
     *   all, and tally() prefers that path.
     *
     * ▲ EVERY ID ASKED FOR IS MEMOISED, INCLUDING THE ONES THE STATEMENT DID
     *   NOT ANSWER FOR. A set with no membership rows produces no GROUP BY row;
     *   without seeding the misses first it would miss the memo on every read
     *   and re-run this statement once per set per request, which is the very
     *   N+1 this method is here to remove.
     *
     * @param  iterable<mixed>  $products  Product models; anything else, any
     *                                     non-set and any null is ignored, so a
     *                                     caller can hand over a whole page.
     */
    public static function prime(iterable $products): void
    {
        $ids = [];

        foreach ($products as $product) {
            if (! $product instanceof Product || ! $product->isSet()) {
                continue;
            }

            // The relation is the free path and tally() takes it first; a set
            // that has it loaded must not be counted into a statement whose
            // answer would never be read.
            if ($product->relationLoaded('setItems')) {
                continue;
            }

            $id = (int) $product->getKey();

            if ($id < 1 || array_key_exists($id, self::$memo)) {
                continue;
            }

            $ids[$id] = $id;
        }

        if ($ids === []) {
            return;
        }

        $now = now()->toDateTimeString();

        /*
         * COALESCE down the chain, in one statement:
         *   the variant's own in-window sale price, else the variant's price,
         *   else the member's in-window sale price, else the member's price,
         *   else zero.
         *
         * ▲ THE EXPRESSION ITSELF LIVES IN unitSql() AND goneSql() BECAUSE IT
         *   IS NOW READ FROM TWO PLACES. (Lane SORT) This method groups it over
         *   a named id list for a page; groupedSql() groups the SAME text over
         *   the whole catalogue so the shop's price SORT and price FACET can
         *   reach it inside one statement. A member's charged unit price with
         *   two spellings is the disagreement this class's header was written
         *   against, so there is one spelling and both callers take it.
         */
        $sql = self::unitSql();
        $gone = self::goneSql();

        /*
         * ▲ GROUPED BY THE SET, AND THE GROUPING COLUMN IS SELECTED BY NAME.
         *   MySQL runs ONLY_FULL_GROUP_BY, so every bare column in the list has
         *   to be the one grouped on; `psi.set_product_id` is, and the three
         *   aggregates beside it are aggregates. SQLite would have accepted very
         *   nearly anything here, which is the parity gap docs/MYSQL-PARITY.md
         *   and SqlNeedleDialectGuardTest exist for.
         *
         *   The four `?` belong to the SELECT and the id list to the WHERE.
         *   Laravel emits bindings by group -- select, from, join, where -- and
         *   the compiled statement is SELECT ... FROM ... WHERE ... GROUP BY,
         *   so the order matches without either side being written out by hand.
         */
        $rows = DB::table('product_set_items as psi')
            ->leftJoin('products as p', 'p.id', '=', 'psi.member_product_id')
            ->leftJoin('product_variants as pv', 'pv.id', '=', 'psi.member_variant_id')
            ->whereIn('psi.set_product_id', array_values($ids))
            ->groupBy('psi.set_product_id')
            ->selectRaw(
                'psi.set_product_id as set_id,'
                .' COALESCE(SUM(CASE WHEN '.$gone.' THEN 0 ELSE ('.$sql.')'
                .' * (CASE WHEN psi.quantity < 1 THEN 1 ELSE psi.quantity END) END), 0) as parts,'
                .' COUNT(*) as rows_count,'
                .' COALESCE(SUM(CASE WHEN '.$gone.' THEN 1 ELSE 0 END), 0) as missing',
                [$now, $now, $now, $now]
            )
            ->get();

        // Seed every id asked for, THEN overwrite the ones the statement
        // answered. See the note above: an empty box has no row to return.
        foreach ($ids as $id) {
            self::$memo[$id] = ['parts' => 0, 'rows' => 0, 'missing' => 0];
        }

        foreach ($rows as $row) {
            self::$memo[(int) $row->set_id] = [
                'parts' => (int) ($row->parts ?? 0),
                'rows' => (int) ($row->rows_count ?? 0),
                'missing' => (int) ($row->missing ?? 0),
            ];
        }
    }

    /**
     * ONE MEMBER'S CHARGED UNIT PRICE, IN SQL. FOUR `?` FOR ONE INSTANT.
     * (Lane SORT)
     *
     * prime() grouped this over a page's set ids and groupedSql() groups the
     * same text over the whole catalogue. The aliases are arguments rather than
     * literals so the two callers can name their tables differently without the
     * arithmetic being written out twice -- a member's unit price with two
     * spellings is exactly how the tile and the basket came to disagree.
     *
     * A variant's sale WINDOW is its parent product's -- ProductVariant::
     * saleWindowOpen() reads `$this->product->sale_starts_at` -- which is why
     * the window below is read off the MEMBER row for both branches.
     */
    private static function unitSql(string $member = 'p', string $variant = 'pv'): string
    {
        return 'CASE'
            .'  WHEN '.$variant.'.id IS NOT NULL AND '.$variant.'.sale_price IS NOT NULL'
            .'   AND ('.$member.'.sale_starts_at IS NULL OR '.$member.'.sale_starts_at <= ?)'
            .'   AND ('.$member.'.sale_ends_at IS NULL OR '.$member.'.sale_ends_at >= ?)'
            .'   THEN '.$variant.'.sale_price'
            .'  WHEN '.$variant.'.id IS NOT NULL THEN COALESCE('.$variant.'.price, 0)'
            .'  WHEN '.$member.'.sale_price IS NOT NULL'
            .'   AND ('.$member.'.sale_starts_at IS NULL OR '.$member.'.sale_starts_at <= ?)'
            .'   AND ('.$member.'.sale_ends_at IS NULL OR '.$member.'.sale_ends_at >= ?)'
            .'   THEN '.$member.'.sale_price'
            .'  ELSE COALESCE('.$member.'.price, 0)'
            .' END';
    }

    /**
     * IS THIS MEMBERSHIP ROW POINTING AT SOMETHING NOBODY CAN BUY? No `?`.
     *
     * A LEFT JOIN with the filter moved into a CASE, so a deleted member still
     * produces a countable row: it contributes 0 to `parts` and 1 to `missing`
     * in one pass. See tally() for why the count is taken at all -- a product
     * deleted from the catalogue is not a price reduction.
     */
    private static function goneSql(string $member = 'p'): string
    {
        return '('.$member.'.id IS NULL OR '.$member.'.deleted_at IS NOT NULL'
            ." OR ".$member.".type = 'set')";
    }

    /**
     * EVERY SET IN THE CATALOGUE, TALLIED, AS AN UNCORRELATED DERIVED TABLE.
     * SIX `?`, ALL ONE INSTANT. (Lane SORT)
     *
     * ═══════════════════════════════════════════════════════════════════════
     * THIS IS WHAT LETS THE PRICE SORT AND THE PRICE FACET SEE A SET'S RULE.
     * ═══════════════════════════════════════════════════════════════════════
     *
     * Columns, one row per set, keyed `sid`:
     *
     *   own     the set's OWN charged price -- Product::ownPrice() in SQL, sale
     *           window included. Only the hand-typed modes read it.
     *   parts   what the members cost bought separately, today, in fils.
     *   n       how many membership rows the set has (0 for an empty box).
     *   miss    how many of them name a product nobody can buy.
     *
     * ── WHY A DERIVED TABLE AND NOT A CORRELATED AGGREGATE ────────────────
     *
     * Because the rule needs `parts` more than once -- the percentage divides
     * a product of it, the anchor subtracts it, and the clamp compares against
     * it -- and SQL has no way to name a subexpression. Spelled as a correlated
     * aggregate, `parts` would be written out six times and would carry six
     * copies of the sale window: twenty-four placeholders for one instant.
     * As a derived table it is computed once and referred to by NAME, which is
     * both smaller and the only version a person can read.
     *
     * It is UNCORRELATED on purpose: nothing inside it mentions the outer
     * query, so MySQL materialises it once per statement (a correlated
     * reference inside a FROM subquery needs LATERAL, which is 8.0.14+ and not
     * a dependency this shop is taking). The outer reference is in the scalar
     * subquery's WHERE, one level up, where both engines have always allowed
     * it.
     *
     * ── GROUPED BY THE KEY, AND EVERY BARE COLUMN LISTED ──────────────────
     *
     * MySQL runs ONLY_FULL_GROUP_BY. It does recognise functional dependency on
     * a primary key, but the four window columns beside `kbbss.id` are named in
     * the GROUP BY anyway: this expression is read by people, and a grouping
     * that is legal only because the server worked out a dependency is the kind
     * of thing that stops being legal when somebody adds a join.
     *
     * ── THE EMPTY BOX HAS A ROW ───────────────────────────────────────────
     *
     * It is grouped from `products` outward through a LEFT JOIN, not from
     * `product_set_items` inward, so a set with no members answers
     * parts 0 / n 0 / miss 0 rather than answering nothing at all. A scalar
     * subquery that found no row would have returned NULL, and NULL sorts first
     * ascending on both engines -- an empty box would have opened "Price, low
     * to high" ahead of every real product. That is the same NULL trap
     * App\Support\EffectivePrice::sql() was widened for, arriving from a new
     * direction.
     */
    public static function groupedSql(): string
    {
        $gone = self::goneSql('kbbsp');
        $unit = self::unitSql('kbbsp', 'kbbsv');

        // `kbbsi.id IS NULL` is the empty box: the LEFT JOIN produced one row
        // with nothing on it, and that row is neither a part nor a missing
        // member. It is tested FIRST because goneSql() would call it missing.
        return 'SELECT kbbss.id as sid,'
            .' CASE WHEN kbbss.sale_price IS NOT NULL'
            .'  AND (kbbss.sale_starts_at IS NULL OR kbbss.sale_starts_at <= ?)'
            .'  AND (kbbss.sale_ends_at IS NULL OR kbbss.sale_ends_at >= ?)'
            .'  THEN kbbss.sale_price ELSE kbbss.price END as own,'
            .' COALESCE(SUM(CASE WHEN kbbsi.id IS NULL THEN 0 WHEN '.$gone.' THEN 0'
            .'  ELSE ('.$unit.') * (CASE WHEN kbbsi.quantity < 1 THEN 1 ELSE kbbsi.quantity END)'
            .'  END), 0) as parts,'
            .' COUNT(kbbsi.id) as n,'
            .' COALESCE(SUM(CASE WHEN kbbsi.id IS NULL THEN 0 WHEN '.$gone.' THEN 1 ELSE 0 END), 0) as miss'
            .' FROM products kbbss'
            .' LEFT JOIN product_set_items kbbsi ON kbbsi.set_product_id = kbbss.id'
            .' LEFT JOIN products kbbsp ON kbbsp.id = kbbsi.member_product_id'
            .' LEFT JOIN product_variants kbbsv ON kbbsv.id = kbbsi.member_variant_id'
            ." WHERE kbbss.type = 'set'"
            .' GROUP BY kbbss.id, kbbss.price, kbbss.sale_price,'
            .' kbbss.sale_starts_at, kbbss.sale_ends_at';
    }

    /**
     * WRAP A CHARGED-PRICE EXPRESSION SO A RULE-PRICED SET ANSWERS ITS RULE.
     * Product::effectivePrice() in SQL, for the one product type whose price is
     * not in a column. (Lane SORT)
     *
     * ═══════════════════════════════════════════════════════════════════════
     * THE SORT KEY AND THE PRINTED PRICE ARE NOW THE SAME NUMBER.
     * ═══════════════════════════════════════════════════════════════════════
     *
     * ── THE DEFECT, ON THE SHOP ───────────────────────────────────────────
     *
     * App\Support\EffectivePrice is SQL over `products.price` and its sale
     * window, and it is evaluated across the whole catalogue before a page is
     * in hand, so it could not see a rule. `discount_percent` and
     * `discount_amount` sorted and bucketed at the derived price AS AT THE LAST
     * SAVE -- Admin\ProductEditorApiController writes that snapshot into
     * `products.price` and nothing writes it again -- and `fixed` with an
     * anchor sorted at the typed figure, BEFORE the reduction. So:
     *
     *   - "Price, low to high" put a set later in the list than the number on
     *     its own tile deserved, and the shopper could see both at once.
     *   - The price-range facet filed a set in the band its STALE figure fell
     *     in, so a set printing AED 145.00 was missing from "AED 54 - 150".
     *   - "On sale" never contained a rule-priced set at all, because
     *     whereOnSale() asks `charged < regular` and both sides read the same
     *     stale column. The tile drew a -N% badge from Product::isOnSale(),
     *     which compares the DERIVED price with the same snapshot, so the badge
     *     and the filter contradicted each other on the same screen.
     *
     * ── WHY THIS SHAPE, AND NOT THE TWO prime() REJECTED ──────────────────
     *
     * prime()'s docblock rejected a correlated subquery beside EffectivePrice
     * on two grounds and rejected a cached `products.price` refreshed on a
     * member's save on a third. This IS the first of those, taken up
     * deliberately, and each ground is answered rather than ignored:
     *
     *   "IT PUTS THE MONEY RULE IN SQL, TWICE." It does, and that is the real
     *   cost of this change. It is paid for in two ways. The parts total -- the
     *   half with a sale window in it -- is NOT duplicated: unitSql() and
     *   goneSql() are one text, read by prime() and by groupedSql() alike. What
     *   is spelled twice is derived()'s arithmetic, and
     *   tests/Feature/SetSortKeyMatchesPriceTest drives several hundred
     *   (parts, mode, discount, basis, price, sale) combinations through BOTH
     *   spellings and asserts them equal to the fil, on SQLite and again under
     *   `-c phpunit-mysql.xml`. A duplicated rule nobody checks is a hazard; a
     *   duplicated rule the suite compares on both engines is a property.
     *
     *   "MySQL SPELLS INTEGER DIVISION `DIV`." It need not be spelled at all.
     *   `(n - n % 10000) / 10000` is exact on both engines for every sign of n:
     *   `%` truncates toward zero on both, so the numerator is already an exact
     *   multiple and the division has nothing to round. SQLite answers an
     *   INTEGER; MySQL answers a DECIMAL, which is an EXACT type -- not a float
     *   -- and compares and orders identically. There is no float on this path
     *   in either engine, which is the rule this repository holds money to.
     *
     *   "IT TAXES THE HOTTEST QUERY ON THE SHOP." It does not, and that is what
     *   the outer CASE is for. `type = 'set'` is one comparison against a column
     *   already on the row; a CASE evaluates only the branch it takes, on both
     *   engines, so for a product that is not a set the subquery is not merely
     *   cheap, it is NEVER EXECUTED -- and the derived table inside it is never
     *   built. The STATEMENT COUNT does not move by one on any page, with a set
     *   or without: this is zero extra queries, which is the property prime()
     *   bought and this must not spend.
     *
     *   "FIVE WRITERS WOULD HAVE TO FIND EVERY SET." That objection kills the
     *   cached column, and a THIRD shape -- a `set_effective_price` refreshed by
     *   one owner, an observer or a sweep -- dies with it for a reason neither
     *   of the first two mention: A MEMBER'S SCHEDULED SALE OPENS WITH NO WRITE
     *   AT ALL. Nothing is saved at midnight when a markdown's window opens, so
     *   no observer fires and no hook can; only a sweep would notice, and until
     *   it ran the shop would sort a set by a price it had stopped charging.
     *   This shop schedules its markdowns -- EffectivePrice exists entirely
     *   because it does -- so a stored sort key is stale by design on exactly
     *   the days the owner most wants the sort to be right. The window here is
     *   a bound instant in the same statement, so it cannot be stale by any
     *   amount.
     *
     * ── WHAT IT ANSWERS, BRANCH BY BRANCH ─────────────────────────────────
     *
     *   NOT A SET, or a set with no rule and no anchor -- which is every
     *   product in this shop and every set built before Lane SP -- the
     *   expression handed in, unchanged and byte-identical. The guard is two
     *   column tests and no subquery.
     *   `discount_percent`  intdiv(parts * (10000 - bp) + 5000, 10000), clamped
     *                       at 0, with bp clamped to 0..10000 first.
     *   `discount_amount`   parts less the discount, clamped at 0.
     *   `fixed` + anchor    the set's own charged figure less
     *                       max(0, basis - parts), clamped at MIN_PRICE_FILS
     *                       and never raised -- and only when the box has
     *                       members and none of them is missing.
     *
     * ▲ `kbbst.own` IS THE HANDED-IN EXPRESSION FOR A SET, not a second answer.
     *   sql()'s COALESCE falls through to the cheapest variation only when the
     *   row's own price is NULL, and a set has no variations, so for a set the
     *   two are the same number. Using the derived table's copy is what keeps
     *   the placeholder count at ten instead of twenty-two.
     *
     * @param  string  $base  The charged-price expression for everything that
     *                        is not a rule-priced set. Its own `?` are kept.
     */
    public static function chargedSql(string $base, string $table = 'products'): string
    {
        $c = static fn (string $column): string => $table === '' ? $column : $table.'.'.$column;

        $discount = 'COALESCE('.$c('set_discount').', 0)';

        // The basis-point clamp derived() applies before it multiplies, so a
        // row holding 20000 prices the set at zero rather than at minus the
        // parts total. CASE, not LEAST/GREATEST: MySQL's MAX() of two arguments
        // is an aggregate and SQLite's is a scalar, which is the kind of
        // difference that passes here and raises 1064 on the server.
        $bp = 'CASE WHEN '.$discount.' < 0 THEN 0'
            .' WHEN '.$discount.' > '.self::FULL_BP.' THEN '.self::FULL_BP
            .' ELSE '.$discount.' END';

        // intdiv($parts * (10000 - $bp) + 5000, 10000): integer multiply, one
        // integer divide, half carried up, once.
        $numerator = '(kbbst.parts * ('.self::FULL_BP.' - ('.$bp.')) + '.intdiv(self::FULL_BP, 2).')';
        $divided = '(('.$numerator.' - '.$numerator.' % '.self::FULL_BP.') / '.self::FULL_BP.')';
        $percent = 'CASE WHEN '.$divided.' < 0 THEN 0 ELSE '.$divided.' END';

        $flat = 'CASE WHEN '.$discount.' < 0 THEN 0 ELSE '.$discount.' END';
        $amount = 'CASE WHEN kbbst.parts - ('.$flat.') < 0 THEN 0'
            .' ELSE kbbst.parts - ('.$flat.') END';

        return 'CASE WHEN '.$c('type')." = 'set' AND ("
            .$c('set_price_mode')." IN ('".self::MODE_PERCENT."', '".self::MODE_AMOUNT."')"
            .' OR '.$c('set_price_basis').' IS NOT NULL) THEN ('
            .'SELECT CASE'
            .' WHEN '.$c('set_price_mode')." = '".self::MODE_PERCENT."' THEN ".$percent
            .' WHEN '.$c('set_price_mode')." = '".self::MODE_AMOUNT."' THEN ".$amount
            .' WHEN '.self::anchorSql($c('set_price_basis')).' THEN '
            .self::reducedSql('kbbst.own', $c('set_price_basis'))
            .' ELSE kbbst.own END'
            .' FROM ('.self::groupedSql().') kbbst WHERE kbbst.sid = '.$c('id')
            .') ELSE '.$base.' END';
    }

    /**
     * WRAP A COMPARE-AT EXPRESSION THE SAME WAY — Product::compareAtPrice() in
     * SQL. SIX `?` ON TOP OF WHATEVER $base CARRIES. (Lane SORT)
     *
     * compareAtPrice() is `afterAdjustment($this, (int) $this->price)`, and
     * afterAdjustment() answers its input unchanged for everything that is not
     * a hand-priced set with an anchor. So only that one case is wrapped here,
     * and the two discount modes are deliberately NOT: their compare-at is the
     * `products.price` column exactly as it stands, which is the figure the
     * editor snapshotted and the figure Product::compareAtPrice() returns.
     *
     * ── WHICH IS THE "ON SALE" DECISION, MADE RATHER THAN INHERITED ────────
     *
     * whereOnSale() is `charged < compare-at`, and with both halves wrapped it
     * is Product::isOnSale() term for term again. A rule-priced set therefore
     * enters the "On sale" facet on exactly the days its tile draws a -N%
     * badge, and leaves it on exactly the days the badge goes: both read the
     * same two numbers. The alternative -- taking the badge OFF the tile -- was
     * rejected because it changes a surface that works today, and because the
     * shop would then be silent about a real reduction a shopper is being
     * offered. See the lane report for the third reading that was considered
     * and refused: a set is NOT put in "On sale" for being cheaper than its
     * members bought separately. That is a different claim, it is the one
     * App\Support\SetContents prints as `saving` on the set's own page, and it
     * is true of nearly every set ever built -- a facet that contains almost
     * everything filters nothing.
     */
    public static function compareSql(string $base, string $table = 'products'): string
    {
        $c = static fn (string $column): string => $table === '' ? $column : $table.'.'.$column;

        return 'CASE WHEN '.$c('type')." = 'set' AND ".$c('set_price_basis').' IS NOT NULL'
            .' AND ('.$c('set_price_mode').' IS NULL OR '.$c('set_price_mode')
            ." NOT IN ('".self::MODE_PERCENT."', '".self::MODE_AMOUNT."')) THEN ("
            .'SELECT CASE WHEN '.self::anchorSql($c('set_price_basis')).' THEN '
            .self::reducedSql($c('price'), $c('set_price_basis'))
            .' ELSE '.$c('price').' END'
            .' FROM ('.self::groupedSql().') kbbst WHERE kbbst.sid = '.$c('id')
            .') ELSE '.$base.' END';
    }

    /**
     * Is there an adjustment to make at all? adjustment()'s three refusals, in
     * SQL and in the same order: an empty box, a box with a member missing, and
     * a basis today's total has already caught up with. No `?`.
     */
    private static function anchorSql(string $basis): string
    {
        return '(kbbst.n >= 1 AND kbbst.miss = 0 AND '.$basis.' - kbbst.parts > 0)';
    }

    /**
     * afterAdjustment() in SQL: $amount less (basis - parts), never below
     * MIN_PRICE_FILS and never ABOVE $amount. No `?` of its own.
     *
     * The first branch is the `min($amount, MIN_PRICE_FILS)` half, which is
     * there so a product genuinely priced at 0 is not LIFTED to one fil by a
     * clamp that was meant to protect it. A clamp that can move a figure up is
     * not a clamp, it is a price change.
     */
    private static function reducedSql(string $amount, string $basis): string
    {
        $reduced = $amount.' - ('.$basis.' - kbbst.parts)';

        return 'CASE WHEN '.$amount.' < '.self::MIN_PRICE_FILS.' THEN '.$amount
            .' WHEN '.$reduced.' < '.self::MIN_PRICE_FILS.' THEN '.self::MIN_PRICE_FILS
            .' ELSE '.$reduced.' END';
    }

    /**
     * The parts total this set's HAND-TYPED price was anchored to, or null.
     *
     * NULL is every set that existed before this shipped, and every set whose
     * operator has not typed a price since. It means "no anchor", and no anchor
     * means nothing moves — which is what makes applying this package a no-op
     * on the live shop until somebody deliberately prices a set.
     */
    public static function basis(?Product $set): ?int
    {
        if ($set === null || ! $set->isSet()) {
            return null;
        }

        $stored = $set->getAttributes()['set_price_basis'] ?? null;

        return $stored === null ? null : (int) $stored;
    }

    /**
     * WHAT TO TAKE OFF A HAND-TYPED SET PRICE TODAY, IN FILS. NEVER NEGATIVE.
     *
     * ═══════════════════════════════════════════════════════════════════════
     * THIS IS THE ANSWER TO "IF I REDUCE A PRODUCT'S PRICE, REDUCE THE SET TOO"
     * FOR THE ONE MODE WHERE THE PRICE IS A NUMBER THE OPERATOR TYPED.
     * ═══════════════════════════════════════════════════════════════════════
     *
     *     adjustment = max(0, basis - parts total now)
     *
     * `basis` is the parts total at the moment he typed the price: the figure
     * he was looking at when he decided AED 180 was the right price for a box
     * worth AED 200. Every fil the box has come down since is a fil off the
     * set, off the regular price and off the sale price alike.
     *
     * ── DOWN ONLY, AND WHY THAT IS NOT A RATCHET THAT EATS THE MARGIN ──────
     *
     * `max(0, ...)` is the decision the owner's sentence does not make for us:
     * he asked for reductions, and a member getting DEARER is the case he did
     * not mention. Three arguments decided it:
     *
     *   A SHOP MUST NOT RAISE ITS OWN PRICES. An automatic increase changes
     *   the advertised price of a published product with nobody having touched
     *   it. Charging more than any human typed is the worst failure on this
     *   list; charging less is bounded by the operator's own figure and is
     *   visible as a smaller number on the screen he typed it into.
     *
     *   IT IS NOT A ONE-WAY RATCHET. The reduction is a function of TODAY'S
     *   delta, not of the lowest price ever seen. When a member's sale ends,
     *   the parts total climbs back to the basis, the delta returns to zero and
     *   the set returns to exactly the price that was typed. So it cannot lose
     *   margin permanently either: the set is never above the typed price and
     *   never below it for longer than its members are.
     *
     *   AND THE SCREEN SAYS SO. Catalog → Product editor → What is in the box
     *   prints the basis, today's total, the reduction and the resulting price,
     *   with the sentence "prices only ever come down" beside them. A price
     *   that moved on its own is only alarming when nothing explains it.
     *
     * ── WHEN IT REFUSES TO MOVE AT ALL ────────────────────────────────────
     *
     *   NO ANCHOR (basis null) — every set built before this feature, and every
     *   set whose operator has not typed a price since. Nothing moves.
     *
     *   NOT `fixed` — the two discount modes derive the whole price from the
     *   parts total already, so an adjustment on top would take the drop twice.
     *
     *   AN EMPTY BOX, OR ONE WITH A MEMBER MISSING — see tally(). A product
     *   deleted from the catalogue is not a price reduction.
     */
    public static function adjustment(?Product $set): int
    {
        if ($set === null || ! $set->isSet() || self::mode($set) !== self::MODE_FIXED) {
            return 0;
        }

        $basis = self::basis($set);

        if ($basis === null) {
            return 0;
        }

        $tally = self::tally($set);

        if ($tally['rows'] < 1 || $tally['missing'] > 0) {
            return 0;
        }

        return max(0, $basis - $tally['parts']);
    }

    /**
     * One of this set's typed figures, less today's adjustment, clamped.
     *
     * ANSWERS ITS INPUT UNCHANGED FOR EVERYTHING THAT IS NOT A HAND-PRICED SET
     * WITH AN ANCHOR — which is every product in this shop, so the three call
     * sites in App\Models\Product are free and byte-identical for them.
     *
     * Integer fils in, integer fils out, one subtraction: there is no rounding
     * on this path at all, because there is no percentage on it. (The one
     * percentage this class applies is in derived(), and it is one integer
     * division with the half carried, for the reason the class header gives.)
     */
    public static function afterAdjustment(?Product $set, int $amount): int
    {
        $adjustment = self::adjustment($set);

        if ($adjustment <= 0) {
            return $amount;
        }

        // Never below the floor, and never ABOVE the figure handed in: a clamp
        // that can raise a price is not a clamp. See MIN_PRICE_FILS.
        return max(min($amount, self::MIN_PRICE_FILS), $amount - $adjustment);
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
