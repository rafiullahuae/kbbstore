<?php

declare(strict_types=1);

namespace App\Services\Payments\Gateways;

use App\Models\Order;
use App\Services\Payments\HandlesWebhooks;
use App\Services\Payments\PaymentStart;
use App\Services\Payments\Reconciliation\ListsTransactions;
use App\Services\Payments\Reconciliation\ReconcileWindow;
use App\Services\Payments\Reconciliation\RemotePage;
use App\Services\Payments\Reconciliation\RemoteTxn;
use App\Services\Payments\SettlementResult;
use App\Services\Payments\SettlesPayments;
use App\Services\Payments\Signature;
use App\Services\Payments\WebhookOutcome;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Tabby — buy now, pay later.
 *
 * Shapes taken from the merchant's working WooCommerce plugin, which is the
 * only reliable description of this API; the implementation is our own.
 *
 *   POST /api/v2/checkout          create a session, get a hosted web_url
 *   GET  /api/v2/payments/{id}     the authoritative state of a payment
 *   POST /api/v1/payments/{id}/captures   take the money after authorisation
 *   POST /api/v1/payments/{id}/refunds    give some or all of it back
 *   Note the version split: reads are v2, settlement writes are v1.
 *   Auth: Authorization: Bearer <secret_key>, plus X-Merchant-Code per country
 *   Statuses: CREATED, AUTHORIZED, CLOSED, REJECTED, EXPIRED
 *   Amounts: decimal strings in major units
 *
 * ---------------------------------------------------------------------------
 * How the webhook is trusted, which is the part that matters
 *
 * Tabby's own plugin registers its webhook endpoint with
 * `permission_callback => '__return_true'` — the callback is completely
 * unauthenticated — and then re-fetches the payment from the API before
 * believing anything. That is the right instinct and it is what this does too,
 * but on its own it makes the endpoint a free oracle: anyone can POST an id
 * and make us call Tabby.
 *
 * So there are two independent checks here, and BOTH must pass:
 *
 *   1. A shared secret in the URL, compared with hash_equals. It is generated
 *      once, stored in the encrypted config, and is what makes the endpoint
 *      address unguessable rather than merely obscure. This is what rejects an
 *      unsigned or wrongly-signed request.
 *   2. The payment is re-fetched from Tabby over an authenticated GET, and the
 *      amount, currency, reference and status in THAT response — never the
 *      ones in the POST body — decide whether the order is paid.
 *
 * Point 2 is why a forged body cannot mark an order paid even if the secret
 * ever leaked: the body is used for exactly one thing, reading the payment id
 * to go and ask about.
 */
class TabbyGateway extends RemoteGateway implements HandlesWebhooks, ListsTransactions, SettlesPayments
{
    private const API = 'https://api.tabby.ai';

    /** Tabby operates in these markets, and only these. */
    private const COUNTRIES = ['AE', 'SA', 'BH', 'KW', 'QA'];

    /**
     * Default days an AUTHORIZED payment stays capturable.
     *
     * A default, not a constant of the API: the merchant's own plugin reads a
     * per-account `order_timeout` and Tabby sets the real figure per merchant
     * agreement, so this is overridable from the payments screen. Whatever the
     * number, it is advisory here — capture() re-reads the payment's live
     * status and Tabby's own answer is what decides.
     */
    private const DEFAULT_CAPTURE_DAYS = 30;

    public function id(): string
    {
        return 'tabby';
    }

    public function title(): string
    {
        return 'Pay in 4 with Tabby';
    }

    public function configured(): bool
    {
        return $this->credentials->filled($this->id(), 'public_key', 'secret_key');
    }

    public function description(int $totalFils): ?string
    {
        return 'Split into 4 interest-free payments. No fees, no interest.';
    }

    public function availableFor(int $totalFils, ?string $country = null): bool
    {
        if ($totalFils <= 0) {
            return false;
        }

        // Tabby only operates in the Gulf. Offering it to a shopper it will
        // certainly decline wastes a redirect and looks like our bug.
        return $country === null || in_array(strtoupper($country), self::COUNTRIES, true);
    }

