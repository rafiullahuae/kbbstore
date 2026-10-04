<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Support\HtmlString;

/**
 * One marketing campaign message (Lane MK, docs/EMAILS-PLAN.md §4).
 *
 * The HTML and the text part arrive already rendered by
 * App\Services\Marketing\CampaignRenderer — from the campaign's frozen blocks,
 * with this recipient's name, click links and unsubscribe link — so this
 * class decides nothing about content. What it owns is the envelope:
 *
 *   List-Unsubscribe        <https://…/email/u/{token}>
 *   List-Unsubscribe-Post   List-Unsubscribe=One-Click   (RFC 8058)
 *   Precedence              bulk
 *
 * The one-click POST is real here, unlike the newsletter and back-in-stock
 * mails (whose headers explain why THEY must not advertise it): POST
 * /email/u/{token} is the one CSRF-exempt route in the shop, listed by exact
 * path, and it unsubscribes on the token alone.
 *
 * NO mailto: entry, although the plan's draft listed one. Nothing on this
 * host reads an unsubscribe@ mailbox, and a List-Unsubscribe address that
 * nobody processes is an unsubscribe request that is silently ignored —
 * exactly what the header exists to prevent. Gmail and Apple Mail use the
 * https one-click entry.
 */
class CampaignMail extends Mailable
{
    public function __construct(
        private string $subjectLine,
        private string $body,
        private string $plain,
        private string $unsubscribeUrl,
        private ?string $fromName = null,
    ) {}

    public function envelope(): Envelope
    {
        $envelope = new Envelope(subject: $this->subjectLine);

        $name = trim((string) $this->fromName);

        if ($name !== '') {
            try {
                $address = app(\App\Services\Mail\MailSettings::class)->fromAddress();

                if ($address !== '') {
                    $envelope->from(new \Illuminate\Mail\Mailables\Address($address, $name));
                }
            } catch (\Throwable) {
                // The shop's own From stays; a campaign does not fail over a name.
            }
        }

        return $envelope;
    }

    public function content(): Content
    {
        return new Content(
            htmlString: $this->body,
            text: 'emails.marketing.campaign-text',
            with: ['plain' => new HtmlString($this->plain)],
        );
    }

    public function headers(): Headers
    {
        return new Headers(text: [
            'List-Unsubscribe' => '<' . $this->unsubscribeUrl . '>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
            'Precedence' => 'bulk',
        ]);
    }
}
