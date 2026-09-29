<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\ProductVariant;

/**
 * One jar, claimed twice: the set keeps it and the loose line goes. (Lane SEC)
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * A BASKET HOLDING A SET AND ONE OF ITS MEMBERS AS ITS OWN LINE COULD NOT BE
 * PAID FOR AT ALL, AND NOTHING ON THE SCREEN SAID WHY.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * ── WHAT IT LOOKED LIKE ON THE SHOP ────────────────────────────────────────
 *
 * The owner, with a screenshot of his own checkout:
 *
 *     "along with set, the order is not placing, i don't know why. please make
 *      sure about the stock quantity, if any product is in the set, and also
 *      user added in the cart too. and the stock qnty is 1, then the individual
 *      product will be removed from the cart, and set will remain there."
 *
 * The basket held the Medicube booster set AND the 1025 Dokdo Toner that is
 * inside it. One toner on the shelf. Place order came back:
 *
 *     "1025 Dokdo Toner (in Medicube booster set) is sold out. Please remove it
 *      from your basket to continue."
 *
 * — and pressing it again did the same thing for ever, because nothing the
 * shopper could do from the checkout changed the basket. Reproduced here with
 * a one-unit shelf: the refusal is "Only 1 of 1025 Dokdo Toner is left. Please
 * reduce the quantity in your basket to continue.", zero orders written, and
 * the stock untouched. Same defect, and the wording differs only by whether the
 * shelf is short or empty.
 *
 * ── THE MECHANISM, ESTABLISHED BEFORE ANYTHING WAS DESIGNED ────────────────
 *
 * Nothing is applied twice and nothing is double-counted by mistake. It is the
 * rule working exactly as written, against a basket the rule has no answer for:
 *
 *   1. `StockSetRule::expand()` (MODE_MEMBERS, the shipped default since the
 *      owner's decision of 29 September) adds one claim line per member: the
 *      set's line PLUS one toner.
 *   2. `StockClaim::perShelf()` then sums the two lines that share the toner's
 *      shelf — the member's, and the shopper's own loose line — into a single
 *      demand of TWO.
 *   3. `takeFromShelf()` finds one, refuses, and rolls the whole order back.
 *
 * Every step is right on its own. Two units really are wanted and one really is
 * there. What was missing is the decision about which of the two claims loses,
 * and the owner has now made it: THE STANDALONE LINE GOES AND THE SET STAYS.
 *
 * ── SO THIS RECONCILES THE BASKET, IT DOES NOT RELAX THE CHECK ─────────────
 *
 * ▲ Nothing here lets a set be oversold. This class only ever REMOVES demand,
 * never adds or permits it, and `StockClaim` is still the only thing that
 * decides whether units may leave a shelf — unchanged, inside the placing
 * transaction, under the same lock. A basket this class has been through is
 * refused by exactly the same rules as one it has not; it is simply a basket
 * that no longer asks for the same jar twice.
 *
 * ── AND IT HAPPENS WHILE THE SHOPPER IS LOOKING AT THE BASKET ──────────────
 *
 * On the cart page and on the checkout as they are rendered, never inside
 * `place()`. `CartService::claimStock()`'s own comment sets out why, and it is
 * the rule this follows rather than a new one:
 *
 *     "drop the unavailable line and take the rest. The shopper then pays for a
 *      basket they never agreed to, at a total they never saw."
 *
 * Reconciling at render means the totals, the item count and the free-delivery
 * bar are all computed AFTER the line is gone — they are read off the basket,
 * so they follow without being told — and the shopper is told in words what
 * left and why before they press anything.
 *
 * ── IT COSTS NOTHING ON A BASKET WITH NO SET IN IT ─────────────────────────
 *
 * Which is every basket on this shop that has not bought a set. `reconcile()`
 * returns on a cached settings read and an in-memory scan of the lines the page
 * had already loaded: NO QUERY, and StorefrontQueryBudgetTest does not move.
 * With a set in the bag it is at most two batched statements — one for the
 * member shelves, one for any variant shelves — never one per member, which is
 * the N+1 SetRowSurfacesTest measures a nine-member set against a three-member
 * one to catch.
 */
