<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Mail\NewOrderAlert;
use App\Mail\OrderConfirmation;
use App\Mail\OrderRefunded;
use App\Mail\OrderStatusChanged;
use App\Models\Order;
use App\Models\Refund;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Every order email this store sends, and the one rule that governs all of them.
 *
 * THE RULE: PLACING AN ORDER MUST NEVER FAIL BECAUSE EMAIL FAILED.
 *
 * This is not a general preference for robustness. The store runs on shared
 * hosting through the host's own SMTP, and the ways that goes wrong are ordinary:
 * the relay refuses the From address, the mailbox password expires, outbound 465
 * is silently dropped so the connection hangs until the 20-second timeout in
 * MailConfigurator fires. Each of those throws a Symfony TransportException. If
 * one of them reached CheckoutController::place() the shopper would see a 500 on
 * a card that had already been charged — and this project has already had a
 * checkout outage (the missing $giftFee in the transaction closure) that nothing
 * caught until a test finally POSTed to the endpoint.
 *
 * So every send in this class is wrapped. A failure is logged with the order
 * number and the exception class, and the caller is told nothing, because there
 * is nothing the caller could usefully do: the order is placed, the money is
 * taken, and a missing email is a support question rather than a failed sale.
 *
 * NOTHING IS QUEUED, AND NOTHING MAY BE. There is no queue worker on this host.
 * A queued Mailable would be written to the jobs table and never sent, which is
 * worse than a logged failure because it looks like success. The two marketing
 * modules that need a worker (`abandoned_cart`, `back_in_stock`) are still marked
 * `todo` in the registry for exactly this reason.
 *
 * THE SWITCHES ARE READ LITERALLY, NOT BUILT. Each check below spells its module
 * key out in full rather than interpolating one. Phase3ModuleSwitchesTest greps
 * the source for `moduleEnabled('<key>'` to prove that a registry row marked
 * `live` really has a reader, and a key assembled at runtime would defeat that
 * check — which is the same class of fault as a toggle that silently does
 * nothing, three of which this project has already shipped.
 */
class OrderMailer
{
    /**
     * Where a merchant alert goes when the owner has not named an address.
     *
     * The From address, because on this host it must already be a real mailbox on
     * the domain — a shared host rejects anything else — so it is the one address
     * we know exists and can receive. Better than dropping the alert silently.
     */
    public const MERCHANT_FALLBACK_KEY = 'mail_from_address';

    public function __construct(
        private SettingsService $settings,
        private MailSettings $mail,
    ) {}

    // ------------------------------------------------------------------
    // The switches. One literal key each — see the class header.
    // ------------------------------------------------------------------

    public function confirmationEnabled(): bool
    {
        return $this->settings->moduleEnabled('email_order_confirmation', true);
    }

    public function merchantAlertEnabled(): bool
    {
        return $this->settings->moduleEnabled('email_merchant_new_order', true);
    }

    public function shippedEnabled(): bool
    {
        return $this->settings->moduleEnabled('email_order_shipped', true);
    }

    public function cancelledEnabled(): bool
    {
        return $this->settings->moduleEnabled('email_order_cancelled', true);
    }

    public function refundEnabled(): bool
    {
        return $this->settings->moduleEnabled('email_order_refunded', true);
    }

    /**
     * Which status changes email, and the per-order exception to it.
     *
     * Resolved lazily rather than injected, because the policy resolves THIS
     * class back — it asks shippedEnabled() and cancelledEnabled() above rather
     * than reading the two keys a second time, so that each module key keeps
     * exactly one reader in this application. Two scoped services that
     * construct each other would not resolve; two scoped services that fetch
     * each other on use resolve fine, and both are the request's own instance,
     * which is what carries the operator's per-order decision from the
     * controller to the observer.
     */
    private function statusPolicy(): OrderStatusMailPolicy
    {
        return app(OrderStatusMailPolicy::class);
    }

    // ------------------------------------------------------------------
    // The sends
    // ------------------------------------------------------------------

