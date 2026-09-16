<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * Who gets which order number, and why it is no longer decided inside the
 * placing transaction.
 *
 * ── THE BUG THIS REPLACES ───────────────────────────────────────────────────
 *
 * `orders.order_number` is NOT NULL UNIQUE. Both placement paths —
 * Store\CheckoutController::nextOrderNumber() and
 * ManualOrderBuilder::insertOrder() — minted it the same way: read MAX(), walk
 * forward to the first free number, insert, and on a duplicate key try again.
 *
 * The retry was the trap. The read happened INSIDE the placing transaction,
 * and under MySQL's default REPEATABLE READ every consistent read in a
 * transaction is served from the snapshot taken at its first read. So each
 * retry re-read the same frozen maximum, computed the identical candidate, and
 * was refused by the unique index again — a thousand times on the storefront,
 * eight times in the back office — before failing the placement outright with
 * SQLSTATE 23000 at the last step of a customer's checkout. Two shoppers
 * pressing Place order together meant one of them lost their order.
 *
 * Measured, before this class existed, with two real OS processes on MySQL
 * (tests/Feature/OrderNumberRaceTest.php): eight rounds, eight lost orders.
 *
 * The checkout path carried a second defect that needed no concurrency at all.
 * It read `Order::max(...)` and `Order::where(...)->exists()` through the
 * DEFAULT scope, so a SOFT-DELETED order was invisible to it — while its
 * number remained very much present in the unique index. One trashed order at
 * the top of the range made every checkout in the shop fail, permanently, on
 * the same number. `withTrashed()` below is that fix, and the reason every
 * query in this class carries it.
 *
 * ── WHAT ARBITRATES NOW ─────────────────────────────────────────────────────
 *
 * A single sequence row, advanced by a COMPARE-AND-SWAP, and never read inside
 * the caller's transaction. That is the whole design, and both halves matter:
 *
 *   OUTSIDE THE TRANSACTION. allocate() is called BEFORE the placing
 *   transaction opens, so each statement here is its own transaction and every
 *   re-read sees what the other process just committed. This is precisely what
 *   the old in-transaction retry could not do. It is also why there is no
 *   `SELECT ... FOR UPDATE` anywhere below: a row lock taken here would be
 *   held until the caller's placement committed, and every checkout in the
 *   shop would queue behind the slowest one. That is a different outage, not a
 *   fix.
 *
 *   COMPARE-AND-SWAP, not a lock. The claim is written to be REFUSED — the
 *   UPDATE carries `WHERE next_number = <what we read>`, and a row count of
 *   zero means another allocator moved the sequence first, so we re-read and
 *   try again. Same instinct as InvoiceNumbers::claim() and the unique index
 *   on refunds.idempotency_key: a check in application code describes the
 *   rule, the statement that can fail IS the rule. Nothing is held between
 *   statements, so contention costs a retry rather than a queue.
 *
 * The unique index remains the last word. Nothing here promises the number is
 * still free by the time the caller inserts it — an importer writing explicit
 * WooCommerce numbers can take one in between — so callers keep their retry,
 * and because allocation now happens outside their transaction, their retry
 * finally sees fresh data.
 *
 * ── WHAT IT WILL NOT DO ─────────────────────────────────────────────────────
 *
 * It never renumbers anything. Imported WooCommerce orders keep the numbers
 * they arrived with; the sequence is seeded ABOVE everything already in the
 * table (soft-deleted rows included) and only ever moves forward, and a claimed
 * number that turns out to be taken is abandoned rather than reused.
 *
 * GAPS ARE EXPECTED AND CHEAP. A number is spent when it is allocated, so a
 * placement that then rolls back leaves a hole in the sequence. That is the
 * price of not holding a lock across the placement, and an order number is a
 * reference, not a count — nothing in this application derives a total from
 * the spacing between them. An invoice number, which auditors do count, is
 * allocated by InvoiceNumbers at the point the invoice is issued, not here.
 *
 * ── PORTABILITY ─────────────────────────────────────────────────────────────
 *
 * Every statement below is a plain SELECT, UPDATE or INSERT with no locking
 * clause and no dialect-specific syntax, so MySQL and SQLite run the same
 * code. The suite runs on the second and the shop on the first, and this is
 * exactly the kind of difference that let the original bug reach production.
 */
class OrderNumbers
{
    /** The one-row table this sequence lives in. */
    public const TABLE = 'order_number_sequence';

    /** The row. There is only ever one. */
    public const ROW_ID = 1;

    /**
     * The floor for a store that has never taken an order.
     *
     * 10001 rather than 1: the original formula was `10000 + max(id)`, live
     * order numbers are five digits because of it, and starting lower would
     * hand out numbers that look like somebody else's.
     */
    public const FIRST_NUMBER = 10001;

