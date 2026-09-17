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
 * Tamara — buy now, pay later.
 *
 * Shapes taken from the merchant's working WooCommerce plugin and the SDK
 * bundled with it; the implementation is our own.
 *
 *   POST /checkout                        create a session -> checkout_url
 *   GET  /merchants/orders/{id}           the authoritative state of an order
 *   POST /orders/{id}/authorise           confirm an approved order
 *   POST /payments/capture                take the money after authorisation
 *   POST /payments/refund                 give some or all of it back
 *   Settlement endpoints are flat: the order id travels in the BODY.
 *   Auth: Authorization: Bearer <api_token>
 *   https://api.tamara.co  |  https://api-sandbox.tamara.co
 *   Amounts: {amount, currency} with amount in MAJOR units
 *
 * ---------------------------------------------------------------------------
 * Verification
 *
 * Tamara signs its notifications properly, which makes this simpler than
 * Tabby: an HS256 JWT in `Authorization: Bearer`, keyed on the merchant's
 * notification token. Signature::verifyJwtHs256 fixes the algorithm at HS256
 * rather than reading it out of the header — trusting the header's `alg` is
 * the classic JWT forgery, where "alg":"none" or an RS256 token verified as
 * HMAC against a public key both sail through.
 *
 * Three gates, all of which must pass:
 *
 *   1. the URL secret (hash_equals), same as every other gateway here
 *   2. the JWT signature against the notification token
 *   3. the order is re-fetched from Tamara over an authenticated GET, and the
 *      amount and currency in THAT response are what get compared to ours
 *
 * Gate 3 is not redundant given gate 2. The signed payload carries an order
 * status but no trustworthy total, and the thing being protected is "is this
 * the amount we asked for" — so the figure has to come from an authenticated
 * call, not from a body, however well signed the body is.
 *
 * Two kinds of callback arrive at the same endpoint. The IPN carries
 * `order_status` and is how an approval is announced; the webhook carries
 * `event_type` and is how expiry and decline are. Both are handled.
 */
class TamaraGateway extends RemoteGateway implements HandlesWebhooks, ListsTransactions, SettlesPayments
{
    private const LIVE = 'https://api.tamara.co';

    private const SANDBOX = 'https://api-sandbox.tamara.co';

    /** Tamara operates in these markets. */
    private const COUNTRIES = ['AE', 'SA', 'KW', 'BH', 'QA', 'OM'];

    /**
     * Default days an authorised order stays capturable.
     *
     * Taken from the outer bound the merchant's own Tamara plugin sweeps to:
     * its "force capture" job hunts orders authorised but not captured within
     * 180 days, and gives up past that. Overridable on the payments screen,
     * because Tamara agrees this per merchant. Advisory either way — capture()
     * reads the order's live status from Tamara and that is what decides.
     */
    private const DEFAULT_CAPTURE_DAYS = 180;

    public function id(): string
    {
        return 'tamara';
    }

    public function title(): string
    {
        return 'Pay later with Tamara';
    }

    public function configured(): bool
    {
        return $this->credentials->filled($this->id(), 'api_token', 'notification_token');
    }

    public function description(int $totalFils): ?string
    {
        return 'Split in up to 4 payments, or pay in 30 days. No interest.';
    }

    public function availableFor(int $totalFils, ?string $country = null): bool
    {
        if ($totalFils <= 0) {
            return false;
        }

        return $country === null || in_array(strtoupper($country), self::COUNTRIES, true);
    }

    public function configSchema(): array
    {
        return [
            'api_token' => ['secret', 'API token', 'From Tamara merchant portal -> Settings -> API. Long JWT-looking string.'],
            'notification_token' => ['secret', 'Notification token', 'Separate from the API token. This is the key Tamara signs webhooks with; without it no webhook can be verified.'],
            'public_key' => ['text', 'Public key', 'Optional, for the product-page widget only.'],
            'webhook_secret' => ['secret', 'Webhook secret', 'Generated for you. Forms part of the webhook URL below.'],
            'capture_days' => ['text', 'Capture window (days)', 'How long Tamara leaves an authorised order capturable on your account. Default 180. Used only to warn you before it lapses — Tamara itself decides.'],
        ];
    }

    protected function baseUrl(): string
    {
        return $this->credentials->live($this->id()) ? self::LIVE : self::SANDBOX;
    }

