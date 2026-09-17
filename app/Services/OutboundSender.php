<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\BackInStockAlert;
use App\Mail\CartRecoveryReminder;
use App\Services\Mail\MailConfigurator;
use App\Services\Mail\MailLog;
use App\Support\OutboundOptOut;
use App\Support\Url;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * The only thing that hands a back-in-stock alert or a basket reminder to a
 * transport (Lane EN).
 *
 * Separate from StockAlerts and CartRecovery on purpose. Those two decide WHO
 * is owed a message and enforce that nobody is owed one twice; this decides
 * what the message says and gives it to the mailer. Keeping them apart means
 * the claim logic — the part that must never be wrong — can be read without
 * any of the rendering, and it means a test can exercise a claim without a mail
 * fake and a send without a clock.
 *
 * ── THE ORDER OF OPERATIONS, WHICH IS THE WHOLE SAFETY ARGUMENT ────────────
 *
 * CLAIM, THEN SEND. Never the other way round and never "send, then mark". A
 * sender that mailed first and recorded afterwards would double-send every time
 * the process died between the two, and this host is shared hosting where a
 * request can be killed mid-flight for reasons nobody here controls.
 *
 * The cost of that ordering is stated rather than hidden: IF THE TRANSPORT
 * FAILS, THE MESSAGE IS NOT RETRIED. The row is already spent. That is the
 * deliberate trade — a retry is a second chance to double-send, and "nothing
 * sends twice" is the harder guarantee. The failure is not silent: MailLog
 * opens its row BEFORE the transport is called and closes it afterwards, so a
 * send that threw leaves a `failed` row on the Sent mail screen carrying the
 * recipient and the transport's own words. That screen is the answer to "did
 * this customer get it", and it is why the trade is affordable.
 *
 * ── EVERY SEND IS LABELLED ─────────────────────────────────────────────────
 *
 * MailLog cannot tell an order confirmation from a basket reminder by the time
 * Symfony has a Message — all that is left is the subject line, which is
 * owner-editable wording. labelNext() is how a sender names itself, and an
 * unlabelled sender is recorded as 'unknown'. Both kinds here label themselves,
 * so the owner's Sent mail screen can group them and a test can assert on them.
 *
 * ── NOTHING HERE MAY THROW ─────────────────────────────────────────────────
 *
 * This runs inside a deferred callback on an ordinary storefront request. An
 * exception escaping it would surface as a 500 on a page that had already been
 * rendered and sent, for a shopper who has nothing to do with the message. So
 * each send is wrapped individually — individually, not as a batch, because one
 * bad address must not stop the other nine messages in the tick.
 */
class OutboundSender
{
    public function __construct(
        private StockAlerts $alerts,
        private CartRecovery $recovery,
        private MailLog $log,
    ) {}

    /**
     * Send up to $budget back-in-stock alerts. Returns how many went.
     *
     * The wording is read ONCE, before the loop, and a null return means the
     * owner has not written the message — in which case nothing is claimed.
     * That ordering matters: claiming rows and then discovering there is
     * nothing to say would spend every shopper's one alert on an email that was
     * never sent.
     */
    public function sendStockAlerts(int $budget): int
    {
        $wording = $this->alerts->messageWording();

        if ($wording === null || $budget < 1) {
            return 0;
        }

        $sent = 0;

        foreach ($this->alerts->due($budget) as $row) {
            // The claim is the gate. Anything that fails it belongs to another
            // process and this one must not look at it again.
            if (! $this->alerts->claim((int) $row->id)) {
                continue;
            }

            try {
                $mailable = new BackInStockAlert(
                    $wording['subject'],
                    $wording['body'],
                    (string) $row->product_name,
                    Url::redirect('/product/' . (string) $row->product_slug . '/'),
                    OutboundOptOut::link('stock', (int) $row->id, (string) $row->email),
                );

                $this->log->labelNext('stock.back');

                Mail::mailer(MailConfigurator::MAILER)
                    ->to((string) $row->email)
                    ->send($mailable);

                $sent++;
            } catch (\Throwable $e) {
                $this->fail('back-in-stock alert', (int) $row->id, $e);
            }
        }

        return $sent;
    }

    /**
     * Send up to $budget basket reminders. Returns how many went.
     */
    public function sendCartReminders(int $budget): int
    {
        $wording = $this->recovery->messageWording();

        if ($wording === null || $budget < 1) {
            return 0;
        }

        $sent = 0;

        foreach ($this->recovery->due($budget) as $row) {
            $id = (int) $row->id;

            if (! $this->recovery->claim($id, (int) $row->stage)) {
                continue;
            }

            try {
                /*
                 * Built BEFORE the last look, so that the gap between the check
                 * and the handoff is as small as it can be made. Rendering a
                 * mailable reads the cart, the settings and the branding; doing
                 * that after the check would put every one of those queries
                 * inside the window this check exists to shrink.
                 */
                $mailable = new CartRecoveryReminder(
                    $wording['subject'],
                    $wording['body'],
                    $this->recovery->basket((int) $row->cart_id),
                    Url::redirect('/cart'),
                    OutboundOptOut::link('cart', $id, (string) $row->email),
                );

                /*
                 * THE LAST LOOK. Barrier 3 in CartRecovery's header: did they
                 * place the order while this message was being prepared? The
                 * claim is not given back — the row is cancelled and a
                 * cancelled row can never claim again.
                 */
                if (! $this->recovery->sendable($id)) {
                    continue;
                }

                $this->log->labelNext('cart.recovery');

                Mail::mailer(MailConfigurator::MAILER)
                    ->to((string) $row->email)
                    ->send($mailable);

                $sent++;
            } catch (\Throwable $e) {
                $this->fail('cart recovery reminder', $id, $e);
            }
        }

        return $sent;
    }

    /**
     * A send that threw, recorded twice and swallowed.
     *
     * The row id and the exception CLASS only. Not the message — a mail
     * transport puts the recipient and sometimes the body in it — and above all
     * not the unsubscribe link, which would be a permanent second copy of a
     * live token in a log file. Store\SubscribeController and
     * CustomerPasswordReset both make the same rule.
     */
    private function fail(string $what, int $id, \Throwable $e): void
    {
        try {
            $this->log->recordFailure($e);
        } catch (\Throwable) {
            // A recorder that cannot record must not become the failure.
        }

        Log::warning('A ' . $what . ' could not be sent.', [
            'row_id' => $id,
            'exception' => $e::class,
        ]);
    }
}
