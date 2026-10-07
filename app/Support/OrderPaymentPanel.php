<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Order;
use App\Services\Payments\GatewayRegistry;

/**
 * Store -> Orders -> (an order) -> the payment panel under the Items. (Lane PU)
 *
 * ── WHAT THE OWNER ASKED FOR ────────────────────────────────────────────────
 *
 *   "I want a big green box where clearly written 'Paid with <payment method
 *    name>' along with captured/transaction id etc and further information."
 *
 * The order screen said how an order was paid in one grey sentence under the
 * heading, and the only box on the page about money was "Not captured ...
 * Capture AED X" -- drawn on every cash-on-delivery order, including the ones
 * that had long been delivered and paid for. This class decides what the panel
 * says; the console only draws it.
 *
 * ── THE STATES, AND THE INTEGRATOR'S RULE FOR CASH ON DELIVERY ──────────────
 *
 *   paid           green. Processing / Shipped / Completed on anything that is
 *                  not COD: "if the order goes to processing, means that payment
 *                  is made" -- the owner's own rule, and the one WooCommerce
 *                  applied before the import.
 *   cod_due        amber. COD on Processing or Shipped with no cash recorded:
 *                  "Cash on delivery -- AED X to collect". COD is not money
 *                  received until the courier hands it over.
 *   cod_collected  green. COD on Completed, or with a cash-received record
 *                  (`captured_at`, the same column CashOnDeliveryPosition and
 *                  reconciliation already read as "collected").
 *   unpaid         grey (pending, on hold, draft, anything unrecognised) or red
 *                  (failed, cancelled): "Not paid".
 *   refunded       red: the order was paid and the money went back.
 *
 * `paid_at` is deliberately NOT what makes a COD order green. WooCommerce
 * stamps date_paid on a COD order the moment it reaches Processing, so every
 * imported COD order carries one -- for cash that is still in the customer's
 * hand. Reading it as "paid" would turn the amber panel green on exactly the
 * orders it exists to flag.
 *
 * ── CAPTURE: WHERE IT SURVIVES, AND WHY ONLY THERE ──────────────────────────
 *
 * The owner asked for Capture to go. Checked before removing it: Tabby and
 * Tamara genuinely AUTHORISE at checkout and pay nothing until captured, and
 * both void an authorisation that is never captured (TabbyGateway::capture,
 * TamaraGateway, TamaraCaptureSweep). Stripe Checkout captures at authorisation
 * (StripeGateway::capture: "Stripe already did"), and COD's "capture" is only a
 * bookkeeping record that the cash arrived. So Capture is offered ONLY on a
 * Tabby or Tamara order this shop's own checkout authorised (`paid_at` set,
 * not captured, not released, not cancelled) -- PaymentCapturer::status()'s
 * `capturable`, narrowed to the two gateways where it means money. The endpoint
 * and PaymentCapturer are untouched; only the box is gone everywhere else.
 *
 * Imported WooCommerce orders carry the plugins' own method ids
 * (`tabby_installments`, `tamara-gateway`, ...), which the registry does not
 * know, so no imported order is ever offered a capture this shop cannot make.
 *
 * ── NO GATEWAY DASHBOARD LINK ───────────────────────────────────────────────
 *
 * The shop holds no per-payment dashboard URL for any gateway: the only two
 * provider URLs in the console are Stripe's API-keys pages, and a test pins them
 * as the only two (a wrong link on a payment screen is the shape of a phishing
 * page). So the panel prints the reference with a copy button and invents no
 * link.
 *
 * Every string here is plain text. The console escapes all of it.
 */
final class OrderPaymentPanel
{
    /** Statuses that mean "the customer has paid" -- the owner's rule. */
    public const PAID_STATUSES = ['processing', 'shipped', 'completed'];

    /**
     * The statuses a move OUT of, into a paid one, asks for a payment record.
     * Store -> Orders -> (an order) -> Status, the "Mark as paid" modal.
     */
    public const UNPAID_STATUSES = ['pending', 'onhold', 'draft', 'failed'];

    /** Gateways that hold an authorisation and pay nothing until captured. */
    public const AUTHORISE_THEN_CAPTURE = ['tabby', 'tamara'];

    private const RED_UNPAID = ['failed', 'cancelled'];

