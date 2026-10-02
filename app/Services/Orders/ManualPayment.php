<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\PaymentProvider;
use App\Support\Money;
use App\Support\StoreTime;
use Carbon\CarbonImmutable;

/**
 * "Mark as paid" -- a payment the owner took outside this shop's checkout and
 * is now writing down. (Lane PU)
 *
 * Store -> Orders -> (an order) -> Status: moving an order FROM pending, on
 * hold, draft or failed TO processing, shipped or completed opens a small modal
 * asking for the method, the gateway's reference and the date. His words:
 *
 *   "Upon changing the status to processing / completed or shipped, I can enter
 *    the manual payment id, which I will get from my payment gateways manually
 *    -- only in case I change the status from pending / draft or new order."
 *
 * ONE WRITE. The status move and the payment columns go through
 * OrderStatus::moveTo() together, in its `$also`, which that funnel carries for
 * exactly this: "for the callers whose status change carries one fact (paid_at,
 * a transaction reference). Present so those stay one atomic write." A revive
 * the shop cannot pay for (a failed order whose units have since sold) is
 * refused by moveTo() with nothing written -- no payment recorded against an
 * order that did not move.
 *
 * CASH ON DELIVERY is written as collected: `captured_at`, `captured_total` and
 * a `cod:` capture reference, which is what PaymentCapturer has always written
 * for COD and what CashOnDeliveryPosition and the reconciliation report read as
 * "the cash arrived". The order screen's panel turns green on it.
 *
 * WHAT IT REFUSES: a trashed order, a cancelled or refunded one (there is no
 * sale to have been paid for), and a method this shop does not have -- the
 * select stores one of its own options, never free text.
 */
final class ManualPayment
{
    public const REFERENCE_MAX = 191;

    public function __construct(private OrderStatus $status) {}

    /**
     * The methods the modal offers: this shop's configured gateways, plus the
     * method the order already carries (an imported `ziina` or
     * `tabby_installments` must stay selectable for its own order).
     *
     * @return list<array{id: string, title: string}>
     */
    public function methodsFor(Order $order): array
    {
        $rows = PaymentProvider::query()
            ->orderBy('position')->orderBy('id')
            ->get(['id', 'title', 'position'])
            ->map(fn (PaymentProvider $p) => ['id' => (string) $p->id, 'title' => (string) ($p->title ?: $p->id)])
            ->all();

        if ($rows === []) {
            $rows[] = ['id' => 'cod', 'title' => 'Cash on delivery'];
        }

        $own = trim((string) $order->payment_method);

        if ($own !== '' && ! in_array($own, array_column($rows, 'id'), true)) {
            array_unshift($rows, ['id' => $own, 'title' => $order->paymentLabel()]);
        }

        return array_values($rows);
    }

    /**
     * Record it, moving the status in the same write when $to is given.
     *
     * @return array{ok: bool, message: string, from?: ?string}
     *
     * @throws OrderReviveRefused  passed through untouched: nothing was written.
     */
    public function record(
        Order $order,
        string $methodId,
        ?string $reference,
        CarbonImmutable $paidAt,
        string $by,
        ?string $to = null,
    ): array {
        if ($order->trashed()) {
            return ['ok' => false, 'message' => 'This order is in the trash.'];
        }

        if (in_array((string) $order->status, ['cancelled', 'refunded'], true)) {
            return ['ok' => false, 'message' => sprintf('This order is %s, so there is no payment to record.', $order->status)];
        }

        $methods = collect($this->methodsFor($order))->keyBy('id');

        if (! $methods->has($methodId)) {
            return ['ok' => false, 'message' => 'Choose one of this shop\'s payment methods.'];
        }

        $title = (string) $methods[$methodId]['title'];
        $reference = $reference !== null ? trim($reference) : '';

        $also = [
            'payment_method' => $methodId,
            'payment_method_title' => $title,
            'paid_at' => $paidAt,
        ];

        if ($reference !== '') {
            $also['transaction_id'] = $reference;
        }

        /*
         * WRITTEN AS TAKEN, for cash and for the two gateways that otherwise
         * wait to be captured.
         *
         * Cash: `captured_at` is the column every COD reader treats as "the
         * cash arrived" (CashOnDeliveryPosition, reconciliation, the panel).
         *
         * Tabby / Tamara: the owner is recording money he has already seen in
         * the gateway's dashboard. Left uncaptured, the order would offer a
         * Capture button -- and TamaraCaptureSweep would try -- against a
         * reference he typed by hand, which is a call to the provider for an
         * order this shop never authorised. Measured before this branch: a
         * failed Tamara order marked paid at 390px came back with the Capture
         * button drawn.
         */
        $capturesLater = in_array($methodId, \App\Support\OrderPaymentPanel::AUTHORISE_THEN_CAPTURE, true);

        if (($methodId === 'cod' || $capturesLater) && $order->captured_at === null) {
            $also['captured_at'] = $paidAt;
            $also['captured_total'] = (int) $order->total;
            $also['capture_ref'] = ($methodId === 'cod' ? 'cod:' : 'manual:') . $order->order_number;
        }

        $note = sprintf(
            'Marked paid manually by %s: %s, AED %s, paid %s%s.',
            $by,
            $title,
            number_format(Money::toAed((int) $order->total), 2),
            StoreTime::display($paidAt)?->format('j F Y H:i') ?? '',
            $reference !== '' ? '; reference ' . $reference : '; no reference given',
        );

        $from = $this->status->moveTo(
            $order,
            $to ?? (string) $order->status,
            by: $by,
            reason: $to !== null && $to !== (string) $order->status ? 'Marked paid on the order screen.' : null,
            also: $also,
        );

        if ($from === null) {
            return ['ok' => false, 'message' => 'That order could not be updated.'];
        }

        $order->notes()->create(['content' => $note, 'author' => $by, 'is_customer_note' => false]);

        return ['ok' => true, 'message' => 'Payment recorded', 'from' => $from];
    }
}
