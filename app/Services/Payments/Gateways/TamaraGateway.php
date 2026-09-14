<?php

declare(strict_types=1);

namespace App\Services\Payments\Gateways;

use App\Models\Order;
use App\Services\Payments\HandlesWebhooks;
use App\Services\Payments\PaymentStart;
use App\Services\Payments\Signature;
use App\Services\Payments\WebhookOutcome;
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
class TamaraGateway extends RemoteGateway implements HandlesWebhooks
{
    private const LIVE = 'https://api.tamara.co';

    private const SANDBOX = 'https://api-sandbox.tamara.co';

    /** Tamara operates in these markets. */
    private const COUNTRIES = ['AE', 'SA', 'KW', 'BH', 'QA', 'OM'];

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
