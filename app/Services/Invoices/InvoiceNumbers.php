<?php

declare(strict_types=1);

namespace App\Services\Invoices;

use App\Models\Order;
use App\Services\SettingsService;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Who gets which invoice number, and the two guarantees that has to carry.
 *
 * `orders.invoice_number` is an integer column with a UNIQUE index on it
 * (Phase 0 created it that way; `2026_09_22_000000_add_import_external_ids`
 * re-adds the index on a server whose repair migration dropped it). An invoice
 * number is a financial reference: the same one may never appear on two orders,
 * and one order may never be given a second one after the first has been
 * printed or emailed.
 *
 * ── THE SEQUENCE, AND WHY IT IS NOT max(id) ─────────────────────────────────
 *
 * docs/IMPORT-READINESS.md D6: historical WooCommerce invoice numbers may be
 * imported, and the WebToffee sequence is expected to CONTINUE from
 * `MAX(wf_invoice_number)`. So the next number is
 *
 *     max( the configured starting number, MAX(invoice_number) + 1 )
 *
 * read from the table every time, `withTrashed()` included — a trashed order
 * still holds its number in the unique index, and skipping it would hand the
 * same number out twice. Nothing is derived from `orders.id`: that is exactly
 * the assumption that broke `nextOrderNumber()` in CheckoutController, where
 * `10000 + max(id)` collided with imported numbers and 500'd the checkout.
 *
 * The starting number is a setting (`invoice_number_start`, default 1000) so a
 * store cutting over mid-year can begin where its old system stopped without a
 * code change. It is a FLOOR, never an override: once anything higher exists
 * the table wins, so lowering the setting later cannot re-issue a used number.
 *
 * ── THE RACE, AND WHO ARBITRATES IT ─────────────────────────────────────────
 *
 * Two admins opening the same order in two tabs at the same moment both read
 * the same MAX and both compute the same candidate. A read-then-write with a
 * PHP-side check between them cannot fix that, whatever it checks: the gap is
 * between the SELECT and the UPDATE, and nothing in this process can see into
 * the other one's gap.
 *
 * So the database arbitrates, twice, and this class only reacts:
 *
 *   NEVER TWO ORDERS WITH ONE NUMBER — the UNIQUE index. The claim below is
 *   written to be REFUSED: the loser of the race gets SQLSTATE 23000 and
 *   simply tries the next candidate. That is the same instinct as the unique
 *   index on `refunds.idempotency_key`, which AdminOrderController's refund
 *   note calls out as the thing that actually stops a double click — a check
 *   in application code is a description of the rule, the index IS the rule.
 *
 *   NEVER TWO NUMBERS ON ONE ORDER — `WHERE invoice_number IS NULL` in the
 *   same UPDATE, and the affected-row count read back. A claim that changes no
 *   rows means somebody else invoiced this order between our read and our
 *   write; the answer is to re-read THEIR number and return it, never to mint
 *   a second one. allocate() is therefore idempotent: call it ten times and
 *   the order has one invoice number and the sequence has advanced once.
 *
 * Both are single statements. There is no transaction to hold open, no row
 * lock, and nothing that behaves differently on SQLite and MySQL — which
 * matters, because the suite runs on the first and the store on the second.
 */
class InvoiceNumbers
{
    /** Setting holding the first number to issue on a store with no invoices. */
    public const START_KEY = 'invoice_number_start';

    /** What that setting is when nobody has set it. */
    public const DEFAULT_START = 1000;

    /**
     * How many taken numbers in a row we walk past before giving up.
     *
     * The same bound, for the same reason, as nextOrderNumber(): a thousand
     * consecutive collisions is not contention, it is a broken table, and a
     * retry loop should surface that rather than spin on it.
     */
    private const MAX_ATTEMPTS = 1000;

    /**
     * `orders.invoice_number` is `integer` on a repaired server (see the
     * divergence table in docs/IMPORT-READINESS.md §3), i.e. signed 32-bit.
     * Refusing above that here gives a named error instead of MySQL's
     * out-of-range abort — or SQLite's silent acceptance of a value the live
     * site could never store.
     */
    private const CEILING = 2147483647;