final class SetStockReconciler
{
    public function __construct(private StockSetRule $rule) {}

    /**
     * Take the loose lines that a set in this basket has already claimed.
     *
     * Returns one entry per product it trimmed, in the shopper's words, so the
     * page can say what happened. An empty list means the basket was left
     * exactly as it was — which is the answer for every basket that has no set
     * in it, and for every basket whose shelves can cover both claims.
     *
     * @return list<array{product: string, set: string, removed: int, left: int}>
     */
    public function reconcile(?Cart $cart): array
    {
        if ($cart === null || ! $this->rule->decrementsMembers()) {
            return [];
        }

        $items = $cart->relationLoaded('items') ? $cart->items : $cart->items()->get();

        if ($items->isEmpty()) {
            return [];
        }

        /*
         * THE EARLY RETURN THAT KEEPS THIS FREE. Read off the `type` column the
         * cart page and the checkout have already selected (both name it in
         * their LINE_COLUMNS), so a basket with no set in it costs one array
         * scan and no statement at all.
         */
        $setItems = $items->filter(fn (CartItem $item) => $item->product?->isSet() === true);

        if ($setItems->isEmpty()) {
            return [];
        }

        /*
         * ▲ THE STOCK COLUMNS ARE FETCHED HERE AND NOT READ OFF THE LOADED
         *   MODELS, and that is not caution — it is the first thing this class
         *   got wrong.
         *
         * CartController::LINE_COLUMNS and CheckoutController::LINE_COLUMNS
         * both select `stock_status` and NEITHER selects `manage_stock` or
         * `stock`: a cart row has no use for them. Read off those models,
         * shelfOf() finds no managed shelf on ANY loose line, every collision
         * goes unseen and this class silently does nothing at all — which is
         * exactly what it did on the first run, with every case green that
         * asserted nothing had changed.
         *
         * SetEagerLoad's `setItems.member` list is short of the same three
         * columns, for the same reason.
         *
         * So both sides are resolved from ONE batched pair of statements over
         * every product and variant this basket touches — never one per member,
         * which is the N+1 SetRowSurfacesTest measures a nine-member set
         * against a three-member one to catch.
         */
        $wanted = $this->memberRows($setItems);

        if ($wanted === []) {
            return [];
        }

        $loose = [];

        foreach ($items as $item) {
            /*
             * A set line is never a candidate. BELT AND BRACES rather than the
             * load-bearing guard: a set ships with `manage_stock` false — the
             * schema default, and what Catalog → Sets creates — so it has no
             * counted shelf of its own, and array_intersect_key() below can
             * only ever contest a shelf a set MEMBER asked for. Written out
             * anyway, because "the set stays" is the owner's decision and a
             * reader should be able to see it stated rather than inferred.
             */
            if ($item->product?->isSet() === true || $item->product_id === null) {
                continue;
            }

            $loose[] = $item;
        }

        if ($loose === []) {
            return [];
        }

        $shelves = $this->shelves($wanted, $loose);

        $claimedBySets = [];

        foreach ($wanted as $row) {
            $shelf = $this->shelfOf($shelves, $row['product_id'], $row['variant_id']);

            if ($shelf === null) {
                continue;
            }

            $claimedBySets[$shelf['key']] ??= [
                'units' => 0,
                'available' => $shelf['available'],
                'set' => $row['set'],
                'product' => (string) ($shelves['products']->get($row['product_id'])?->name ?? 'That product'),
            ];

            $claimedBySets[$shelf['key']]['units'] += $row['units'];
        }

        if ($claimedBySets === []) {
            return [];
        }

        /*
         * The loose lines, keyed by the shelf they will actually come off --
         * the variant's own when the variant counts stock, the parent
         * product's when it does not, exactly as StockClaim::claimOne() decides
         * it. Keying on the variant id alone would miss the collision this
         * whole class is about: a variable line that shares its parent's single
         * stock figure comes off the SAME shelf as a plain line.
         */
        $byShelf = [];

        foreach ($loose as $item) {
            $shelf = $this->shelfOf(
                $shelves,
                (int) $item->product_id,
                $item->product_variant_id === null ? null : (int) $item->product_variant_id,
            );

            if ($shelf === null) {
                // Nothing is counted on this line, so there is nothing to run
                // out of and nothing to take away.
                continue;
            }

            $byShelf[$shelf['key']][] = $item;
        }

        $contested = array_intersect_key($byShelf, $claimedBySets);

        if ($contested === []) {
            return [];
        }

        return $this->trim($cart, $contested, $claimedBySets);
    }

