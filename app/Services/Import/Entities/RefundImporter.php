<?php

declare(strict_types=1);

namespace App\Services\Import\Entities;

use App\Models\Order;
use App\Models\Refund;
use App\Services\Import\DateParser;
use App\Services\Import\ImportContext;
use App\Services\Import\Row;
use App\Services\Import\RowRejected;
use App\Services\Payments\PaymentRefunder;
use App\Support\Money;

/**
 * Money the shop gave back, matched on `wc_refund_id`.
 *
 * ============================================================================
 * THE DEFECT THIS CLOSES
 * ============================================================================
 *
 * docs/FV-IMPORT-AT-VOLUME.md §11 names `refunds` first among the tables a
 * clean full-volume import leaves at zero, and states the cost in one line:
 *
 *     "Money the shop gave back. `orders.status = refunded` imports, and the
 *      amount refunded does not. A partial refund imports as an order at its
 *      full total."
 *
 * Two things follow from an empty `refunds` table, and the second is the
 * expensive one:
 *
 *   1. EVERY TOTAL IS OVERSTATED. `refunded_total_aed` on the order screen,
 *      the dashboard's revenue, Analytics, and a customer's lifetime value in
 *      Store -> Customers are all built as gross minus
 *      `SUM(refunds.amount) WHERE refunds.status IN (pending, succeeded)`.
 *      With no rows, the subtrahend is zero and every one of those figures
 *      reads as though nothing was ever handed back.
 *
 *   2. THE SHOP OFFERS TO REFUND THE SAME MONEY TWICE. PaymentRefunder's
 *      ceiling is `capturedFils() - refundedFils()`, and `refundedFils()` is
 *      that same sum. An imported AED 199.00 Stripe order that WooCommerce
 *      already refunded AED 99.50 of showed AED 199.00 as still refundable —
 *      so the button on Store -> Orders would happily send a second AED 99.50
 *      (or the whole 199.00) through Stripe, against a charge that no longer
 *      holds it.
 *
 * Both are fixed by the rows existing. Nothing downstream had to be taught
 * anything: the readers were already there and already correct, waiting on a
 * table nothing filled. That is the whole shape of this lane, and it is why
 * the schema was read before a column was proposed — see the doc.
 *
 * ============================================================================
 * WHAT THE SCHEMA ALREADY HAD, AND WHAT WAS NOT ADDED
 * ============================================================================
 *
 * `refunds.wc_refund_id` — nullable, UNIQUE — has been in
 * 0001_01_01_000000_create_kbb_schema.php since Phase 0, and
 * 2026_09_22_000000_add_import_external_ids names it in its header as one of
 * the external ids that were already present. `status`, `provider`,
 * `provider_ref`, `failure_code` and `idempotency_key` arrived with
 * 2026_09_17_000000_add_capture_and_refund_tracking. So this entity adds NO
 * migration and NO column. It fills columns that have been waiting.
 *
 * ============================================================================
 * AN IMPORTED REFUND vs. ONE THIS SHOP PERFORMED — THE ONE DESIGN DECISION
 * ============================================================================
 *
 * They share a table, and they must: a refund is a refund, every reader wants
 * both, and two tables would mean two subtrahends and a reconciliation that
 * can never balance. What separates them is which system moved the money.
 *
 *   wc_refund_id IS NOT NULL   WooCommerce moved it, years ago, at a provider
 *                              account that may no longer exist. There is
 *                              nothing left to do about it here.
 *   wc_refund_id IS NULL       PaymentRefunder moved it, through a gateway
 *                              this build can call, and holds a provider
 *                              reference for it.
 *
 * Three consequences, each of which would be a real defect the other way:
 *
 *   PROVIDER IS `woocommerce`, NOT THE ORDER'S GATEWAY. It is tempting to
 *   write `stripe` on a refund of a Stripe order, and it would be wrong in a
 *   way that costs the owner an afternoon every month.
 *   Reconciler::stepLocalRefunds() selects `refunds WHERE provider = ?` for
 *   the gateway being reconciled and reports every row whose `provider_ref`
 *   Stripe did not list as REFUND_NOT_CONFIRMED — "This shop has recorded a
 *   refund that stripe did not list for this period. The customer may not have
 *   been paid back." We do not have Stripe's `re_...` id; refunds.csv carries
 *   the WooCommerce refund POST id, which is not a Stripe reference and never
 *   will be. Filing these under `stripe` would therefore raise a permanent
 *   false finding on every imported refund — precisely the reconciliation that
 *   never balances. Under `woocommerce`, which is not a gateway in
 *   GatewayRegistry, neither reconciliation phase ever selects them.
 *
 *   PROVIDER_REF IS NULL, for the same reason: it is the provider's own id for
 *   the refund and we do not have one. The WooCommerce refund id goes in
 *   `wc_refund_id`, which is what that column is for, and is what a re-run
 *   matches on.
 *
 *   IDEMPOTENCY_KEY IS NULL. That column is a unique lock on a call to a
 *   payment provider. An import makes no such call, and inventing a key would
 *   put a row in the index that a genuine later refund could collide with.
 *   NULLs do not collide on either engine, which is the property the capture/
 *   refund migration chose it for.
 *
 * STATUS IS `succeeded`, which is the load-bearing half. Only `pending` and
 * `succeeded` are in PaymentRefunder::COUNTED, and COUNTED is what every
 * reader above sums. A row imported as anything else would sit in the table
 * looking imported and change not one figure on any screen.
 *
 * AND THE CUSTOMER IS NOT EMAILED. App\Services\Mail\OrderMailObserver mails
 * on `Refund::created` when the row arrives already `succeeded` — which is
 * exactly the shape of every row this class writes. Left alone, importing a
 * five-year history would tell several hundred real people that their money
 * was on its way back, today. The guard is on the ROW and not on this process
 * (see OrderMailObserver::mailRefund): a refund carrying a `wc_refund_id` was
 * announced by WooCommerce at the time, so no path — this importer, a delta
 * re-run, a hand-inserted row — can mail about one.
 *
 * ============================================================================
 * THE MONEY
 * ============================================================================
 *
 * `amount` is Woo's positive figure; `total` is the SAME money written
 * negative, because a refund is a child order whose lines are negative.
 * docs/GE-WP-EXPORTER.md emits both deliberately — "two conventions, and an
 * importer guessing which it has applies a refund twice or backwards".
 *
 * So this class does not guess. When both are present they are required to
 * agree in magnitude and the row is REFUSED when they do not, naming both
 * figures. An ambiguous money value is the one thing this pipeline has never
 * resolved by picking: App\Services\Import\Money refuses "99,50" for the same
 * reason and with the same argument.
 *
 * Integer fils throughout, parsed by App\Services\Import\Money out of the
 * decimal string. No float touches this path.
 */
