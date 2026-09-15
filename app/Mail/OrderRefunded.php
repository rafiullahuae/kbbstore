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
        $this->isPartial = $this->amountFils < (int) $order->total;
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