    protected function authHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->credentials->get($this->id(), 'api_token'),
        ];
    }

    /* ------------------------------------------------------------- checkout */

    public function start(Order $order): PaymentStart
    {
        if (! $this->configured()) {
            return PaymentStart::failed('Tamara is not available right now.');
        }

        $billing = is_array($order->billing_address) ? $order->billing_address : [];
        $currency = strtoupper((string) ($order->currency ?: 'AED'));

        $result = $this->call('POST', '/checkout', [
            'order_reference_id' => $this->reference($order),
            'order_number' => $this->reference($order),
            'total_amount' => $this->money((int) $order->total, $currency),
            'shipping_amount' => $this->money((int) $order->shipping_total, $currency),
            'tax_amount' => $this->money((int) $order->tax_total, $currency),
            'discount' => [
                'name' => (string) ($order->coupon_code ?: 'Discount'),
                'amount' => $this->money((int) $order->discount_total, $currency),
            ],
            'description' => 'Order ' . $this->reference($order),
            'country_code' => strtoupper((string) ($billing['country'] ?? 'AE')),
            'payment_type' => 'PAY_BY_LATER',
            'locale' => 'en_US',
            'items' => $this->items($order, $currency),
            'consumer' => [
                'first_name' => (string) ($billing['first_name'] ?? ''),
                'last_name' => (string) ($billing['last_name'] ?? ''),
                'phone_number' => (string) ($order->phone ?? $billing['phone'] ?? ''),
                'email' => (string) $order->email,
            ],
            'billing_address' => $this->address($billing),
            'shipping_address' => $this->address(
                is_array($order->shipping_address) ? $order->shipping_address : $billing
            ),
            'merchant_url' => [
                'success' => $this->returnUrl($order, 'success'),
                'failure' => $this->returnUrl($order, 'failure'),
                'cancel' => $this->returnUrl($order, 'cancel'),
                'notification' => $this->webhookUrl(),
            ],
            'platform' => 'KBB Storefront (Laravel)',
        ]);

        $url = $result['checkout_url'] ?? null;

        if ($result === null || ! is_string($url) || $url === '') {
            return PaymentStart::failed('Tamara is not available for this order. Please choose another payment method.');
        }

        $tamaraOrderId = (string) ($result['order_id'] ?? '');

        $order->forceFill(['transaction_id' => $tamaraOrderId])->save();

        $this->log('checkout session created', '/checkout', 200, ['reference' => $this->reference($order)]);

        return PaymentStart::redirect($url, $tamaraOrderId);
    }

    /* -------------------------------------------------------------- webhook */

    public function handleWebhook(Request $request): WebhookOutcome
    {
        // (1) URL secret, constant-time, before anything else happens.
        $expected = $this->credentials->get($this->id(), 'webhook_secret');

        if ($expected === '' || ! Signature::equals($expected, (string) $request->route('secret'))) {
            return WebhookOutcome::rejected();
        }

        // (2) The signature Tamara itself applies. Accepted from the header or
        //     the `tamaraToken` query parameter, which is the other shape
        //     their own SDK looks in.
        $token = Signature::bearer($request->header('Authorization'))
            ?? $request->query('tamaraToken');

        if (! is_string($token) || $token === '') {
            return WebhookOutcome::rejected('no token');
        }

        $claims = Signature::verifyJwtHs256(
            $token,
            $this->credentials->get($this->id(), 'notification_token'),
        );

        if ($claims === null) {
            return WebhookOutcome::rejected();
        }

        $body = $request->json()->all();
        $body = is_array($body) ? $body : [];

        $reference = $body['order_reference_id'] ?? null;
        $tamaraOrderId = (string) ($body['order_id'] ?? '');

        $order = $this->findOrderByReference(is_string($reference) ? $reference : null);

        if ($order === null) {
            return WebhookOutcome::refused('no order for that reference');
        }

        $summary = [
            'tamara_order_id' => $tamaraOrderId,
            'reference' => $reference,
            'event_type' => $body['event_type'] ?? null,
            'order_status' => $body['order_status'] ?? null,
        ];

        // The webhook shape: expiry and decline.
        $event = (string) ($body['event_type'] ?? '');

        if (in_array($event, ['order_expired', 'order_declined'], true)) {
            return $this->confirmer->fail($order, $this->id(), $tamaraOrderId, $event, $summary);
        }

        // The IPN shape: an approval to act on.
        $status = strtolower((string) ($body['order_status'] ?? ''));

        if (! in_array($status, ['approved', 'authorised', 'authorized', 'fully_captured'], true)) {
            return WebhookOutcome::ignored('nothing to do for this event');
        }

        if ($tamaraOrderId === '') {
            return WebhookOutcome::refused('no tamara order id');
        }

        // (3) Ask Tamara what the order actually is. Nothing below comes from
        //     the body -- a signed body still is not an authenticated total.
        $remote = $this->call('GET', '/merchants/orders/' . urlencode($tamaraOrderId));

        if ($remote === null) {
            return WebhookOutcome::failed('could not verify order with Tamara');
        }

        // The reference on the fetched order has to be the one we looked up,
        // or a valid token for order A has been pointed at order B.
        if ((string) ($remote['order_reference_id'] ?? '') !== (string) $order->order_number) {
            return WebhookOutcome::refused('reference does not match');
        }

        $amount = $remote['total_amount']['amount'] ?? 0;
        $currency = (string) ($remote['total_amount']['currency'] ?? '');

        // Authorise before confirming. Tamara holds the money until this call;
        // an order marked paid here but never authorised is one we never get.
        // 409 means it was already authorised, which is a success on a replay.
        $authorised = $this->call('POST', '/orders/' . urlencode($tamaraOrderId) . '/authorise');

        if ($authorised === null && ! in_array($status, ['authorised', 'authorized', 'fully_captured'], true)) {
            return WebhookOutcome::failed('could not authorise with Tamara');
        }

        return $this->confirmer->confirm(
            $order,
            $this->id(),
            $tamaraOrderId,
            $this->toFils($amount),
            $currency,
            $summary,
        );
    }

    /* ----------------------------------------------------------- settlement */

    public function captureWindow(): string
    {
        return sprintf(
            'Within about %d days of authorisation. Tamara voids an authorisation that is never captured, and an uncaptured order is one the merchant is never paid for.',
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
     * `POST /payments/capture`, with the order id in the BODY rather than the
     * path — Tamara's settlement endpoints are flat, unlike its order
     * endpoints. The response is `{capture_id: ...}`, and that id is what a
     * later refund has to be pointed at.
     *
     * The live order is read first and its `status` decides:
     *
     *   authorised            capturable.
     *   fully_captured        already done. A success on a retry, not an error.
     *   partially_captured    also treated as done here. This build captures
     *                         the whole order in one call and never makes a
     *                         partial one, so a partial capture on the account
     *                         came from elsewhere and silently topping it up
     *                         would be the wrong guess to make with money.
     *   anything else         declined, expired, cancelled. Nothing to take.
     */
    public function capture(Order $order, int $amountFils): SettlementResult
    {
        $tamaraOrderId = trim((string) $order->transaction_id);

        if (! $this->configured() || $tamaraOrderId === '') {
            return SettlementResult::failed(
                'not_configured',
                ['provider' => $this->id()],
                'Tamara is not configured, or this order has no Tamara order on it.',
            );
        }

        $remote = $this->call('GET', '/merchants/orders/' . urlencode($tamaraOrderId));

        if ($remote === null) {
            return SettlementResult::failed(
                'unreachable',
                ['provider' => $this->id(), 'tamara_order_id' => $tamaraOrderId],
                'Tamara could not be reached. Nothing was captured; try again.',
            );
        }

        // The same guard the webhook applies: a reference that is not ours
        // means this order id belongs to somebody else's order.
        if ((string) ($remote['order_reference_id'] ?? '') !== (string) $order->order_number) {
            return SettlementResult::failed(
                'reference_mismatch',
                ['provider' => $this->id(), 'tamara_order_id' => $tamaraOrderId],
                'Tamara has a different order against that reference. Nothing was captured.',
            );
        }

        $status = strtolower((string) ($remote['status'] ?? ''));

        if (in_array($status, ['fully_captured', 'partially_captured'], true)) {
            return SettlementResult::ok(
                'already_captured',
                $this->existingCaptureId($remote),
                ['provider' => $this->id(), 'tamara_order_id' => $tamaraOrderId, 'status' => $status],
                'Tamara had already captured this order.',
            );
        }

        if (! in_array($status, ['authorised', 'authorized'], true)) {
            return SettlementResult::failed(
                'not_authorised',
                ['provider' => $this->id(), 'tamara_order_id' => $tamaraOrderId, 'status' => $status],
                'Tamara reports this order as ' . ($status !== '' ? $status : 'unknown') . ', so there is nothing to capture.',
            );
        }

        $currency = strtoupper((string) ($order->currency ?: 'AED'));

        $attempt = $this->attempt('POST', '/payments/capture', [
            'order_id' => $tamaraOrderId,
            'total_amount' => $this->money($amountFils, $currency),
            'shipping_amount' => $this->money((int) $order->shipping_total, $currency),
            'tax_amount' => $this->money((int) $order->tax_total, $currency),
            'discount_amount' => $this->money((int) $order->discount_total, $currency),
            'items' => $this->items($order, $currency),
            'shipping_info' => [
                'shipped_at' => now()->toAtomString(),
                'shipping_company' => (string) ($order->shipping_method ?: 'Courier'),
            ],
        ]);

        $captureId = $attempt['ok'] ? $this->stringOrNull($attempt['body']['capture_id'] ?? null) : null;

        if (! $attempt['ok'] || $captureId === null) {
            return SettlementResult::failed(
                $attempt['error'] ?? 'capture_rejected',
                [
                    'provider' => $this->id(),
                    'tamara_order_id' => $tamaraOrderId,
                    'http_status' => $attempt['status'],
                    'error' => $attempt['error'],
                ],
                'Tamara refused the capture. Nothing was taken.',
            );
        }

        return SettlementResult::ok(
            'captured',
            $captureId,
            ['provider' => $this->id(), 'tamara_order_id' => $tamaraOrderId, 'capture_id' => $captureId],
            'Captured through Tamara.',
        );
    }

    /**
     * Refund some or all of a capture.
     *
     * `POST /payments/refund`, shaped as one order id plus a LIST of refunds
     * — Tamara batches them — each pointing at the capture it reverses. We
     * send exactly one per call so that one refund row maps to one provider
     * transaction; batching would make a partial failure inside the batch
     * impossible to attribute to the right row in our own table.
     *
     * The capture id is required, so a refund before capture is reported as
     * `not_captured` rather than as a generic failure: the fix is to capture
     * first, and no other message says so.
     */
    public function refund(
        Order $order,
        int $amountFils,
        ?string $reason,
        ?string $captureRef,
        ?string $idempotencyKey = null,
    ): SettlementResult
    {
        $tamaraOrderId = trim((string) $order->transaction_id);

        if (! $this->configured() || $tamaraOrderId === '') {
            return SettlementResult::failed(
                'not_configured',
                ['provider' => $this->id()],
                'Tamara is not configured, or this order has no Tamara order on it.',
            );
        }

        $captureId = trim((string) ($order->capture_ref ?? ''));

        if ($captureId === '') {
            return SettlementResult::failed(
                'not_captured',
                ['provider' => $this->id(), 'tamara_order_id' => $tamaraOrderId],
                'This Tamara order has not been captured, so there is nothing to refund yet. Capture it first.',
            );
        }

        $currency = strtoupper((string) ($order->currency ?: 'AED'));

        $attempt = $this->attempt('POST', '/payments/refund', [
            'order_id' => $tamaraOrderId,
            'refunds' => [[
                'capture_id' => $captureId,
                'total_amount' => $this->money($amountFils, $currency),
                'shipping_amount' => $this->money(0, $currency),
                'tax_amount' => $this->money(0, $currency),
                'discount_amount' => $this->money(0, $currency),
                'items' => [],
                'comment' => $reason !== null && trim($reason) !== '' ? trim($reason) : 'Merchant refund',
            ]],
        ]);

        $refundId = $attempt['ok']
            ? $this->stringOrNull($attempt['body']['refunds'][0]['refund_id'] ?? null)
            : null;

        if (! $attempt['ok'] || $refundId === null) {
            return SettlementResult::failed(
                $attempt['error'] ?? 'refund_rejected',
                [
                    'provider' => $this->id(),
                    'tamara_order_id' => $tamaraOrderId,
                    'http_status' => $attempt['status'],
                    'error' => $attempt['error'],
                ],
                'Tamara refused the refund. Nothing has been returned to the customer.',
            );
        }

        return SettlementResult::ok(
            'refunded',
            $refundId,
            ['provider' => $this->id(), 'tamara_order_id' => $tamaraOrderId, 'refund_id' => $refundId],
            'Refunded through Tamara.',
        );
    }

    /* -------------------------------------------------------- reconciliation */

    /**
     * Tamara's books, a page at a time.
     *
     *   GET /merchants/orders?startDate=&endDate=&offset=&limit=
     *
     * The same collection the webhook and capture already read one member of
     * (`/merchants/orders/{id}`), which is why the parsing below shares its
     * field names with existingCaptureId() and with handleWebhook():
     * `order_reference_id` is our order number, `total_amount` is
     * {amount, currency} in MAJOR units, and settlement lives under
     * `transactions.captures[]` and `transactions.refunds[]`.
     *
     * REFUNDS COME OUT OF THE SAME LIST, for the same reason they do on Tabby:
     * Tamara keeps a refund inside the order it belongs to. refund() sends one
     * `capture_id` and reads back `refunds[0].refund_id`; there is no separate
     * collection of refunds to page.
     *
     * WHICH HOST. baseUrl() resolves the live/sandbox switch, so this reads the
     * same books the checkout writes to. That is not a detail: sandbox keys
     * pointed at the live host (or the reverse) is the commonest go-live
     * failure on this gateway, and a reconciliation that read the sandbox would
     * report every real payment in the window as money the provider has never
     * heard of. remoteSourceLabel() prints the host it actually used so the
     * screen can show which one answered.
     *
     * THE LIST FORM IS NOT PROVEN HERE, exactly as on Tabby — the by-id read is
     * exercised by the webhook, the collection read is not, and this project
     * has no Tamara merchant account to settle it with. It is confined to this
     * method, and a wrong path returns RemotePage::failed(), which makes the
     * run say "Tamara's list of orders could not be read" and skip every
     * conclusion that depends on having read it. See RemotePage.
     */
    public function listRemotePayments(ReconcileWindow $window, ?string $cursor, int $limit): RemotePage
    {
        return $this->listTamara($window, $cursor, $limit, RemoteTxn::PAYMENT);
    }

    public function listRemoteRefunds(ReconcileWindow $window, ?string $cursor, int $limit): RemotePage
    {
        return $this->listTamara($window, $cursor, $limit, RemoteTxn::REFUND);
    }

    public function remoteSourceLabel(): string
    {
        return rtrim($this->baseUrl(), '/') . '/merchants/orders (refunds are nested in each order)';
    }

    private function listTamara(ReconcileWindow $window, ?string $cursor, int $limit, string $kind): RemotePage
    {
        if (! $this->configured()) {
            return RemotePage::unsupported('Tamara has no API token stored, so its books cannot be read.');
        }

        $limit = max(1, min($limit, 100));
        $offset = $cursor !== null && ctype_digit(trim($cursor)) ? (int) trim($cursor) : 0;

        $attempt = $this->attempt('GET', '/merchants/orders?' . http_build_query([
            'startDate' => $window->from->toIso8601ZuluString(),
            'endDate' => $window->to->toIso8601ZuluString(),
            'offset' => $offset,
            'limit' => $limit,
        ]));

        if (! $attempt['ok']) {
            return RemotePage::failed($attempt['error'] ?? 'unreachable', $attempt['status']);
        }

        $orders = $this->orderList($attempt['body']);
        $items = [];

        foreach ($orders as $remote) {
            if (! is_array($remote)) {
                continue;
            }

            if ($kind === RemoteTxn::PAYMENT) {
                $txn = $this->tamaraOrderToTxn($remote);

                if ($txn !== null) {
                    $items[] = $txn;
                }

                continue;
            }

            foreach ($this->tamaraRefundsToTxns($remote) as $refund) {
                $items[] = $refund;
            }
        }

        // The cursor counts ORDERS in both modes. See the same note on Tabby:
        // advancing by the number of refunds found would skip orders in
        // proportion to how many refunds they happened to carry.
        $more = count($orders) >= $limit;

        return RemotePage::of($items, $more ? (string) ($offset + count($orders)) : null);
    }

    private function orderList(mixed $body): array
    {
        if (! is_array($body)) {
            return [];
        }

        foreach (['data', 'orders', 'results', 'content'] as $key) {
            if (isset($body[$key]) && is_array($body[$key])) {
                return $body[$key];
            }
        }

        return array_is_list($body) ? $body : [];
    }

    private function tamaraOrderToTxn(array $remote): ?RemoteTxn
    {
        $id = $this->stringOrNull($remote['order_id'] ?? null);

        if ($id === null) {
            return null;
        }

        $status = strtolower((string) ($remote['status'] ?? ''));

        /*
         * Captured is money. Authorised is money Tamara is holding and will
         * release if nobody takes it — its own merchant plugin sweeps for
         * exactly this up to 180 days out. `new`, `approved` and `declined` are
         * all short of a commitment; `expired` and `canceled` are past one.
         *
         * `partially_captured` counts as settled and the amount compared is the
         * order total, which will disagree with a short capture. That
         * disagreement is a finding worth having rather than a false one: a
         * partially captured Tamara order IS a case where the two sides hold
         * different figures, and it is invisible from the orders list.
         */
        $state = match ($status) {
            'fully_captured', 'partially_captured' => RemoteTxn::SETTLED,
            'authorised', 'authorized' => RemoteTxn::AUTHORISED,
            default => RemoteTxn::DEAD,
        };

        return new RemoteTxn(
            provider: $this->id(),
            kind: RemoteTxn::PAYMENT,
            remoteId: $id,
            matchKeys: [],
            reference: $this->stringOrNull($remote['order_reference_id'] ?? null),
            // Major units on this API, through toFils() and its round(). Never
            // an (int) cast on a float.
            amountFils: $this->toFils($remote['total_amount']['amount'] ?? 0),
            currency: strtoupper((string) ($remote['total_amount']['currency'] ?? '')),
            state: $state,
            rawState: $status,
            createdAt: $this->tamaraMoment($remote['created_at'] ?? null),
        );
    }

    /** @return array<int, RemoteTxn> */
    private function tamaraRefundsToTxns(array $remote): array
    {
        $refunds = $remote['transactions']['refunds'] ?? null;

        if (! is_array($refunds)) {
            return [];
        }

        $reference = $this->stringOrNull($remote['order_reference_id'] ?? null);
        $currency = strtoupper((string) ($remote['total_amount']['currency'] ?? ''));
        $out = [];

        foreach ($refunds as $refund) {
            if (! is_array($refund)) {
                continue;
            }

            $id = $this->stringOrNull($refund['refund_id'] ?? null);

            if ($id === null) {
                continue;
            }

            $out[] = new RemoteTxn(
                provider: $this->id(),
                kind: RemoteTxn::REFUND,
                remoteId: $id,
                matchKeys: [],
                reference: $reference,
                amountFils: $this->toFils(
                    $refund['total_amount']['amount'] ?? ($refund['amount'] ?? 0)
                ),
                currency: strtoupper((string) ($refund['total_amount']['currency'] ?? $currency)),
                state: RemoteTxn::SETTLED,
                rawState: 'refunded',
                createdAt: $this->tamaraMoment($refund['created_at'] ?? null),
                // The Tamara order this refund sits inside, which is what
                // `payments.provider_ref` holds for this gateway.
                parentKeys: [(string) ($remote['order_id'] ?? '')],
            );
        }

        return $out;
    }

    private function tamaraMoment(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    /** The capture id off an already-captured order, if Tamara volunteered one. */
    private function existingCaptureId(array $remote): ?string
    {
        return $this->stringOrNull(
            $remote['transactions']['captures'][0]['capture_id']
                ?? ($remote['capture_id'] ?? null)
        );
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /* -------------------------------------------------------------- helpers */

    /** Tamara wants {amount, currency}, amount in major units. */
    private function money(int $fils, string $currency): array
    {
        return ['amount' => (float) $this->toMajor($fils), 'currency' => $currency];
    }

    private function items(Order $order, string $currency): array
    {
        return $order->items->map(fn ($item) => [
            'reference_id' => (string) $item->id,
            'type' => 'Physical',
            'name' => (string) $item->name,
            'sku' => (string) ($item->sku ?: $item->product_id),
            'quantity' => (int) $item->quantity,
            'unit_price' => $this->money((int) $item->unit_price, $currency),
            'discount_amount' => $this->money(0, $currency),
            'tax_amount' => $this->money(0, $currency),
            'total_amount' => $this->money((int) $item->total, $currency),
        ])->values()->all();
    }

    private function address(array $a): array
    {
        return [
            'first_name' => (string) ($a['first_name'] ?? ''),
            'last_name' => (string) ($a['last_name'] ?? ''),
            'line1' => (string) ($a['line1'] ?? ''),
            'city' => (string) ($a['city'] ?? ''),
            'region' => (string) ($a['state'] ?? ''),
            'country_code' => strtoupper((string) ($a['country'] ?? 'AE')),
            'phone_number' => (string) ($a['phone'] ?? ''),
        ];
    }

    /**
     * The notification URL Tamara is handed at session creation. Must match
     * the route in routes/payments-webhooks.php, secret included.
     */
    private function webhookUrl(): string
    {
        return url(\App\Support\Url::redirect('/api/payments/webhook/tamara/'))
            . $this->credentials->get($this->id(), 'webhook_secret');
    }
}