    /**
     * Every member every set in this basket asks for, one row per membership.
     *
     * @param  \Illuminate\Support\Collection<int, CartItem>  $setItems
     * @return list<array{product_id: int, variant_id: ?int, units: int, set: string}>
     */
    private function memberRows($setItems): array
    {
        $rows = [];

        foreach ($setItems as $item) {
            $sets = max(0, (int) $item->quantity);

            if ($sets < 1) {
                continue;
            }

            foreach ($item->product->setItems as $member) {
                if ($member->member_product_id === null) {
                    // A membership row whose product has been deleted is a
                    // hole, not a member -- the reading SetContents and
                    // StockSetRule both already give it.
                    continue;
                }

                /*
                 * max(1, ...) mirrors StockSetRule::expand() and
                 * SetContents::fromProduct(): a membership row of quantity 0 is
                 * one item, not an absent member. Both factors are integers, so
                 * there is no rounding here to get wrong.
                 */
                /*
                 * ▲ THE MEMBER'S NAME IS NOT READ OFF `$member->member` HERE,
                 *   and that is the second thing this class got wrong.
                 *
                 * `setItems.member` is eager-loaded by SetEagerLoad on the cart
                 * and the checkout, but NOT by every caller — and an unloaded
                 * belongsTo lazy-loads, which is one query per member inside a
                 * page render. Measured before it was moved: nine statements
                 * for a nine-member set and three for a three-member one.
                 * shelves() already fetches `name` for every member in one
                 * batch, so that is where the name comes from.
                 */
                $rows[] = [
                    'product_id' => (int) $member->member_product_id,
                    'variant_id' => $member->member_variant_id === null ? null : (int) $member->member_variant_id,
                    'units' => max(1, (int) $member->quantity) * $sets,
                    'set' => (string) ($item->product->name ?? 'this set'),
                ];
            }
        }

        return $rows;
    }

    /**
     * The stock columns for every product and variant this basket touches, in
     * one statement each.
     *
     * @param  list<array{product_id: int, variant_id: ?int, units: int, set: string}>  $wanted
     * @param  list<CartItem>  $loose
     * @return array{products: \Illuminate\Support\Collection, variants: \Illuminate\Support\Collection}
     */
    private function shelves(array $wanted, array $loose): array
    {
        $productIds = [];
        $variantIds = [];

        foreach ($wanted as $row) {
            $productIds[$row['product_id']] = true;

            if ($row['variant_id'] !== null) {
                $variantIds[$row['variant_id']] = true;
            }
        }

        foreach ($loose as $item) {
            $productIds[(int) $item->product_id] = true;

            if ($item->product_variant_id !== null) {
                $variantIds[(int) $item->product_variant_id] = true;
            }
        }

        return [
            'products' => Product::query()
                ->whereIn('id', array_keys($productIds))
                ->get(['id', 'name', 'manage_stock', 'stock', 'stock_status'])
                ->keyBy('id'),
            'variants' => $variantIds === []
                ? collect()
                : ProductVariant::query()
                    ->whereIn('id', array_keys($variantIds))
                    ->get(['id', 'product_id', 'manage_stock', 'stock', 'stock_status'])
                    ->keyBy('id'),
        ];
    }

