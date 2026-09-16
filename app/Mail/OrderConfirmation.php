<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * "We have your order" — the receipt, to the customer, the moment it is placed.
 *
 * The one email this store could least afford not to send. Until this class
 * existed a customer paid and heard nothing at all; the order-received page was
 * the only confirmation, and it is gated to the browser that placed the order, so
 * closing the tab lost it.
 *
 * The subject carries the order number and nothing else. No token, no link, no
 * total — subjects are quoted in notification previews, in shared screenshots and
 * in every mail server's logs along the way.
 */
class OrderConfirmation extends OrderMail
{
    use BrandedSubject;

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your ' . $this->brandName() . ' order ' . $this->orderNumber(),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.order-confirmation',
            text: 'emails.order-confirmation-text',
        );
    }
}
