<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;

/**
 * Taking units off the shelf, and putting them back. One implementation.
 *
 * ---------------------------------------------------------------------------
 * Why this is its own class
 * ---------------------------------------------------------------------------
 *
 * This logic was written inside CartService::claimStock(), which takes a Cart.
 * Two doors into this shop write real orders and only one of them has a Cart:
 * Store\CheckoutController::place() does, and Api\CheckoutController::session()
 * builds its lines from a request shape instead. `/api/*` is unauthenticated —
 * CLAUDE.md says so in as many words — so for as long as that second door
 * checked `stock_status` and nothing else, anyone on the internet could buy the
 * same single jar as many times as they liked.
 *
 * The fix is not a second decrement written against the request shape. Two
 * implementations of "take the units off the shelf" drift, and the one that
 * drifts is the one nobody is looking at. So the routine moved here, it takes a
 * list of (product, variant, quantity) and nothing else, and CartService is now
 * the thing that turns a Cart into that list.
 *
 * ---------------------------------------------------------------------------
 * The two halves that make it safe, both load-bearing
 * ---------------------------------------------------------------------------
 *
 * lockForUpdate() is what serialises two MySQL transactions on the same row,
 * the same way CouponService::lockForRedemption() does for a usage limit —
 * without it both read `stock = 1`, both decide there is room, and both write.
 * It is a no-op on SQLite, which takes one database-wide write lock instead.
 *
 * The decrement then repeats the condition in its own WHERE and checks how many
 * rows it changed. That is what makes the sequence correct with no lock at all:
 * an UPDATE ... WHERE stock >= n is atomic in its own right, so if anything did
 * slip between the read and the write, the loser changes no rows and is refused
 * rather than driving the column negative.
 *
 * THE ROWS ARE RE-READ BY KEY, never taken off a caller's own instances. Every
 * storefront path loads its lines through a narrow column list —
 * Store\CheckoutController::LINE_COLUMNS has `stock_status` and neither
 * `manage_stock` nor `stock`, and the variant is loaded as
 * `id,product_id,sku,price,sale_price,image,stock_status`. On those instances
 * `manage_stock` reads null, which is falsy, so a check written against them
 * would decide that NOTHING in the shop counts stock and pass every basket.
 * That is the identical trap CouponService::lockForRedemption() documents, and
 * the reason it re-reads the coupon.
 *
 * ---------------------------------------------------------------------------
 * The ledger, and why a return needs one
 * ---------------------------------------------------------------------------
 *
 * Every decrement writes a row to `order_stock_claims` saying which shelf lost
 * how many units for which order. release() puts back exactly those units and
 * nothing else. The migration that creates the table sets out at length why
 * every way of DERIVING that answer later — from the order's lines, from
 * `manage_stock` as it stands now, from which shelf the line points at —
 * invents inventory in this shop. A claim with no order id recorded against it
 * writes no ledger row and therefore can never be returned; the two placement
 * paths both pass one, and OrderStockReturnTest pins that they do.
 */
final class StockClaim
{
    /**
     * The only two tables this class will ever read a shelf out of.
     *
     * An explicit allowlist because release() reads `shelf_table` back out of
     * the database and hands it to DB::table(). The column is written only by
     * claimOne() below, so a bad value should be impossible — which is exactly
     * the assumption worth not making about a table name that becomes SQL.
     */
    public const SHELVES = ['products', 'product_variants'];

    private const LEDGER = 'order_stock_claims';

