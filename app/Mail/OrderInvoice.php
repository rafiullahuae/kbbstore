<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Order;
use App\Services\Invoices\InvoiceDocument;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * "Here is your invoice" — sent by the owner, on purpose, from the order screen.
 *
 * NOT ONE OF THE FOUR AUTOMATIC ORDER EMAILS, and deliberately not a subclass of
 * OrderMail. Those four render OrderEmailPresenter, which describes an order as a
 * receipt describes it. This one renders InvoiceDocument, which describes the same
 * order as an accounting document does — with an invoice number, an issue date and
 * the seller's own business details on it — and there must be exactly one
 * definition of that, shared with the printable page, or the emailed invoice and
 * the printed one can quietly disagree.
 *
 * NOT QUEUED, like every other mail in this app. There is no queue worker on this
 * host; a queued Mailable would be written to the jobs table and never sent, which
 * looks like success and is not. See OrderMail's header.
 *
 * THE INVOICE IS THE BODY, NOT AN ATTACHMENT. There is no PDF to attach — the
 * host cannot carry a PDF library through the updater (InvoiceController explains
 * why) — and an .html attachment is the kind of thing mail filters strip, quarantine
 * or refuse to preview on a phone. Rendered inline it is legible everywhere, it
 * prints from the mail client, and there is nothing to fail to open.
 *
 * THE SUBJECT CARRIES THE INVOICE NUMBER AND THE ORDER NUMBER AND NOTHING ELSE.
 * No total, no link, no token: subjects are quoted in notification previews, in
 * shared screenshots and in every mail server's log along the way.
 */
class OrderInvoice extends Mailable
{
    /** @var array<string, mixed> */
    public array $doc;

    public function __construct(Order $order)
    {
        $this->doc = app(InvoiceDocument::class)->present($order);
    }

    public function envelope(): Envelope
    {
        $reference = (string) $this->doc['invoiceReference'];

        return new Envelope(
            subject: $reference !== ''
                ? 'Invoice ' . $reference . ' for your K Beauty Bliss order ' . $this->doc['orderNumber']
                : 'Invoice for your K Beauty Bliss order ' . $this->doc['orderNumber'],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.order-invoice',
            text: 'emails.order-invoice-text',
        );
    }
}
