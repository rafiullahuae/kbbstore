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
use Illuminate\Support\Facades\Http;

/**
 * Stripe — cards, via Checkout.
 *
 * Stripe Checkout rather than the Payment Intents API with our own card form,
 * deliberately: a hosted page means card numbers never touch this server, and
 * the PCI obligation stays SAQ-A. This app runs on shared hosting.
 *
 *   POST /v1/checkout/sessions   create a session -> url
 *   GET  /v1/checkout/sessions/{id}
 *   Auth: Authorization: Bearer sk_...
 *   Form-encoded, NOT JSON — Stripe's API takes application/x-www-form-urlencoded
 *   Amounts are integer MINOR units, which is already how this schema stores
 *   money, so unlike Tabby and Tamara there is no conversion to get wrong.
 *
 * ---------------------------------------------------------------------------
 * Verification
 *
 * Stripe signs with `Stripe-Signature: t=<unix>,v1=<hex hmac>`, where the MAC
 * is HMAC-SHA256 of "<t>.<raw body>" keyed on the endpoint's signing secret
 * (whsec_...). Three things that are easy to get wrong and are each load
 * bearing:
 *
 *   - the RAW body, byte for byte. Re-encoding the decoded JSON changes key
 *     order and spacing and the MAC will never match.
 *   - hash_equals, not ==.
 *   - the timestamp must be checked. Without it a captured-and-replayed
 *     delivery stays valid forever, because the signature itself never
 *     expires.
 *
 * Unlike Tabby and Tamara there is no re-fetch here. A verified Stripe
 * signature is a strong statement about the body's integrity, and the event
 * carries `amount_total` from Stripe rather than from the client. That amount
 * is still compared against the order's own total by PaymentConfirmer, which
 * is what actually protects us.
 */
class StripeGateway extends RemoteGateway implements HandlesWebhooks, ListsTransactions, SettlesPayments
{
    private const API = 'https://api.stripe.com';

    /**
     * Days an uncaptured PaymentIntent survives before Stripe releases it.
     *
     * Only reachable at all if the Checkout session is created with manual
     * capture, which this build does not do — see capture(). Kept because the
     * window is real whenever a session IS created that way, and because a
     * capture screen that quietly reported "no window" for cards would be
     * telling the merchant something that stops being true the moment
     * somebody sets capture_method.
     */
    private const CAPTURE_DAYS = 7;

    public function id(): string
    {
        return 'stripe';
    }

    public function title(): string
    {
        return 'Credit or debit card';
    }

    public function configured(): bool
    {
        return $this->credentials->filled($this->id(), 'secret_key');
    }

    public function description(int $totalFils): ?string
    {
        return 'Pay securely by card. Your card details never reach this site.';
    }

    public function configSchema(): array
    {
        return [
            'publishable_key' => ['text', 'Publishable key', 'Starts pk_test_ or pk_live_. Safe to appear in the page.'],
            'secret_key' => ['secret', 'Secret key', 'Starts sk_test_ or sk_live_. Never leaves the server, and is never returned by any API.'],
            'webhook_signing_secret' => ['secret', 'Webhook signing secret', 'Starts whsec_. From Stripe Dashboard -> Developers -> Webhooks, after adding the endpoint URL below. Without it no webhook can be verified.'],
            'webhook_secret' => ['secret', 'URL secret', 'Generated for you. Forms part of the webhook URL below.'],
        ];
    }

    protected function baseUrl(): string
    {
        return self::API;
    }

    protected function authHeaders(): array
    {
        return ['Authorization' => 'Bearer ' . $this->credentials->get($this->id(), 'secret_key')];
    }

    /* ------------------------------------------------------------- checkout */