    /**
     * Take the contested units off the loose lines, newest line first.
     *
     * @param  array<string, list<CartItem>>  $contested
     * @param  array<string, array{units: int, available: int, set: string, product: string}>  $claimed
     * @return list<array{product: string, set: string, removed: int, left: int}>
     */
    private function trim(Cart $cart, array $contested, array $claimed): array
    {
        $report = [];
        $changed = false;

        foreach ($contested as $key => $lines) {
            $claim = $claimed[$key];

            /*
             * What is left for a loose line after the box has been filled. The
             * set is served first and in full — that is the owner's decision,
             * in as many words — so the loose line may have whatever the shelf
             * still holds, which is frequently nothing.
             *
             * Integer arithmetic throughout and floored at zero: a shelf that
             * cannot even fill the box leaves the loose line NOTHING, rather
             * than a negative allowance that would read as a credit.
             */
            $allowed = max(0, $claim['available'] - $claim['units']);

            $wanted = 0;

            foreach ($lines as $line) {
                $wanted += max(0, (int) $line->quantity);
            }

            if ($wanted <= $allowed) {
                continue;
            }

            $removed = 0;

            /*
             * From the LAST line backwards, so a shopper who added the same
             * product twice keeps the line they added first. Whole lines are
             * deleted rather than left at zero: a zero-quantity row is a line
             * the cart page would draw, the totals would count and nobody could
             * remove.
             */
            foreach (array_reverse($lines) as $line) {
                $quantity = max(0, (int) $line->quantity);

                if ($quantity < 1) {
                    continue;
                }

                $keep = max(0, min($quantity, $allowed));
                $allowed -= $keep;

                if ($keep === $quantity) {
                    continue;
                }

                $removed += $quantity - $keep;
                $changed = true;

                if ($keep === 0) {
                    $line->delete();

                    continue;
                }

                $line->quantity = $keep;
                $line->save();
            }

            if ($removed > 0) {
                $report[] = [
                    'product' => $claim['product'],
                    'set' => $claim['set'],
                    'removed' => $removed,
                    'left' => max(0, $wanted - $removed),
                ];
            }
        }

        if ($changed) {
            /*
             * The relation in memory still describes the basket as it was, and
             * every figure the page is about to print — the subtotal, the item
             * count, the free-delivery bar — is read off it. Dropping it is
             * what makes them all follow; the caller re-hydrates with its own
             * eager load, which is the only place that knows which columns the
             * view needs.
             */
            $cart->unsetRelation('items');
        }

        return $report;
    }

    /**
     * Which shelf a (product, variant) pair comes off, and how deep it is.
     *
     * The same three-way decision StockClaim::claimOne() makes, and it has to
     * stay the same one: the variant's own shelf when the variant counts stock,
     * the parent product's when it does not, and NO shelf at all when neither
     * counts -- an uncounted product cannot run out, so it can never be the one
     * contested.
     *
     * A shelf whose `stock_status` is not `instock` is read as EMPTY rather
     * than as its figure, because that is the question StockClaim asks first
     * and refuses on: the owner flips that column by hand in Store -> Products,
     * and a product he has marked sold out is sold out whatever the number
     * says.
     *
     * @param  array{products: \Illuminate\Support\Collection, variants: \Illuminate\Support\Collection}  $shelves
     * @return array{key: string, available: int}|null
     */
    private function shelfOf(array $shelves, int $productId, ?int $variantId): ?array
    {
        $product = $shelves['products']->get($productId);

        if ($product === null) {
            return null;
        }

        $variant = $variantId === null ? null : $shelves['variants']->get($variantId);

        $soldOut = ($variant?->stock_status ?? $product->stock_status) !== 'instock';

        if ($variant !== null && $variant->manage_stock) {
            return [
                'key' => 'variant:' . (int) $variant->id,
                'available' => $soldOut ? 0 : max(0, (int) ($variant->stock ?? 0)),
            ];
        }

        if ($product->manage_stock) {
            return [
                'key' => 'product:' . (int) $product->id,
                'available' => $soldOut ? 0 : max(0, (int) ($product->stock ?? 0)),
            ];
        }

        return null;
    }
}
