<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/**
 * "Please confirm you want these emails."
 *
 * The only message this shop sends to an address that has not yet agreed to
 * hear from it, which makes it the one mailable whose content is constrained by
 * something other than taste: it must be plainly a confirmation request and
 * nothing else. No offer, no product, no discount code — the moment this
 * carries marketing, the shop is marketing to an unconfirmed address, which is
 * the exact thing double opt-in was added to stop.
 *
 * NOT a Notification, unlike the password-reset and verification mails. Those
 * are addressed to a Customer model and go through the notification routing
 * that a Customer provides. A subscriber is a row in `subscribers` and is very
 * often not a customer at all, so there is no notifiable here; a Mailable
 * addressed by the mailer is the honest shape rather than a fake notifiable
 * built to satisfy a facade.
 *
 * The subject carries no token and no address. Subjects are quoted in
 * notification previews, in shared screenshots and in every mail server's logs
 * along the way — the same rule OrderConfirmation's header states.
 */
class NewsletterConfirmation extends Mailable
{
    use BrandedSubject;

    /**
     * The masthead, the wordmark and the sign-off, exactly as every order email
     * builds them.
     *
     * Public, because the view reads it, and built here rather than in the view
     * so that a branding lookup that fails degrades to EmailBranding's own
     * fallback once instead of half-rendering. `true` for customerFacing: this
     * goes to a shopper, and the flag selects the customer presentation rather
     * than the merchant one — see EmailBranding::present().
     *
     * @var array<string, mixed>
     */
    public array $brand;

    public function __construct(
        private string $confirmUrl,
        private string $unsubscribeUrl,
        private int $days,
    ) {
        $this->brand = \App\Services\Mail\EmailBranding::forMailable(true, self::class);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Please confirm your ' . $this->brandName() . ' subscription',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.newsletter-confirm',
            text: 'emails.newsletter-confirm-text',
            with: [
                'confirmUrl' => $this->confirmUrl,
                'unsubscribeUrl' => $this->unsubscribeUrl,
                'days' => $this->days,
                'brand' => $this->brand,
            ],
        );
    }

    /**
     * `List-Unsubscribe`, so the mail client's own unsubscribe button works.
     *
     * WHY THIS IS WORTH THE HEADER. Gmail, Outlook and Apple Mail all render a
     * one-click unsubscribe control when they see this, and — since 2024 — the
     * large providers require it of anyone sending bulk mail. A recipient who
     * cannot find the link in the body presses "report spam" instead, and a
     * spam complaint costs this shop's whole domain far more than one
     * subscriber.
     *
     * NO `List-Unsubscribe-Post`, deliberately. That header promises RFC 8058
     * one-click: the provider POSTs the URL itself, with no human involved, and
     * the address must come off the list on that POST alone. This shop's
     * unsubscribe route answers a GET with a confirmation page and only acts on
     * a POST carrying a CSRF token — which a provider's automated POST does not
     * have and cannot get. Advertising one-click and then rejecting the
     * provider's POST is worse than not advertising it: the button appears, the
     * recipient presses it, and nothing happens. The URL alone is honest — the
     * client opens it and the human presses the button on the page.
     */
    public function headers(): Headers
    {
        return new Headers(
            text: [
                'List-Unsubscribe' => '<' . $this->unsubscribeUrl . '>',
            ],
        );
    }
}
