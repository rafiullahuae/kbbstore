<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\ContactInquiry;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * "You have a new inquiry" — to the shop, when the contact page's form is sent.
 * (Lane CT)
 *
 * To the owner, not to a shopper, so it carries no branding block and no
 * unsubscribe, and its view lives with the back office (admin/mail/), in the
 * operator's English like every other screen of the console. Reply-To is the visitor's address, which the form validated with
 * StorefrontEmail, so pressing Reply in the mail client answers them.
 *
 * Not ShouldQueue: no worker runs on this host (OrderMail's header). The form's
 * controller sends it after the response, inside a try, and the inquiry is
 * already stored by then — a mail that fails costs the visitor nothing and the
 * owner still finds the message in Store → Inquiries.
 *
 * Every value is the visitor's own text, so the view prints each one with {{ }}
 * and nothing with {!! !!}. The subject is two single-line values (no CR or LF
 * can survive ContactPage::singleLine()), and Symfony encodes it.
 */
class ContactInquiryAlert extends Mailable
{
    public function __construct(public ContactInquiry $inquiry) {}

    public function envelope(): Envelope
    {
        $topic = trim((string) $this->inquiry->topic);

        return new Envelope(
            replyTo: [new Address((string) $this->inquiry->email, (string) $this->inquiry->name)],
            subject: 'New inquiry from '.$this->inquiry->name.($topic !== '' ? ' — '.$topic : ''),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'admin.mail.contact-inquiry',
            with: ['q' => $this->inquiry->toAdmin()],
        );
    }
}
