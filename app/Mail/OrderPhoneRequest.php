<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Order;
use App\Support\OrderLinks;
use App\Support\UaePhone;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * "Ask for UAE number" (2.60.562): the order screen asking the customer for a
 * UAE mobile number, because the courier calls UAE numbers only.
 *
 * The owner's pick, Option H, with his additions: "add a small line above
 * heading 'Your UAE's phone number is missing' also in arabic ... also include
 * our support section and footer". His wording, not a warning: he turned down
 * the first draft as reading like "a warning or ... legal stuff".
 *
 * Look A (emails.kit.order), so it carries the order chip, the help box, the
 * sign-off and the footer every order email does, in the ORDER's language
 * (OrderMailer::send runs it in OrderLocale) -- which is also why the link is
 * built here, inside that locale, and not by the caller: /ar/ only gets its
 * segment while the Arabic render is running.
 *
 * Two bodies, so the email never says something untrue: a number that is not
 * a UAE mobile is "from another country"; a UAE-looking number that turned out
 * to be wrong is one "we could not reach".
 */
class OrderPhoneRequest extends OrderMail
{
    public string $phoneUrl;

    public string $currentPhone;

    public bool $foreign;

    public string $firstName;

    public function __construct(Order $order)
    {
        parent::__construct($order);

        $this->phoneUrl = OrderLinks::phoneUrl($order);
        $this->currentPhone = trim((string) $order->phone);
        $this->foreign = ! UaePhone::isMobile($this->currentPhone);

        $first = trim((string) (($order->billing_address ?? [])['first_name'] ?? ''));
        $this->firstName = $first === '' ? '' : mb_convert_case(mb_strtolower($first), MB_CASE_TITLE);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('email.phone_request.subject', ['number' => $this->orderNumber()]));
    }

    public function content(): Content
    {
        $name = $this->firstName === '' ? 'anon' : 'named';
        $why = $this->foreign ? 'foreign' : 'unreached';

        return new Content(
            view: 'emails.order-phone-request',
            text: 'emails.order-phone-request-text',
            with: [
                'kitTitle' => (string) $this->envelope()->subject,
                'eyebrow' => __('email.phone_request.eyebrow'),
                'heading' => __('email.phone_request.heading'),
                'body' => __("email.phone_request.body_{$why}_{$name}", ['name' => $this->firstName]),
            ],
        );
    }
}