final class RefundImporter extends EntityImporter
{
    /**
     * What goes in `refunds.provider` for a refund WooCommerce made.
     *
     * Deliberately not a GatewayRegistry id — see the class header. A constant
     * so the importer, the mail guard's tests and the doc cannot drift onto
     * three spellings of one word.
     */
    public const PROVIDER = 'woocommerce';

    /** @var array<int, int> local order id => orders.total in fils */
    private array $orderTotals = [];

    public function name(): string
    {
        return 'refunds';
    }

    public function conventionalFile(): string
    {
        return 'refunds.csv';
    }

    /**
     * Refunds the export supplied.
     *
     * On `wc_refund_id`, not on the whole table: this shop makes its own
     * refunds through PaymentRefunder and a demo install seeds orders, so
     * COUNT(*) would answer a different question and the difference would read
     * as an import that lost rows.
     */
    public function countImported(): ?int
    {
        return Refund::query()->whereNotNull('wc_refund_id')->count();
    }

    public function import(Row $row, ImportContext $context): void
    {
        $wcRefundId = $row->requireId('refund_id', 'refund_id', 'id', 'wc_refund_id');
        $wcOrderId = $row->requireId('order_id', 'order_id', 'parent_order_id', 'wc_order_id');

        $orderId = $context->localId('orders', $wcOrderId);

        if ($orderId === null) {
            throw RowRejected::because(
                'order '.$wcOrderId.' is not in this database — import the orders first, or check whether that '
                .'order was rejected. `refunds.order_id` is NOT NULL and a refund with no order is money '
                .'nothing can be netted out of.'
            );
        }

        $report = $context->report->for($this->name());

        $amount = $this->amountFils($row);

        $createdAt = $row->date('date_created', $context->timezone(), 'date_created', 'date', 'post_date', 'created_at');

        if ($createdAt === null) {
            throw RowRejected::because(
                'date_created is empty. A refund with no date cannot be placed in the order history it belongs '
                .'to, and Store -> Payments reconciles refunds inside a window built from this column.'
            );
        }

        $this->checkDeclaredTimezone($row, $context);

        $refund = Refund::query()->where('wc_refund_id', $wcRefundId)->first() ?? new Refund;

        $outcome = $context->apply($refund, [
            'wc_refund_id' => $wcRefundId,
            'order_id' => $orderId,
            'amount' => $amount,
            'reason' => $row->text('reason', 'refund_reason'),
            'refunded_by' => $this->refundedBy($row, $context),
            /*
             * The four columns the capture/refund migration added, written
             * explicitly rather than left to their defaults — see the class
             * header for why each is what it is. `status` in particular is the
             * difference between a row that exists and a row that counts.
             */
            'status' => 'succeeded',
            'provider' => self::PROVIDER,
            'provider_ref' => null,
            'idempotency_key' => null,
            'failure_code' => null,
            /*
             * Explicitly, both of them. An `updated_at` of now() would make
             * every row read as touched today — and, worse, would make the
             * second pass report `updated` on rows nothing changed, which is
             * the only evidence this import produces that it is idempotent.
             */
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        $context->record($this->name(), $outcome);

        $this->reportCurrency($row, $context, $orderId);
        $this->reportRefundedItems($row, $context);
        $this->reportAgainstOrderTotal($row, $context, $orderId, $wcOrderId);
    }

    /* --------------------------------------------------------------- money */

    /**
     * The refund, in fils, positive.
     *
     * @throws RowRejected
     */
    private function amountFils(Row $row): int
    {
        $amount = $row->money('amount', 'amount', 'refund_amount');
        $total = $row->money('total', 'total', 'refund_total', 'line_total');

        if ($amount === null && $total === null) {
            throw RowRejected::because(
                'no refund amount — neither `amount` nor `total` carries a figure, and a refund of nothing is '
                .'not a refund. This is the one column the whole row exists to carry.'
            );
        }

        /*
         * BOTH PRESENT AND DISAGREEING IS A REFUSAL, NOT A CHOICE.
         *
         * WooCommerce holds `_refund_amount` positive and the refund order's
         * `total` negative, and they are the same money. If they are not, the
         * export has been touched or the two were written by different
         * plugins, and an importer that picks one has a 50% chance of
         * refunding the wrong figure for ever. Both are named so the owner can
         * see which is which.
         */
        if ($amount !== null && $total !== null && abs($total) !== abs($amount)) {
            throw RowRejected::because(sprintf(
                'amount (%s) and total (%s) are the same money written two ways and they disagree. '
                .'Guessing which is right applies the refund at the wrong figure, silently, so the row is '
                .'refused instead.',
                Money::amount(abs($amount), 2),
                Money::amount(abs($total), 2),
            ));
        }

        $fils = abs($amount ?? $total ?? 0);

        if ($fils === 0) {
            throw RowRejected::because(
                'the refund amount is zero. WooCommerce writes a refund row when money moves; a zero one is '
                .'either a cancelled draft or a broken export, and importing it would put a row on the order '
                .'screen saying nothing went back.'
            );
        }

        return $fils;
    }

    /* -------------------------------------------------------------- report */

    /**
     * Woo names the refunder by WordPress user id; this column is a name.
     *
     * `refunds.refunded_by` is printed on the order screen beside the money.
     * A bare "1" there is not a person, so the id is spelled out as what it
     * is. Reported, because it is a value the database now holds that the
     * export did not write.
     */
    private function refundedBy(Row $row, ImportContext $context): ?string
    {
        $raw = $row->text('refunded_by', 'refunded_by_user', 'author');

        if ($raw === null) {
            return null;
        }

        if (preg_match('/^\d+$/', $raw) !== 1) {
            return $raw;
        }

        $context->report->for($this->name())->adjusted(
            'the refunder named by WordPress user id — this column is printed beside the money on the order '
            .'screen, where a bare number is not a person',
            $row->line,
            $this->identify($row),
            'refunded_by',
            $raw,
            'WordPress user '.$raw,
        );

        return 'WordPress user '.$raw;
    }

    /**
     * `refunds` has no currency column, so a refund in a currency that is not
     * the order's is a figure that will be subtracted from a total it does not
     * belong to.
     *
     * Not a rejection — the money moved and the record belongs in the history
     * — and not a conversion either, for the reason OrderImporter gives about
     * foreign-currency orders: there is no rate for the day it happened and
     * inventing one is worse than naming the problem.
     */
    private function reportCurrency(Row $row, ImportContext $context, int $orderId): void
    {
        $currency = $row->text('currency', 'order_currency');

        if ($currency === null) {
            return;
        }

        $orderCurrency = (string) (Order::withTrashed()->whereKey($orderId)->value('currency') ?? '');

        if ($orderCurrency === '' || strcasecmp($currency, $orderCurrency) === 0) {
            return;
        }

        $context->report->for($this->name())->adjusted(
            'a refund in a currency that is not the order\'s — `refunds` has no currency column, so this '
            .'figure is netted out of a total denominated in something else',
            $row->line,
            $this->identify($row),
            'currency',
            $currency,
            'stored as a bare amount against an order in '.$orderCurrency,
        );
    }

    /**
     * WHERE THE REFUND LINES WENT.
     *
     * docs/GE-WP-EXPORTER.md excludes refund lines from `order_items.csv` —
     * WooCommerce keeps them in the same table with `order_id` pointing at the
     * refund — and carries them here instead, as `item_id:qty:total` pipes.
     *
     * THIS SHOP HAS NOWHERE TO PUT THEM. A refund is one amount against an
     * order; there is no `refund_items` table and adding one would be a second
     * place for a line's money to live, which is how an order's lines and its
     * total start disagreeing. The money is not lost — it is the `amount`
     * column, in full — what is lost is WHICH lines it covered.
     *
     * So it is named in the discard channel, with its value, which is the
     * channel Phase 13 built for exactly this: the owner approves a fact
     * rather than a column heading.
     */
    private function reportRefundedItems(Row $row, ImportContext $context): void
    {
        $items = $row->text('refunded_items', 'refund_items', 'line_items');

        if ($items === null) {
            return;
        }

        $context->report->for($this->name())->discarded(
            'which lines of the order a refund covered — this shop records a refund as one amount against the '
            .'order and has no table for its lines, so the money is kept in full and the breakdown is not',
            $row->line,
            $this->identify($row),
            'refunded_items',
            $items,
        );
    }

    /**
     * A refund bigger than the order was ever charged.
     *
     * NOT CLAMPED AND NOT REFUSED. WooCommerce's record is the evidence that
     * money moved; `orders.total` is a column an operator can edit — the
     * header on PaymentRefunder::capturedFils() sets out what trusting it as a
     * ceiling has already cost this shop in both directions. So the row is
     * imported exactly as the export wrote it and the disagreement is named.
     *
     * It is worth naming because it is the one shape that makes an imported
     * refund look like a defect on the order screen: `refundable_aed` is
     * `max(0, captured - refunded)`, so an over-total refund reads as zero
     * refundable rather than as a negative, and the owner would have nothing
     * to go on.
     */
    private function reportAgainstOrderTotal(Row $row, ImportContext $context, int $orderId, int $wcOrderId): void
    {
        $total = $this->orderTotal($orderId);

        if ($total <= 0) {
            return;
        }

        $refunded = (int) Refund::query()
            ->where('order_id', $orderId)
            ->whereIn('status', PaymentRefunder::COUNTED)
            ->sum('amount');

        if ($refunded <= $total) {
            return;
        }

        $context->report->for($this->name())->note(
            'a refund that takes an order\'s refunded total past what the order itself says it was charged '
            .'(order '.$wcOrderId.': '.Money::amount($refunded, 2).' refunded against a total of '
            .Money::amount($total, 2).') — imported unchanged, because WooCommerce\'s record is the evidence '
            .'that money moved and `orders.total` is a column that can be edited after the fact'
        );
    }

    private function orderTotal(int $orderId): int
    {
        return $this->orderTotals[$orderId] ??= (int) (
            Order::withTrashed()->whereKey($orderId)->value('total') ?? 0
        );
    }

    /**
     * The same measured-offset check OrderImporter makes, for the same reason:
     * a refund read four hours out lands in the wrong day on every reconcile
     * window and every daily figure derived from it.
     */
    private function checkDeclaredTimezone(Row $row, ImportContext $context): void
    {
        $disagreement = DateParser::disagreementWithGmt(
            $row->raw('date_created', 'date', 'post_date', 'created_at'),
            $row->raw('date_created_gmt', 'post_date_gmt', 'date_gmt'),
            'date_created',
            $context->timezone(),
        );

        if ($disagreement === null) {
            return;
        }

        [$declared, $gmt] = $disagreement;

        $context->report->for($this->name())->adjusted(
            'the export\'s own GMT column disagrees with --timezone='.$context->timezone().' -- every date '
            .'in this file is being read in the wrong zone. Re-run with the site timezone the export was '
            .'really written in.',
            $row->line,
            $this->identify($row),
            'date_created',
            $declared->toDateTimeString().'Z (reading it as '.$context->timezone().')',
            $gmt->toDateTimeString().'Z (what the export\'s GMT column says)',
        );
    }

    /** `refund_id` first, so a rejection names the refund and not its order. */
    public function identify(Row $row): string
    {
        $value = $row->raw('refund_id');

        if ($value !== null && $value !== '') {
            return 'refund_id='.$value;
        }

        return parent::identify($row);
    }
}
