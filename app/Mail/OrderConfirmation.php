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

    /**
     * "Track your order", signed for this order (App\Support\OrderLinks) so it
     * opens on any device — Lane RL. Falls back to the old order-received
     * address if the link cannot be signed.
     */
    public string $trackSignedUrl = '';

    /**
     * Whether the payment is in. The approved preview's lead says "Your payment
     * is in" — true of a card, Tabby or Tamara order (receipted when the
     * payment confirms), not of cash on delivery, which keeps the old lead.
     */
    public bool $paid = false;

    public function __construct(\App\Models\Order $order)
    {
        parent::__construct($order);

        $this->paid = $order->paid_at !== null;

        try {
            $this->trackSignedUrl = \App\Support\OrderLinks::trackUrl($order);
        } catch (\Throwable) {
            $this->trackSignedUrl = (string) ($this->order['trackUrl'] ?? '');
        }
    }

    public function envelope(): Envelope
    {
        // Keyed (Lane RL) so it carries the owner's 🎉 and can be translated
        // and edited like every other subject; it was English in code.
        return new Envelope(
            subject: __('email.confirmation.subject', ['store' => $this->brandName(), 'number' => $this->orderNumber()]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.order-confirmation',
            text: 'emails.order-confirmation-text',
            // The <title> of the look-A document: the subject (Lane EM).
            with: ['kitTitle' => (string) $this->envelope()->subject],
        );
    }
}
