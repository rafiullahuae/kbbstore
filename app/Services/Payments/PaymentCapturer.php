<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\Order;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * The one place an order's money is actually taken.
 *
 * WHY THIS EXISTS AT ALL
 *
 * PaymentConfirmer marks an order paid. For Stripe that is the same event as
 * being paid; for Tabby and Tamara it is not. Both AUTHORISE at checkout — the
 * buyer is committed, the funds are held, nothing has moved — and both
 * auto-void an authorisation that is never captured. Until this class existed
 * the store shipped goods against authorisations that quietly expired, and the
 * merchant was never paid. `paid_at` said otherwise, which is worse than
 * saying nothing.
 *
 * IDEMPOTENCY, AND WHY IT IS A CLAIM RATHER THAN A CHECK
 *
 * PaymentConfirmer guards with one conditional UPDATE against a null
 * timestamp: the guard and the write are a single statement, so two racing
 * webhooks cannot both pass it. The same trick is used here, with one
 * difference that matters. Confirmation already knows the money moved by the
 * time it writes, so it can write last. Capture has to make a network call, so
 * it writes FIRST — `captured_at` is claimed before the provider is called and
 * released if the call fails. That makes the timestamp a mutex, not just a
 * record:
 *
 *   - a double-clicked button claims once, and the second click is told the
 *     order is already captured WITHOUT a second call to the provider;
 *   - a genuinely failed call releases the claim, so the merchant can retry;
 *   - a provider that reports "already captured" is a success, not an error,
 *     so a retry after a dropped connection settles instead of alarming.
 *
 * MONEY
 *
 * Integer fils, taken from the order's own `total` column — computed
 * server-side when the order was placed and not near a browser since. Nothing
 * that reaches this class can influence the amount.
 */
class PaymentCapturer
{
    public function __construct(
        private GatewayRegistry $registry,
        private PaymentLedger $ledger,
    ) {}

    /**
     * Capture an order.
     *
     * @param  string|null  $by  the admin's name, for the order note.
     */
    public function capture(Order $order, ?string $by = null): SettlementResult
    {
        $providerId = (string) ($order->payment_method ?? '');
        $gateway = $this->registry->find($providerId !== '' ? $providerId : null);

        if (! $gateway instanceof SettlesPayments) {
            return SettlementResult::failed(
                'unsupported_gateway',
                ['provider' => $providerId],
                'This order was not paid through a gateway that can be captured.',
            );
        }

        if ($order->trashed()) {
            return SettlementResult::failed('order_trashed', [], 'This order is in the trash.');
        }

        $amountFils = (int) $order->total;

        if ($amountFils <= 0) {
            return SettlementResult::failed('nothing_to_capture', [], 'This order has no amount to capture.');
        }

        /*
         * A gateway with a capture window is one that holds an authorisation,
         * and an authorisation is what PaymentConfirmer records as `paid_at`.
         * Capturing before that is capturing money nobody has agreed to pay.
         *
         * Cash on delivery reports no window — there is no authorisation being
         * held, nothing to expire, and no confirmation step to have happened
         * first. The courier collecting the cash IS the capture.
         */
        if ($gateway->captureWindowDays() !== null && $order->paid_at === null) {
            return SettlementResult::failed(
                'not_authorised',
                ['provider' => $providerId],
                'This order has not been authorised yet, so there is nothing to capture.',
            );
        }

        // The claim. One statement, so two callers cannot both win it.
        $claimed = DB::transaction(fn () => Order::query()
            ->whereKey($order->getKey())
            ->whereNull('captured_at')
            ->update(['captured_at' => now(), 'updated_at' => now()]));

        if ($claimed === 0) {
            $order->refresh();

            return SettlementResult::ok(
                'already_captured',
                $order->capture_ref,
                ['provider' => $providerId],
                'This order was already captured.',
            );
        }

        $result = $gateway->capture($order, $amountFils);

        if (! $result->ok) {
            // Release the claim before anything else: an order left marked
            // captured after a failed call is the exact lie this class exists
            // to stop telling.
            Order::query()
                ->whereKey($order->getKey())
                ->update(['captured_at' => null, 'updated_at' => now()]);

            $this->ledger->record($order, $providerId, 'capture_failed', $amountFils, null, $result->summary);

            $this->ledger->note($order, sprintf(
                'Capture of %s %s via %s FAILED (%s). The money has not been taken and the order is not captured.',
                Money::amount($amountFils, 2),
                strtoupper((string) ($order->currency ?: 'AED')),
                $providerId,
                $result->code,
            ), $by);

            $order->refresh();

            return $result;
        }

        Order::query()
            ->whereKey($order->getKey())
            ->update([
                'captured_total' => $amountFils,
                'capture_ref' => $result->reference,
                'updated_at' => now(),
            ]);

        $this->ledger->record($order, $providerId, 'capture', $amountFils, $result->reference, $result->summary);

        $this->ledger->note($order, sprintf(
            'Captured %s %s via %s.%s',
            Money::amount($amountFils, 2),
            strtoupper((string) ($order->currency ?: 'AED')),
            $providerId,
            $result->reference !== null ? ' Capture reference ' . $result->reference . '.' : '',
        ), $by);

        $order->refresh();

        return $result;
    }

    /**
     * What the admin screen needs to decide whether to offer the button, and
     * how loudly. Never calls the network — this is rendered on every order
     * page and a provider being slow must not be.
     *
     * @return array<string, mixed>
     */
    public function status(Order $order): array
    {
        $providerId = (string) ($order->payment_method ?? '');
        $gateway = $this->registry->find($providerId !== '' ? $providerId : null);
        $supported = $gateway instanceof SettlesPayments;

        $windowDays = $supported ? $gateway->captureWindowDays() : null;
        $authorisedAt = $order->paid_at;

        $daysHeld = ($authorisedAt !== null)
            ? (int) floor(abs(now()->diffInDays($authorisedAt)))
            : null;

        $captured = $order->captured_at !== null;

        return [
            'supported' => $supported,
            'captured' => $captured,
            'captured_at' => optional($order->captured_at)->toAtomString(),
            'captured_total_aed' => Money::toAed((int) ($order->captured_total ?? 0)),
            'capture_ref' => $order->capture_ref,
            // COD is capturable the moment it is placed; everything else needs
            // the authorisation that `paid_at` records.
            'capturable' => $supported && ! $captured && (int) $order->total > 0
                && ($windowDays === null || $authorisedAt !== null),
            'window' => $supported ? $gateway->captureWindow() : null,
            'window_days' => $windowDays,
            'days_held' => $daysHeld,
            'days_left' => ($windowDays !== null && $daysHeld !== null)
                ? max(0, $windowDays - $daysHeld)
                : null,
            'expiring' => $windowDays !== null && $daysHeld !== null && ! $captured
                && ($windowDays - $daysHeld) <= 3,
        ];
    }
}