    /**
     * @param  array<string, mixed>  $settlement  PaymentCapturer::status()
     * @return array<string, mixed>
     */
    public static function for(Order $order, int $refundedFils, array $settlement): array
    {
        $status = (string) $order->status;
        $method = trim((string) $order->payment_method);
        $title = $order->paymentLabel();
        $recorded = $title !== 'Not recorded';
        $isCod = $method === 'cod';
        $total = (int) $order->total;

        $transaction = trim((string) $order->transaction_id);
        $captureRef = trim((string) $order->capture_ref);

        $panel = [
            'state' => 'unpaid',
            'tone' => 'grey',
            'headline' => 'Not paid',
            'detail' => '',
            'method_id' => $method !== '' ? $method : null,
            'method_title' => $recorded ? $title : null,
            'gateway' => self::gatewayName($method),
            'transaction_id' => $transaction !== '' ? $transaction : null,
            'capture_ref' => ($captureRef !== '' && $captureRef !== $transaction && ! str_starts_with($captureRef, 'cod:') && ! str_starts_with($captureRef, 'manual:')) ? $captureRef : null,
            'date_paid' => null,
            'date_label' => null,
            'amount_aed' => Money::toAed($total),
            'to_collect_aed' => null,
            'refunds' => [
                'total_aed' => Money::toAed($refundedFils),
                'any' => $refundedFils > 0,
                'net_aed' => Money::toAed(max(0, $total - $refundedFils)),
            ],
            'capture' => self::capture($order, $method, $settlement),
            'can_record_cash' => false,
        ];

        if ($status === 'refunded') {
            return array_merge($panel, [
                'state' => 'refunded',
                'tone' => 'red',
                'headline' => 'Refunded',
                'detail' => $recorded ? 'Was paid with ' . $title . '.' : '',
                'date_paid' => StoreTime::iso($order->paid_at),
                'date_label' => self::label($order->paid_at),
            ]);
        }

        if (! in_array($status, self::PAID_STATUSES, true)) {
            return array_merge($panel, [
                'tone' => in_array($status, self::RED_UNPAID, true) ? 'red' : 'grey',
                'detail' => self::unpaidDetail($status, $recorded ? $title : null),
            ]);
        }

        if ($isCod) {
            $collectedAt = $order->captured_at ?? ($status === 'completed' ? $order->completed_at : null);
            $collected = $order->captured_at !== null || $status === 'completed';

            if (! $collected) {
                return array_merge($panel, [
                    'state' => 'cod_due',
                    'tone' => 'amber',
                    'headline' => 'Cash on delivery — AED ' . self::aed($total) . ' to collect',
                    'detail' => 'Not money received yet: the courier collects it on delivery. It turns green when the order is Completed or the cash is recorded.',
                    'to_collect_aed' => Money::toAed($total),
                    'can_record_cash' => true,
                ]);
            }

            $date = StoreTime::formatDate($collectedAt);

            return array_merge($panel, [
                'state' => 'cod_collected',
                'tone' => 'green',
                'headline' => $date !== '' ? 'Paid — cash collected on ' . $date : 'Paid — cash collected',
                'detail' => 'Cash on delivery.',
                'date_paid' => StoreTime::iso($collectedAt),
                'date_label' => self::label($collectedAt),
            ]);
        }

        /*
         * AUTHORISED, NOT CAPTURED (Lane SR). A Stripe card taken under
         * "Authorise only, capture later": the bank has approved it and the
         * money is HELD, not taken. Amber, said in words, with the Capture
         * button below -- the owner must not read a green "Paid" on an order
         * whose money Stripe will hand back in a week unless he acts.
         */
        if (! empty($settlement['awaiting_capture']) && $order->captured_at === null) {
            return array_merge($panel, [
                'state' => 'authorised',
                'tone' => 'amber',
                'headline' => 'Card authorised — not captured yet',
                'detail' => 'The money is held on the customer\'s card but not taken. Press Capture to take it; '
                    . 'Stripe releases an authorisation that is not captured within about 7 days.',
                'date_paid' => StoreTime::iso($order->paid_at),
                'date_label' => self::label($order->paid_at),
            ]);
        }

        return array_merge($panel, [
            'state' => 'paid',
            'tone' => 'green',
            'headline' => $recorded ? 'Paid with ' . $title : 'Paid',
            'detail' => $recorded ? '' : 'The payment method was not recorded on this order.',
            'date_paid' => StoreTime::iso($order->paid_at),
            'date_label' => self::label($order->paid_at),
        ]);
    }

    /**
     * The one Capture button left on the order screen. See the class note.
     *
     * @param  array<string, mixed>  $settlement
     * @return array<string, mixed>
     */
    private static function capture(Order $order, string $method, array $settlement): array
    {
        $offered = (in_array($method, self::AUTHORISE_THEN_CAPTURE, true) || ! empty($settlement['awaiting_capture']))
            && ! empty($settlement['capturable'])
            && $order->paid_at !== null
            && $order->captured_at === null;

        return [
            'offered' => $offered,
            'window' => $offered ? ($settlement['window'] ?? null) : null,
            'days_left' => $offered ? ($settlement['days_left'] ?? null) : null,
            'expiring' => $offered && ! empty($settlement['expiring']),
        ];
    }

    /** The gateway's own name where this build knows it, else the stored id. */
    private static function gatewayName(string $method): ?string
    {
        if ($method === '') {
            return null;
        }

        $gateway = app(GatewayRegistry::class)->find($method);

        return $gateway !== null ? $gateway->title() : $method;
    }

    private static function unpaidDetail(string $status, ?string $title): string
    {
        $why = match ($status) {
            'pending' => 'Awaiting payment.',
            'onhold' => 'On hold — the payment has not been confirmed.',
            'draft' => 'Draft order — no payment has been taken.',
            'failed' => 'The payment failed.',
            'cancelled' => 'Cancelled — no payment was taken.',
            default => 'No payment has been recorded.',
        };

        return $title !== null ? $why . ' Chosen at checkout: ' . $title . '.' : $why;
    }

    private static function label(\DateTimeInterface|string|null $at): ?string
    {
        $d = StoreTime::display($at);

        return $d?->format('j F Y \a\t H:i');
    }

    /** 249 or 249.50 -- whole dirhams print without the fils. */
    private static function aed(int $fils): string
    {
        return $fils % 100 === 0
            ? number_format(intdiv($fils, 100))
            : number_format($fils / 100, 2);
    }
}
