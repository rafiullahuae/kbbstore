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

    /**
     * The delivery estimate in WORDING['shipped'] is a UAE one, and this store
     * does not only ship to the UAE.
     *
     * ShippingSeeder has carried a "Gulf Countries" zone — Saudi Arabia,
     * Kuwait, Qatar, Bahrain, Oman — since the port began, priced at its own
     * flat rate. Every one of those customers was nevertheless told, in
     * writing, that "delivery in the UAE normally takes one to three working
     * days from dispatch" about a parcel that was never going to the UAE. The
     * sentence was not merely irrelevant to them: it is a delivery promise, and
     * it was the wrong one.
     *
     * WHAT THIS SAYS INSTEAD, AND WHAT IT DELIBERATELY DOES NOT SAY. It does
     * not quote a Gulf delivery window, because nobody has measured one. The
     * UAE figure above is the owner's, from the storefront's own delivery text;
     * a "three to seven working days" invented here to fill the gap would be
     * the same class of untruth in the other direction, and it would be
     * invented by the person least qualified to invent it. So the estimate is
     * simply dropped and the two things that ARE known are said. If the owner
     * wants a figure for the Gulf zone, it belongs in settings beside the
     * storefront's `delivery_texts`, not in this constant.
     */
    private const SHIPPED_ABROAD = 'Your order has left us and is with the courier. Deliveries outside the UAE take longer than local ones and also wait on customs clearance in your country, so please allow a few extra days.';

    /**
     * And when the order does not say where it is going.
     *
     * An order with no country on either address — an import, a half-filled
     * manual order — gets no estimate at all rather than a guessed one. Saying
     * nothing about timing is the only sentence that is certainly true.
     */
    private const SHIPPED_UNKNOWN = 'Your order has left us and is with the courier. Your tracking will update as it moves.';

    /** The store's own country: the one destination WORDING['shipped'] describes. */
    private const HOME_COUNTRY = 'AE';

    public string $status;

    public function __construct(Order $order, string $status)
    {
        parent::__construct($order);

        $this->status = $status;
    }

    /**
     * The dispatch sentence for where this parcel is actually going.
     *
     * Only `shipped` has a destination-dependent body; `cancelled` says nothing
     * about delivery and is returned untouched. The country comes from
     * OrderEmailPresenter, which reads the shipping address and falls back to
     * the billing one — the same choice it makes for the address it prints, so
     * the estimate and the address can never describe two different places.
     */
    private function bodyFor(string $default): string
    {
        if ($this->status !== 'shipped') {
            return $default;
        }

        $country = (string) ($this->order['destinationCountry'] ?? '');

        return match (true) {
            $country === self::HOME_COUNTRY => $default,
            $country === '' => self::SHIPPED_UNKNOWN,
            default => self::SHIPPED_ABROAD,
        };
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
            with: ['heading' => $heading, 'body' => $this->bodyFor($body), 'status' => $this->status],
        );
    }
}
