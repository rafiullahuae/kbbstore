<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\Order;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * The one place an authorisation this shop is holding is given back.
 *
 * ── WHY THIS EXISTS ─────────────────────────────────────────────────────────
 *
 * PaymentCapturer takes the money. PaymentRefunder returns money that was
 * taken. Neither of them reaches the state a cancelled BNPL order sits in:
 * authorised, never captured, and still live at the provider for up to 180
 * days. VoidsAuthorisation's header sets out what that costs the customer —
 * a payment plan on their Tamara account for an order the shop has cancelled,
 * restocked and refunded the coupon for. This class is the shop's side of
 * releasing it, and the record that it was released.
 *
 * ── IDEMPOTENCY, THE SAME WAY PaymentCapturer DOES IT ───────────────────────
 *
 * `voided_at` is claimed with one conditional UPDATE against NULL before the
 * provider is called, and released if the call fails. That makes the column a
 * mutex and not just a record, which is what the capture path already proved
 * is necessary here: a void can arrive from the order screen, from a bulk
 * status change on the orders list, and from an operator who did not see the
 * first one work, all within a few seconds. Two of those must not become two
 * cancel calls.
 *
 * The release-on-failure half matters more here than it does for capture. An
 * order left marked voided after a failed call is an authorisation the shop
 * believes it has released and has not — the customer keeps the plan, and the
 * button that would fix it is disabled by the very column that is wrong.
 *
 * ── WHAT IS RECORDED, AND WHY EVEN THE FAILURES ─────────────────────────────
 *
 * Every path lands in PaymentLedger before it returns, successes and failures
 * alike, for the reason that class's own header gives: a release that errored
 * and left no trace is a customer still being billed with nothing on the order
 * to say anybody tried. The order note is written in the operator's name when
 * there is one, so the trail reads without SQL.
 *
 * ── WHAT THIS CLASS DOES NOT DO ─────────────────────────────────────────────
 *
 * It does not change the order's status. Cancelling an order and releasing its
 * authorisation are two acts, they can fail independently, and an order that is
 * cancelled with a live authorisation must stay cancelled — putting it back
 * would restock units that have already gone and re-spend a coupon use that has
 * already been handed back. App\Services\Orders\OrderStatus owns the status and
 * this class does not reach into it.
 */
class PaymentVoider
{
    /**
     * Statuses on which releasing the authorisation is the RIGHT thing to do.
     *
     * The inverse of the list PaymentCapturer refuses on, and written out here
     * rather than borrowed from it: these are two different questions with
     * opposite answers, and expressing one as `! in_array(…, PaymentCapturer's
     * list)` would tie a future change to one into a silent change to the
     * other. `refunded` is deliberately absent — an order that reached
     * `refunded` had money taken and given back, so its authorisation was
     * captured and there is nothing left to release.
     */
    private const RELEASABLE = ['cancelled', 'failed'];

    public function __construct(
        private GatewayRegistry $registry,
        private PaymentLedger $ledger,
    ) {}