    public function start(Order $order): PaymentStart
    {
        if (! $this->configured()) {
            return PaymentStart::failed('Card payment is not available right now.');
        }

        $currency = strtolower((string) ($order->currency ?: 'AED'));

        // One line item for the order total rather than a per-product
        // breakdown. The breakdown would have to reproduce this order's
        // discount and shipping apportionment exactly or Stripe's total would
        // disagree with ours by a fil, and the order's own total is the figure
        // the webhook will be checked against.
        $payload = [
            'mode' => 'payment',
            'client_reference_id' => $this->reference($order),
            'customer_email' => (string) $order->email,
            'success_url' => $this->returnUrl($order, 'success'),
            'cancel_url' => $this->returnUrl($order, 'cancel'),
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => $currency,
                    // Already integer minor units. No conversion, no float.
                    'unit_amount' => (int) $order->total,
                    'product_data' => ['name' => 'Order ' . $this->reference($order)],
                ],
            ]],
            'metadata' => ['order_number' => $this->reference($order)],
            /*
             * THE SAME REFERENCE, STAMPED ON THE PAYMENTINTENT AS WELL.
             *
             * Session metadata does not propagate: a Checkout session's
             * `metadata` and `client_reference_id` stay on the session, and the
             * charge that comes out of it carries neither. That is invisible on
             * the webhook path, which reads the session, and it is exactly what
             * breaks reconciliation — GET /v1/charges lists money with no way
             * to say which order it belongs to, so a payment whose webhook
             * never arrived can be reported as "Stripe has taken money we have
             * no record of" without being able to name the order.
             *
             * `payment_intent_data.metadata` is copied onto the PaymentIntent
             * and from there onto its charge, which is what lets the
             * reconciliation say "order KBB-1042" instead of "some charge".
             * Two identical stamps, and the redundancy is the point: neither
             * path depends on the other's object.
             */
            'payment_intent_data' => [
                'metadata' => ['order_number' => $this->reference($order)],
            ],
        ];

        $result = $this->form('/v1/checkout/sessions', $payload);

        $url = $result['url'] ?? null;

        if ($result === null || ! is_string($url) || $url === '') {
            return PaymentStart::failed('We could not reach our card processor. Please try another payment method.');
        }

        $sessionId = (string) ($result['id'] ?? '');

        $order->forceFill(['transaction_id' => $sessionId])->save();

        $this->log('checkout session created', '/v1/checkout/sessions', 200, ['reference' => $this->reference($order)]);

        return PaymentStart::redirect($url, $sessionId);
    }

    /**
     * Stripe's API is form-encoded, with nested data as bracketed keys
     * (line_items[0][price_data][currency]). RemoteGateway::call() sends JSON,
     * which Stripe ignores, so this is its own method rather than a flag.
     */
    private function form(string $path, array $payload): ?array
    {
        return $this->stripeAttempt('POST', $path, $payload)['body'];
    }

    /**
     * The same form-encoded call, with the failure kept.
     *
     * form() throws away everything but a successful body, which is right for
     * checkout and wrong for settlement: a declined refund has to reach
     * `payment_events` with Stripe's own error code on it. Same wire format,
     * same timeout, same logging rule — no bodies, ever.
     *
     * `Idempotency-Key` is Stripe's native replay guard and is passed through
     * when the caller has one. Our unique index on `refunds.idempotency_key`
     * is the guard that actually protects the ledger; this one covers the case
     * that index cannot see, where our request reached Stripe and the response
     * never reached us.
     *
     * @return array{ok: bool, status: int|null, body: array|null, error: string|null}
     */
    private function stripeAttempt(string $method, string $path, array $payload = [], ?string $idempotencyKey = null): array
    {
        $headers = $this->authHeaders();

        if ($idempotencyKey !== null && trim($idempotencyKey) !== '') {
            // Stripe caps this at 255 characters.
            $headers['Idempotency-Key'] = substr(trim($idempotencyKey), 0, 255);
        }

        try {
            $request = Http::withHeaders($headers)->timeout(self::TIMEOUT)->asForm();
            $url = rtrim($this->baseUrl(), '/') . $path;

            $response = strtoupper($method) === 'GET'
                ? $request->get($url)
                : $request->post($url, $this->flatten($payload));
        } catch (\Throwable $e) {
            $this->log('transport error', $path, null, ['error' => $e->getMessage()]);

            return ['ok' => false, 'status' => null, 'body' => null, 'error' => 'transport_error'];
        }

        $this->log('api call', $path, $response->status());

        $decoded = $response->json();
        $decoded = is_array($decoded) ? $decoded : null;

        return [
            'ok' => $response->successful(),
            'status' => $response->status(),
            'body' => $response->successful() ? $decoded : null,
            'error' => $response->successful() ? null : $this->errorCode($decoded),
        ];
    }

    /** ['a' => ['b' => 1]] -> ['a[b]' => 1] */
    private function flatten(array $data, string $prefix = ''): array
    {
        $out = [];

        foreach ($data as $key => $value) {
            $name = $prefix === '' ? (string) $key : $prefix . '[' . $key . ']';

            if (is_array($value)) {
                $out += $this->flatten($value, $name);

                continue;
            }

            $out[$name] = is_bool($value) ? ($value ? 'true' : 'false') : $value;
        }

        return $out;
    }

    /* -------------------------------------------------------------- webhook */

    public function handleWebhook(Request $request): WebhookOutcome
    {
        // (1) URL secret, before anything else.
        $urlSecret = $this->credentials->get($this->id(), 'webhook_secret');

        if ($urlSecret === '' || ! Signature::equals($urlSecret, (string) $request->route('secret'))) {
            return WebhookOutcome::rejected();
        }

        // (2) Stripe's own signature, over the RAW body.
        $signing = $this->credentials->get($this->id(), 'webhook_signing_secret');
        $raw = $request->getContent();

        if (! $this->signatureValid($request->header('Stripe-Signature'), $raw, $signing)) {
            return WebhookOutcome::rejected();
        }

        $event = json_decode($raw, true);

        if (! is_array($event)) {
            return WebhookOutcome::refused('unparseable body');
        }

        $type = (string) ($event['type'] ?? '');
        $object = $event['data']['object'] ?? [];
        $object = is_array($object) ? $object : [];

        $reference = $object['client_reference_id']
            ?? ($object['metadata']['order_number'] ?? null);

        $order = $this->findOrderByReference(is_string($reference) ? $reference : null);

        if ($order === null) {
            return WebhookOutcome::refused('no order for that reference');
        }

        $summary = [
            'event_id' => $event['id'] ?? null,
            'event_type' => $type,
            'session_id' => $object['id'] ?? null,
            'reference' => $reference,
        ];

        if (in_array($type, ['checkout.session.expired', 'payment_intent.payment_failed'], true)) {
            return $this->confirmer->fail(
                $order, $this->id(), (string) ($object['id'] ?? ''), str_replace('.', '_', $type), $summary,
            );
        }

        if ($type !== 'checkout.session.completed' && $type !== 'checkout.session.async_payment_succeeded') {
            return WebhookOutcome::ignored('event type not acted on');
        }

        // A completed session is not necessarily a paid one -- a delayed method
        // can complete the session and settle later.
        if (($object['payment_status'] ?? '') !== 'paid') {
            return WebhookOutcome::ignored('session completed but not paid');
        }

        return $this->confirmer->confirm(
            $order,
            $this->id(),
            (string) ($object['payment_intent'] ?? $object['id'] ?? ''),
            // Already minor units. Stripe's figure, not the client's.
            (int) ($object['amount_total'] ?? 0),
            (string) ($object['currency'] ?? ''),
            $summary,
        );
    }

    /* ----------------------------------------------------------- settlement */

    public function captureWindow(): string
    {
        return sprintf(
            'Card payments through Checkout are captured by Stripe at authorisation, so there is normally nothing to do. '
            . 'An authorisation deliberately left uncaptured lapses after about %d days.',
            self::CAPTURE_DAYS,
        );
    }

    public function captureWindowDays(): ?int
    {
        return self::CAPTURE_DAYS;
    }

    /**
     * Capture a card payment.
     *
     * This build creates Checkout sessions with Stripe's default automatic
     * capture, so by the time `checkout.session.completed` arrives the money
     * has already moved and the honest answer to "capture this" is "Stripe
     * already did". The PaymentIntent is read rather than assumed, because the
     * alternative is a screen that reports a capture that never happened:
     *
     *   succeeded          already captured. ok(), no write, no second call.
     *   requires_capture   a manual-capture intent. Capture it for real.
     *   anything else      requires_payment_method, canceled — nothing to take.
     */
    public function capture(Order $order, int $amountFils): SettlementResult
    {
        if (! $this->configured()) {
            return SettlementResult::failed('not_configured', ['provider' => $this->id()], 'Stripe is not configured.');
        }

        $intentId = $this->paymentIntentId($order);

        if ($intentId === null) {
            return SettlementResult::failed(
                'no_payment_intent',
                ['provider' => $this->id()],
                'This order has no Stripe payment on it yet.',
            );
        }

        $read = $this->stripeAttempt('GET', '/v1/payment_intents/' . urlencode($intentId));

        if (! $read['ok']) {
            return SettlementResult::failed(
                $read['error'] ?? 'unreachable',
                ['provider' => $this->id(), 'payment_intent' => $intentId, 'http_status' => $read['status']],
                'Stripe could not be reached. Nothing was captured; try again.',
            );
        }

        $status = (string) ($read['body']['status'] ?? '');

        if ($status === 'succeeded') {
            return SettlementResult::ok(
                'already_captured',
                $intentId,
                ['provider' => $this->id(), 'payment_intent' => $intentId, 'status' => $status],
                'Stripe captured this card payment at authorisation; there was nothing left to take.',
            );
        }

        if ($status !== 'requires_capture') {
            return SettlementResult::failed(
                'not_capturable',
                ['provider' => $this->id(), 'payment_intent' => $intentId, 'status' => $status],
                'Stripe reports this payment as ' . ($status !== '' ? $status : 'unknown') . ', so there is nothing to capture.',
            );
        }

        $attempt = $this->stripeAttempt(
            'POST',
            '/v1/payment_intents/' . urlencode($intentId) . '/capture',
            // Already integer minor units, which is how this schema stores
            // money. No conversion here, so none to get wrong.
            ['amount_to_capture' => $amountFils],
            'capture:' . $order->order_number,
        );

        if (! $attempt['ok'] || (string) ($attempt['body']['status'] ?? '') !== 'succeeded') {
            return SettlementResult::failed(
                $attempt['error'] ?? 'capture_rejected',
                [
                    'provider' => $this->id(),
                    'payment_intent' => $intentId,
                    'http_status' => $attempt['status'],
                    'error' => $attempt['error'],
                ],
                'Stripe refused the capture. Nothing was taken.',
            );
        }

        return SettlementResult::ok(
            'captured',
            $intentId,
            ['provider' => $this->id(), 'payment_intent' => $intentId],
            'Captured through Stripe.',
        );
    }

    /**
     * Refund some or all of a card payment.
     *
     * `POST /v1/refunds` against the PaymentIntent, not the Checkout session —
     * a session id is not a thing Stripe will refund.
     *
     * `reason` on this endpoint is an enum of three values, not free text, and
     * sending the merchant's own wording would be a 400. The wording goes in
     * metadata instead, where it is visible in the Stripe dashboard beside the
     * refund it explains.
     */
    public function refund(
        Order $order,
        int $amountFils,
        ?string $reason,
        ?string $captureRef,
        ?string $idempotencyKey = null,
    ): SettlementResult {
        if (! $this->configured()) {
            return SettlementResult::failed('not_configured', ['provider' => $this->id()], 'Stripe is not configured.');
        }

        $intentId = $this->paymentIntentId($order);

        if ($intentId === null) {
            return SettlementResult::failed(
                'no_payment_intent',
                ['provider' => $this->id()],
                'This order has no Stripe payment on it yet.',
            );
        }

        $payload = [
            'payment_intent' => $intentId,
            'amount' => $amountFils,
            'reason' => 'requested_by_customer',
        ];

        if ($reason !== null && trim($reason) !== '') {
            $payload['metadata'] = ['note' => substr(trim($reason), 0, 500)];
        }

        $attempt = $this->stripeAttempt('POST', '/v1/refunds', $payload, $idempotencyKey);

        $refundId = $attempt['ok'] ? ($attempt['body']['id'] ?? null) : null;
        $refundStatus = $attempt['ok'] ? (string) ($attempt['body']['status'] ?? '') : '';

        // `failed` and `canceled` are real Stripe refund states and both come
        // back on a 200. A refund is only a refund when Stripe says pending or
        // succeeded; anything else is money that did not move.
        if (! $attempt['ok'] || ! is_string($refundId) || ! in_array($refundStatus, ['succeeded', 'pending'], true)) {
            return SettlementResult::failed(
                $attempt['error'] ?? ($refundStatus !== '' ? 'refund_' . $refundStatus : 'refund_rejected'),
                [
                    'provider' => $this->id(),
                    'payment_intent' => $intentId,
                    'http_status' => $attempt['status'],
                    'error' => $attempt['error'],
                    'refund_status' => $refundStatus !== '' ? $refundStatus : null,
                ],
                'Stripe refused the refund. Nothing has been returned to the customer.',
            );
        }

        return SettlementResult::ok(
            'refunded',
            $refundId,
            [
                'provider' => $this->id(),
                'payment_intent' => $intentId,
                'refund_id' => $refundId,
                'refund_status' => $refundStatus,
            ],
            'Refunded through Stripe.',
        );
    }

    /**
     * The PaymentIntent for this order.
     *
     * `transaction_id` holds whichever of the two Stripe ids was written last:
     * start() stores the Checkout session (`cs_...`) and the webhook replaces
     * it with the PaymentIntent (`pi_...`). Settlement needs the intent, so a
     * session id is exchanged for one rather than sent to an endpoint that
     * will reject it.
     */
    private function paymentIntentId(Order $order): ?string
    {
        $ref = trim((string) $order->transaction_id);

        if ($ref === '') {
            return null;
        }

        if (! str_starts_with($ref, 'cs_')) {
            return $ref;
        }

        $session = $this->stripeAttempt('GET', '/v1/checkout/sessions/' . urlencode($ref));
        $intent = $session['body']['payment_intent'] ?? null;

        return is_string($intent) && $intent !== '' ? $intent : null;
    }

    /* -------------------------------------------------------- reconciliation */

    /**
     * Stripe's books, a page at a time.
     *
     *   GET /v1/charges?created[gte]=&created[lte]=&limit=&starting_after=
     *   GET /v1/refunds?created[gte]=&created[lte]=&limit=&starting_after=
     *
     * CHARGES, NOT CHECKOUT SESSIONS, and not PaymentIntents either. A session
     * is an intention — it exists whether or not anybody paid, and the list is
     * mostly abandoned baskets. A charge is money. That is the question this
     * report asks, so that is the object it reads.
     *
     * Stripe pages with `starting_after`, which is the id of the last object
     * you were given, plus a `has_more` flag. The cursor this returns is
     * therefore an object id and nothing else, which is also what makes it safe
     * to checkpoint: it describes a position in Stripe's own ordering rather
     * than a count of rows we think we have seen.
     *
     * `created` is a UNIX INTEGER on this API, both in the filter and in the
     * response. Not a string, not RFC3339. Sending a date string here does not
     * error — Stripe coerces it to 0 — and the request then quietly asks for
     * everything since 1970, which pages until the rate limit rather than
     * failing in a way anyone would notice.
     */
    public function listRemotePayments(ReconcileWindow $window, ?string $cursor, int $limit): RemotePage
    {
        return $this->listStripe('/v1/charges', $window, $cursor, $limit, RemoteTxn::PAYMENT);
    }

    public function listRemoteRefunds(ReconcileWindow $window, ?string $cursor, int $limit): RemotePage
    {
        return $this->listStripe('/v1/refunds', $window, $cursor, $limit, RemoteTxn::REFUND);
    }

    public function remoteSourceLabel(): string
    {
        return 'api.stripe.com /v1/charges and /v1/refunds';
    }

    private function listStripe(string $path, ReconcileWindow $window, ?string $cursor, int $limit, string $kind): RemotePage
    {
        if (! $this->configured()) {
            return RemotePage::unsupported('Stripe has no secret key stored, so its books cannot be read.');
        }

        $query = [
            'created' => [
                'gte' => $window->from->getTimestamp(),
                'lte' => $window->to->getTimestamp(),
            ],
            // Stripe's own ceiling. Asking for more is a 400, not a clamp.
            'limit' => max(1, min($limit, 100)),
        ];

        if ($cursor !== null && trim($cursor) !== '') {
            $query['starting_after'] = trim($cursor);
        }

        $attempt = $this->stripeAttempt('GET', $path . '?' . http_build_query($query));

        if (! $attempt['ok'] || ! is_array($attempt['body'] ?? null)) {
            return RemotePage::failed($attempt['error'] ?? 'unreachable', $attempt['status']);
        }

        $data = $attempt['body']['data'] ?? [];
        $data = is_array($data) ? $data : [];

        $items = [];
        $lastId = null;

        foreach ($data as $row) {
            if (! is_array($row)) {
                continue;
            }

            $id = (string) ($row['id'] ?? '');

            if ($id === '') {
                continue;
            }

            $lastId = $id;
            $items[] = $kind === RemoteTxn::PAYMENT ? $this->chargeToTxn($row, $id) : $this->refundToTxn($row, $id);
        }

        $hasMore = ($attempt['body']['has_more'] ?? false) === true;

        return RemotePage::of($items, $hasMore ? $lastId : null);
    }

    private function chargeToTxn(array $charge, string $id): RemoteTxn
    {
        $status = (string) ($charge['status'] ?? '');
        $captured = ($charge['captured'] ?? true) === true;

        /*
         * `succeeded` is not the same as "captured". A PaymentIntent created
         * with manual capture produces a charge that is succeeded and NOT
         * captured, which is an authorisation Stripe will release in about a
         * week. Calling that settled money would put it in the same list as
         * money actually taken, and the owner would go looking for funds that
         * are not there.
         */
        $state = match (true) {
            $status === 'succeeded' && $captured => RemoteTxn::SETTLED,
            $status === 'succeeded' => RemoteTxn::AUTHORISED,
            $status === 'pending' => RemoteTxn::AUTHORISED,
            default => RemoteTxn::DEAD,
        };

        /*
         * The PaymentIntent is what `payments.provider_ref` holds — the webhook
         * writes `$object['payment_intent']` — so it is the key that matches,
         * and the charge id is what a human pastes into Stripe's own search.
         * Both are carried; matching on one of them alone is how a
         * reconciliation invents a crisis.
         */
        $intent = $charge['payment_intent'] ?? null;
        $reference = $charge['metadata']['order_number'] ?? null;

        return new RemoteTxn(
            provider: $this->id(),
            kind: RemoteTxn::PAYMENT,
            remoteId: $id,
            matchKeys: is_string($intent) && $intent !== '' ? [$intent] : [],
            reference: is_string($reference) && $reference !== '' ? $reference : null,
            // Stripe amounts are already integer MINOR units, which is how this
            // schema stores money. No conversion here, so none to get wrong.
            amountFils: (int) ($charge['amount'] ?? 0),
            currency: strtoupper((string) ($charge['currency'] ?? '')),
            state: $state,
            rawState: $status . ($captured ? '' : ' (uncaptured)'),
            createdAt: $this->stripeMoment($charge['created'] ?? null),
        );
    }

    private function refundToTxn(array $refund, string $id): RemoteTxn
    {
        $status = (string) ($refund['status'] ?? '');

        // `pending` counts, and matches PaymentRefunder::COUNTED on our side: a
        // refund Stripe has accepted is money the merchant no longer has, even
        // before the card network finishes with it.
        $state = in_array($status, ['succeeded', 'pending'], true) ? RemoteTxn::SETTLED : RemoteTxn::DEAD;

        /*
         * A Stripe refund carries no order reference of any kind — not
         * `client_reference_id`, not our metadata. What it does carry is the
         * PaymentIntent and the charge it reverses, and `payments.provider_ref`
         * holds that intent. Passed as PARENT keys rather than match keys: they
         * identify the payment, not the refund, and letting them match a refund
         * would make a refund the provider never made look confirmed. See
         * RemoteTxn::parents().
         */
        $parents = array_values(array_filter([
            $refund['payment_intent'] ?? null,
            $refund['charge'] ?? null,
        ], fn ($v) => is_string($v) && $v !== ''));

        return new RemoteTxn(
            provider: $this->id(),
            kind: RemoteTxn::REFUND,
            remoteId: $id,
            matchKeys: [],
            reference: null,
            amountFils: (int) ($refund['amount'] ?? 0),
            currency: strtoupper((string) ($refund['currency'] ?? '')),
            state: $state,
            rawState: $status,
            createdAt: $this->stripeMoment($refund['created'] ?? null),
            parentKeys: $parents,
        );
    }

    /** A Stripe `created` is a unix integer, and occasionally a numeric string. */
    private function stripeMoment(mixed $created): ?CarbonImmutable
    {
        return is_numeric($created) ? CarbonImmutable::createFromTimestampUTC((int) $created) : null;
    }

    /**
     * `t=<unix>,v1=<hex>[,v1=<hex>]` — more than one v1 during a secret
     * rollover, so any one matching is a pass.
     */
    private function signatureValid(?string $header, string $raw, string $secret): bool
    {
        if ($header === null || $secret === '') {
            return false;
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            $pair = explode('=', trim($part), 2);

            if (count($pair) !== 2) {
                continue;
            }

            [$key, $value] = $pair;

            if ($key === 't') {
                $timestamp = $value;
            } elseif ($key === 'v1') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || ! ctype_digit($timestamp) || $signatures === []) {
            return false;
        }

        // Replay window. The signature itself never expires, so without this a
        // delivery captured today stays valid indefinitely.
        if (abs(time() - (int) $timestamp) > Signature::TOLERANCE) {
            return false;
        }

        foreach ($signatures as $candidate) {
            // The raw body, byte for byte -- re-encoding the parsed JSON would
            // change key order and never match.
            if (Signature::hmacMatches('sha256', $timestamp . '.' . $raw, $secret, $candidate)) {
                return true;
            }
        }

        return false;
    }
}
