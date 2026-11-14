<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Quiqup\QuiqupClient;
use App\Services\Quiqup\QuiqupErrors;
use App\Services\Quiqup\QuiqupResult;
use App\Services\Quiqup\QuiqupSettings;
use Illuminate\Contracts\Foundation\Application;

/**
 * An in-memory Quiqup that behaves the way the docs say the real one does
 * (docs/plans/quiqup/api-docs): the same uuid replays the original order with
 * 200 instead of 201; an unknown id is 403 on GET and a zero-byte label;
 * cancel works only while pending / ready_for_collection; the list leaves
 * `pending` out. Every call is recorded in $calls.
 *
 * Scripted failures: push a QuiqupResult (or a closure returning one) onto
 * $script['createOrder'] etc. and the next call of that method answers it.
 *
 * It never touches the network. Feature tests also call
 * Http::preventStrayRequests(), so a code path that bypassed the fake would
 * throw instead of reaching api.staging.quiqup.com.
 */
final class FakeQuiqupClient extends QuiqupClient
{
    /** @var list<array{0: string, 1: string, 2: mixed}> */
    public array $calls = [];

    /** @var array<int, array<string, mixed>> */
    public array $orders = [];

    /** @var array<string, list<QuiqupResult|\Closure>> */
    public array $script = [];

    private int $nextId = 10000001;

    public static function install(Application $app): self
    {
        $fake = new self($app->make(QuiqupSettings::class));
        $app->instance(QuiqupClient::class, $fake);

        return $fake;
    }

    public function count(string $method): int
    {
        return count(array_filter($this->calls, fn ($c) => $c[0] === $method));
    }

    /** Move an order to a state at Quiqup, as Quiqdash's Bulk Change State would. */
    public function move(int $id, string $state, ?string $at = null, array $extra = []): void
    {
        $this->orders[$id] = array_merge($this->orders[$id], ['state' => $state, 'state_updated_at' => $at ?? now()->utc()->format('Y-m-d\TH:i:s.u\Z')], $extra);
    }

    private function scripted(string $method, array $args): ?QuiqupResult
    {
        if (! empty($this->script[$method])) {
            $next = array_shift($this->script[$method]);

            return $next instanceof \Closure ? $next(...$args) : $next;
        }

        return null;
    }

    public function createOrder(string $env, array $payload): QuiqupResult
    {
        $this->calls[] = ['createOrder', $env, $payload];

        if ($r = $this->scripted('createOrder', [$env, $payload])) {
            return $r;
        }

        foreach ($this->orders as $o) {
            if (($o['uuid'] ?? null) === ($payload['uuid'] ?? 'none')) {
                return new QuiqupResult(200, ['order' => $o]);
            }
        }

        $id = $this->nextId++;
        $uuid = (string) ($payload['uuid'] ?? \Illuminate\Support\Str::uuid());
        $this->orders[$id] = [
            'id' => $id,
            'uuid' => $uuid,
            'state' => 'pending',
            'state_updated_at' => now()->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'partner_order_id' => $payload['partner_order_id'] ?? null,
            'service_kind' => $payload['kind'] ?? 'partner_same_day',
            'payment_mode' => $payload['payment_mode'] ?? 'pre_paid',
            'tracking_url' => 'https://track-parcel.quiqup.com/' . $uuid,
            'delivery_attempts' => 0,
        ];

        return new QuiqupResult(201, ['order' => $this->orders[$id]]);
    }

    public function order(string $env, int $id): QuiqupResult
    {
        $this->calls[] = ['order', $env, $id];

        if ($r = $this->scripted('order', [$env, $id])) {
            return $r;
        }

        return isset($this->orders[$id])
            ? new QuiqupResult(200, ['order' => $this->orders[$id]])
            : QuiqupErrors::describe(403, ['error' => 'forbidden']);
    }

    public function listOrders(string $env, array $q): QuiqupResult
    {
        $this->calls[] = ['listOrders', $env, $q];

        if ($r = $this->scripted('listOrders', [$env, $q])) {
            return $r;
        }

        $rows = array_values(array_filter($this->orders, fn ($o) => isset($q['state']) ? $o['state'] === $q['state'] : $o['state'] !== 'pending'));
        $per = (int) ($q['per_page'] ?? 100);
        $page = array_slice($rows, ((int) ($q['page'] ?? 1) - 1) * $per, $per);

        return new QuiqupResult(200, ['current_page' => (int) ($q['page'] ?? 1), 'per_page' => $per, 'results' => $page, 'total' => count($rows)]);
    }

    public function labels(string $env, array $ids): QuiqupResult
    {
        $this->calls[] = ['labels', $env, $ids];

        if ($r = $this->scripted('labels', [$env, $ids])) {
            return $r;
        }

        foreach ($ids as $id) {
            if (! isset($this->orders[(int) $id])) {
                return new QuiqupResult(200, null, '', 'Quiqup returned an empty label (HTTP 200, 0 bytes).', 'This happens when Quiqup does not know the order id.');
            }
        }

        return new QuiqupResult(200, null, "%PDF-1.4\n% fake label for " . implode(',', $ids) . "\n%%EOF");
    }

    public function cancel(string $env, int $id, string $reason): QuiqupResult
    {
        $this->calls[] = ['cancel', $env, ['order_ids' => [$id], 'reason' => $reason]];

        if ($r = $this->scripted('cancel', [$env, $id, $reason])) {
            return $r;
        }

        $state = $this->orders[$id]['state'] ?? null;

        if (! in_array($state, ['pending', 'ready_for_collection'], true)) {
            return QuiqupErrors::describe(422, ['api_error' => ['message' => 'Order ' . $id . ' cannot be cancelled in state ' . $state, 'human' => 'Order ' . $id . ' cannot be cancelled in state ' . $state], 'error' => 'cannot be cancelled']);
        }

        $this->move($id, 'cancelled');

        return new QuiqupResult(201, [$this->orders[$id]]);
    }

    public function ping(string $env): QuiqupResult
    {
        $this->calls[] = ['ping', $env, null];

        if ($r = $this->scripted('ping', [$env])) {
            return $r;
        }

        return $this->settings->configured($env)
            ? new QuiqupResult(200, ['current_page' => 1, 'per_page' => 1, 'results' => [], 'total' => 0])
            : new QuiqupResult(0, null, '', 'No API key is saved.');
    }
}
