<?php

declare(strict_types=1);

namespace App\Services\Quiqup;

use App\Models\QuiqupConfig;
use App\Models\Shipment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * The reconciliation poll: a BACKUP to webhooks, never the primary
 * (QUIQUP-PLAN §6.3).
 *
 *   - Due = shipments with qq_id, job=created and next_poll_at <= now.
 *   - In-flight, non-pending: one GET /orders page (100 per page, up to 3
 *     pages a tick) for the whole due set, matched by id.
 *   - Pending (the list hides them) and anything the list did not return:
 *     GET /orders/{id}, up to 5 a tick.
 *   - CEILING: 30 calls per hour per shop, whatever the backlog. Counted in
 *     the cache by clock hour.
 *   - On an API error a shipment backs off x2 per failure up to 2 h.
 */
final class QuiqupPoller
{
    public const HOURLY_CEILING = 30;

    public const MAX_LIST_PAGES = 3;

    public const MAX_SINGLE_READS = 5;

    public function __construct(
        private QuiqupClient $client,
        private QuiqupSettings $settings,
        private ShipmentSync $sync,
    ) {}

    /** @return array{polled: int, changed: int, calls: int} */
    public function pollDue(int $limit = 100): array
    {
        $due = Shipment::query()
            ->where('job', 'created')
            ->whereNotNull('qq_id')
            ->whereNotNull('next_poll_at')
            ->where('next_poll_at', '<=', now()->format('Y-m-d H:i:s.u'))
            ->orderBy('next_poll_at')
            ->limit($limit)
            ->get();

        $out = ['polled' => 0, 'changed' => 0, 'calls' => 0];

        foreach ($due->groupBy('env') as $env => $rows) {
            if (! $this->settings->configured((string) $env)) {
                continue;
            }

            $r = $this->pollEnv((string) $env, $rows);
            $out['polled'] += $r['polled'];
            $out['changed'] += $r['changed'];
            $out['calls'] += $r['calls'];
        }

        return $out;
    }

    /** Read one shipment now (Refresh from Quiqup, or --order=). Counts towards the ceiling. */
    public function refresh(Shipment $s): QuiqupResult
    {
        if ($s->qq_id === null) {
            return new QuiqupResult(0, null, '', 'This booking has no Quiqup number yet.');
        }

        if (! $this->spend()) {
            return new QuiqupResult(0, null, '', 'The hourly limit of ' . self::HOURLY_CEILING . ' Quiqup reads is used up. Try again after the hour.');
        }

        $r = $this->client->order($s->env, (int) $s->qq_id);

        if ($r->ok() && $r->order() !== null) {
            $obs = ShipmentSync::observe($r->order());

            if ($obs !== null) {
                $this->sync->observeAndApply($s, $obs, 'api');
            }
        }

        return $r;
    }

    /** @param Collection<int, Shipment> $rows @return array{polled: int, changed: int, calls: int} */
    private function pollEnv(string $env, Collection $rows): array
    {
        $calls = 0;
        $changed = 0;
        $seen = [];
        $error = null;

        $listable = $rows->filter(fn (Shipment $s) => $s->state !== 'pending');

        if ($listable->isNotEmpty()) {
            $oldest = $listable->min(fn (Shipment $s) => $s->created_at?->getTimestamp() ?? time());
            $from = CarbonImmutable::createFromTimestamp($oldest)->setTimezone('Asia/Dubai')->subDay()->format('Y-m-d');
            $to = now('Asia/Dubai')->format('Y-m-d');
            $want = $listable->keyBy('qq_id');

            for ($page = 1; $page <= self::MAX_LIST_PAGES; $page++) {
                if (! $this->spend()) {
                    break;
                }

                $calls++;
                $r = $this->client->listOrders($env, ['page' => $page, 'per_page' => 100, 'from' => $from, 'to' => $to]);

                if (! $r->ok()) {
                    $error = $r->error;
                    break;
                }

                $results = is_array($r->json['results'] ?? null) ? $r->json['results'] : [];

                foreach ($results as $item) {
                    $id = is_array($item) && is_numeric($item['id'] ?? null) ? (int) $item['id'] : 0;

                    if ($id > 0 && $want->has($id)) {
                        $seen[$id] = true;
                        $obs = ShipmentSync::observe($item);

                        if ($obs !== null && $this->sync->observeAndApply($want[$id], $obs, 'poll')) {
                            $changed++;
                        }
                    }
                }

                if (count($results) < 100 || count($seen) === $want->count()) {
                    break;
                }
            }
        }

        $singles = 0;

        foreach ($rows as $s) {
            if (isset($seen[$s->qq_id])) {
                continue;
            }

            if ($error !== null || $singles >= self::MAX_SINGLE_READS || ! $this->spend()) {
                continue;
            }

            $singles++;
            $calls++;
            $r = $this->client->order($env, (int) $s->qq_id);

            if ($r->ok() && $r->order() !== null) {
                $seen[$s->qq_id] = true;
                $obs = ShipmentSync::observe($r->order());

                if ($obs !== null && $this->sync->observeAndApply($s, $obs, 'poll')) {
                    $changed++;
                }
            } else {
                $this->backOff($s);
                $error ??= $r->error;
            }
        }

        // Everything that was read moves to its next slot; anything left
        // unread waits for the next tick (it is still due).
        foreach ($rows as $s) {
            if (! isset($seen[$s->qq_id])) {
                continue;
            }

            $fresh = Shipment::query()->find($s->getKey());

            if ($fresh !== null) {
                $fresh->forceFill(['next_poll_at' => $this->sync->nextPoll($fresh), 'tries' => 0])->save();
            }
        }

        QuiqupConfig::query()->whereKey($env)->update([
            'last_poll_at' => now(),
            'last_poll_error' => $error === null ? null : mb_substr($error, 0, 300),
        ]);

        return ['polled' => count($seen), 'changed' => $changed, 'calls' => $calls];
    }

    private function backOff(Shipment $s): void
    {
        $tries = min(10, (int) $s->tries + 1);
        $minutes = min(120, 10 * (2 ** ($tries - 1)));
        Shipment::query()->whereKey($s->getKey())->update([
            'tries' => $tries,
            'next_poll_at' => now()->addMinutes($minutes)->format('Y-m-d H:i:s.u'),
        ]);
    }

    /** One call off this hour's budget; false when it is spent. */
    public function spend(): bool
    {
        $key = 'quiqup:calls:' . now()->format('YmdH');
        Cache::add($key, 0, 3700);

        if ((int) Cache::get($key, 0) >= self::HOURLY_CEILING) {
            return false;
        }

        Cache::increment($key);

        return true;
    }

    public static function spentThisHour(): int
    {
        return (int) Cache::get('quiqup:calls:' . now()->format('YmdH'), 0);
    }
}