    /**
     * An order has just been placed: receipt the customer, tell the store.
     *
     * Called from CheckoutController::place() AFTER the database transaction has
     * committed and after the gateway has accepted the order. Not before either:
     * inside the transaction a mail failure would roll the order back, and before
     * the gateway a declined payment would have receipted an order that does not
     * exist.
     *
     * The two sends are independent. A merchant address that bounces must not cost
     * the customer their receipt, so each is attempted in its own guard.
     */
    public function placed(Order $order): void
    {
        /*
         * The outermost guard, and the reason it is here rather than only around
         * each send: CheckoutController calls this method directly, so anything
         * that throws BEFORE the per-send try — reading the module toggles when
         * the cache store is broken, loading the items relation, resolving the
         * merchant address — would surface as a 500 on a checkout whose order is
         * already placed and whose payment may already be taken.
         */
        try {
            $this->sendPlaced($order);
        } catch (\Throwable $e) {
            Log::error('order mail failed before sending', [
                'order' => $order->order_number,
                'kind' => 'placed',
                'exception' => class_basename($e),
                'message' => $this->redact($e->getMessage()),
            ]);
        }
    }

    private function sendPlaced(Order $order): void
    {
        $order->loadMissing('items');

        if ($this->confirmationEnabled()) {
            $this->send(static fn () => new OrderConfirmation($order), (string) $order->email, $order, 'confirmation');
        }

        if ($this->merchantAlertEnabled()) {
            $this->send(static fn () => new NewOrderAlert($order), $this->merchantAddress(), $order, 'merchant_alert');
        }
    }

    /**
     * Send the customer's receipt again, on purpose, from the admin.
     *
     * NOT placed(): that also fires the merchant alert, and a second "new
     * order" landing in the owner's inbox because they re-sent a customer's
     * receipt is a lie about what happened.
     *
     * And unlike every other method here, this one REPORTS. The swallowing in
     * placed() exists because a dead SMTP host must not take down a checkout
     * whose payment is already taken; nothing is waiting on the answer. Here a
     * person pressed a button and is owed the truth — a button that says
     * "Sent" whatever happened is worse than one that refuses.
     *
     * The module switch is honoured: if the owner has turned confirmations off,
     * a resend is refused rather than quietly overriding their setting.
     *
     * @return array{ok: bool, message: string}
     */
    public function resendConfirmation(Order $order): array
    {
        if (! $this->confirmationEnabled()) {
            return [
                'ok' => false,
                'message' => 'Order confirmation emails are switched off in Store → Modules → Order emails.',
            ];
        }

        $to = trim((string) $order->email);

        if ($to === '') {
            return ['ok' => false, 'message' => 'This order has no email address on it.'];
        }

        try {
            $order->loadMissing('items');

            Mail::mailer(MailConfigurator::MAILER)
                ->to($to)
                ->send(new OrderConfirmation($order));

            return ['ok' => true, 'message' => 'Confirmation re-sent to ' . $to . '.'];
        } catch (\Throwable $e) {
            Log::error('resend confirmation failed', [
                'order' => $order->order_number,
                'exception' => class_basename($e),
                'message' => $this->redact($e->getMessage()),
            ]);

            return [
                'ok' => false,
                // The driver's own words, redacted of the SMTP password, because
                // "it failed" sends the owner to a log file they cannot read on
                // shared hosting.
                'message' => 'Could not send: ' . $this->redact($e->getMessage()),
            ];
        }
    }

