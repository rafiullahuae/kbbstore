<?php

declare(strict_types=1);

namespace App\Services\Quiqup;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The last-mile API, and nothing else (QUIQUP-PLAN §1.2, docs/plans/quiqup/
 * api-docs/qq-orders.yaml).
 *
 *   - The host is QuiqupSettings::HOSTS[$env]: two constants. No URL from a
 *     setting or a request reaches this class.
 *   - 10 s timeout, 5 s to connect. No automatic retries here: a retry is the
 *     cron's or the sheet's decision, made with the same uuid.
 *   - Logged on failure at ERROR (live runs LOG_LEVEL=error): environment,
 *     path WITHOUT ids' payloads, HTTP status and Quiqup's masked message.
 *     Never the key, never a request or response body.
 *
 * Tests never reach a real host: they swap this class for
 * tests/Support/FakeQuiqupClient.php, or drive it with Http::fake() and
 * Http::preventStrayRequests() (QuiqupContractTest).
 */
class QuiqupClient
{
    public function __construct(protected QuiqupSettings $settings) {}

    /** POST /orders. 201 = created, 200 = the same uuid replayed (no new order). */
    public function createOrder(string $env, array $payload): QuiqupResult
    {
        return $this->send($env, 'POST', '/orders', [], $payload);
    }

    /** GET /orders/{id}. An unknown id answers 403, not 404. */
    public function order(string $env, int $id): QuiqupResult
    {
        return $this->send($env, 'GET', '/orders/' . $id);
    }

    /**
     * GET /orders. `from`/`to` are YYYY-MM-DD (UAE days). Leaves `pending` out
     * unless filters[state] names it.
     *
     * @param  array{page?: int, per_page?: int, from: string, to: string, state?: string}  $q
     */
    public function listOrders(string $env, array $q): QuiqupResult
    {
        $query = [
            'page' => max(1, (int) ($q['page'] ?? 1)),
            'per_page' => max(1, min(100, (int) ($q['per_page'] ?? 100))),
            'from' => (string) $q['from'],
            'to' => (string) $q['to'],
        ];

        if (! empty($q['state']) && QuiqupStates::wellFormed($q['state'])) {
            $query['filters'] = ['state' => $q['state']];
        }

        return $this->send($env, 'GET', '/orders', $query);
    }

    /**
     * GET /order_label/{a,b,c}. An unknown id answers 200 application/pdf with
     * ZERO bytes, so a body that is empty or does not start with %PDF is a
     * failure here, never a file.
     *
     * @param  list<int>  $ids
     */
    public function labels(string $env, array $ids): QuiqupResult
    {
        $ids = array_values(array_filter(array_map('intval', $ids), fn (int $i) => $i > 0));

        if ($ids === [] || count($ids) > 25) {
            return new QuiqupResult(0, null, '', 'Between 1 and 25 Quiqup orders per label file.');
        }

        $r = $this->send($env, 'GET', '/order_label/' . implode(',', $ids), [], null, true);

        if (! $r->ok()) {
            return $r;
        }

        if ($r->body === '' || ! str_starts_with($r->body, '%PDF')) {
            return new QuiqupResult($r->status, null, '', 'Quiqup returned an empty label (HTTP ' . $r->status . ', ' . strlen($r->body) . ' bytes).', 'This happens when Quiqup does not know the order id.');
        }

        return $r;
    }

    /**
     * PUT /orders/batch/set_cancelled, ONE order per call: the batch is
     * all-or-nothing, so one refusal would cancel nothing for everyone (§3.3).
     */
    public function cancel(string $env, int $id, string $reason): QuiqupResult
    {
        return $this->send($env, 'PUT', '/orders/batch/set_cancelled', [], [
            'order_ids' => [$id],
            'reason' => mb_substr($reason, 0, 200),
        ]);
    }

    /** Test connection: the smallest real authenticated read (§3.4). */
    public function ping(string $env): QuiqupResult
    {
        $today = now('Asia/Dubai')->format('Y-m-d');

        return $this->listOrders($env, ['page' => 1, 'per_page' => 1, 'from' => $today, 'to' => $today]);
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $json
     */
    protected function send(string $env, string $method, string $path, array $query = [], ?array $json = null, bool $binary = false): QuiqupResult
    {
        if (! array_key_exists($env, QuiqupSettings::HOSTS)) {
            return new QuiqupResult(0, null, '', 'Unknown Quiqup environment.');
        }

        $key = $this->settings->apiKey($env);

        if ($key === '') {
            return new QuiqupResult(0, null, '', 'No ' . ($env === 'live' ? 'live' : 'staging') . ' API key is saved.', 'Add your staging API key in Store → Quiqup → Connection.');
        }

        $url = QuiqupSettings::HOSTS[$env] . $path;

        try {
            $req = Http::withToken($key)->timeout(10)->connectTimeout(5)->withoutRedirecting()
                ->withHeaders(['Accept' => $binary ? 'application/pdf' : 'application/json']);

            /** @var Response $res */
            $res = match ($method) {
                'GET' => $req->get($url, $query),
                'POST' => $req->asJson()->post($url, $json ?? []),
                'PUT' => $json === null ? $req->put($url) : $req->asJson()->put($url, $json),
                default => throw new \LogicException('method'),
            };
        } catch (ConnectionException $e) {
            $r = QuiqupErrors::transport(str_contains(strtolower($e->getMessage()), 'timed out') ? 'timed out' : 'could not connect');
            $this->log($env, $method, $path, $r);

            return $r;
        }

        $status = $res->status();

        if ($status >= 200 && $status < 300) {
            if ($binary) {
                return new QuiqupResult($status, null, (string) $res->body());
            }

            $decoded = $res->json();

            return new QuiqupResult(
                $status,
                is_array($decoded) ? $decoded : null,
                '',
                '',
                '',
                $status === 202 || strtolower((string) $res->header('x-projection-pending')) === 'true',
            );
        }

        $decoded = $binary ? null : $res->json();
        $r = QuiqupErrors::describe($status, is_array($decoded) ? $decoded : null, is_array($decoded) ? '' : mb_substr((string) $res->body(), 0, 400));
        $this->log($env, $method, $path, $r);

        return $r;
    }

    private function log(string $env, string $method, string $path, QuiqupResult $r): void
    {
        // The path carries Quiqup order ids only -- no customer data -- but
        // strip any query string all the same.
        Log::error('quiqup: call failed', [
            'env' => $env,
            'call' => $method . ' ' . strtok($path, '?'),
            'status' => $r->status,
            'error' => $r->error,
        ]);
    }
}
