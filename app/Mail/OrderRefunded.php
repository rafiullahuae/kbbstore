<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Order;
use App\Models\Refund;
use App\Services\Mail\OrderEmailPresenter;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * "We have sent your money back."
 *
 * SENT WHEN MONEY ACTUALLY MOVES, NOT WHEN A STATUS IS TYPED. The trigger is a
 * `refunds` row reaching `succeeded`, which PaymentRefunder::settle() writes only
 * after the provider confirmed the return (or after the merchant recorded a
 * settle-by-hand, which is still money gone). A refund that fails writes `failed`
 * on the same row and mails nothing: telling a customer their money is on the way
 * when the gateway refused is worse than telling them nothing.
 *
 * It is also why `orders.status = refunded` does NOT mail. PaymentRefunder flips
 * that column with a query-builder update once the refunded total catches the
 * captured total, and a partial refund never flips it at all — so a status-driven
 * email would miss every partial refund and double up on every full one.
 *
 * The amount is the REFUND's amount in fils, not the order total. On a partial
 * refund those differ, and the email says which it is.
 */
class OrderRefunded extends OrderMail
{
    public int $amountFils;

    public string $amountHtml;

    public string $amountPlain;

    public bool $isPartial;

    /**
     * Did a gateway actually push this money back, or did we only write it down?
     *
     * CASH ON DELIVERY IS THIS STORE'S ORDINARY PAYMENT METHOD, AND IT HAS NO
     * REFUND API. PaymentRefunder says so in as many words: a provider whose
     * gateway does not implement SettlesPayments "still gets a recorded refund —
     * the ledger entry a manual bank transfer needs" and settles it
     * `recorded_only`, with the note "return the money by hand". Nothing has
     * moved at that point. A person has to.
     *
     * The email did not know that. It told every refunded customer, in the same
     * words, that we had "sent [the amount] back to the payment method you
     * used" and that it "usually appears on a card statement within five to ten
     * working days, depending on your bank". For a customer who handed cash to a
     * courier that is three untruths in one sentence: nothing was sent, there is
     * no payment method to send it to, and there is no card statement for it to
     * appear on. The worst part is the ten working days — it buys the store ten
     * days of a customer waiting quietly for money that is not coming unless
     * somebody remembers to send it.
     *
     * IT IS NOT ASKED AS `payment_method === 'cod'`, AND IT IS NOT ASKED OF THE
     * INTERFACE EITHER. The obvious two answers are both wrong here:
     *
     *   - an `=== 'cod'` would be a second copy of a fact that lives in the
     *     gateway, and a build that ships a new provider before its refund half
     *     would go on promising those customers a card refund;
     *   - `instanceof SettlesPayments` LOOKS like the right predicate — it is
     *     the one PaymentRefunder branches on — but CashOnDelivery implements
     *     that interface. It has a refund() method; the method returns
     *     `recorded_only` and a summary reading "Cash on delivery has nothing to
     *     call — return the money by hand". Implementing the contract is not the
     *     same claim as moving money, and using it here sent exactly the wrong
     *     answer for exactly the case this exists for.
     *
     * THE RECORDED FACT IS THE REFERENCE. Every gateway in this build that can
     * really push a refund refuses to report success without the provider's own
     * refund id: Stripe requires `is_string($refundId)` and a status of
     * succeeded or pending, Tabby and Tamara both `return SettlementResult::failed`
     * when their refund id is null. CashOnDelivery passes null, and so does
     * PaymentRefunder's own fallback for a gateway with no settlement code at
     * all. PaymentRefunder::settle() then writes that reference to the refund
     * row. So on a SUCCEEDED refund, a null `provider_ref` means precisely one
     * thing: no provider acknowledged it, because none was asked.
     *
     * It is also the safe direction to be wrong in. A future gateway that
     * somehow settled without a reference would get the recorded-only wording —
     * "we will arrange the money with you directly, reply if you have not heard
     * from us" — which is a customer being invited to check on money that is
     * already on its way. The reverse, which is what shipped, is a customer
     * being told to wait ten working days for money nobody has sent.
     */
    public bool $settledByGateway;

    public function __construct(Order $order, Refund $refund)
    {
        parent::__construct($order);

        $this->amountFils = (int) $refund->amount;
        $this->amountHtml = OrderEmailPresenter::html($this->amountFils);
        $this->amountPlain = OrderEmailPresenter::plain($this->amountFils);

        /*
         * PARTIAL MEANS "SOMETHING IS STILL UNREFUNDED", NOT "THIS REFUND IS
         * SMALLER THAN THE ORDER".
         *
         * This was:
         *
         *     $this->isPartial = $this->amountFils < (int) $order->total;
         *
         * — one refund's amount against the order total, which is the wrong
         * comparison as soon as an order is refunded in more than one go. A
         * 220-dirham order returned as two 110-dirham refunds (a goodwill
         * amount now, the balance when the parcel is back — ordinary) made BOTH
         * emails say "This is a partial refund — the rest of the order is
         * unaffected." The second one said it about an order that had just been
         * refunded in full. The customer is told in writing that they are still
         * owed nothing back on an order they are owed nothing on, which is the
         * kind of sentence that starts a chargeback.
         *
         * The right question is the one PaymentRefunder itself asks when it
         * decides whether to move the order to `refunded`: does everything
         * refunded so far reach everything that was captured? So it is asked of
         * the same object, with the same two methods, and the email and the
         * order status can no longer disagree.
         *
         * capturedFils() rather than `total` also fixes the partial-capture
         * case: an order captured for less than its total and then refunded in
         * full was described as partial, because the refund could never reach a
         * total that was never taken.
         *
         * THE FALLBACK TO `total` IS LOAD-BEARING, and it is what cash on
         * delivery needs. capturedFils() answers 0 for an order with no
         * captured_total and no paid_at — which is every COD order, because
         * CashOnDelivery::start() moves the order on without marking it paid,
         * and nothing was ever taken through a gateway to capture. Compared
         * against a ceiling of 0, ANY refund reads as "fully refunded" and a 50
         * dirham goodwill refund on a 235 dirham COD order would drop the word
         * partial from an email that is entirely about a partial refund. So a
         * ceiling of zero means "nothing was captured through a gateway", not
         * "nothing is owed", and the order's own total is the honest ceiling
         * there — the same figure the customer handed over at the door.
         */
        $refunder = app(\App\Services\Payments\PaymentRefunder::class);

        $ceiling = $refunder->capturedFils($order) ?: (int) $order->total;

        $this->isPartial = $refunder->refundedFils($order) < $ceiling;

        $this->settledByGateway = trim((string) $refund->provider_ref) !== '';
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Refund sent for your K Beauty Bliss order ' . $this->orderNumber(),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.order-refunded',
            text: 'emails.order-refunded-text',
        );
    }
}
