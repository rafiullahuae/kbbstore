<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/**
 * "The thing you asked about is back." One message, once (Lane EN).
 *
 * ── THE SUBJECT AND THE BODY ARE THE OWNER'S, AND THIS CLASS HAS NONE ───────
 *
 * Both arrive as constructor arguments and both have already been proved
 * non-empty by StockAlerts::messageWording(), which is the only thing that
 * builds this. There is no default subject and no default sentence anywhere in
 * this file or its views. That is the rule the brief sets — on-and-unwritten
 * sends nothing at all — and the way to make it true of a mailable is to leave
 * it nothing to fall back to. A `?: 'Back in stock!'` here would be the whole
 * feature quietly re-acquiring wording of its own.
 *
 * The product name and the link ARE this class's, because they are facts about
 * the shop rather than things to say about them. An owner cannot be asked to
 * type the product's name into a template that goes to everybody.
 *
 * ── WHY THE SUBJECT DOES NOT CARRY THE PRODUCT NAME ────────────────────────
 *
 * It would read better. It also puts a shopper's purchase interest in the one
 * line that shows on a locked phone screen, in notification previews, in shared
 * screenshots and in every mail server's logs along the way — and this shop
 * sells things people do not necessarily want announced. OrderConfirmation's
 * header states the same rule for order numbers. The owner's subject is used
 * verbatim; the product is named in the body.
 *
 * ── NOT A NOTIFICATION ─────────────────────────────────────────────────────
 *
 * Same reasoning as NewsletterConfirmation: the recipient is a row in
 * `stock_alerts` and very often has no account at all, so there is no notifiable
 * to route through and a Mailable addressed by the mailer is the honest shape.
 *
 * ── List-Unsubscribe, AND NO List-Unsubscribe-Post ─────────────────────────
 *
 * Exactly as NewsletterConfirmation does it, for the reason set out there: the
 * opt-out route answers a GET with a page and only acts on a CSRF-bearing POST,
 * which a provider's automated one-click POST does not have and cannot get.
 * Advertising RFC 8058 one-click and then refusing the provider's POST shows the
 * recipient a button that does nothing, which is worse than not showing one.
 */
class BackInStockAlert extends Mailable
{
    use BrandedSubject;

    /** @var array<string, mixed> */
    public array $brand;

    public function __construct(
        private string $subjectLine,
        private string $body,
        private string $productName,
        private string $productUrl,
        private string $unsubscribeUrl,
    ) {
        $this->brand = \App\Services\Mail\EmailBranding::forMailable(true, self::class);
    }

    public function envelope(): Envelope
    {
        // Verbatim. See the header: this class supplies no wording of its own,
        // and that includes not decorating the owner's.
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.back-in-stock',
            text: 'emails.back-in-stock-text',
            with: [
                'body' => $this->body,
                'productName' => $this->productName,
                'productUrl' => $this->productUrl,
                'unsubscribeUrl' => $this->unsubscribeUrl,
                'brand' => $this->brand,
            ],
        );
    }

    public function headers(): Headers
    {
        return new Headers(
            text: [
                'List-Unsubscribe' => '<' . $this->unsubscribeUrl . '>',
            ],
        );
    }
}
