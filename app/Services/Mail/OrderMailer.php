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

        $enabled = match ($status) {
            'shipped' => $this->shippedEnabled(),
            'cancelled' => $this->cancelledEnabled(),
            default => false,
        };

        if (! $enabled) {
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
