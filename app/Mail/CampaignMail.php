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
 * THE mailto: ENTRY (Lane EB). Lane MK left it out because nothing read an
 * unsubscribe mailbox, and a List-Unsubscribe address nobody processes is an
 * unsubscribe silently ignored. Lane EB reads one: when Bounces &
 * unsubscribes → Settings → "Read bounces" is on, the header also carries
 *
 *   <mailto:{account}+unsubscribe@{domain}?subject=unsubscribe%20{token}>
 *
 * which the same Gmail filter files under "KBB Bounces" and BounceReader
 * honours by the token. Off, there is no mailto, exactly as before.
 *
 * Also (Lane EB):
 *   Message-ID   <{BounceRef}@{From domain}> — signed per send, so a bounce
 *                report that quotes it names the campaign and the address.
 *   Feedback-ID  {campaign}:mkt:kbb — Gmail Postmaster Tools' spam-rate
 *                breakdown per campaign (Google's bulk-sender guidance).
 *
 * The From is always the shop's one From address (MailSettings::fromAddress);
 * a campaign may change the NAME only — a consistent From is one of Google's
 * bulk-sender requirements.
 */
class CampaignMail extends Mailable
{
    public function __construct(
        private string $subjectLine,
        private string $body,
        private string $plain,
        private string $unsubscribeUrl,
        private ?string $fromName = null,
        private ?string $messageId = null,
        private ?string $mailto = null,
        private ?int $campaignId = null,
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
        $text = [
            // https first: RFC 8058 one-click is what Gmail and Apple Mail use.
            'List-Unsubscribe' => '<' . $this->unsubscribeUrl . '>' . ($this->mailto !== null ? ', <' . $this->mailto . '>' : ''),
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
            'Precedence' => 'bulk',
        ];

        if ($this->campaignId !== null) {
            $text['Feedback-ID'] = $this->campaignId . ':mkt:kbb';
        }

        return new Headers(messageId: $this->messageId, text: $text);
    }
}