    /**
     * How many times we lose the compare-and-swap before giving up.
     *
     * Each loss means another process allocated between our read and our
     * write, which is the system working as designed.
     *
     * SIZED FROM MEASUREMENTS, not from taste. Losing is geometric: with N
     * contenders each round is won by one of them, so the chance of losing K
     * in a row is ((N-1)/N)^K. Three numbers from this repo's own runs, all
     * with processes doing nothing whatsoever but allocating:
     *
     *   at 20, no backoff, 4 processes  -> two of the four failed
     *   at 50, with backoff, 4 processes -> all four completed, 400/400 numbers
     *   at 50, with backoff, 8 processes -> three of the eight failed
     *
     * The eight-process case is (7/8)^50, about one allocation in 770, which
     * over hundreds of allocations is a near-certainty. At 200 the same case is
     * (7/8)^200 — about one in 4x10^11 — and the worst-case wait is still under
     * a second, because the backoff is capped in microseconds.
     *
     * NONE OF THIS AFFECTS CORRECTNESS. Every run above, including the ones
     * that failed, handed out numbers that were all distinct: the claim either
     * succeeds and the number is exclusively ours, or it fails and we take
     * nothing. The ceiling only decides how long we are willing to keep trying,
     * and eight processes allocating flat out is orders of magnitude past what
     * this shop does.
     */
    private const MAX_CLAIMS = 200;

    /**
     * The next free order number.
     *
     * MUST BE CALLED OUTSIDE THE PLACING TRANSACTION. Called inside one, every
     * statement below joins that transaction: the re-reads go back to the
     * caller's snapshot and this becomes the bug it replaces. Both callers do
     * it first and pass the result in — see CheckoutController::place() and
     * ManualOrderBuilder::create().
     */
    public function allocate(): string
    {
        /*
         * Bring the sequence up to the table before claiming anything.
         *
         * WHY THIS IS NOT OPTIONAL. The contract this allocator inherited is
         * that new numbers CONTINUE from whatever is already there — imported
         * WooCommerce orders keep theirs, and the next order placed takes a
         * higher one. CheckoutPlacementTest pins it with an imported order at
         * 48231. Without this the sequence would happily hand out 10001 beside
         * it: unique, so nothing would break, but an order numbered below
         * thirty-eight thousand orders that came before it.
         *
         * WHAT IT COSTS, honestly. seedValue() reads the order_number column
         * and compares the values as integers in PHP, so this is O(orders) once
         * per order PLACED — not per request, not per page. On this store's
         * ~2,400 orders it is a single-column scan measured in low single-digit
         * milliseconds, against a checkout that is already doing considerably
         * more than that. The UPDATE behind it is skipped entirely when the
         * sequence is already ahead, which is the normal case.
         *
         * WHEN TO REVISIT. This is linear, so at a few hundred thousand orders
         * it stops being free. The fix then is a high-water column maintained
         * by whatever writes a number out of band — today only the importer —
         * rather than a scan here. Not worth the extra moving part at this
         * size, and the comment is here so the decision is visible rather than
         * rediscovered.
         */
        $this->resync();

        for ($claim = 0; $claim < self::MAX_CLAIMS; $claim++) {
            $observed = $this->sequence();

            /*
             * Claim exactly one number, and do NOTHING between the read above
             * and this write.
             *
             * That emptiness is the design. An earlier version worked out
             * whether the number was free first and claimed the result, which
             * put a query inside the window two allocators can collide in and
             * made losing the claim the normal outcome rather than the rare
             * one: four processes allocating flat out drove one of them into
             * the attempt ceiling even with backoff. Asking the question after
             * the claim instead — where the answer is nobody else's business,
             * because the number is already ours — leaves a window of one
             * statement.
             *
             * Written to be refused: nothing is held between the two
             * statements, so `next_number = <what we read>` is the only thing
             * standing between two allocators, and it is enough.
             */
            $claimed = DB::table(self::TABLE)
                ->where('id', self::ROW_ID)
                ->where('next_number', $observed)
                ->update(['next_number' => $observed + 1]);

            if ($claimed === 1) {
                // Ours exclusively: no other allocator can now reach it, so
                // the rest can be done at leisure.
                if (! $this->taken($observed)) {
                    return (string) $observed;
                }

                /*
                 * The number is spoken for, which means something wrote it
                 * without going through this sequence — an import, which
                 * writes WooCommerce numbers verbatim and deliberately. Push
                 * the sequence above everything the table holds in one move
                 * rather than stepping over an imported block one claim at a
                 * time, then go round again.
                 */
                $this->resync();

                continue;
            }

            /*
             * Lost it. Stand back for a random moment before looking again.
             *
             * Without this, contenders stay in lock-step: they re-read, reach
             * the same conclusion and re-claim within the same few
             * microseconds, so they collide again for the same reason they
             * collided the first time. Four processes allocating flat out
             * exhausted the attempt ceiling this way and two of them failed
             * outright. Randomised — a fixed sleep would keep them in step at a
             * slower tempo — and it grows with the attempt, so a brief burst
             * costs microseconds while sustained contention spreads out.
             *
             * Capped well below any request timeout: the sleep tops out at two
             * milliseconds, so even the full two hundred attempts cannot add up
             * to more than a fraction of a second.
             */
            usleep(random_int(0, min(2000, 100 * ($claim + 1))));
        }

        /*
         * Two things cause this, and the message names the likelier one first.
         *
         * A CALLER INSIDE A TRANSACTION is the one to check. In there every
         * re-read above comes back from the caller's snapshot holding the value
         * we already saw, so the UPDATE's WHERE clause can never match again
         * and the loop is guaranteed to spin out — the failure this class
         * exists to remove, reappearing because it was called from the wrong
         * place. It fails every attempt, so it fails every time, which makes it
         * easy to tell from the other one.
         *
         * GENUINE CONTENTION is the other, and with the backoff above it takes
         * a degree of load this shop does not see. It is intermittent.
         */
        throw new \RuntimeException(
            'Could not allocate an order number: lost the sequence claim '
            . self::MAX_CLAIMS . ' times in a row. Check first that allocate() '
            . 'was called BEFORE the placing transaction opened — called inside '
            . 'one, its re-reads are served from that transaction\'s snapshot, '
            . 'can never see another process\'s commit, and this fails every '
            . 'time rather than intermittently.'
        );
    }

