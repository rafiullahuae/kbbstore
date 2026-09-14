<?php

declare(strict_types=1);

namespace App\Services\Payments\Gateways;

use App\Models\Order;
use App\Services\PayShipRules;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\PaymentStart;
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
class CashOnDelivery implements PaymentGateway
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
}