    public function configSchema(): array
    {
        return [
            'public_key' => ['text', 'Public key', 'Starts pk_test_ on sandbox, pk_ live. Safe to appear in the page.'],
            'secret_key' => ['secret', 'Secret key', 'Starts sk_test_ on sandbox, sk_ live. Never leaves the server.'],
            'merchant_code' => ['text', 'Merchant code', 'The country code Tabby issued the account under — AE for this store.'],
            'webhook_secret' => ['secret', 'Webhook secret', 'Generated for you. It forms part of the webhook URL below; regenerate it by clearing this field and saving.'],
            'capture_days' => ['text', 'Capture window (days)', 'How long Tabby leaves an authorisation capturable on your account. Default 30. Used only to warn you before it lapses — Tabby itself decides.'],
        ];
    }

    protected function baseUrl(): string
    {
        return self::API;
    }

    protected function authHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->credentials->get($this->id(), 'secret_key'),
            'X-Merchant-Code' => $this->merchantCode(),
        ];
    }

    private function merchantCode(): string
    {
        $code = strtoupper($this->credentials->get($this->id(), 'merchant_code'));

        return $code !== '' ? $code : 'AE';
    }

    /* ------------------------------------------------------------- checkout */

    public function start(Order $order): PaymentStart
    {
        if (! $this->configured()) {
            return PaymentStart::failed('Tabby is not available right now.');
        }

        $result = $this->call('POST', '/api/v2/checkout', [
            'payment' => [
                'amount' => $this->toMajor((int) $order->total),
                'currency' => strtoupper((string) ($order->currency ?: 'AED')),
                'description' => 'Order ' . $this->reference($order),
                'buyer' => $this->buyer($order),
                'order' => [
                    'reference_id' => $this->reference($order),
                    'shipping_amount' => $this->toMajor((int) $order->shipping_total),
                    'discount_amount' => $this->toMajor((int) $order->discount_total),
                    'tax_amount' => $this->toMajor((int) $order->tax_total),
                    'items' => $this->items($order),
                ],
            ],
            'lang' => 'en',
            'merchant_code' => $this->merchantCode(),
            'merchant_urls' => [
                'success' => $this->returnUrl($order, 'success'),
                'cancel' => $this->returnUrl($order, 'cancel'),
                'failure' => $this->returnUrl($order, 'failure'),
            ],
        ]);

        if ($result === null || ($result['status'] ?? null) !== 'created') {
            return PaymentStart::failed('We could not reach Tabby. Please try another payment method.');
        }

        // The hosted URL sits under the first available product. When Tabby
        // has pre-declined this buyer the list is empty -- that is a decline,
        // not an outage, and the shopper is told to pick something else.
        $products = $result['configuration']['available_products'] ?? [];
        $url = null;

        foreach (is_array($products) ? $products : [] as $offers) {
            $url = $offers[0]['web_url'] ?? null;

            if (is_string($url) && $url !== '') {
                break;
            }
        }

        if (! is_string($url) || $url === '') {
            return PaymentStart::failed('Tabby is not available for this order. Please choose another payment method.');
        }

        $paymentId = (string) ($result['id'] ?? '');

        $order->forceFill(['transaction_id' => $paymentId])->save();

        $this->log('checkout session created', '/api/v2/checkout', 200, ['reference' => $this->reference($order)]);

        return PaymentStart::redirect($url, $paymentId);
    }

    /* -------------------------------------------------------------- webhook */

    public function handleWebhook(Request $request): WebhookOutcome
    {
        // (1) Shared secret, constant-time. Before anything is parsed, before
        //     the database is touched, before a line is logged.
        $expected = $this->credentials->get($this->id(), 'webhook_secret');

        if ($expected === '' || ! Signature::equals($expected, (string) $request->route('secret'))) {
            return WebhookOutcome::rejected();
        }

        $paymentId = (string) ($request->input('id') ?? '');

        if ($paymentId === '') {
            return WebhookOutcome::refused('no payment id');
        }

        // (2) Ask Tabby what actually happened. The POST body is used for the
        //     id and nothing else -- every figure below comes from this call.
        $payment = $this->call('GET', '/api/v2/payments/' . urlencode($paymentId));

        if ($payment === null) {
            // Could not verify. 503 so Tabby retries rather than us guessing.
            return WebhookOutcome::failed('could not verify payment with Tabby');
        }

        $order = $this->findOrderByReference($payment['order']['reference_id'] ?? null);

        if ($order === null) {
            return WebhookOutcome::refused('no order for that reference');
        }

        $status = strtoupper((string) ($payment['status'] ?? ''));

        $summary = [
            'payment_id' => $paymentId,
            'status' => $status,
            'reference' => $payment['order']['reference_id'] ?? null,
        ];

        if (in_array($status, ['REJECTED', 'EXPIRED'], true)) {
            return $this->confirmer->fail($order, $this->id(), $paymentId, strtolower($status), $summary);
        }

        if (! in_array($status, ['AUTHORIZED', 'CLOSED'], true)) {
            // CREATED means the shopper has not finished. Nothing to do, and
            // nothing wrong -- a 200 so it is not retried forever.
            return WebhookOutcome::ignored('payment not authorised yet');
        }

        return $this->confirmer->confirm(
            $order,
            $this->id(),
            $paymentId,
            $this->toFils($payment['amount'] ?? 0),
            (string) ($payment['currency'] ?? ''),
            $summary,
        );
    }

    /* ----------------------------------------------------------- settlement */

    public function captureWindow(): string
    {
        return sprintf(
            'Within about %d days of authorisation. Tabby auto-voids an authorisation that is never captured, and the money is then gone.',
            $this->captureWindowDays(),
        );
    }

    public function captureWindowDays(): ?int
    {
        $configured = (int) $this->credentials->get($this->id(), 'capture_days', (string) self::DEFAULT_CAPTURE_DAYS);

        return $configured > 0 ? $configured : self::DEFAULT_CAPTURE_DAYS;
    }

    /**
     * Take the authorised money.
     *
     * `POST /api/v1/payments/{id}/captures` — v1, not the v2 the checkout and
     * the webhook read use. Tabby splits its API that way and a capture posted
     * to v2 is a 404, so the version is spelled out here rather than inherited
     * from baseUrl().
     *
     * The status is re-read from Tabby first, over an authenticated GET, and
     * that answer decides:
     *
     *   AUTHORIZED  capturable. Capture it.
     *   CLOSED      already captured. A success, not an error — this is what a
     *               retry after a timeout hits, and treating it as a failure
     *               is how a merchant ends up capturing twice by hand.
     *   anything    REJECTED, EXPIRED or still CREATED. Nothing to take, and
     *   else        the reason is recorded rather than guessed at.
     */
    public function capture(Order $order, int $amountFils): SettlementResult
    {
        $paymentId = trim((string) $order->transaction_id);

        if (! $this->configured() || $paymentId === '') {
            return SettlementResult::failed(
                'not_configured',
                ['provider' => $this->id()],
                'Tabby is not configured, or this order has no Tabby payment on it.',
            );
        }

        $payment = $this->call('GET', '/api/v2/payments/' . urlencode($paymentId));

        if ($payment === null) {
            return SettlementResult::failed(
                'unreachable',
                ['provider' => $this->id(), 'payment_id' => $paymentId],
                'Tabby could not be reached. Nothing was captured; try again.',
            );
        }

        $status = strtoupper((string) ($payment['status'] ?? ''));

        if ($status === 'CLOSED') {
            $existing = $this->lastId($payment['captures'] ?? null);

            return SettlementResult::ok(
                'already_captured',
                $existing,
                ['provider' => $this->id(), 'payment_id' => $paymentId, 'status' => $status],
                'Tabby had already captured this payment.',
            );
        }

        if ($status !== 'AUTHORIZED') {
            return SettlementResult::failed(
                'not_authorised',
                ['provider' => $this->id(), 'payment_id' => $paymentId, 'status' => $status],
                'Tabby reports this payment as ' . ($status !== '' ? strtolower($status) : 'unknown') . ', so there is nothing to capture.',
            );
        }

        $attempt = $this->attempt('POST', '/api/v1/payments/' . urlencode($paymentId) . '/captures', [
            'amount' => $this->toMajor($amountFils),
            'tax_amount' => $this->toMajor((int) $order->tax_total),
            'shipping_amount' => $this->toMajor((int) $order->shipping_total),
            'items' => $this->items($order),
        ]);

        $captureId = $attempt['ok'] ? $this->lastId($attempt['body']['captures'] ?? null) : null;

        if (! $attempt['ok'] || $captureId === null) {
            return SettlementResult::failed(
                $attempt['error'] ?? 'capture_rejected',
                [
                    'provider' => $this->id(),
                    'payment_id' => $paymentId,
                    'http_status' => $attempt['status'],
                    'error' => $attempt['error'],
                ],
                'Tabby refused the capture. Nothing was taken.',
            );
        }

        return SettlementResult::ok(
            'captured',
            $captureId,
            ['provider' => $this->id(), 'payment_id' => $paymentId, 'capture_id' => $captureId],
            'Captured through Tabby.',
        );
    }

    /**
     * Refund some or all of a capture.
     *
     * `POST /api/v1/payments/{id}/refunds`, and `capture_id` is not optional:
     * Tabby refunds against a capture, not against a payment, so a refund
     * attempted on an authorisation that was never captured has nothing to
     * point at. That is reported as its own code rather than as a generic
     * failure, because the fix is "capture it first" and no other message
     * says so.
     */
    public function refund(
        Order $order,
        int $amountFils,
        ?string $reason,
        ?string $captureRef,
        ?string $idempotencyKey = null,
    ): SettlementResult
    {
        $paymentId = trim((string) $order->transaction_id);

        if (! $this->configured() || $paymentId === '') {
            return SettlementResult::failed(
                'not_configured',
                ['provider' => $this->id()],
                'Tabby is not configured, or this order has no Tabby payment on it.',
            );
        }

        $captureId = trim((string) ($order->capture_ref ?? ''));

        if ($captureId === '') {
            return SettlementResult::failed(
                'not_captured',
                ['provider' => $this->id(), 'payment_id' => $paymentId],
                'This Tabby payment has not been captured, so there is nothing to refund yet. Capture it first.',
            );
        }

        $attempt = $this->attempt('POST', '/api/v1/payments/' . urlencode($paymentId) . '/refunds', [
            'capture_id' => $captureId,
            'amount' => $this->toMajor($amountFils),
            'reason' => $reason !== null && trim($reason) !== '' ? trim($reason) : 'Merchant refund',
        ]);

        $refundId = $attempt['ok'] ? $this->lastId($attempt['body']['refunds'] ?? null) : null;

        if (! $attempt['ok'] || $refundId === null) {
            return SettlementResult::failed(
                $attempt['error'] ?? 'refund_rejected',
                [
                    'provider' => $this->id(),
                    'payment_id' => $paymentId,
                    'http_status' => $attempt['status'],
                    'error' => $attempt['error'],
                ],
                'Tabby refused the refund. Nothing has been returned to the customer.',
            );
        }

        return SettlementResult::ok(
            'refunded',
            $refundId,
            ['provider' => $this->id(), 'payment_id' => $paymentId, 'refund_id' => $refundId],
            'Refunded through Tabby.',
        );
    }

    /* -------------------------------------------------------- reconciliation */

    /**
     * Tabby's books, a page at a time.
     *
     *   GET /api/v2/payments?created_at__gte=&created_at__lte=&offset=&limit=
     *
     * REFUNDS COME OUT OF THE SAME LIST, and that is not a shortcut. Tabby does
     * not keep refunds anywhere else: a refund is an entry in the `refunds[]`
     * array of the payment it reverses, exactly as a capture is an entry in
     * `captures[]` — which is why refund() has to send a `capture_id` and why
     * lastId() reads the whole array back. Inventing a `/refunds` endpoint to
     * make this symmetrical with Stripe would be inventing an endpoint, and a
     * reconciliation whose refund half 404s is a reconciliation that reports
     * every refund this shop has made as unconfirmed.
     *
     * ---------------------------------------------------------------------
     * THE ONE THING HERE THAT IS NOT PROVEN
     *
     * The single-payment read (`GET /api/v2/payments/{id}`) is exercised by
     * the webhook and by capture, and is known good. The LIST form above —
     * the same collection without an id, with a date filter and an offset — is
     * taken from the same v2 API and cannot be verified from this project,
     * which has no Tabby merchant account. It is deliberately confined to this
     * one method for that reason.
     *
     * If it is wrong, the failure is safe and loud rather than quiet and
     * wrong: a 404 or a 401 returns RemotePage::failed(), the run records
     * `payments_source_unavailable` against Tabby, and every conclusion that
     * would have been drawn from Tabby's silence is SKIPPED. What the owner
     * sees is "Tabby's list of payments could not be read", not a clean bill
     * of health and not a page of invented discrepancies. Read RemotePage's
     * class comment; that property is the reason it is shaped the way it is.
     */
    public function listRemotePayments(ReconcileWindow $window, ?string $cursor, int $limit): RemotePage
    {
        return $this->listTabby($window, $cursor, $limit, RemoteTxn::PAYMENT);
    }

    public function listRemoteRefunds(ReconcileWindow $window, ?string $cursor, int $limit): RemotePage
    {
        return $this->listTabby($window, $cursor, $limit, RemoteTxn::REFUND);
    }

    public function remoteSourceLabel(): string
    {
        return 'api.tabby.ai /api/v2/payments (refunds are nested in each payment)';
    }

    private function listTabby(ReconcileWindow $window, ?string $cursor, int $limit, string $kind): RemotePage
    {
        if (! $this->configured()) {
            return RemotePage::unsupported('Tabby has no keys stored, so its books cannot be read.');
        }

        $limit = max(1, min($limit, 100));
        $offset = $cursor !== null && ctype_digit(trim($cursor)) ? (int) trim($cursor) : 0;

        $attempt = $this->attempt('GET', '/api/v2/payments?' . http_build_query([
            // RFC3339 with an explicit Z. Tabby timestamps in UTC and so does
            // ReconcileWindow, so there is no conversion here and none to get
            // wrong — see that class for why the boundary is padded instead.
            'created_at__gte' => $window->from->toIso8601ZuluString(),
            'created_at__lte' => $window->to->toIso8601ZuluString(),
            'offset' => $offset,
            'limit' => $limit,
        ]));

        if (! $attempt['ok']) {
            return RemotePage::failed($attempt['error'] ?? 'unreachable', $attempt['status']);
        }

        $payments = $this->paymentList($attempt['body']);
        $items = [];

        foreach ($payments as $payment) {
            if (! is_array($payment)) {
                continue;
            }

            if ($kind === RemoteTxn::PAYMENT) {
                $txn = $this->tabbyPaymentToTxn($payment);

                if ($txn !== null) {
                    $items[] = $txn;
                }

                continue;
            }

            foreach ($this->tabbyRefundsToTxns($payment) as $refund) {
                $items[] = $refund;
            }
        }

        /*
         * Offset paging, so "is there more" is "was this page full". The cursor
         * counts PAYMENTS in both modes, never refunds: the refund pass walks
         * the same collection, and advancing by the number of refunds found
         * would skip payments in proportion to how many refunds they happened
         * to carry.
         */
        $more = count($payments) >= $limit;

        return RemotePage::of($items, $more ? (string) ($offset + count($payments)) : null);
    }

    /** Tabby has answered collections as a bare array and as {payments:[…]}. */
    private function paymentList(mixed $body): array
    {
        if (! is_array($body)) {
            return [];
        }

        foreach (['payments', 'data', 'results'] as $key) {
            if (isset($body[$key]) && is_array($body[$key])) {
                return $body[$key];
            }
        }

        return array_is_list($body) ? $body : [];
    }

    private function tabbyPaymentToTxn(array $payment): ?RemoteTxn
    {
        $id = trim((string) ($payment['id'] ?? ''));

        if ($id === '') {
            return null;
        }

        $status = strtoupper((string) ($payment['status'] ?? ''));

        /*
         * CLOSED is Tabby's word for captured — money taken. AUTHORIZED is
         * money committed and not yet taken, which Tabby auto-voids; it is not
         * settled and must not be counted as such. CREATED is a shopper who
         * never finished.
         */
        $state = match ($status) {
            'CLOSED' => RemoteTxn::SETTLED,
            'AUTHORIZED' => RemoteTxn::AUTHORISED,
            default => RemoteTxn::DEAD,
        };

        return new RemoteTxn(
            provider: $this->id(),
            kind: RemoteTxn::PAYMENT,
            remoteId: $id,
            matchKeys: [],
            reference: $this->referenceOf($payment),
            // Major-unit decimal string on this API. Through toFils(), which
            // goes via round() rather than an (int) cast on a float — (int)
            // (10.10 * 100) is 1009, and that is a one-fil discrepancy
            // reported against a perfectly correct order.
            amountFils: $this->toFils($payment['amount'] ?? 0),
            currency: strtoupper((string) ($payment['currency'] ?? '')),
            state: $state,
            rawState: $status,
            createdAt: $this->tabbyMoment($payment['created_at'] ?? null),
        );
    }

    /** @return array<int, RemoteTxn> */
    private function tabbyRefundsToTxns(array $payment): array
    {
        $refunds = $payment['refunds'] ?? null;

        if (! is_array($refunds)) {
            return [];
        }

        $reference = $this->referenceOf($payment);
        $currency = strtoupper((string) ($payment['currency'] ?? ''));
        $out = [];

        foreach ($refunds as $refund) {
            if (! is_array($refund)) {
                continue;
            }

            $id = trim((string) ($refund['id'] ?? ''));

            if ($id === '') {
                continue;
            }

            $out[] = new RemoteTxn(
                provider: $this->id(),
                kind: RemoteTxn::REFUND,
                remoteId: $id,
                matchKeys: [],
                reference: $reference,
                amountFils: $this->toFils($refund['amount'] ?? 0),
                currency: $currency,
                // A refund that appears in Tabby's list is a refund Tabby made.
                // There is no pending state on these entries.
                state: RemoteTxn::SETTLED,
                rawState: 'refunded',
                createdAt: $this->tabbyMoment($refund['created_at'] ?? null),
                // The payment this refund sits inside. Tabby does carry the
                // order reference too, so this is belt as well as braces — but
                // a reference is a string somebody could have changed, and the
                // payment id is what `payments.provider_ref` actually holds.
                parentKeys: [(string) ($payment['id'] ?? '')],
            );
        }

        return $out;
    }

    private function referenceOf(array $payment): ?string
    {
        $reference = $payment['order']['reference_id'] ?? null;

        return is_string($reference) && trim($reference) !== '' ? trim($reference) : null;
    }

    private function tabbyMoment(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (\Throwable) {
            // A timestamp we cannot read is not a reason to drop a transaction
            // out of a reconciliation. It is used for display only.
            return null;
        }
    }

    /**
     * Tabby answers a capture or a refund with the payment's WHOLE list of
     * them, newest last, rather than with the one just made. The last id is
     * the transaction this call created.
     */
    private function lastId(mixed $list): ?string
    {
        if (! is_array($list) || $list === []) {
            return null;
        }

        $last = end($list);
        $id = is_array($last) ? ($last['id'] ?? null) : null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /* -------------------------------------------------------------- helpers */

    private function buyer(Order $order): array
    {
        $billing = is_array($order->billing_address) ? $order->billing_address : [];

        $name = trim(($billing['first_name'] ?? '') . ' ' . ($billing['last_name'] ?? ''));

        return [
            'email' => (string) $order->email,
            'name' => $name !== '' ? $name : (string) $order->email,
            // Tabby rejects a leading +.
            'phone' => ltrim((string) ($order->phone ?? $billing['phone'] ?? ''), '+'),
        ];
    }

    private function items(Order $order): array
    {
        return $order->items->map(fn ($item) => [
            'title' => (string) $item->name,
            'quantity' => (int) $item->quantity,
            'unit_price' => $this->toMajor((int) $item->unit_price),
            'reference_id' => (string) ($item->sku ?: $item->product_id),
        ])->values()->all();
    }
}
