<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * An email whose HTML was already drawn by the kit — Lane EK.
 *
 * The template editor's "Send test to me" for the two account emails, which are
 * notifications rather than Mailables: their message is rendered through its
 * own view (the same bytes a customer gets) and handed to the mailer as it is.
 * Nothing typed by anybody reaches $html here except through the kit's own
 * escaped views.
 */
class KitPreviewMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(private string $subjectLine, private string $kitHtml) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->kitHtml);
    }
}
