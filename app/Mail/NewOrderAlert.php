<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * "An order came in" — to the store, not to the shopper.
 *
 * Before this the only way to learn an order had arrived was to open the admin
 * and look. A store whose owner is not told when it makes a sale is a store that
 * ships late.
 *
 * NO ADMIN LINK, ON PURPOSE. The obvious thing to put here is a deep link to the
 * order screen, which would mean printing the `admin_path` setting into an email
 * body. CLAUDE.md lists `admin_path` among the columns that must never leave the
 * server casually, and mail is the least controlled channel this app has — it
 * passes through the host's relay, the recipient's provider, and whatever forwards
 * the store address points at. The order number is enough to find the order, and
 * it is worth nothing to anyone who intercepts it.
 *
 * It is the customer's details that make this email useful — address, phone,
 * payment method — so it carries them, and it goes only to an address the owner
 * configured under Store → Mail.
 */
class NewOrderAlert extends OrderMail
{
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'New order ' . $this->orderNumber() . ' — ' . $this->order['totalPlain'],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.new-order-alert',
            text: 'emails.new-order-alert-text',
        );
    }
}
