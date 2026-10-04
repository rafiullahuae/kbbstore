<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * One recipient's copy of a campaign — Lane EK.
 *
 * The HTML is CampaignRenderer's batch template with this recipient's token and
 * first name filled in. Every one carries the two headers Gmail and Yahoo have
 * required of bulk senders since 2024 — List-Unsubscribe (the recipient's own
 * unsubscribe URL) and List-Unsubscribe-Post: List-Unsubscribe=One-Click
 * (RFC 8058), which lets the mail client unsubscribe with a single POST and no
 * page in between. That POST lands on CampaignPublicController::unsubscribe and
 * is honoured at once.
 */
class CampaignMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        private string $subjectLine,
        private string $kitHtml,
        private string $plain,
        private string $unsubscribeUrl,
        private ?string $fromAddress = null,
        private ?string $fromName = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            // Only when the campaign names its own sender; otherwise the
            // shop's From (Emails → Sending & delivery), name and all.
            from: ($this->fromAddress ?? '') !== '' && ($this->fromName ?? '') !== '' ? new Address($this->fromAddress, $this->fromName) : null,
            subject: $this->subjectLine,
        );
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->kitHtml, text: 'emails.campaign-text', with: ['plain' => $this->plain]);
    }

    public function headers(): Headers
    {
        return new Headers(text: [
            'List-Unsubscribe' => '<' . $this->unsubscribeUrl . '>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }
}
