<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Order;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * "Your order is on its way", or "your order has been cancelled".
 *
 * ONE CLASS, TWO EVENTS, AND A CLOSED LIST. The statuses are not invented here:
 * `orders.status` is written in exactly four places in this app — checkout writes
 * `pending` and `failed`, CashOnDelivery and PaymentConfirmer write `processing`,
 * AdminOrderController::runAction writes `cancelled` and `draft`, and
 * AdminController::updateOrderStatus accepts the full vocabulary it validates
 * against: draft, pending, processing, onhold, shipped, completed, cancelled,
 * refunded, failed. WORDING below covers the subset a customer is owed a message
 * about; anything else mails nothing at all rather than guessing.
 *
 * WHAT IS NOT HERE, AND WHY:
 *
 *   processing — the order was confirmed seconds ago and the confirmation email
 *                already said so. Cash on delivery moves pending → processing
 *                inside CheckoutController::place() itself, so a message here
 *                would be a second email about the same event, sent in the same
 *                second as the first.
 *   refunded   — money going back is OrderRefunded's job, and it is driven by the
 *                Refund row actually settling rather than by a status column
 *                somebody typed. Mailing on both would mean two emails for one
 *                refund, or one email for a refund that never happened.
 *   failed,
 *   draft,
 *   onhold,
 *   completed  — internal bookkeeping. `failed` in particular is written when a
 *                gateway declines at checkout, where the shopper is looking at
 *                the error on screen; emailing them about it as well is noise.
 */
class OrderStatusChanged extends OrderMail
{
    /**
     * The statuses worth an email, and exactly what each one says.
     *
     * @var array<string, array{0:string,1:string,2:string}>  status => [subject, heading, body]
     */
    public const WORDING = [
        'shipped' => [
            'Your K Beauty Bliss order %s is on its way',
            'Your order is on its way',
            'Your order has left us and is with the courier. Delivery in the UAE normally takes one to three working days from dispatch.',
        ],
        'cancelled' => [
            'Your K Beauty Bliss order %s has been cancelled',
            'Your order has been cancelled',
            'This order has been cancelled and nothing further will be sent. If you had already paid, the refund is on its way back to you by the method you paid with — you will get a separate email when it has been sent.',
        ],
    ];

    public string $status;

    public function __construct(Order $order, string $status)
    {
        parent::__construct($order);

        $this->status = $status;
    }

    /** Is this a status a customer gets told about at all? */
    public static function handles(string $status): bool
    {
        return array_key_exists($status, self::WORDING);
    }

    public function envelope(): Envelope
    {
        [$subject] = self::WORDING[$this->status];

        return new Envelope(
            subject: sprintf($subject, $this->orderNumber()),
        );
    }

    public function content(): Content
    {
        [, $heading, $body] = self::WORDING[$this->status];

        return new Content(
            view: 'emails.order-status',
            text: 'emails.order-status-text',
            with: ['heading' => $heading, 'body' => $body, 'status' => $this->status],
        );
    }
}
