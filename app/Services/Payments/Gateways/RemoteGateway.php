<?php

declare(strict_types=1);

namespace App\Services\Payments\Gateways;

use App\Models\Order;
use App\Services\Payments\GatewayCredentials;
use App\Services\Payments\PaymentConfirmer;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\PaymentStart;
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
                /*
                 * DELETE IS NAMED, and until it was this fell through to POST.
                 *
                 * A caller asking for a DELETE got a POST to the same URL with
                 * no error anywhere: silent, and on a provider API the shape of
                 * "POST /webhooks/{id}" is a second registration rather than a
                 * removal. Both BNPL gateways need it and both arrived at it
                 * independently — TabbyGateway::syncWebhooks() prunes a stale
                 * registration, TamaraGateway::unregisterWebhook() removes one
                 * — and neither should be the caller that finds this out from a
                 * duplicated webhook in production.
                 *
                 * Listed rather than folded into the default so that a MISTYPED
                 * verb keeps falling through to POST, which is the behaviour
                 * every caller written before this line relied on.
                 */
                'DELETE' => $request->delete($url, $body),
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
                /*
                 * DELETE IS NAMED, and until it was this fell through to POST.
                 *
                 * A caller asking for a DELETE got a POST to the same URL with
                 * no error anywhere: silent, and on a provider API the shape of
                 * "POST /webhooks/{id}" is a second registration rather than a
                 * removal. Both BNPL gateways need it and both arrived at it
                 * independently — TabbyGateway::syncWebhooks() prunes a stale
                 * registration, TamaraGateway::unregisterWebhook() removes one
                 * — and neither should be the caller that finds this out from a
                 * duplicated webhook in production.
                 *
                 * Listed rather than folded into the default so that a MISTYPED
                 * verb keeps falling through to POST, which is the behaviour
                 * every caller written before this line relied on.
                 */
                'DELETE' => $request->delete($url, $body),
                default => $request->post($url, $body),
            };
        } catch (\Throwable $e) {
            $this->log('transport error', $path, null, ['error' => $e->getMessage()]);

            return [
                'ok' => false, 'status' => null, 'body' => null, 'error' => 'transport_error',
                // A category, never the exception text: that can carry the URL
                // and, on some transports, the request it was sending.
                'detail' => stripos($e->getMessage(), 'timed out') !== false
                    ? 'no answer within ' . self::TIMEOUT . ' seconds (timed out)'
                    : 'could not connect (' . class_basename($e) . ')',
            ];
        }

        $this->log('api call', $path, $response->status());

        $decoded = $response->json();
        $decoded = is_array($decoded) ? $decoded : null;

        return [
            'ok' => $response->successful(),
            'status' => $response->status(),
            'body' => $response->successful() ? $decoded : null,
            'error' => $response->successful() ? null : $this->errorCode($decoded),
            'detail' => $response->successful() ? null : $this->refusalDetail($decoded),
        ];
    }

    /**
     * The provider's own reason for refusing a request, made safe to print.
     * ═══════════════════════════════════════════════════════════════════════
     *
     * (Lane TM.) errorCode() keeps one identifier, which is right for an audit
     * column and too little for an owner reading an order: "400" alone does
     * not say WHICH field Tamara disliked. This keeps the provider's named
     * error parts — `message`, `error_code`, and each entry of `errors` (a
     * list of {error_code, message, field} or a map of field => messages) —
     * and nothing else from the body.
     *
     * SANITISED, NOT TRUSTED. Every piece is stripped to letters, digits and
     * plain punctuation; any run of five or more digits (a phone, a card, an
     * id) becomes "[number]" and anything shaped like an email "[email]", so
     * a provider that echoes the buyer's phone back in its message cannot put
     * it into an order note. Capped at 300 characters. Printed escaped on
     * every screen; it is data, never markup.
     */
    protected function refusalDetail(?array $body): ?string
    {
        if ($body === null) {
            return null;
        }

        $parts = [];
        $add = function (mixed $value, ?string $field = null) use (&$parts): void {
            if (! is_scalar($value) || is_bool($value)) {
                return;
            }

            $clean = self::safeText((string) $value);

            if ($clean === '') {
                return;
            }

            $field = $field !== null ? self::safeText($field) : '';
            $parts[] = $field !== '' && ! str_contains($clean, $field) ? $field . ': ' . $clean : $clean;
        };

        $add($body['message'] ?? null);
        $add(is_array($body['error'] ?? null) ? ($body['error']['message'] ?? null) : ($body['error'] ?? null));
        $add($body['error_code'] ?? null);
        $add($body['errorType'] ?? null);

        $errors = $body['errors'] ?? null;

        if (is_array($errors)) {
            foreach (array_slice($errors, 0, 6, true) as $key => $entry) {
                if (is_array($entry) && array_is_list($entry)) {
                    // {"phone_number": ["is invalid"]}
                    foreach (array_slice($entry, 0, 2) as $m) {
                        $add($m, is_string($key) ? $key : null);
                    }
                } elseif (is_array($entry)) {
                    // [{"error_code": "...", "message": "...", "field": "..."}]
                    $field = $entry['field'] ?? $entry['property'] ?? $entry['path'] ?? (is_string($key) ? $key : null);
                    $add($entry['error_code'] ?? $entry['code'] ?? null, is_string($field) ? $field : null);
                    $add($entry['message'] ?? null, is_string($field) ? $field : null);
                } else {
                    $add($entry, is_string($key) ? $key : null);
                }
            }
        }

        $parts = array_values(array_unique($parts));

        if ($parts === []) {
            return null;
        }

        $out = implode('; ', $parts);

        return mb_strlen($out) > 300 ? rtrim(mb_substr($out, 0, 299)) . '…' : $out;
    }

    /** One piece of provider text, reduced to what is safe to show anybody. */
    private static function safeText(string $value): string
    {
        $value = preg_replace('/[^\s@]+@[^\s@]+/u', '[email]', $value) ?? '';
        $value = preg_replace('/\+?\d[\d\s\-]{3,}\d/', '[number]', $value) ?? '';
        $value = preg_replace('/(?:\[number\]|\d){5,}/', '[number]', $value) ?? '';
        $value = preg_replace("/[^\p{L}\p{N}\s_.,:;'()\/\[\]\-]/u", '', $value) ?? '';
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');

        return mb_substr($value, 0, 120);
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
     * A payment that did not start, written down where the owner can read it.
     *
     * (Lane TM; Tamara and Tabby.) Three places, each for a different reader:
     *   - the returned `detail` goes into the order note the checkout writes
     *     ("The payment could not be started. Tamara refused the checkout: HTTP
     *     400 — …"), which both order screens already show;
     *   - payment_logs, which the order's Payment journey reads;
     *   - laravel.log at ERROR, the one level the live .env keeps, with the
     *     order number on the line so `grep <number>` over SSH finds it.
     * None of them carries a body, a header or a token: `$why` is built from
     * the status and RemoteGateway::refusalDetail()'s sanitised text only.
     */
    protected function notStarted(Order $order, string $shopper, string $why, string $code, ?int $status = null, ?string $errorCode = null): PaymentStart
    {
        \App\Services\Payments\PaymentLog::record($this->id(), 'error', 'checkout_refused', $why, [
            'order' => $this->reference($order),
            'http_status' => $status,
            'error_code' => $errorCode,
            'reason' => $code,
        ], $this->credentials->mode($this->id()));

        try {
            \Illuminate\Support\Facades\Log::error('payments: checkout not started', [
                'gateway' => $this->id(),
                'order' => $this->reference($order),
                'status' => $status,
                'reason' => $code,
                'detail' => $why,
            ]);
        } catch (\Throwable) {
            // A log that cannot be written must not turn a refused payment into a 500.
        }

        return PaymentStart::failed($shopper, $why);
    }

    /**
     * The other half of the journey: the provider opened a session and the
     * shopper is being sent to its page. (Lane TM.)
     */
    protected function started(Order $order, ?int $status, string $providerRef): void
    {
        \App\Services\Payments\PaymentLog::record($this->id(), 'info', 'checkout_created',
            sprintf('%s accepted the checkout; the shopper was sent to %s\'s page.', $this->providerName(), $this->providerName()),
            ['order' => $this->reference($order), 'http_status' => $status, 'reference' => $providerRef],
            $this->credentials->mode($this->id()));
    }

    /** A verified notification from the provider, for the order's journey. (Lane TM.) */
    protected function journeyWebhook(Order $order, string $event, string $status, string $providerRef): void
    {
        $word = fn (string $v) => substr(preg_replace('/[^A-Za-z0-9_.\-]/', '', $v) ?? '', 0, 40);

        \App\Services\Payments\PaymentLog::record($this->id(), 'info', 'webhook',
            $this->providerName() . ' notification: ' . ($word($event) ?: 'no event type')
                . ($word($status) !== '' ? ' (' . $word($status) . ')' : '') . '.',
            ['order' => (string) $order->order_number, 'event_type' => $word($event), 'status' => $word($status), 'reference' => $word($providerRef)],
            $this->credentials->mode($this->id()));
    }

    protected function providerName(): string
    {
        return ucfirst($this->id());
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
