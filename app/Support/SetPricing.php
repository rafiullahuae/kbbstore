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

        /*
         * ▲ A LEFT JOIN WITH THE FILTER MOVED INTO A CASE, AND STILL ONE
         *   STATEMENT. The inner join this replaces dropped a deleted member's
         *   row from the result entirely, which is the right answer for the
         *   TOTAL and makes the row uncountable -- and the count is the whole
         *   point of this method. A missing member now contributes 0 to `parts`
         *   exactly as it did before, and 1 to `missing`, in one pass.
         */
        $gone = "(p.id IS NULL OR p.deleted_at IS NOT NULL OR p.type = 'set')";

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