    /**
     * Take the units for one order off the shelves, or refuse the whole order.
     *
     * THE WHOLE ORDER IS REFUSED rather than trimmed. Dropping the unavailable
     * line means the shopper pays for a basket they did not agree to; letting
     * it through with a flag means the shop takes money for something it cannot
     * ship. Both move money against an order the customer never confirmed. A
     * refusal costs the sale and leaves the basket intact for them to fix, and
     * it is what the coupon path next door already does when a code runs out
     * mid-checkout, so one situation has one shape.
     *
     * MUST RUN INSIDE THE TRANSACTION THAT CREATES THE ORDER, and says so
     * rather than hoping. A decrement that can commit on its own would take
     * units off the shelf for an order that then rolls back on the next
     * statement.
     *
     * WHICH SHELF A LINE COMES OFF. A variant that counts its own stock is its
     * own shelf; a variant that does not falls back to the parent product's,
     * which is how a variable product with one shared stock figure behaves.
     * Products that count no stock at all (`manage_stock` off) are not counted,
     * not decremented and not marked — the only thing asked of them is the same
     * `stock_status` question the add-to-basket paths already ask.
     *
     * @param  list<array{product_id:int, variant_id:?int, quantity:int, label:string}>  $lines
     * @param  int|null  $orderId  the order these units are leaving for. Without
     *                             it nothing is recorded and nothing can ever be
     *                             given back — see the class comment.
     *
     * @throws StockUnavailable  and nothing is written
     */
    public function claim(array $lines, ?int $orderId = null): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException(
                'StockClaim::claim() must run inside the transaction that creates the order. '
                . 'A decrement that can commit on its own takes units off the shelf for an order that then rolls back.'
            );
        }

        foreach ($this->perShelf($lines) as $line) {
            $this->claimOne($line, $orderId);
        }
    }

    /**
     * Demand summed PER SHELF before anything is checked.
     *
     * One product can legitimately appear on two lines — the same product added
     * through the cart page and through the checkout's Browsed tab, or a
     * variant line beside a plain one — and the API endpoint's request shape is
     * a plain list of slugs, so the same slug twice is a single POST away.
     * Checking each line against the shelf on its own lets a basket of 1 + 1 buy
     * a single remaining unit twice over.
     *
     * @param  list<array{product_id:int, variant_id:?int, quantity:int, label:string}>  $lines
     * @return array<string, array{product_id:int, variant_id:?int, quantity:int, label:string}>
     */
    private function perShelf(array $lines): array
    {
        $wanted = [];

        foreach ($lines as $line) {
            $productId = (int) ($line['product_id'] ?? 0);

            if ($productId < 1) {
                continue;
            }

            $variantId = isset($line['variant_id']) && $line['variant_id'] !== null
                ? (int) $line['variant_id']
                : null;

            $key = $variantId !== null ? 'variant:' . $variantId : 'product:' . $productId;

            $wanted[$key] ??= [
                'product_id' => $productId,
                'variant_id' => $variantId,
                'quantity' => 0,
                // Used only in the refusal sentence, so it is taken from what
                // the caller has already loaded and never fetched again.
                'label' => (string) ($line['label'] ?? 'Item'),
            ];

            $wanted[$key]['quantity'] += max(0, (int) ($line['quantity'] ?? 0));
        }

        return $wanted;
    }

    /**
     * One shelf: checked under a lock, then decremented conditionally.
     *
     * @param  array{product_id:int, variant_id:?int, quantity:int, label:string}  $line
     *
     * @throws StockUnavailable
     */
    private function claimOne(array $line, ?int $orderId): void
    {
        $quantity = $line['quantity'];

        if ($quantity < 1) {
            return;
        }

        // Soft-deleted products are excluded by the model's own scope, so a
        // product the owner has binned since it went in the basket arrives here
        // as null and is refused rather than sold.
        $product = Product::whereKey($line['product_id'])->lockForUpdate()->first();

        if ($product === null) {
            throw new StockUnavailable($line['label'] . ' is no longer available. Please remove it from your basket to continue.');
        }

        $variant = $line['variant_id'] !== null
            ? ProductVariant::whereKey($line['variant_id'])->lockForUpdate()->first()
            : null;

        if ($line['variant_id'] !== null && $variant === null) {
            throw new StockUnavailable($line['label'] . ' is no longer available. Please remove it from your basket to continue.');
        }

        /*
         * The same question the add-to-basket paths ask, asked again here:
         * `($variant?->stock_status ?? $product->stock_status) !== 'instock'`.
         * It applies whether or not stock is counted, because this is the shape
         * a sell-out actually takes in this shop — the owner flips the status by
         * hand in Store → Products — and a product nobody may add to a basket
         * is not one anybody may pay for either.
         */
        if (($variant?->stock_status ?? $product->stock_status) !== 'instock') {
            throw new StockUnavailable($line['label'] . ' is sold out. Please remove it from your basket to continue.');
        }

        // The shelf: the variant's own when it counts stock, otherwise the
        // parent's, otherwise nothing is counted and there is nothing to do.
        if ($variant !== null && $variant->manage_stock) {
            $this->takeFromShelf('product_variants', (int) $variant->id, $variant->stock, $quantity, $line['label'], $orderId, $line);

            return;
        }

        if ($product->manage_stock) {
            $this->takeFromShelf('products', (int) $product->id, $product->stock, $quantity, $line['label'], $orderId, $line);
        }
    }

    /**
     * Decrement one counted shelf, and mark it sold out when it empties.
     *
     * MARKING IT MATTERS AS MUCH AS THE DECREMENT. Leaving stock_status at
     * `instock` over a zero shelf is what makes the shop go on advertising a
     * product it cannot ship: every card, every listing and the product page
     * itself read that column, and the next shopper gets all the way to Place
     * order before anything says no. Setting it here is the difference between
     * one refused checkout and a queue of them.
     *
     * It is no longer one-way. release() below undoes exactly this, and only
     * this: the ledger row records whether this claim is what set the status,
     * so a return re-lists a product only when a claim is what delisted it.
     * Restocking in the ordinary sense is still the owner's own action in
     * Store → Products and nothing here touches it.
     *
     * @param  array{product_id:int, variant_id:?int, quantity:int, label:string}  $line
     *
     * @throws StockUnavailable
     */
    private function takeFromShelf(
        string $table,
        int $id,
        mixed $have,
        int $quantity,
        string $label,
        ?int $orderId,
        array $line,
    ): void {
        // NULL on a row that says it counts stock is zero, not "unlimited" —
        // the same reading the admin's own list takes (CatalogProductsApi
        // Controller prints `stock` as 0 for a managed product with no figure).
        $have = (int) ($have ?? 0);

        $changed = DB::table($table)
            ->where('id', $id)
            ->where('stock', '>=', $quantity)
            ->update(['stock' => DB::raw('stock - ' . $quantity)]);

        if ($changed !== 1) {
            throw new StockUnavailable($this->shortfall($label, $have));
        }

        $emptied = $have - $quantity <= 0;

        if ($emptied) {
            DB::table($table)->where('id', $id)->update(['stock_status' => 'outofstock']);
        }

        $this->record($orderId, $table, $id, $quantity, $emptied, $line);
    }

    /** The sentence a shopper reads when the shelf cannot cover their basket. */
    private function shortfall(string $label, int $have): string
    {
        if ($have < 1) {
            return $label . ' is sold out. Please remove it from your basket to continue.';
        }

        return 'Only ' . $have . ' of ' . $label . ' ' . ($have === 1 ? 'is' : 'are')
            . ' left. Please reduce the quantity in your basket to continue.';
    }

    /**
     * Write down what just left the shelf.
     *
     * In the same transaction as the decrement it describes, so the two cannot
     * disagree: a rolled-back order has neither, and a committed one has both.
     *
     * A claim made with no order id records nothing. That is not a silent
     * failure — it is the honest answer, because there is nothing to attribute
     * the units to and therefore no event that could ever give them back. The
     * only caller in that position is the two-process race harness, which
     * writes its own order rows outside the placement code.
     *
     * @param  array{product_id:int, variant_id:?int, quantity:int, label:string}  $line
     */
    private function record(?int $orderId, string $table, int $id, int $quantity, bool $emptied, array $line): void
    {
        if ($orderId === null) {
            return;
        }

        $now = now();

        DB::table(self::LEDGER)->insert([
            'order_id' => $orderId,
            'shelf_table' => $table,
            'shelf_id' => $id,
            'product_id' => $line['product_id'],
            'product_variant_id' => $line['variant_id'],
            'quantity' => $quantity,
            'marked_outofstock' => $emptied,
            'released_at' => null,
            'released_reason' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /* ------------------------------------------------------------- the way back */

    /**
     * Put an order's claimed units back on the shelves they came off.
     *
     * WHAT STOPS A DOUBLE RETURN, and why it is a recorded fact rather than an
     * inference. Cancelling an order twice, or cancelling it and then refunding
     * it, must not credit the shelf twice. The order's own status cannot answer
     * that — `cancelled` looks identical whether the units went back a second
     * ago or a month ago — so each ledger row carries its own `released_at`,
     * and the row is CLAIMED BEFORE THE STOCK MOVES:
     *
     *     UPDATE order_stock_claims SET released_at = ... WHERE id = ? AND released_at IS NULL
     *
     * with the affected row count checked. Of two callers racing to release the
     * same order, exactly one gets 1 back and exactly one adds the units. The
     * loser gets 0 and does nothing. It is the same shape as the conditional
     * decrement on the way out, for the same reason.
     *
     * ORDERS THAT NEVER CLAIMED ANYTHING GET NOTHING BACK, which is the point.
     * Imported WooCommerce orders, orders written by ManualOrderBuilder and
     * orders placed while `manage_stock` was off all have no rows here, so this
     * returns 0 and touches nothing. Crediting them would invent inventory,
     * which is worse than the bug this repairs.
     *
     * THE WHOLE THING IS ONE TRANSACTION so a crash between marking a row
     * released and moving its units cannot lose them. Nested inside a caller's
     * transaction it becomes a savepoint, which is the behaviour wanted there
     * too.
     *
     * @param  string  $reason  short, for the operator reading the table later.
     * @return int  units actually returned; 0 when there was nothing to return.
     */
    public function release(int $orderId, string $reason = 'released'): int
    {
        return DB::transaction(function () use ($orderId, $reason): int {
            $rows = DB::table(self::LEDGER)
                ->where('order_id', $orderId)
                ->whereNull('released_at')
                ->orderBy('id')
                ->get();

            $returned = 0;

            foreach ($rows as $row) {
                $table = (string) $row->shelf_table;

                // The allowlist, applied to a value that is about to become a
                // table name in a query. See SHELVES.
                if (! in_array($table, self::SHELVES, true)) {
                    continue;
                }

                $quantity = (int) $row->quantity;

                if ($quantity < 1) {
                    continue;
                }

                /*
                 * Claim the row first. Whoever wins this is the one that moves
                 * the stock; everybody else is told 0 and stands down.
                 *
                 * THE `whereNull` IN HERE IS WHAT MAKES THE COUNT MEAN
                 * ANYTHING, and it is not the same argument as the decrement's.
                 * There the count is safe because `stock - n` always changes
                 * the value. Here the values being written are timestamps at
                 * second precision, so two releases in the same second write
                 * BYTE-IDENTICAL rows and MySQL reports 0 changed rows for the
                 * second one whether or not it was entitled to proceed —
                 * accidentally the right answer, for a reason that would stop
                 * being true the moment anything about this row changed.
                 *
                 * With the condition in the WHERE, the second release matches
                 * no row at all. That is a fact about entitlement rather than
                 * about what the bytes happened to be, and it is what the
                 * two-process StockReturnRaceTest measures.
                 */
                $claimed = DB::table(self::LEDGER)
                    ->where('id', $row->id)
                    ->whereNull('released_at')
                    ->update([
                        'released_at' => now(),
                        'released_reason' => mb_substr($reason, 0, 60),
                        'updated_at' => now(),
                    ]);

                if ($claimed !== 1) {
                    continue;
                }

                // COALESCE because the column may have been blanked by hand in
                // Store → Products since the claim was made. NULL + n is NULL
                // in SQL, which would erase the shelf rather than credit it.
                DB::table($table)
                    ->where('id', (int) $row->shelf_id)
                    ->update(['stock' => DB::raw('COALESCE(stock, 0) + ' . $quantity)]);

                /*
                 * Re-list the shelf only when THIS claim is what delisted it.
                 *
                 * Undoing our own write, not making a judgement about what the
                 * owner wants on sale: a product marked sold out by hand in
                 * Store → Products carries no such flag on any claim row, so an
                 * unrelated cancellation cannot quietly put it back in the shop
                 * window. The `stock > 0` clause is there because a return of
                 * one unit against a shelf that other live orders have emptied
                 * again should not advertise stock that is spoken for.
                 */
                if ($row->marked_outofstock) {
                    DB::table($table)
                        ->where('id', (int) $row->shelf_id)
                        ->where('stock_status', 'outofstock')
                        ->where('stock', '>', 0)
                        ->update(['stock_status' => 'instock']);
                }

                $returned += $quantity;
            }

            return $returned;
        });
    }


    /* ---------------------------------------------------- and back off again */

    /**
     * Re-take the units a release put back, for an order that has come alive.
     *
     * ---------------------------------------------------------------------
     * Why this exists, and what it is the inverse of
     * ---------------------------------------------------------------------
     *
     * release() is called when an order moves to a status that means it will
     * never ship. Until this method existed that was the end of it: the units
     * were on the shelf, and an operator who set the order back to
     * `processing` on the order screen got a live order holding a line for a
     * unit the shop had already put back on sale. The order still had to be
     * shipped; the unit was sellable to somebody else. That is an oversell,
     * created by a dropdown, with nothing on the screen to say so.
     *
     * So the way back is now the same shape as the way out, and this is its
     * other half.
     *
     * ---------------------------------------------------------------------
     * THE LEDGER DECIDES, NOT THE ORDER'S STATUS
     * ---------------------------------------------------------------------
     *
     * What comes off the shelf here is exactly the rows a release stamped —
     * `released_at IS NOT NULL` — and nothing else. That is what makes the two
     * properties this has to have true by construction rather than by a list
     * somebody has to keep in step:
     *
     *   AN ORDER THAT NEVER RELEASED IS NEVER RE-CLAIMED. An order cancelled
     *   from `shipped` never gave anything back, because
     *   OrderTransitionStock::returns() said so; its rows are unreleased and
     *   this finds nothing to take. Re-taking there would invent a shortage
     *   out of nothing, which is the same bug pointing the other way.
     *
     *   A SECOND REVIVE TAKES NOTHING. The first one clears `released_at`, so
     *   the second matches no rows. Cancel, revive, cancel, revive ends where
     *   it started, and the arithmetic is the ledger's rather than a count of
     *   how many times anybody pressed anything.
     *
     * It is also strictly better than re-reading the status lists would be.
     * An order cancelled (units back), then refunded, then set to
     * `processing` arrives here with `from = refunded` — a status that returns
     * no stock and therefore looks, from the statuses alone, like an order
     * with nothing to re-take. The ledger still carries the released rows from
     * the cancellation, and they are what gets taken.
     *
     * ---------------------------------------------------------------------
     * IT THROWS, AND THAT IS THE POINT
     * ---------------------------------------------------------------------
     *
     * applied() next door cannot throw, on purpose: a failure to hand stock
     * back must not turn an operator's Cancel button into a 500 on an order
     * that is already cancelled. This is the opposite situation. If the unit
     * has been sold since, there is no honest way to finish the revive — the
     * shop would be promising a unit it does not have — so the whole
     * transition is refused and the caller is told which product is short and
     * by how many. The order is left exactly as it was.
     *
     * The refusal therefore has to roll back the rows already taken by this
     * same call, which is why the throw happens inside the transaction below.
     * Nested inside the caller's transaction that is a savepoint, and the
     * exception carries on past it to roll the status change back too.
     *
     * WHAT IS NOT CHECKED: `stock_status`. A shelf the owner has marked sold
     * out by hand while still holding the units is not a reason to refuse — the
     * scarce thing is the unit, and the column is a shop-window flag. The
     * conditional decrement below is the real test and it is the only one.
     *
     * NO $reason, deliberately, where release() takes one. A release WRITES a
     * sentence to `released_reason` for the operator reading the table later;
     * a re-take CLEARS that column, because the units are not on the shelf any
     * more and the old sentence would say they were. There is nothing for a
     * reason to be written to, and a parameter nobody can act on is a promise
     * the next reader has to check. What the revive cost is recorded where an
     * operator actually looks: the order note OrderStatus writes.
     *
     * @return int  units taken back off the shelves; 0 when nothing was released.
     *
     * @throws StockUnavailable  naming the shelf that is short, and nothing is left applied
     */
    public function reclaim(int $orderId): int
    {
        return DB::transaction(function () use ($orderId): int {
            $rows = DB::table(self::LEDGER)
                ->where('order_id', $orderId)
                ->whereNotNull('released_at')
                ->orderBy('id')
                ->get();

            $taken = 0;

            foreach ($rows as $row) {
                $table = (string) $row->shelf_table;

                // The allowlist, applied to a value that is about to become a
                // table name in a query. See SHELVES.
                if (! in_array($table, self::SHELVES, true)) {
                    continue;
                }

                $quantity = (int) $row->quantity;

                if ($quantity < 1) {
                    continue;
                }

                /*
                 * Claim the row back first, on the same conditional-update
                 * shape release() uses and for the same reason: two requests
                 * reviving one order must re-take the units once between them,
                 * and `released_at` is the fact that says whether there is
                 * anything to re-take. The loser matches no row and stands
                 * down.
                 *
                 * `released_reason` is cleared with it. Leaving the old
                 * sentence on a row that is live again would tell the operator
                 * reading the table that these units are back on the shelf
                 * when they are not.
                 */
                $claimed = DB::table(self::LEDGER)
                    ->where('id', $row->id)
                    ->whereNotNull('released_at')
                    ->update([
                        'released_at' => null,
                        'released_reason' => null,
                        'updated_at' => now(),
                    ]);

                if ($claimed !== 1) {
                    continue;
                }

                $this->takeBack($table, (int) $row->shelf_id, $quantity, (int) $row->id, $row);

                $taken += $quantity;
            }

            return $taken;
        });
    }

    /**
     * One shelf, taken back conditionally, or the whole revive is refused.
     *
     * The same `WHERE stock >= n` that claimOne() relies on, for the identical
     * reason: it is atomic in its own right, so the shelf cannot be driven
     * negative by anything that slipped in between the read and the write, and
     * a row count of zero is the honest "there are not enough".
     *
     * @throws StockUnavailable
     */
    private function takeBack(string $table, int $shelfId, int $quantity, int $ledgerId, object $row): void
    {
        $changed = DB::table($table)
            ->where('id', $shelfId)
            ->where('stock', '>=', $quantity)
            ->update(['stock' => DB::raw('stock - ' . $quantity)]);

        if ($changed !== 1) {
            // Read what is actually there for the sentence. The shelf row may
            // also be gone entirely — a product binned since the order was
            // cancelled — which is a refusal too and reads as "sold out".
            $have = DB::table($table)->where('id', $shelfId)->value('stock');

            throw new StockUnavailable($this->shortBy($row, (int) ($have ?? 0), $quantity));
        }

        /*
         * Delisting, and recording whether WE did it.
         *
         * `marked_outofstock` means "this claim is what took the shop window
         * down", and release() reads it to decide whether to put the window
         * back. It is recomputed here rather than trusted from before the
         * release: the shelf may have been restocked, or delisted by hand in
         * Store -> Products, in the meantime. Only a change this statement
         * actually made is recorded, so a later release cannot re-list
         * something the owner delisted himself.
         */
        $left = (int) (DB::table($table)->where('id', $shelfId)->value('stock') ?? 0);

        $marked = false;

        if ($left <= 0) {
            $marked = DB::table($table)
                ->where('id', $shelfId)
                ->where('stock_status', 'instock')
                ->update(['stock_status' => 'outofstock']) === 1;
        }

        DB::table(self::LEDGER)->where('id', $ledgerId)->update([
            'marked_outofstock' => $marked,
            'updated_at' => now(),
        ]);
    }

    /**
     * The sentence an operator reads when a revive cannot be paid for.
     *
     * It names the product, because "not enough stock" on a forty-order bulk
     * action tells them nothing they can act on. The name is read here, once,
     * only on the refusal path — the happy path never needs it.
     */
    private function shortBy(object $row, int $have, int $want): string
    {
        $name = null;

        if ($row->product_id !== null) {
            $name = DB::table('products')->where('id', (int) $row->product_id)->value('name');
        }

        if ($name === null && (string) $row->shelf_table === 'products') {
            $name = DB::table('products')->where('id', (int) $row->shelf_id)->value('name');
        }

        $label = trim((string) ($name ?? '')) !== '' ? (string) $name : 'A product on this order';

        if ($row->product_variant_id !== null) {
            $sku = DB::table('product_variants')->where('id', (int) $row->product_variant_id)->value('sku');

            if (trim((string) ($sku ?? '')) !== '') {
                $label .= ' (' . $sku . ')';
            }
        }

        return sprintf(
            '%s is short by %d — this order needs %d and there %s %d in stock.',
            $label,
            max(0, $want - $have),
            $want,
            $have === 1 ? 'is' : 'are',
            max(0, $have),
        );
    }

    /** Units this order took and has not had returned. Read-only; for tests and screens. */
    public function outstandingFor(int $orderId): int
    {
        return (int) DB::table(self::LEDGER)
            ->where('order_id', $orderId)
            ->whereNull('released_at')
            ->sum('quantity');
    }
}
