<?php

declare(strict_types=1);

namespace App\Services\Payments\Gateways;

use App\Models\Order;
use App\Services\PayShipRules;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\PaymentStart;
use App\Services\Payments\SettlementResult;
use App\Services\Payments\SettlesPayments;
use App\Services\SettingsService;

/**
 * Cash on delivery.
 *
 * No API, no credentials, no webhook. What it does have is a rule that was
 * already enforced in three places before this class existed — the checkout
 * page's gateway list, Store\CheckoutController::place(), and
 * Api\CheckoutController::session() — all of them calling PayShipRules.
 *
 * This class does not re-implement that window. It calls the same
 * PayShipRules, so there is still exactly one definition of when COD is
 * allowed and the three existing call sites keep working unchanged. Adding a
 * second copy of the rule here is how the two would drift apart, and the
 * module's own notes already say what happens when one value gets two
 * controls.
 *
 * `paid_at` is deliberately NOT set on a COD order. No money has moved; the
 * courier collects it. Setting it would make the order look settled in every
 * report that reads that column.
 */
class CashOnDelivery implements PaymentGateway, SettlesPayments
{
    public function __construct(
        private PayShipRules $rules,
        private SettingsService $settings,
    ) {}

    public function id(): string
    {
        return 'cod';
    }

    public function title(): string
    {
        return 'Cash on delivery';
    }

    /** Nothing to configure, so it is always ready. */
    public function configured(): bool
    {
        return true;
    }

    public function availableFor(int $totalFils, ?string $country = null): bool
    {
        return $this->rules->codAllowed($totalFils);
    }

    /** Null when the window excludes this basket; the sentence to show when it does. */
    public function unavailableReason(int $totalFils): ?string
    {
        return $this->rules->codHiddenReason($totalFils);
    }

    public function description(int $totalFils): ?string
    {
        $fee = $this->feeFils($totalFils);

        return $fee > 0
            ? 'Pay in cash to the courier. A small ' . \App\Support\Money::format($fee) . ' handling fee applies.'
            : 'Pay in cash to the courier.';
    }

    public function feeFils(int $totalFils): int
    {
        return max(0, (int) $this->settings->get('cod_fee', 0));
    }

    /**
     * Nothing to redirect to. The order is real the moment it is placed, and
     * moves to `processing` so it reaches the warehouse like any other.
     */
    public function start(Order $order): PaymentStart
    {
        if ($order->status === 'pending') {
            $order->forceFill(['status' => 'processing'])->save();
        }

        return PaymentStart::placed();
    }

    public function configSchema(): array
    {
        return [];
    }

    /* ----------------------------------------------------------- settlement */

    /**
     * There is no window, because there is no authorisation.
     *
     * The other three gateways hold money that expires if it is not taken.
     * Nothing is held here — the cash is in the customer's hand until the
     * courier takes it — so there is nothing to race and nothing to lose by
     * waiting. Returning null from captureWindowDays() is also what tells
     * PaymentCapturer not to insist on `paid_at` first: a COD order is never
     * confirmed, deliberately (see the class note above), so a capturer that
     * required confirmation would make COD permanently uncapturable.
     */
    public function captureWindow(): string
    {
        return 'No window — the courier collects the cash, and capture is a status change here rather than a call to anyone.';
    }

    public function captureWindowDays(): ?int
    {
        return null;
    }

    /**
     * Delivery happened and the money was collected.
     *
     * No HTTP, no credentials, nothing to be down. The whole of capture for
     * cash on delivery is recording that the cash arrived; PaymentCapturer
     * writes `captured_at`, `captured_total` and the order note, and this
     * method's only job is to agree that it may.
     */
    public function capture(Order $order, int $amountFils): SettlementResult
    {
        return SettlementResult::ok(
            'captured',
            'cod:' . $order->order_number,
            ['provider' => 'cod', 'method' => 'cash_on_delivery'],
            'Marked as collected on delivery.',
        );
    }

    /**
     * Cash goes back as cash, or as a bank transfer somebody makes by hand.
     *
     * Returning ok() here is not pretending the money moved. It records the
     * refund in the ledger — which is the thing the merchant needs when they
     * make the transfer and again when they reconcile — and says plainly in
     * the message and the order note that there was no API call, so nobody
     * reads the green tick as "the customer has been paid".
     */
    public function refund(
        Order $order,
        int $amountFils,
        ?string $reason,
        ?string $captureRef,
        ?string $idempotencyKey = null,
    ): SettlementResult
    {
        return SettlementResult::ok(
            'recorded_only',
            null,
            ['provider' => 'cod', 'method' => 'cash_on_delivery'],
            'Recorded. Cash on delivery has nothing to call — return the money by hand and this is the ledger entry for it.',
        );
    }
}