    /**
     * Send this order's invoice to the customer, on purpose, from the admin.
     *
     * The second method in this class that REPORTS rather than swallows, and for
     * the same reason resendConfirmation() gives: the swallowing everywhere else
     * exists because a dead SMTP host must not take down a checkout whose payment
     * is already taken, and nothing is waiting on the answer. Here a person
     * pressed "Email invoice" and is owed the truth. A button that says "Sent"
     * whatever happened is worse than one that refuses.
     *
     * THE NUMBER IS ALLOCATED BEFORE THE SEND, NOT AFTER. An invoice with no
     * number on it is not an invoice, and a customer who receives one and then
     * receives a second copy carrying a number has been sent two documents for
     * one debt. Allocation is idempotent and race-safe (see InvoiceNumbers), so
     * emailing an order that has already been printed re-sends THAT invoice
     * rather than minting a new one — the emailed document and the printed one
     * are the same document, by number and by figure.
     *
     * If the send fails the number is NOT rolled back, deliberately. It has been
     * issued; it belongs to this order now. Releasing it would mean the next
     * order could be given a number that a half-delivered email may already be
     * carrying, which is the one thing an invoice sequence may never do.
     *
     * NO MODULE SWITCH IS CONSULTED, and that is a decision rather than an
     * oversight. The five keys above guard emails the store sends BY ITSELF, and
     * a toggle exists so the owner can stop them happening without being asked.
     * This one only ever happens because the owner asked for it, one order at a
     * time, from a screen they are looking at. A switch whose only effect is to
     * make a button the owner just pressed refuse is not a setting, it is a
     * trap. (ModuleRegistry belongs to another lane; adding a row there is the
     * integrator's call if the owner ever wants one.)
     *
     * @return array{ok: bool, message: string, invoice_number?: int}
     */
    public function emailInvoice(Order $order): array
    {
        $to = trim((string) $order->email);

        if ($to === '') {
            return ['ok' => false, 'message' => 'This order has no email address on it.'];
        }

        try {
            $number = app(\App\Services\Invoices\InvoiceNumbers::class)->allocate($order);
        } catch (\Throwable $e) {
            Log::error('invoice number allocation failed', [
                'order' => $order->order_number,
                'exception' => class_basename($e),
                'message' => $this->redact($e->getMessage()),
            ]);

            return [
                'ok' => false,
                'message' => 'Could not allocate an invoice number for this order: ' . $this->redact($e->getMessage()),
            ];
        }

        try {
            // Re-read before rendering: under contention the number written may
            // be another request's, and the document must print what the
            // database holds rather than what this process hoped to write.
            $order->refresh();
            $order->loadMissing('items');

            Mail::mailer(MailConfigurator::MAILER)
                ->to($to)
                ->send(new \App\Mail\OrderInvoice($order));

            return [
                'ok' => true,
                'message' => 'Invoice ' . \App\Services\Invoices\InvoiceNumbers::format($number)
                    . ' sent to ' . $to . '.',
                'invoice_number' => $number,
            ];
        } catch (\Throwable $e) {
            Log::error('email invoice failed', [
                'order' => $order->order_number,
                'invoice_number' => $number,
                'exception' => class_basename($e),
                'message' => $this->redact($e->getMessage()),
            ]);

            return [
                'ok' => false,
                // The driver's own words, redacted of the SMTP password, because
                // "it failed" sends the owner to a log file they cannot read on
                // shared hosting.
                'message' => 'Could not send: ' . $this->redact($e->getMessage()),
                'invoice_number' => $number,
            ];
        }
    }

    /**
     * The order's status column changed to something the customer is owed a
     * message about.
     *
     * OrderStatusChanged::WORDING is the closed list, and it explains which
     * statuses are deliberately silent. Anything not on it returns here without
     * sending, rather than inventing wording for a status nobody designed.
     */
    public function statusChanged(Order $order, string $status): void
    {
        if (! OrderStatusChanged::handles($status)) {
            return;
        }

        /*
         * THE ONE GATE, AND THE ONE PLACE IT MAY LIVE.
         *
         * `orders.status` is written from five places, and one of them —
         * OrdersApiController::bulkStatus — is a query-builder `update()` that
         * fires no model events at all. That is how bulk status changes once
         * emailed nobody in this store: marking one order shipped from the
         * detail screen sent an email, marking forty from the list sent none,
         * with no error and no difference on screen. A per-status switch
         * implemented in the order-detail controller would have exactly that
         * hole, and it would be invisible in exactly the same way.
         *
         * So it is asked HERE, in the method both the observer and the bulk
         * path already call, and every writer is governed by it whether or not
         * it knows OrderStatusMailPolicy exists.
         *
         * The policy folds together the standing per-status rule (the module
         * switches read literally below, which is what Phase3ModuleSwitchesTest
         * greps for) and the operator's decision about THIS order, if they took
         * one on the screen they were looking at.
         */
        if (! $this->statusPolicy()->shouldNotify($order, $status)) {
            return;
        }

        $order->loadMissing('items');

        $this->send(
            static fn () => new OrderStatusChanged($order, $status),
            (string) $order->email,
            $order,
            'status_' . $status,
        );
    }

