<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\Order;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Release an authorisation nobody captured.
 *
 * PaymentCapturer's mirror image, and deliberately built to the same shape so
 * the two can be read against each other: resolve the gateway, refuse the
 * states where the act makes no sense, claim the guard BEFORE calling out, call
 * the gateway once, and write the answer to the ledger whichever way it went.
 *
 * WHY IT IS A SERVICE AND NOT THREE LINES IN A CONTROLLER
 *
 * Because the refusals are the substance and every one of them is about money:
 *
 *   - **A CAPTURED order is never voided.** `captured_at` is checked here, from
 *     our own column, before the gateway is asked — and the gateway checks the
 *     provider's live status as well, so the two have to agree. A void posted
 *     against captured money is at best rejected and at worst a reversal that
 *     leaves no refund row, no `refunded_total` and no ledger entry, which is a
 *     refund the shop cannot see it made.
 *   - **A REFUNDED order is never voided.** Same money, already given back by a
 *     path that recorded it.
 *   - **The claim is taken before the call.** `voided_at` is set by a
 *     conditional UPDATE against a null column, exactly as PaymentCapturer
 *     claims `captured_at`, so a double-clicked button releases once. It is put
 *     BACK if the gateway refuses, because an order recorded as released against
 *     an authorisation that is still open is the one lie this class exists to
 *     avoid — the merchant would stop looking for it.
 *   - **A failure is written down.** A release that silently failed is a
 *     customer's credit limit held against a cancelled order, and nobody
 *     looking.
 *
 * WHAT IT DOES NOT DO: it does not change the order's status. Cancelling an
 * order and releasing its authorisation are two acts, the first goes through
 * App\Services\Orders\OrderStatus (which is the only writer of that column) and
 * this one is about the money. An order can be cancelled with the hold still
 * open — that is exactly the state this exists to clean up — and releasing a
 * hold on an order that is still live must not cancel it behind the operator.
 */
class PaymentVoider
{
    /**
     * Statuses on which there is nothing left to release.
     *
     * `refunded` is here because the money moved and came back; releasing a
     * hold is not a thing that can be done to it. `cancelled` and `failed` are
     * NOT here, and that is the whole point: a cancelled order with a live
     * authorisation is the case this class was written for.
     */
    private const SETTLED = ['refunded'];

    public function __construct(
        private GatewayRegistry $registry,
        private PaymentLedger $ledger,
    ) {}

    public function void(Order $order, ?string $by = null): SettlementResult
    {
        $providerId = (string) ($order->payment_method ?? '');
        $gateway = $this->registry->find($providerId);

        if (! $gateway instanceof VoidsAuthorisations) {
            return SettlementResult::failed(
                'unsupported',
                ['provider' => $providerId],
                'This order was not paid through a gateway whose authorisation can be released.',
            );
        }

        if ($order->captured_at !== null) {
            return SettlementResult::failed(
                'already_captured',
                ['provider' => $providerId],
                'This order has been captured, so the money has already moved. Refund it instead.',
            );
        }

        if (in_array((string) $order->status, self::SETTLED, true)) {
            return SettlementResult::failed(
                'nothing_to_void',
                ['provider' => $providerId],
                sprintf('This order is %s, so there is no authorisation left to release.', $order->status),
            );
        }

        if ($order->voided_at !== null) {
            return SettlementResult::ok(
                'already_voided',
                null,
                ['provider' => $providerId],
                'This authorisation has already been released.',
            );
        }

        /*
         * The claim, before the call. Same construction as
         * PaymentCapturer::capture(): a conditional UPDATE against a null
         * column, so two operators clicking at once produce one release and the
         * loser is told it was already done.
         */
        $claimed = 1 === (int) Order::query()
            ->whereKey($order->getKey())
            ->whereNull('voided_at')
            ->update(['voided_at' => now(), 'updated_at' => now()]);

        if (! $claimed) {
            return SettlementResult::ok(
                'already_voided',
                null,
                ['provider' => $providerId],
                'This authorisation has already been released.',
            );
        }

        $result = $gateway->voidAuthorisation($order);

        if (! $result->ok) {
            // PUT IT BACK. An order flagged released against a hold that is
            // still open is the failure mode this whole class is shaped around.
            Order::query()
                ->whereKey($order->getKey())
                ->update(['voided_at' => null, 'updated_at' => now()]);

            $this->ledger->record($order, $providerId, 'void_failed', 0, null, $result->summary);

            $this->ledger->note($order, sprintf(
                'Release of the %s authorisation FAILED (%s). The hold on %s is still open and the customer\'s '
                . 'credit is still committed. Nothing was charged and nothing was released.',
                $providerId,
                $result->code,
                Money::amount((int) $order->total, 2),
            ), $by);

            return $result;
        }

        DB::transaction(function () use ($order, $providerId, $result, $by) {
            $this->ledger->record($order, $providerId, 'void', 0, $result->reference, $result->summary);

            $this->ledger->note($order, sprintf(
                'The %s authorisation for %s was released (%s). The customer has not been charged.',
                $providerId,
                Money::amount((int) $order->total, 2),
                $result->code,
            ), $by);
        });

        $order->refresh();

        return $result;
    }

    /**
     * Can this order's authorisation be released right now?
     *
     * Reads only, and never calls a provider: it is rendered with the order page
     * and a BNPL API having a slow morning must not be why an admin cannot look
     * at an order.
     *
     * @return array<string, mixed>
     */
    public function status(Order $order): array
    {
        $providerId = (string) ($order->payment_method ?? '');
        $gateway = $this->registry->find($providerId);
        $supported = $gateway instanceof VoidsAuthorisations;

        return [
            'provider' => $providerId,
            'void_supported' => $supported,
            'voided' => $order->voided_at !== null,
            'voided_at' => optional($order->voided_at)->toAtomString(),
            'voidable' => $supported
                && $order->voided_at === null
                && $order->captured_at === null
                && ! in_array((string) $order->status, self::SETTLED, true)
                && (int) $order->total > 0,
        ];
    }
}
