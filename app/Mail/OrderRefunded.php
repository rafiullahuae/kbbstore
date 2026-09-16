<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Order;
use App\Models\Refund;
use App\Services\Mail\OrderEmailPresenter;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * "We have sent your money back."
 *
 * SENT WHEN MONEY ACTUALLY MOVES, NOT WHEN A STATUS IS TYPED. The trigger is a
 * `refunds` row reaching `succeeded`, which PaymentRefunder::settle() writes only
 * after the provider confirmed the return (or after the merchant recorded a
 * settle-by-hand, which is still money gone). A refund that fails writes `failed`
 * on the same row and mails nothing: telling a customer their money is on the way
 * when the gateway refused is worse than telling them nothing.
 *
 * It is also why `orders.status = refunded` does NOT mail. PaymentRefunder flips
 * that column with a query-builder update once the refunded total catches the
 * captured total, and a partial refund never flips it at all — so a status-driven
 * email would miss every partial refund and double up on every full one.
 *
 * The amount is the REFUND's amount in fils, not the order total. On a partial
 * refund those differ, and the email says which it is.
 */
class OrderRefunded extends OrderMail
{
    public int $amountFils;

    public string $amountHtml;

    public string $amountPlain;

    public bool $isPartial;

    public function __construct(Order $order, Refund $refund)
    {
        parent::__construct($order);

        $this->amountFils = (int) $refund->amount;
        $this->amountHtml = OrderEmailPresenter::html($this->amountFils);
        $this->amountPlain = OrderEmailPresenter::plain($this->amountFils);

        /*
         * PARTIAL MEANS "SOMETHING IS STILL UNREFUNDED", NOT "THIS REFUND IS
         * SMALLER THAN THE ORDER".
         *
         * This was:
         *
         *     $this->isPartial = $this->amountFils < (int) $order->total;
         *
         * — one refund's amount against the order total, which is the wrong
         * comparison as soon as an order is refunded in more than one go. A
         * 220-dirham order returned as two 110-dirham refunds (a goodwill
         * amount now, the balance when the parcel is back — ordinary) made BOTH
         * emails say "This is a partial refund — the rest of the order is
         * unaffected." The second one said it about an order that had just been
         * refunded in full. The customer is told in writing that they are still
         * owed nothing back on an order they are owed nothing on, which is the
         * kind of sentence that starts a chargeback.
         *
         * The right question is the one PaymentRefunder itself asks when it
         * decides whether to move the order to `refunded`: does everything
         * refunded so far reach everything that was captured? So it is asked of
         * the same object, with the same two methods, and the email and the
         * order status can no longer disagree.
         *
         * capturedFils() rather than `total` also fixes the partial-capture
         * case: an order captured for less than its total and then refunded in
         * full was described as partial, because the refund could never reach a
         * total that was never taken.
         *
         * THE FALLBACK TO `total` IS LOAD-BEARING, and it is what cash on
         * delivery needs. capturedFils() answers 0 for an order with no
         * captured_total and no paid_at — which is every COD order, because
         * CashOnDelivery::start() moves the order on without marking it paid,
         * and nothing was ever taken through a gateway to capture. Compared
         * against a ceiling of 0, ANY refund reads as "fully refunded" and a 50
         * dirham goodwill refund on a 235 dirham COD order would drop the word
         * partial from an email that is entirely about a partial refund. So a
         * ceiling of zero means "nothing was captured through a gateway", not
         * "nothing is owed", and the order's own total is the honest ceiling
         * there — the same figure the customer handed over at the door.
         */
        $refunder = app(\App\Services\Payments\PaymentRefunder::class);

        $ceiling = $refunder->capturedFils($order) ?: (int) $order->total;

        $this->isPartial = $refunder->refundedFils($order) < $ceiling;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Refund sent for your K Beauty Bliss order ' . $this->orderNumber(),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.order-refunded',
            text: 'emails.order-refunded-text',
        );
    }
}
