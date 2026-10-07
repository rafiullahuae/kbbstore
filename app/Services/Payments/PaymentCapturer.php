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
    /**
     * Statuses on which there is no longer an order to capture for.
     *
     * Deliberately the same list as PaymentConfirmer::VOID rather than a
     * reference to it: these are two different questions that happen to have
     * the same answer today ("may this order be marked paid" and "may this
     * order's money be taken"), and tying them together would mean a future
     * change to one silently changing the other. The reasoning is written out
     * where the check is made, below.
     */
    private const VOID = ['cancelled', 'refunded', 'failed'];

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

        /*
         * THE SALE IS OFF, SO THE MONEY IS NOT THE SHOP'S TO TAKE.
         *
         * The same three statuses PaymentConfirmer refuses a confirmation on,
         * and for the same reason: all three have handed the coupon use back,
         * and `cancelled` and `failed` have put the units back on the shelf,
         * where they have since been sold to somebody else.
         *
         * Without this the Capture button worked perfectly on a cancelled
         * order. On Tabby or Tamara that is the customer being charged for an
         * order the shop cancelled and restocked — the authorisation is still
         * live and capturable for weeks after the cancellation, which is
         * exactly the window in which somebody works through the order list.
         * On cash on delivery it is quieter and no better: capture writes
         * `captured_total`, and `captured_total` is the ceiling
         * PaymentRefunder::capturedFils() measures a refund against, so a
         * cancelled order that never saw a fil became refundable for its full
         * value.
         *
         * Not the same rule as `paid_at`, which is checked below: that one
         * asks whether the money was ever authorised, this one asks whether
         * there is still an order to take it for.
         */
        if (in_array((string) $order->status, self::VOID, true)) {
            return SettlementResult::failed(
                'order_not_live',
                ['provider' => $providerId, 'status' => (string) $order->status],
                sprintf('This order is %s, so there is nothing to capture.', $order->status),
            );
        }

        /*
         * THE AUTHORISATION HAS BEEN GIVEN BACK, SO THERE IS NOTHING TO TAKE.
         *
         * PaymentVoider releases the hold at the provider and records it as
         * `voided_at`. Nothing recreates it — that class says so in as many
         * words: start() would have to send the shopper through the provider's
         * checkout again, and they have gone. So an order carrying `voided_at`
         * has no authorisation, whatever else its columns say.
         *
         * This looked covered and was not. PaymentVoider only releases on
         * `cancelled` or `failed`, and the VOID list above refuses both, so the
         * two paths look mutually exclusive — and they are, until an operator
         * uses the status dropdown. OrderStatus deliberately permits a revive
         * ("an operator may correct any mistake"), and a revive re-takes the
         * units and the coupon use but CANNOT re-take an authorisation the
         * provider has already cancelled. Driven, on a released Tabby order
         * revived to `processing`: `capturable` came back TRUE and the Capture
         * button was drawn.
         *
         * Two things were wrong with that, and the second is the expensive one:
         *
         *   - status() promises exactly the conditions capture() accepts, so
         *     that "a button that is offered and then refused reads as a bug in
         *     the screen rather than as the rule it is". `voided_at` broke that
         *     promise, and the refusal the operator eventually got named the
         *     PROVIDER — an order note reading "Capture FAILED (transport_error)"
         *     on an order this shop released itself.
         *
         *   - the refusal came only from the gateway's own live status read, not
         *     from here. A gateway whose capture endpoint accepts a closed
         *     authorisation, or the next SettlesPayments implementation that
         *     does not read status first, turns that into a real capture against
         *     a released hold. The shop's own record of the release is the
         *     cheapest and most certain place to stop it, and it needs no
         *     network call.
         *
         * Checked BEFORE the claim, like every other refusal here, so a refused
         * capture never marks the order captured even momentarily.
         */
        if ($order->voided_at !== null) {
            return SettlementResult::failed(
                'authorisation_released',
                ['provider' => $providerId],
                'The authorisation on this order has been released, so there is nothing left to capture. '
                . 'The customer would have to place the order and pay again.',
            );
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

        $capturedFils = self::settledFils($result, $amountFils);

        Order::query()
            ->whereKey($order->getKey())
            ->update([
                'captured_total' => $capturedFils,
                'capture_ref' => $result->reference,
                'updated_at' => now(),
            ]);

        $this->ledger->record($order, $providerId, 'capture', $capturedFils, $result->reference, $result->summary);

        $this->ledger->note($order, sprintf(
            'Captured %s %s via %s.%s%s',
            Money::amount($capturedFils, 2),
            strtoupper((string) ($order->currency ?: 'AED')),
            $providerId,
            $result->reference !== null ? ' Capture reference ' . $result->reference . '.' : '',
            // Only ever appended when the provider named a SHORTER figure than
            // the one asked for, so the sentence an operator reads on every
            // ordinary capture is byte-identical to the one this shop has
            // always written.
            $capturedFils < $amountFils ? sprintf(
                ' PARTIAL: %s reports %s taken against an order of %s, so only the captured amount is refundable.',
                $providerId,
                Money::amount($capturedFils, 2),
                Money::amount($amountFils, 2),
            ) : '',
        ), $by);

        $order->refresh();

        return $result;
    }

    /**
     * How much money to record as taken: the provider's figure when it gave
     * one, the amount we asked for when it did not.
     * ═══════════════════════════════════════════════════════════════════════
     *
     * ── THE DEFECT THIS EXISTS FOR ─────────────────────────────────────────
     *
     * `captured_total` was written as `$amountFils` on EVERY success. That is
     * right for the successes this shop causes — it asks for the whole order
     * total and the provider either takes it or refuses — and wrong for the
     * successes it merely finds. Three of the four gateways answer
     * `already_captured` on a captured state the provider reached without us,
     * and on all three that state can be a PARTIAL capture:
     *
     *   Tamara   `partially_captured`, which TamaraGateway::capture()
     *            deliberately does not top up ("silently topping it up would be
     *            the wrong guess to make with money") — and which this method
     *            then recorded at full value anyway.
     *   Tabby    CLOSED with a short `captures[]`.
     *   Stripe   a `succeeded` intent whose `amount_received` is below its
     *            `amount`.
     *
     * `captured_total` is the ceiling PaymentRefunder::capturedFils() measures
     * a refund against. An order captured at 120.00 out of 300.00 recorded
     * 300.00 and accepted a 300.00 refund: 180.00 of the shop's own money paid
     * to a buyer who never paid it.
     *
     * ── THE RULE, AND WHY IT CANNOT MAKE ANYTHING WORSE ────────────────────
     *
     * A figure is used only when the gateway names one AND it is a positive
     * integer AND it is no larger than the amount requested. So the value
     * written here is always `<= $amountFils`, which is exactly what was
     * written before this method existed: cash on delivery (no provider, no
     * figure, null) and every ordinary full capture write the identical fil
     * they have always written, and the only orders that move are the ones
     * that were over-stating the shop's exposure.
     *
     * The three clamps are each load-bearing:
     *
     *   null        the provider named nothing on this path. Not zero, not
     *               "all of it" — see SettlementResult::$capturedFils.
     *   <= 0        a zero would put `captured_total` at 0 with `captured_at`
     *               set, and PaymentRefunder::ceilingFrom() falls THROUGH its
     *               capture branch on a zero (`$captured && $capturedTotal > 0`)
     *               into the confirmed-payments branch — which sums the
     *               AUTHORISATION and hands back the very inflated ceiling this
     *               method is here to remove. A provider that reports a capture
     *               and no money is a response we do not understand, and the
     *               safe reading of one of those is the amount we asked for.
     *   > requested a provider cannot capture more than it authorised, so this
     *               is a response shape we have misread rather than money the
     *               shop is holding. Capping it keeps a parsing mistake in this
     *               lane from RAISING a refund ceiling, which is the one
     *               direction this change must never be able to move.
     *
     * Integer fils throughout. Nothing here divides, multiplies or casts a
     * float — the gateways convert, through RemoteGateway::toFils().
     */
    private static function settledFils(SettlementResult $result, int $amountFils): int
    {
        $reported = $result->capturedFils;

        if ($reported === null || $reported <= 0) {
            return $amountFils;
        }

        return min($reported, $amountFils);
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
            // the authorisation that `paid_at` records. A cancelled, failed or
            // refunded order is capturable on neither basis — capture() refuses
            // it, and a button that is offered and then refused reads as a bug
            // in the screen rather than as the rule it is.
            //
            // `voided_at` is in this list for that exact reason, and it is the
            // one that had gone missing: a released authorisation that an
            // operator revived out of `cancelled` offered the button and could
            // only ever fail at the provider. See the matching refusal in
            // capture(), which this mirrors condition for condition.
            'capturable' => $supported && ! $captured && (int) $order->total > 0
                && $order->voided_at === null
                && ! in_array((string) $order->status, self::VOID, true)
                && ($windowDays === null || $authorisedAt !== null),
            /*
             * A card that was only AUTHORISED (Stripe's "Authorise only,
             * capture later", Lane SR). Asked of the gateway, which reads the
             * ledger row the payment was applied with, so an order keeps the
             * answer it was placed under. False for every other gateway.
             */
            'awaiting_capture' => $supported && method_exists($gateway, 'awaitingCapture') && $gateway->awaitingCapture($order),
            'window' => $supported ? $gateway->captureWindow() : null,
            'window_days' => $windowDays,
            'days_held' => $daysHeld,
            'days_left' => ($windowDays !== null && $daysHeld !== null)
                ? max(0, $windowDays - $daysHeld)
                : null,
            // "N days left to capture", in red. A released authorisation has no
            // window left to run out, so urging the operator to beat one is the
            // same lie `capturable` was telling one line up.
            'expiring' => $windowDays !== null && $daysHeld !== null && ! $captured
                && $order->voided_at === null
                && ($windowDays - $daysHeld) <= 3,
        ];
    }
}
