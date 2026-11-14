<?php

/*
 * Lane QQB preview only: a Quiqup that never leaves the machine, so the
 * schedule sheet can be photographed with green AND red rows. Deterministic
 * across requests (the preview's php -S has no memory between them): the
 * Quiqup id is derived from the uuid, and an order whose number ends in "7"
 * is refused with Quiqup's documented 422 text. Never loaded by the app.
 */

use App\Services\Quiqup\QuiqupClient;
use App\Services\Quiqup\QuiqupErrors;
use App\Services\Quiqup\QuiqupResult;

final class QqPreviewFake extends QuiqupClient
{
    public function createOrder(string $env, array $payload): QuiqupResult
    {
        usleep(350000);

        if (str_ends_with((string) ($payload['partner_order_id'] ?? ''), '7')) {
            return QuiqupErrors::describe(422, ['api_error' => ['message' => '[destination][address][address1] is missing', 'human' => '[destination][address][address1] is missing']]);
        }

        $id = 10000000 + (crc32((string) $payload['uuid']) % 8999999);

        return new QuiqupResult(201, ['order' => [
            'id' => $id, 'uuid' => $payload['uuid'], 'state' => 'pending',
            'state_updated_at' => now()->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'tracking_url' => 'https://track-parcel.quiqup.com/' . $payload['uuid'],
        ]]);
    }

    public function order(string $env, int $id): QuiqupResult
    {
        return new QuiqupResult(200, ['order' => ['id' => $id, 'state' => 'ready_for_collection', 'state_updated_at' => now()->utc()->format('Y-m-d\TH:i:s.u\Z')]]);
    }

    public function listOrders(string $env, array $q): QuiqupResult
    {
        return new QuiqupResult(200, ['current_page' => 1, 'per_page' => 100, 'results' => [], 'total' => 0]);
    }

    public function labels(string $env, array $ids): QuiqupResult
    {
        return new QuiqupResult(200, null, "%PDF-1.4\n% preview label\n%%EOF");
    }

    public function cancel(string $env, int $id, string $reason): QuiqupResult
    {
        return new QuiqupResult(201, [['id' => $id, 'state' => 'cancelled']]);
    }

    public function ping(string $env): QuiqupResult
    {
        usleep(400000);

        return new QuiqupResult(200, ['results' => [], 'total' => 0]);
    }
}
