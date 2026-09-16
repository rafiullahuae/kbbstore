<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Order;
use App\Services\Mail\OrderEmailPresenter;
use App\Services\SettingsService;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\Log;

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
    use BrandedSubject;

    /**
     * The statuses worth an email, and exactly what each one says.
     *
     * THE SUBJECT TAKES TWO PLACEHOLDERS, NUMBERED — Lane DI. `%1$s` is the
     * store's name and `%2$s` is the order number. It used to be one `%s` with
     * the shop's name spelled out beside it, which is why renaming the shop
     * left two subjects claiming the old one. Numbered rather than positional
     * so a subject in another language can put them in the other order, and so
     * that reading this table says which is which.
     *
     * @var array<string, array{0:string,1:string,2:string}>  status => [subject, heading, body]
     */
    public const WORDING = [
        'shipped' => [
            'Your %1$s order %2$s is on its way',
            'Your order is on its way',
            /*
             * STILL THE DEFAULT, AND NO LONGER THE ONLY POSSIBILITY. The second
             * sentence is a delivery window for one country, and the owner can
             * now rewrite it on Store → Mail without a code change — see
             * SHIPPED_TIMING_KEY below. Left alone here so that "nothing moves
             * until he edits it" is a property of this constant rather than a
             * promise about a reconstruction: bodyFor() returns this string,
             * byte for byte, while the box is blank.
             */
            'Your order has left us and is with the courier. Delivery in the UAE normally takes one to three working days from dispatch.',
        ],
        'cancelled' => [
            'Your %1$s order %2$s has been cancelled',
            'Your order has been cancelled',
            'This order has been cancelled and nothing further will be sent.',
        ],
    ];

    /**
     * WHAT THE CANCELLATION EMAIL USED TO SAY ABOUT MONEY, AND WHY IT IS GONE.
     *
     * The `cancelled` body above used to carry a second sentence stating that a
     * customer who had already paid would be getting their money back by the
     * method they paid with, and a separate email once it had been sent. Every
     * clause of it was written before the code that would have had to be true.
     *
     * Cancelling an order in this application starts no refund. `orders.status`
     * is moved to `cancelled` by AdminOrderController::runAction, by
     * AdminController::updateOrderStatus and by OrdersApiController::bulkStatus,
     * and not one of them touches the `refunds` table or calls a gateway.
     * App\Services\Payments\PaymentRefunder is a separate action an operator
     * takes deliberately, from a different form. So the money was not on its
     * way; nothing had been started. The promised follow-up email is
     * OrderRefunded, which is driven by a `refunds` row settling — an event
     * that, for an order nobody has refunded, never happens. The customer waits,
     * and then writes to the shop.
     *
     * NOTHING HAS BEEN INVENTED IN ITS PLACE, and no refund policy has been
     * guessed at. The store has never written one down and this is not the file
     * to write one in. What is said instead is what this order's own rows
     * record, and only that — the same restraint the Gulf delivery window got.
     *
     * THREE CASES, BECAUSE THE TRUTH IS DIFFERENT IN EACH. A single sentence
     * vague enough to cover all three would be a sentence that told a cash
     * customer nothing and a card customer less:
     *
     *   1. A REFUND IS ON THE BOOKS. `refunds` carries pending or succeeded rows
     *      against this order, so a refund of that amount HAS been started, and
     *      saying so is a fact rather than a promise. OrderRefunded is what tells
     *      them it has settled, and it really is sent, because a settled row is
     *      what triggers it.
     *
     *   2. NOTHING WAS EVER TAKEN. PaymentRefunder::capturedFils() is 0, which
     *      means neither `captured_at`/`captured_total` nor `paid_at` is set:
     *      no gateway captured anything and nothing was ever recorded as paid.
     *      That covers every cash-on-delivery order — CashOnDelivery::start()
     *      deliberately leaves `paid_at` null and the courier never goes — and
     *      every card order abandoned before the gateway confirmed. There is
     *      nothing to send back and the email says exactly that.
     *
     *   3. MONEY WAS TAKEN AND NO REFUND HAS BEEN RECORDED. The one case where
     *      the customer is owed something and the application cannot say what
     *      will happen about it, because nothing has happened yet. It states the
     *      amount and the absence, both of which are facts, and then prints
     *      whatever the owner has written in `mail_cancelled_refund_note` on
     *      Store → Mail. That setting ships BLANK: this is a hole only the owner
     *      can fill, and a default sentence written here would be the same
     *      invention that was just removed.
     *
     * IT IS ASKED OF THE PAYMENT RECORD, NOT OF THE GATEWAY ID. `payment_method
     * === 'cod'` would be a second copy of a fact that already has a home, and
     * it would answer wrongly for an imported WooCommerce order (OrderImporter
     * writes a real `paid_at` from `date_paid`) and for any provider added
     * later. capturedFils() and refundedFils() are the same two methods
     * PaymentRefunder itself decides a ceiling with, so this email and the
     * refund screen can never disagree about whether an order was paid.
     *
     * THE MONEY IS RENDERED PLAIN, NOT AS Money::format() MARKUP. The body is a
     * prose string printed through Blade's {{ }} in the HTML part, which is what
     * keeps the owner's own sentence from arriving as markup. A figure carrying
     * a <span> would have to be printed unescaped, and that would unescape the
     * operator's note beside it. Full precision, like every other figure in an
     * order email — see OrderEmailPresenter's header for why a receipt may not
     * round.
     */
    public const CANCELLED_REFUND_NOTE = 'mail_cancelled_refund_note';

    /** What is said when a refund really has been recorded against the order. */
    private const CANCELLED_REFUNDED = 'A refund of %s has been recorded against it.';

    /** And when the shop has no record of ever having been paid. */
    private const CANCELLED_NOTHING_TAKEN = 'Our records show no payment taken on this order, so there is nothing to refund.';

    /** And when there is, and nothing has been started. */
    private const CANCELLED_UNREFUNDED = 'Our records show %s paid on this order and no refund recorded against it yet.';

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
     *
     * IT USED TO OFFER TRACKING AS WELL, and this shop has none. There is no
     * tracking number, no carrier reference and no courier integration anywhere
     * in this application — no column, no setting, no service. What
     * /track-my-order/ shows is the order's own `status` pill, which changes
     * when an operator types a new status and at no other time. So "your
     * tracking will update as it moves" described a parcel being followed by
     * something nobody built, and the one update it could ever produce comes
     * from a person at a keyboard rather than from the parcel. Removed rather
     * than reworded: a claim with nothing behind it is not softened, and the
     * email already links to the order and says plainly what that link can and
     * cannot do.
     *
     * It is SHIPPED_DISPATCHED with nothing after it, and that is the point:
     * the only sentence certainly true of a parcel whose destination this order
     * does not record is that it has gone.
     */
    private const SHIPPED_UNKNOWN = self::SHIPPED_DISPATCHED;

    /**
     * The half of the dispatch email that is true of every destination.
     *
     * One copy of it, because all three branches open with it and a fourth
     * spelling of the same sentence is a fourth place to correct it.
     */
    private const SHIPPED_DISPATCHED = 'Your order has left us and is with the courier.';

    /**
     * WHAT THE DISPATCH EMAIL SAYS ABOUT TIMING, AND WHY IT IS A SECOND BOX.
     *
     * The constant in WORDING['shipped'] above is a delivery promise for one
     * country, written in a PHP file. The owner's own editable UAE wording lives
     * in `delivery_default_text` (Store → Delivery & Shipping → Delivery lines)
     * and is read by App\Support\DeliveryLine and by nothing else. So the day he
     * rewrites his delivery line, this email goes on saying the old thing, and
     * nothing anywhere notices the two have parted company.
     *
     * IT IS NOT THE SAME SENTENCE AND MAY NOT BE SUBSTITUTED FOR IT. The two are
     * anchored to different events:
     *
     *   this one              measured FROM DISPATCH. It is sent at the moment
     *                         the parcel leaves.
     *   delivery_default_text measured FROM THE ORDER. It is printed under Place
     *                         order, before the order exists.
     *
     * On this shop that gap is a configured quantity rather than a quibble:
     * Store → Ecommerce → Delivery carries `dispatch_cutoff_hour` (15) and
     * `dispatch_days`, and the product page builds an arrival date out of both
     * precisely because ordering and dispatching are not the same moment. An
     * order placed at 16:00 on a Thursday is not dispatched that day. Printing
     * the storefront's line here would therefore quietly shorten a promise, in
     * the direction that gets a shop complained about.
     *
     * So it is its own box on Store → Mail, labelled for the clock it is
     * measured on, and SHIPPED BLANK. Blank means this email says exactly what
     * it says today — bodyFor() returns WORDING['shipped'][2] untouched — so a
     * shop that applies the package and never opens the screen tells every
     * customer precisely what it told them before.
     *
     * IT GOVERNS THE HOME-COUNTRY BRANCH ONLY. SHIPPED_ABROAD deliberately
     * quotes no window, because no Gulf transit time has been measured; a box
     * that filled that silence would be the invention the previous lane refused
     * to make. If the owner wants a Gulf figure it needs a measured one first.
     */
    public const SHIPPED_TIMING_KEY = 'mail_shipped_timing_note';

    /** The store's own country: the one destination WORDING['shipped'] describes. */
    private const HOME_COUNTRY = 'AE';

    public string $status;

    /**
     * What this order's own rows say about money going back, or ''.
     *
     * Worked out in the constructor, where the Order model is still in hand,
     * and kept as a finished string: OrderMail deliberately keeps only the
     * presented array, and this class has no business holding a model it would
     * then be tempted to read columns off inside a template. Empty for every
     * status but `cancelled`.
     */
    public string $refundSentence = '';

    public function __construct(Order $order, string $status)
    {
        parent::__construct($order);

        $this->status = $status;

        if ($status === 'cancelled') {
            $this->refundSentence = $this->refundSentenceFor($order);
        }
    }

    /**
     * The true sentence about money for THIS cancelled order. See the three
     * cases set out above CANCELLED_REFUND_NOTE.
     *
     * GUARDED, and for the reason OrderMail's own header gives about branding:
     * OrderMailer's try exists so that a receipt which cannot be built costs the
     * checkout nothing, and the same applies here. This reads the `refunds`
     * table; if that read fails, the money sentence is dropped and the
     * cancellation itself is still said — the half that matters most, and the
     * half that is certainly true whatever the database is doing.
     */
    private function refundSentenceFor(Order $order): string
    {
        try {
            $refunder = app(\App\Services\Payments\PaymentRefunder::class);

            $refunded = $refunder->refundedFils($order);

            if ($refunded > 0) {
                return sprintf(self::CANCELLED_REFUNDED, OrderEmailPresenter::plain($refunded));
            }

            $captured = $refunder->capturedFils($order);

            if ($captured <= 0) {
                return self::CANCELLED_NOTHING_TAKEN;
            }

            $sentence = sprintf(self::CANCELLED_UNREFUNDED, OrderEmailPresenter::plain($captured));

            $note = trim((string) app(SettingsService::class)->get(self::CANCELLED_REFUND_NOTE, ''));

            return $note === '' ? $sentence : $sentence . ' ' . $note;
        } catch (\Throwable $e) {
            Log::warning('cancellation refund sentence unavailable', [
                'order_id' => $order->getKey(),
                'exception' => class_basename($e),
                'message' => $e->getMessage(),
            ]);

            return '';
        }
    }

    /**
     * The sentence that follows the heading, for the event this actually is.
     *
     * `shipped` varies by where the parcel is going; `cancelled` varies by what
     * this order's rows say about money. The country comes from
     * OrderEmailPresenter, which reads the shipping address and falls back to
     * the billing one — the same choice it makes for the address it prints, so
     * the estimate and the address can never describe two different places.
     */
    private function bodyFor(string $default): string
    {
        if ($this->status === 'cancelled') {
            return $this->refundSentence === ''
                ? $default
                : $default . ' ' . $this->refundSentence;
        }

        if ($this->status !== 'shipped') {
            return $default;
        }

        $country = (string) ($this->order['destinationCountry'] ?? '');

        return match (true) {
            $country === self::HOME_COUNTRY => $this->homeShippedBody($default),
            $country === '' => self::SHIPPED_UNKNOWN,
            default => self::SHIPPED_ABROAD,
        };
    }

    /**
     * The home-country dispatch sentence: the owner's timing line, or today's.
     *
     * $default is WORDING['shipped'][2] and is returned UNTOUCHED while the box
     * is blank, which is the shipped state — see SHIPPED_TIMING_KEY for why the
     * storefront's delivery line is not read here instead.
     *
     * GUARDED, for the reason refundSentenceFor() is guarded and OrderMail's
     * header gives about branding: this reads the settings table, and a table
     * read that fails must not be why a customer hears nothing about a parcel
     * that has already left. The fallback is the constant, so the failure costs
     * the wording the owner typed and nothing else.
     */
    private function homeShippedBody(string $default): string
    {
        try {
            $note = trim((string) app(SettingsService::class)->get(self::SHIPPED_TIMING_KEY, ''));
        } catch (\Throwable $e) {
            Log::warning('dispatch timing note unavailable', [
                'exception' => class_basename($e),
                'message' => $e->getMessage(),
            ]);

            return $default;
        }

        return $note === '' ? $default : self::SHIPPED_DISPATCHED . ' ' . $note;
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
            subject: sprintf($subject, $this->brandName(), $this->orderNumber()),
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
