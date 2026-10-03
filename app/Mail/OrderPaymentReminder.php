<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Order;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * "Complete your order" — the 30-minute and 24-hour reminders for an order
 * nobody has paid for (Lane RL). Who gets one, and when, is
 * App\Services\Mail\OrderReminders' business; this is only what it says.
 *
 * The wording follows Lane RJ's approved previews (02-complete-order-30min,
 * 03-complete-order-24h) and is keyed under `email.reminder.*`, so the Arabic
 * translation and the owner's later wording editor have one place to edit.
 *
 * THE BUTTON is App\Support\OrderLinks::payUrl(): a signed, 7-day link to the
 * page where THIS order can be paid with any method the checkout offers, on
 * any device. Built here from the model, because the presenter array that the
 * template reads carries no id to sign.
 */
class OrderPaymentReminder extends OrderMail
{
    use BrandedSubject;

    public int $stage;

    public string $payUrl = '';

    public function __construct(Order $order, int $stage)
    {
        parent::__construct($order);

        $this->stage = $stage === 2 ? 2 : 1;

        try {
            $this->payUrl = \App\Support\OrderLinks::payUrl($order);
        } catch (\Throwable) {
            // No APP_KEY, no signature: the shop's front door rather than no email.
            $this->payUrl = \App\Support\Url::external('/');
        }
    }

    public function envelope(): Envelope
    {
        $replace = ['store' => $this->brandName(), 'number' => $this->orderNumber()];

        return new Envelope(subject: $this->stage === 2
            ? __('email.reminder.second_subject', $replace)
            : __('email.reminder.first_subject', $replace));
    }

    public function content(): Content
    {
        $number = ['number' => $this->orderNumber()];

        return new Content(
            view: 'emails.order-reminder',
            text: 'emails.order-reminder-text',
            with: ['kitTitle' => (string) $this->envelope()->subject] + ($this->stage === 2
                ? [
                    'heading' => __('email.reminder.second_heading'),
                    'body' => __('email.reminder.second_body', $number),
                    'closing' => __('email.reminder.second_closing'),
                ]
                : [
                    'heading' => __('email.reminder.first_heading'),
                    'body' => __('email.reminder.first_body', $number),
                    'closing' => __('email.reminder.first_closing'),
                ]),
        );
    }
}