    /**
     * Release the authorisation on this order.
     *
     * @param  string|null  $by  the operator's name, for the order note.
     */
    public function void(Order $order, ?string $by = null): SettlementResult
    {
        $providerId = (string) ($order->payment_method ?? '');
        $gateway = $this->registry->find($providerId !== '' ? $providerId : null);

        if (! $gateway instanceof VoidsAuthorisation) {
            return SettlementResult::failed(
                'unsupported_gateway',
                ['provider' => $providerId],
                'This order was not paid through a gateway that holds a releasable authorisation.',
            );
        }

        /*
         * NO TRASHED-ORDER REFUSAL, AND THAT IS A DECISION, NOT AN OMISSION.
         *
         * Two lanes built this service in the same round -- one for Tamara, one
         * for Tabby -- and this is the one place their designs disagreed. The
         * Tamara side refused a trashed order outright; the Tabby side resolved
         * with withTrashed() on purpose and argued that a trashed order is the
         * order MOST likely to be sitting on a hold nobody meant to leave open.
         *
         * The Tabby reading wins, because refusing here has a cost and allowing
         * it has none. Releasing money is safe on an order in any state -- it is
         * TAKING it that is not, which is why PaymentCapturer does not do this --
         * so the refusal's only effect was to leave the customer's credit
         * committed with no button anywhere that could free it, until the
         * provider's own 180-day timer.
         *
         * Nothing is given up. The trashed check never guarded anything the
         * RELEASABLE check below does not already guard harder: a trashed order
         * that is still `processing` is refused as `order_still_live` exactly as
         * an untrashed one is, and a trashed `cancelled` order is precisely the
         * case this class was written for. The Tamara lane had already noticed
         * its own branch was unreachable through the controller.
         */
        /*
         * MONEY THAT HAS MOVED IS A REFUND, AND THIS IS CHECKED HERE AS WELL AS
         * IN THE GATEWAY.
         *
         * The gateway checks it too, and against Tamara's own live status,
         * which is the stronger test — it catches a capture made in Tamara's
         * dashboard that this shop never saw. This one is cheaper and catches
         * the ordinary case with no network call at all, which is what keeps a
         * double-click free.
         */
        if ($order->captured_at !== null || trim((string) ($order->capture_ref ?? '')) !== '') {
            return SettlementResult::failed(
                'already_captured',
                ['provider' => $providerId],
                'This order has been captured, so there is no authorisation to release. Refund it instead.',
            );
        }

        /*
         * IS THE SALE ACTUALLY OFF?
         *
         * A void on a live order is the most expensive mistake available here:
         * it releases the authorisation on an order the shop still intends to
         * ship, so the goods go out and the payment plan the customer signed up
         * to no longer exists. Nothing recreates it — start() would have to send
         * the shopper through Tamara's checkout again, and they have gone.
         *
         * So this refuses anything that is not already over. `draft` and
         * `pending` are refused too, although neither is a live sale: an
         * authorisation against a `pending` order is a shopper who is still in
         * Tamara's hosted flow, and cancelling it underneath them turns a
         * working checkout into a decline.
         */
        if (! in_array((string) $order->status, self::RELEASABLE, true)) {
            return SettlementResult::failed(
                'order_still_live',
                ['provider' => $providerId, 'status' => (string) $order->status],
                sprintf(
                    'This order is %s. Cancel it first — an authorisation is only released once the sale is off.',
                    $order->status,
                ),
            );
        }

        if ($order->paid_at === null) {
            /*
             * Nothing was ever authorised, so there is nothing being held. Not
             * a failure: the caller asked for the authorisation to be released
             * and there is none, which is the state they wanted. `paid_at` is
             * what PaymentConfirmer writes when Tamara says the order is
             * authorised, and it is the only record this shop has that the
             * provider ever committed.
             */
            return SettlementResult::ok(
                'nothing_to_void',
                null,
                ['provider' => $providerId],
                'This order was never authorised, so nothing is being held.',
            );
        }

        $amountFils = (int) $order->total;

        // The claim. One statement, so two callers cannot both win it.
        $claimed = DB::transaction(fn () => Order::query()
            ->whereKey($order->getKey())
            ->whereNull('voided_at')
            ->update(['voided_at' => now(), 'updated_at' => now()]));

        if ($claimed === 0) {
            $order->refresh();

            return SettlementResult::ok(
                'already_voided',
                $order->void_ref,
                ['provider' => $providerId],
                'The authorisation on this order has already been released.',
            );
        }

        $result = $gateway->void($order, $amountFils);

        if (! $result->ok) {
            // Release the claim FIRST, before the ledger write and before the
            // note. An order left marked voided after a failed call is an
            // authorisation the shop thinks it gave back and did not, and the
            // column that is wrong is the one that disables the retry.
            Order::query()
                ->whereKey($order->getKey())
                ->update(['voided_at' => null, 'updated_at' => now()]);

            $this->ledger->record($order, $providerId, 'void_failed', $amountFils, null, $result->summary);

            $this->ledger->note($order, sprintf(
                'Release of the %s authorisation for %s %s FAILED (%s). '
                . 'The authorisation is STILL LIVE and the customer may still be billed for it.',
                $providerId,
                Money::amount($amountFils, 2),
                strtoupper((string) ($order->currency ?: 'AED')),
                $result->code,
            ), $by);

            $order->refresh();

            return $result;
        }

        Order::query()
            ->whereKey($order->getKey())
            ->update(['void_ref' => $result->reference, 'updated_at' => now()]);

        $this->ledger->record($order, $providerId, 'void', $amountFils, $result->reference, $result->summary);

        $this->ledger->note($order, sprintf(
            'Released the %s authorisation for %s %s.%s',
            $providerId,
            Money::amount($amountFils, 2),
            strtoupper((string) ($order->currency ?: 'AED')),
            $result->reference !== null ? ' Cancellation reference ' . $result->reference . '.' : '',
        ), $by);

        $order->refresh();

        return $result;
    }

    /**
     * What the order screen needs to decide whether to offer the button.
     *
     * NEVER CALLS THE NETWORK — same rule as PaymentCapturer::status(), and for
     * the same reason: this is rendered with the order and a provider being slow
     * must not be. Every field below is read off columns this order already
     * carries.
     *
     * @return array<string, mixed>
     */
    public function status(Order $order): array
    {
        $providerId = (string) ($order->payment_method ?? '');
        $gateway = $this->registry->find($providerId !== '' ? $providerId : null);
        $supported = $gateway instanceof VoidsAuthorisation;

        $voided = $order->voided_at !== null;
        $captured = $order->captured_at !== null || trim((string) ($order->capture_ref ?? '')) !== '';

        return [
            'supported' => $supported,
            'voided' => $voided,
            'voided_at' => optional($order->voided_at)->toAtomString(),
            'void_ref' => $order->void_ref,
            /*
             * Exactly the conditions void() accepts, so the screen never offers
             * a button that is then refused. A refused button reads as a bug in
             * the screen rather than as the rule it is — the same point
             * PaymentCapturer::status() makes about `capturable`.
             */
            'voidable' => $supported
                && ! $voided
                && ! $captured
                && $order->paid_at !== null
                && in_array((string) $order->status, self::RELEASABLE, true),
            /*
             * The one sentence an operator needs when the button is NOT offered
             * on an order that plainly holds an authorisation. Cheap to compute
             * and it stops the commonest support question about this screen.
             */
            'why_not' => $this->whyNot($order, $supported, $voided, $captured),
        ];
    }

    private function whyNot(Order $order, bool $supported, bool $voided, bool $captured): ?string
    {
        if (! $supported) {
            return 'This payment method does not hold a releasable authorisation.';
        }

        if ($voided) {
            return 'Already released.';
        }

        if ($captured) {
            return 'The money has been captured. Refund it instead.';
        }

        if ($order->paid_at === null) {
            return 'Nothing was ever authorised on this order.';
        }

        if (! in_array((string) $order->status, self::RELEASABLE, true)) {
            return sprintf('This order is %s. Cancel it first.', $order->status);
        }

        return null;
    }
}
