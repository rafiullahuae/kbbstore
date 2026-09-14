<?php

declare(strict_types=1);

namespace App\Services\Payments\Gateways;

use App\Models\Order;
use App\Services\Payments\HandlesWebhooks;
use App\Services\Payments\PaymentStart;
use App\Services\Payments\Signature;
use App\Services\Payments\WebhookOutcome;
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
class StripeGateway extends RemoteGateway implements HandlesWebhooks
{
    private const API = 'https://api.stripe.com';

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
        try {
            $response = Http::withHeaders($this->authHeaders())
                ->timeout(self::TIMEOUT)
                ->asForm()
                ->post(rtrim($this->baseUrl(), '/') . $path, $this->flatten($payload));
        } catch (\Throwable $e) {
            $this->log('transport error', $path, null, ['error' => $e->getMessage()]);

            return null;
        }

        $this->log('api call', $path, $response->status());

        if (! $response->successful()) {
            return null;
        }

        $decoded = $response->json();

        return is_array($decoded) ? $decoded : null;
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
