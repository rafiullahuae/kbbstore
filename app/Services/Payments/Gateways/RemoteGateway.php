<?php

declare(strict_types=1);

namespace App\Services\Payments\Gateways;

use App\Models\Order;
use App\Services\Payments\GatewayCredentials;
use App\Services\Payments\PaymentConfirmer;
use App\Services\Payments\PaymentGateway;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Shared behaviour for the three gateways that talk to somebody else's API.
 *
 * Mostly this exists so the logging rule is written once and cannot be
 * forgotten in one of three places: request and response BODIES are never
 * logged. A Tabby checkout body carries the buyer's name, email and phone; a
 * Tamara order carries a full billing address; a Stripe session carries both.
 * What gets logged is the gateway, the endpoint, the HTTP status, and a
 * provider reference — enough to find the transaction in the provider's own
 * dashboard, and nothing that would be a breach if the log leaked.
 */
abstract class RemoteGateway implements PaymentGateway
{
    /** Shared hosting, and a shopper waiting on the response. */
    protected const TIMEOUT = 20;

    public function __construct(
        protected GatewayCredentials $credentials,
        protected PaymentConfirmer $confirmer,
    ) {}

    abstract protected function baseUrl(): string;

    /** @return array<string, string> */
    abstract protected function authHeaders(): array;

    public function feeFils(int $totalFils): int
    {
        return 0;
    }

    public function availableFor(int $totalFils, ?string $country = null): bool
    {
        return $totalFils > 0;
    }

    /**
     * A JSON call to the provider.
     *
     * Never throws: a BNPL provider being down must not turn into a 500 on our
     * checkout. Callers get null and turn that into "try another method".
     */
    protected function call(string $method, string $path, array $body = []): ?array
    {
        $url = rtrim($this->baseUrl(), '/') . '/' . ltrim($path, '/');

        try {
            $request = Http::withHeaders($this->authHeaders())
                ->timeout(self::TIMEOUT)
                ->acceptJson()
                ->asJson();

            /** @var Response $response */
            $response = match (strtoupper($method)) {
                'GET' => $request->get($url),
                'PUT' => $request->put($url, $body),
                default => $request->post($url, $body),
            };
        } catch (\Throwable $e) {
            // Message only. An exception's context can contain the request
            // body, and that is the thing we must not write down.
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

    /**
     * A JSON call whose FAILURE is worth keeping.
     *
     * call() collapses everything that is not a 2xx to null, which is the
     * right answer for checkout — the shopper does not care why Tabby is down,
     * only that they should pick something else. It is the wrong answer for
     * settlement: a capture or a refund that failed has to be written into
     * `payment_events` with enough of the provider's own answer to find the
     * transaction in their dashboard, or the merchant is left with "it didn't
     * work" and no way to tell a declined refund from an expired token.
     *
     * What comes back is still narrow by construction. The HTTP status, and a
     * short error code lifted from the NAMED error fields the three providers
     * use — Stripe's error.code/error.type, Tabby's errorType, Tamara's
     * message/error_code. Never the body: a failed capture response echoes the
     * order back, buyer block included.
     *
     * @return array{ok: bool, status: int|null, body: array|null, error: string|null}
     */
    protected function attempt(string $method, string $path, array $body = []): array
    {
        $url = rtrim($this->baseUrl(), '/') . '/' . ltrim($path, '/');

        try {
            $request = Http::withHeaders($this->authHeaders())
                ->timeout(self::TIMEOUT)
                ->acceptJson()
                ->asJson();

            /** @var Response $response */
            $response = match (strtoupper($method)) {
                'GET' => $request->get($url),
                'PUT' => $request->put($url, $body),
                default => $request->post($url, $body),
            };
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

    /**
     * A short machine code out of an error body, or null.
     *
     * Named keys only, capped in length, and stripped of anything that is not
     * an identifier character. A provider is free to put a customer's name in
     * an error message and one of them does; this makes it impossible for that
     * to end up in our audit table by accident.
     */
    protected function errorCode(?array $body): ?string
    {
        if ($body === null) {
            return null;
        }

        foreach ([
            $body['error']['code'] ?? null,
            $body['error']['type'] ?? null,
            $body['errorType'] ?? null,
            $body['error_code'] ?? null,
            $body['code'] ?? null,
            $body['status'] ?? null,
        ] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return substr(preg_replace('/[^A-Za-z0-9_.\-]/', '', $candidate) ?: '', 0, 64) ?: null;
            }
        }

        return null;
    }

    /**
     * Structured, and deliberately narrow. No bodies, no headers (they carry
     * the bearer token), no buyer fields.
     */
    protected function log(string $message, string $path, ?int $status = null, array $extra = []): void
    {
        Log::channel(config('logging.default'))->info('payments: ' . $message, array_merge([
            'gateway' => $this->id(),
            'endpoint' => $path,
            'status' => $status,
        ], $extra));
    }

    /**
     * Our own order number is the reference every provider echoes back, and it
     * is what a webhook is matched on. Unique by schema, and meaningless to
     * anyone who does not already have our database.
     */
    protected function reference(Order $order): string
    {
        return (string) $order->order_number;
    }

    protected function findOrderByReference(?string $reference): ?Order
    {
        if ($reference === null || trim($reference) === '') {
            return null;
        }

        return Order::where('order_number', trim($reference))->first();
    }

    /** Major units, 2dp, as a string — what all three of these APIs want. */
    protected function toMajor(int $fils): string
    {
        return number_format($fils / 100, 2, '.', '');
    }

    /**
     * Back to integer fils.
     *
     * Via a string and round(), never a bare (int) cast on a float: (int)
     * (10.10 * 100) is 1009 on a binary float, and that is a payment refused
     * for a one-fil mismatch on an order that was perfectly correct.
     */
    protected function toFils(mixed $major): int
    {
        return (int) round(((float) $major) * 100);
    }

    /** Where the provider sends the shopper back to. */
    protected function returnUrl(Order $order, string $outcome): string
    {
        $path = $outcome === 'success'
            ? '/checkout/success'
            : '/checkout/pending';

        return url(\App\Support\Url::external($path)) . '?order=' . urlencode($this->reference($order));
    }
}