    public function __construct(private SettingsService $settings) {}

    /**
     * This order's invoice number, allocating one if it has none.
     *
     * Safe to call from anywhere, any number of times: an order that already
     * carries a number is answered from the column without touching the
     * sequence.
     */
    public function allocate(Order $order): int
    {
        $existing = $this->existing($order);

        if ($existing !== null) {
            return $existing;
        }

        $candidate = $this->nextCandidate();

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++, $candidate++) {
            if ($candidate > self::CEILING) {
                throw new \RuntimeException('Invoice numbers have run past what the column can hold.');
            }

            if ($this->claim($order, $candidate)) {
                return $candidate;
            }

            // The claim failed. Either the number went to somebody else (try
            // the next one) or this order went to somebody else (take theirs).
            $existing = $this->existing($order);

            if ($existing !== null) {
                return $existing;
            }
        }

        throw new \RuntimeException(
            'Could not find a free invoice number after ' . self::MAX_ATTEMPTS . ' attempts.'
        );
    }

    /** The number already on this order, read from the database, or null. */
    public function existing(Order $order): ?int
    {
        $value = Order::withTrashed()
            ->whereKey($order->getKey())
            ->value('invoice_number');

        if ($value === null || (int) $value <= 0) {
            return null;
        }

        // Keep the in-memory model honest, so a caller that goes on to render
        // from $order does not print "not yet invoiced" against a row that is.
        $order->invoice_number = (int) $value;

        return (int) $value;
    }

    /**
     * The number this process would try first.
     *
     * Public because the race is worth testing, and the only way to test it
     * honestly is to let two callers compute the same candidate — which is
     * precisely what two web requests do — and then watch the second claim get
     * refused. A test that mocked this would prove nothing about the index.
     */
    public function nextCandidate(): int
    {
        $highest = (int) Order::withTrashed()->max('invoice_number');

        return max($this->start(), $highest + 1);
    }

    /**
     * Try to put $number on $order. True only if this call is the one that did.
     *
     * Deliberately a single conditional UPDATE. The `whereNull` is what makes
     * an order un-re-invoiceable and the UNIQUE index is what makes a number
     * un-re-issuable; both answers come from the database, so two callers
     * racing get one winner no matter how their reads interleaved.
     *
     * `invoiced_at` is stamped in the same statement. A row with a number and
     * no date, or a date and no number, would be a state nothing else in the
     * app knows how to read, and a second UPDATE is a second chance to be
     * interrupted.
     */
    public function claim(Order $order, int $number): bool
    {
        try {
            $affected = Order::withTrashed()
                ->whereKey($order->getKey())
                ->whereNull('invoice_number')
                ->update([
                    'invoice_number' => $number,
                    'invoiced_at' => now(),
                ]);
        } catch (UniqueConstraintViolationException) {
            // Somebody else holds this number. Expected under contention.
            return false;
        } catch (QueryException $e) {
            // Older drivers, and SQLite through some builds, do not always get
            // classified into the typed exception above. A 23xxx SQLSTATE is a
            // constraint violation whatever wrapped it; anything else is a real
            // fault and must not be swallowed as "try the next number".
            if (! str_starts_with((string) $e->getCode(), '23')) {
                throw $e;
            }

            return false;
        }

        if ($affected !== 1) {
            return false;
        }

        $order->invoice_number = $number;
        $order->invoiced_at = now();

        return true;
    }

    /** The configured floor for a store that has never issued an invoice. */
    public function start(): int
    {
        $configured = (int) $this->settings->get(self::START_KEY, self::DEFAULT_START);

        return $configured > 0 ? $configured : self::DEFAULT_START;
    }

    /**
     * How the number reads on the document.
     *
     * Zero-padded to five digits so a run of invoices lines up in a filing
     * cabinet, and left alone once the sequence outgrows that. No prefix: the
     * column is an integer and a prefix stored nowhere is a prefix that
     * disagrees with the database the first time somebody searches for one.
     */
    public static function format(int $number): string
    {
        return str_pad((string) $number, 5, '0', STR_PAD_LEFT);
    }
}
