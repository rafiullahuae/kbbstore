<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * "Payment not received" — to the store, 30 minutes after an order that is
 * still unpaid (OrderReminders, the same rules as the customer's first
 * "Complete your order").
 *
 * The owner, 17 October 2026, after order 56251 (Tamara, never paid) arrived
 * as a plain "New order": "i only need to send pending payment email to admin
 * as well. so i can be sure that payment is failed. instead of checking the
 * pending payment on the order detailed page."
 *
 * The new-order alert's own design and contents, with a red "Payment not
 * received" header and "do not pack it" in place of "pick, pack and mark it
 * dispatched". Like NewOrderAlert: the shop's language, no admin link.
 */
class UnpaidOrderAlert extends NewOrderAlert
{
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Payment not received — order ' . $this->orderNumber() . ' — ' . $this->order['totalPlain'],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.new-order-alert',
            text: 'emails.new-order-alert-text',
            with: ['kitTitle' => (string) $this->envelope()->subject, 'kitUnpaid' => true],
        );
    }
}
