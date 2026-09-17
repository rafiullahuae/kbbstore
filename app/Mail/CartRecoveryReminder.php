<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/**
 * "Your basket is still here." (Lane EN)
 *
 * ── NO WORDING OF ITS OWN ──────────────────────────────────────────────────
 *
 * Subject and body arrive as arguments, already proved non-empty by
 * CartRecovery::messageWording(), which is the only thing that builds this.
 * There is no default sentence in this class or its views — see
 * BackInStockAlert's header for why a fallback string would quietly undo the
 * whole "off and unwritten" rule.
 *
 * ── WHY THERE IS NO ONE-CLICK BASKET RESTORE ───────────────────────────────
 *
 * The module registry's inherited description promises "a one-click recovery
 * link", and this deliberately does not ship one. A link that restores a
 * basket is a bearer credential for a shopping session: anyone holding the URL
 * — a colleague the message was forwarded to, a link-safety scanner's log, a
 * shared family inbox — becomes that shopper, with their basket, their
 * addresses at checkout and their saved details. Support\CustomerLinkSigner's
 * header sets out how carefully this shop treats links that travel through an
 * inbox, and handing over a session is a much larger promise than verifying an
 * address.
 *
 * So the message names what is in the basket and links to each item's own
 * page. On the device that built the basket, the cart cookie is still there and
 * the basket is already waiting; on any other device the shopper gets the
 * products they chose, one tap from adding them. That covers the real case
 * without shipping a credential nobody asked for. Whether the owner wants the
 * stronger version, and accepts what it means, is in the hand-back as a
 * question rather than decided here.
 *
 * ── THE STAGE NUMBER IS NOT IN THE MESSAGE ─────────────────────────────────
 *
 * A second reminder does not say it is the second. The owner writes one body
 * and it is used for every stage, because a per-stage body multiplies the
 * number of boxes that must be filled before anything sends — and an owner who
 * fills two of three writes a sequence with a blank message in it. One body,
 * used as often as the schedule says, is the version that cannot half-work.
 * Per-stage wording is in the hand-back as a question.
 */
class CartRecoveryReminder extends Mailable
{
    use BrandedSubject;

    /** @var array<string, mixed> */
    public array $brand;

    /**
     * @param  list<array{name: string, slug: string, quantity: int, unit_price: int}>  $items
     */
    public function __construct(
        private string $subjectLine,
        private string $body,
        private array $items,
        private string $cartUrl,
        private string $unsubscribeUrl,
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
            view: 'emails.cart-recovery',
            text: 'emails.cart-recovery-text',
            with: [
                'body' => $this->body,
                'items' => $this->items,
                'cartUrl' => $this->cartUrl,
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
