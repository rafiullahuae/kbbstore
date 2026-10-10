<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Order;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * "Send order link" → Email (Lane OL): the shop sending one customer the link
 * to finish one saved order, because somebody pressed the button.
 *
 * The 30-minute reminder's own view and kit (emails.order-reminder), so it is
 * the shop's transactional look and not a campaign: it goes through
 * OrderMailer like every order email, never through the marketing sender, and
 * marketing suppression does not apply — the customer is being answered, not
 * marketed to. Only the words, the subject and the link's term differ: the
 * link is OrderPayLink::url(), with the owner's expiry.
 */
class OrderPayLink extends OrderPaymentReminder
{
    public function __construct(Order $order, string $payUrl, private string $subjectLine = '')
    {
        parent::__construct($order, 1);

        $this->payUrl = $payUrl;
    }

    public function envelope(): Envelope
    {
        $replace = ['store' => $this->brandName(), 'number' => $this->orderNumber()];
        $subject = trim($this->subjectLine) !== ''
            ? strtr($this->subjectLine, ['{order}' => $this->orderNumber(), '{shop}' => $this->brandName()])
            : __('email.paylink.subject', $replace);

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        $number = ['number' => $this->orderNumber()];

        return new Content(
            view: 'emails.order-reminder',
            text: 'emails.order-reminder-text',
            with: [
                'kitTitle' => (string) $this->envelope()->subject,
                'heading' => __('email.paylink.heading'),
                'body' => __('email.paylink.body', $number),
                'closing' => __('email.paylink.closing'),
            ],
        );
    }
}
