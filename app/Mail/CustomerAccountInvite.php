<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * "Your account is ready — set your password." (Lane PQ)
 *
 * Sent from Store → Customers → Send account invite to guests who checked out
 * without an account. The wording is the owner's (App\Services\CustomerInvites\
 * InviteTemplate); this class supplies none of its own beyond the button label,
 * the line saying why the message arrived, and the sign-off.
 *
 * ── NO PASSWORD IN IT ──────────────────────────────────────────────────────
 *
 * The owner first asked for a temporary password. An emailed password sits in
 * an inbox, in every forward, backup and synced phone, for as long as that
 * mailbox exists, and most people never change a password that works. What is
 * sent instead is a one-time link: random, stored only as a hash, valid for a
 * few days, dead after one use or when a newer invite is sent. Same outcome
 * for the customer — click, choose a password, signed in — and nothing in the
 * inbox that still opens the account next year.
 *
 * Not ShouldQueue: no worker runs on this host (OrderMail's header). The
 * console drives sending in short batches instead — see CustomerInviter.
 *
 * Not built on emails/layout.blade.php: that is the order-email masthead with
 * the shop's support block and marketing footer. This is a one-off account
 * message, plain like emails/back-in-stock and emails/newsletter-confirm.
 */
class CustomerAccountInvite extends Mailable
{
    /** @var array<string, mixed> */
    public array $brand;

    /**
     * @param  list<array{text?: string, link?: bool}>  $segments  from InviteTemplate::segments()
     */
    public function __construct(
        private string $subjectLine,
        private array $segments,
        private string $textBody,
        private string $link,
        private string $shopName,
    ) {
        $this->brand = \App\Services\Mail\EmailBranding::forMailable(true, self::class);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.customer-invite',
            text: 'emails.customer-invite-text',
            with: [
                'segments' => $this->segments,
                'textBody' => $this->textBody,
                'link' => $this->link,
                'shopName' => $this->shopName,
                'brand' => $this->brand,
            ],
        );
    }
}
