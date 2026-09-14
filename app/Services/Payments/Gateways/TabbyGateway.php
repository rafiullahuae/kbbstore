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
 * Tabby — buy now, pay later.
 *
 * Shapes taken from the merchant's working WooCommerce plugin, which is the
 * only reliable description of this API; the implementation is our own.
 *
 *   POST /api/v2/checkout          create a session, get a hosted web_url
 *   GET  /api/v2/payments/{id}     the authoritative state of a payment
 *   POST /api/v1/payments/{id}/captures   take the money after authorisation
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
class TabbyGateway extends RemoteGateway implements HandlesWebhooks
{
    private const API = 'https://api.tabby.ai';

    /** Tabby operates in these markets, and only these. */
    private const COUNTRIES = ['AE', 'SA', 'BH', 'KW', 'QA'];

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