    /**
     * The sequence's current value, creating the row if it is missing.
     *
     * The row is created by 2026_10_08_000000_create_order_number_sequence,
     * which also seeds it above everything already in `orders`. This is the
     * belt: a database restored from a partial dump, or a test that truncated
     * the table, gets a correct sequence rather than a 500 on the next order.
     */
    private function sequence(): int
    {
        $row = DB::table(self::TABLE)->where('id', self::ROW_ID)->first();

        if ($row !== null) {
            return (int) $row->next_number;
        }

        $seed = self::seedValue();

        // insertOrIgnore, not insert: two processes can arrive here together
        // and the second must not die on the primary key.
        DB::table(self::TABLE)->insertOrIgnore([
            'id' => self::ROW_ID,
            'next_number' => $seed,
        ]);

        $row = DB::table(self::TABLE)->where('id', self::ROW_ID)->first();

        return $row === null ? $seed : (int) $row->next_number;
    }

    /**
     * Push the sequence above every number the table currently holds.
     *
     * Called only when a claimed number turned out to be taken, which in this
     * application means an import: OrderImporter writes the WooCommerce number
     * verbatim, deliberately, and an import run after this sequence was created
     * lands a whole block of them above it. Stepping over two thousand imported
     * orders one claim at a time would burn the attempt ceiling and then refuse
     * to place the order, so the sequence jumps the lot in one move.
     *
     * The scan behind seedValue() is paid for HERE and nowhere else — never on
     * an ordinary allocation, which is a single indexed lookup.
     *
     * NO COMPARE-AND-SWAP, deliberately. This only ever moves the sequence
     * forward, and two processes arriving together compute the same floor from
     * the same table, so the worst case is the same write twice. The guard is
     * `where('next_number', '<', ...)`, which makes it a no-op for a process
     * whose sequence has already been carried past that floor by somebody else.
     */
    private function resync(): void
    {
        DB::table(self::TABLE)
            ->where('id', self::ROW_ID)
            ->where('next_number', '<', self::seedValue())
            ->update(['next_number' => self::seedValue()]);
    }

    /**
     * Is this number already in the table?
     *
     * `withTrashed()` is the point: a soft-deleted order still occupies its
     * number in the unique index, and the checkout's old allocator could not
     * see one — which is how a single trashed order broke every checkout in
     * the shop.
     */
    private function taken(int $candidate): bool
    {
        return Order::withTrashed()
            ->where('order_number', (string) $candidate)
            ->exists();
    }

    /**
     * Where a fresh sequence starts: above everything the table already holds.
     *
     * Shared with the migration that creates the row, so the seed rule is
     * written once.
     *
     * NOT `MAX(order_number)`. That column is a VARCHAR, so SQL's MAX() is
     * LEXICAL: with '9999' and '50002' both present it answers '9999', and a
     * sequence seeded from it would hand out numbers the table already holds.
     * The numbers are read out and compared as integers instead. Non-numeric
     * numbers (an import can carry them) are ignored rather than cast to zero.
     *
     * `10000 + MAX(id)` is kept as a second floor because that is the formula
     * every existing number on this store was minted with, so it is the one
     * thing guaranteed to clear them even on a table whose numbering is
     * otherwise inconsistent.
     */
    public static function seedValue(): int
    {
        $highest = self::FIRST_NUMBER - 1;

        Order::withTrashed()
            ->select('order_number')
            ->orderBy('id')
            ->chunk(1000, function ($rows) use (&$highest) {
                foreach ($rows as $row) {
                    $number = trim((string) $row->order_number);

                    // ctype_digit rather than is_numeric: '1e3' and ' 12 ' are
                    // numeric to PHP and are not order numbers.
                    if ($number !== '' && ctype_digit($number)) {
                        $highest = max($highest, (int) $number);
                    }
                }
            });

        $byId = 10000 + (int) Order::withTrashed()->max('id');

        return max($highest, $byId, self::FIRST_NUMBER - 1) + 1;
    }
}