    /**
     * Money has actually gone back.
     *
     * Driven by the refunds row settling, never by orders.status — see
     * App\Mail\OrderRefunded for why those are not the same event.
     */
    public function refunded(Order $order, Refund $refund): void
    {
        if (! $this->refundEnabled()) {
            return;
        }

        $order->loadMissing('items');

        $this->send(static fn () => new OrderRefunded($order, $refund), (string) $order->email, $order, 'refund');
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * The store's own notification address.
     *
     * A dedicated setting first (Store → Mail), falling back to the From address.
     * Both are trimmed and checked for an @ before use: a half-filled form must
     * produce a skipped send and a log line, never a transport exception on the
     * checkout path.
     */
    public function merchantAddress(): string
    {
        $configured = trim((string) $this->mail->get('mail_merchant_address'));

        if ($configured === '') {
            $configured = trim((string) $this->mail->get(self::MERCHANT_FALLBACK_KEY));
        }

        return $configured;
    }

    /**
     * One send, and the only place a mail exception may be swallowed.
     *
     * THE MAILABLE IS BUILT INSIDE THE TRY, which is why this takes a factory
     * rather than a Mailable. Constructing one runs OrderEmailPresenter over the
     * order — relations, casts, money formatting — and none of that is
     * guaranteed not to throw on a half-imported order with a null address or a
     * line whose product vanished. A receipt that cannot be built must cost the
     * checkout exactly as little as a receipt that cannot be delivered: nothing.
     *
     * Mail::mailer() names the store's own mailer explicitly rather than relying
     * on the default, so this keeps working if MAIL_MAILER is ever set in the
     * environment for some other purpose. MailServiceProvider fills that mailer
     * in from the database on first resolution of the mail manager.
     *
     * The log line carries the order number, the kind of email and the exception
     * class and message. It does NOT carry the body, the recipient's other
     * details, or anything from the mail configuration: MailTester's redact()
     * exists because Symfony transport exceptions quote the DSN, password
     * included, and a log written on every checkout is the last place for that.
     * Only the transport's message is recorded, with the configured password
     * stripped out of it the same way.
     */
    private function send(callable $build, string $to, Order $order, string $kind): void
    {
        $to = trim($to);

        if ($to === '' || ! str_contains($to, '@')) {
            Log::warning('order mail skipped: no recipient', [
                'order' => $order->order_number,
                'kind' => $kind,
            ]);

            return;
        }

        try {
            $mailable = $build();

            Mail::mailer(MailConfigurator::MAILER)->to($to)->send($mailable);
        } catch (\Throwable $e) {
            Log::error('order mail failed', [
                'order' => $order->order_number,
                'kind' => $kind,
                'exception' => class_basename($e),
                'message' => $this->redact($e->getMessage()),
            ]);
        }
    }

    /**
     * Keep the SMTP password out of the log.
     *
     * The same removal MailTester::redact() performs, for the same reason: an
     * AUTH failure can echo the credential back and a transport exception quotes
     * the DSN. Both the literal and the base64 form are stripped, because both
     * have been seen in a mailer exception message.
     */
    private function redact(string $message): string
    {
        /*
         * Itself guarded. This runs on the failure path, and the failure being
         * handled may well be "the mail_credentials table is not there" — a
         * redactor that throws while redacting would turn a logged mail failure
         * back into the 500 this whole class exists to prevent.
         */
        try {
            $password = $this->mail->password();
        } catch (\Throwable) {
            return trim($message);
        }

        if ($password !== '') {
            $message = str_replace(
                [$password, rawurlencode($password), base64_encode($password)],
                '[password redacted]',
                $message,
            );
        }

        return trim($message);
    }
}
