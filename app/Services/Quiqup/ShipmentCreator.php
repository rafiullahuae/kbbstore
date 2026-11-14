<?php

declare(strict_types=1);

namespace App\Services\Quiqup;

use App\Models\OrderNote;
use App\Models\Shipment;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Booking, cancelling and re-booking (QUIQUP-PLAN §3.2, §3.3, §4.3, §9).
 *
 * IDEMPOTENT END TO END.
 *   - request_uuid = UUIDv5(env:order:generation). A double click, two
 *     admins, the page and the cron racing, or a timeout all send the SAME
 *     uuid, and Quiqup answers 200 with the original order.
 *   - active_key UNIQUE: one live booking per order, enforced by the database.
 *   - the create CLAIM is one conditional UPDATE: exactly one worker sends; a
 *     claim older than 120 s is taken over and resent with the same uuid.
 *
 * NO QUEUE WORKER exists on the live host. schedule() only writes rows; the
 * open sheet drives step() a few at a time, and the minute cron finishes
 * whatever a closed tab left (runDue()).
 */
final class ShipmentCreator
{
    /** Minutes before retry n (1-based) of a create Quiqup did not answer (§9). */
    public const BACKOFF = [1, 2, 5, 10, 30];

    public const STALE_CLAIM_SECONDS = 120;

    private const TS = 'Y-m-d H:i:s.u';

    public function __construct(
        private QuiqupClient $client,
        private QuiqupSettings $settings,
        private OrderDraft $drafts,
        private ShipmentSync $sync,
    ) {}

    /**
     * Write one queued row per bookable order, in one transaction, and return
     * at once. Blocked orders are reported, never written.
     *
     * @param  list<int>  $ids
     * @return array{queued: list<int>, skipped: list<array{id: int, number: string, reason: string}>, blocked: list<array{id: int, number: string, blocks: list<array{field: string, text: string}>}>}
     */
    public function schedule(array $ids, array $defaults, array $rows, string $by): array
    {
        $drafts = $this->drafts->drafts($ids, $defaults, $rows);
        $out = ['queued' => [], 'skipped' => [], 'blocked' => []];

        DB::transaction(function () use ($drafts, $by, &$out) {
            foreach ($drafts as $d) {
                if ($d['booked'] !== null) {
                    $out['skipped'][] = ['id' => $d['id'], 'number' => $d['number'], 'reason' => 'Already booked' . ($d['booked']['qq_id'] ? ' · Quiqup #' . $d['booked']['qq_id'] : '')];

                    continue;
                }

                if ($d['blocks'] !== []) {
                    $out['blocked'][] = ['id' => $d['id'], 'number' => $d['number'], 'blocks' => $d['blocks']];

                    continue;
                }

                if ($this->queue($d, $by)) {
                    $out['queued'][] = $d['id'];
                } else {
                    $out['skipped'][] = ['id' => $d['id'], 'number' => $d['number'], 'reason' => 'Already booked'];
                }
            }
        });

        return $out;
    }

    /** Queue one draft. False when another live booking already holds the order. */
    private function queue(array $d, string $by): bool
    {
        $env = (string) $d['env'];
        $orderId = (int) $d['id'];

        // A failed attempt of the latest generation is re-used with its own
        // uuid (nothing was created at Quiqup); a cancelled one moves on.
        $last = Shipment::query()->where('order_id', $orderId)->where('env', $env)->orderByDesc('generation')->first();
        $generation = $last === null ? 0 : ($last->job === 'failed' && $last->qq_id === null ? $last->generation : $last->generation + 1);
        $uuid = OrderDraft::requestUuid($env, $orderId, $generation);
        $payload = $this->drafts->payload($d, $uuid);

        $values = [
            'order_id' => $orderId,
            'provider' => 'quiqup',
            'env' => $env,
            'generation' => $generation,
            'test' => $this->settings->mode() === 'testing',
            'request_uuid' => $uuid,
            'active_key' => $orderId,
            'service_kind' => $payload['kind'],
            'payment_mode' => $d['payment_mode'],
            'cod_fils' => $d['cod_fils'],
            'parcels' => $d['parcels'],
            'job' => 'queued',
            'tries' => 0,
            'last_error' => null,
            'last_error_at' => null,
            'claimed_at' => null,
            'next_poll_at' => now(),
            'draft' => $payload,
            'created_by' => mb_substr($by, 0, 100),
        ];

        try {
            DB::transaction(function () use ($last, $generation, $values) {
                if ($last !== null && $last->generation === $generation) {
                    $last->forceFill($values)->save();
                } else {
                    Shipment::create($values);
                }
            });
        } catch (QueryException $e) {
            // active_key UNIQUE refused a second live booking: the order is
            // already queued or booked by another click, tab or admin.
            return false;
        }

        return true;
    }

    /**
     * Send up to $limit queued creates. With $orderIds, only those (the
     * sheet's own rows); otherwise any that are due (the cron).
     *
     * @param  list<int>|null  $orderIds
     * @return list<array<string, mixed>>
     */
    public function step(?array $orderIds, int $limit = 5): array
    {
        $now = now()->format(self::TS);
        $stale = now()->subSeconds(self::STALE_CLAIM_SECONDS)->format(self::TS);

        $q = Shipment::query()
            ->where(fn ($w) => $w->where(fn ($a) => $a->where('job', 'queued')->where(fn ($b) => $b->whereNull('next_poll_at')->orWhere('next_poll_at', '<=', $now)))
                ->orWhere(fn ($a) => $a->where('job', 'creating')->where('claimed_at', '<', $stale)))
            ->orderBy('id')
            ->limit($limit);

        if ($orderIds !== null) {
            $q->whereIn('order_id', $orderIds);
        }

        $results = [];

        foreach ($q->get() as $s) {
            $results[] = $this->run($s);
        }

        return $results;
    }

    /** The cron's share: finish what a closed tab left queued. */
    public function runDue(int $limit = 5): int
    {
        return count(array_filter($this->step(null, $limit), fn ($r) => $r['job'] === 'created'));
    }

    /**
     * Claim and send one create. Returns the row's state for the sheet.
     *
     * @return array<string, mixed>
     */
    public function run(Shipment $s): array
    {
        $now = now()->format(self::TS);
        $stale = now()->subSeconds(self::STALE_CLAIM_SECONDS)->format(self::TS);

        $claimed = DB::table('shipments')->where('id', $s->id)
            ->where(fn ($w) => $w->where('job', 'queued')->orWhere(fn ($a) => $a->where('job', 'creating')->where('claimed_at', '<', $stale)))
            ->update(['job' => 'creating', 'claimed_at' => $now, 'tries' => DB::raw('tries + 1'), 'updated_at' => $now]);

        if ($claimed !== 1) {
            return $this->result(Shipment::query()->findOrFail($s->id));
        }

        $s = Shipment::query()->findOrFail($s->id);
        $payload = is_array($s->draft) ? $s->draft : null;

        if ($payload === null) {
            $s->forceFill(['job' => 'failed', 'active_key' => null, 'last_error' => 'The booking draft is missing; schedule it again.', 'last_error_at' => now()])->save();

            return $this->result($s);
        }

        $r = $this->client->createOrder($s->env, $payload);
        $order = $r->order();

        if ($r->ok() && $order !== null && is_numeric($order['id'] ?? null)) {
            $obs = ShipmentSync::observe($order);
            $s->forceFill([
                'qq_id' => (int) $order['id'],
                'qq_uuid' => $obs['uuid'] ?? null,
                'tracking_url' => ShipmentSync::trackingUrl($order['tracking_url'] ?? null),
                'job' => 'created',
                'draft' => null,
                'last_error' => null,
                'last_error_at' => null,
                'claimed_at' => null,
            ]);
            $s->next_poll_at = $this->sync->nextPoll($s) ?? now()->addHour();
            $s->save();

            $this->note($s, 'Booked with Quiqup (' . (QuiqupSettings::SERVICES[$s->service_kind] ?? $s->service_kind) . ', '
                . ($s->payment_mode === 'paid_on_delivery' ? 'COD AED ' . number_format(((int) $s->cod_fils) / 100, 2) : 'prepaid')
                . ', ' . $s->parcels . ' parcel' . ($s->parcels === 1 ? '' : 's') . ') — Quiqup #' . $s->qq_id
                . ($r->status === 200 ? ' (already booked; Quiqup returned the original)' : '') . ' — by ' . ($s->created_by ?: 'admin'));

            if ($obs !== null) {
                $this->sync->observeAndApply($s, $obs, 'api');
            }

            return $this->result(Shipment::query()->findOrFail($s->id));
        }

        $message = mb_substr($r->ok() ? 'Quiqup answered without an order id (HTTP ' . $r->status . ').' : $r->message(), 0, 500);

        if (($r->retryable() || $r->ok()) && $s->tries < count(self::BACKOFF)) {
            $s->forceFill([
                'job' => 'queued', 'claimed_at' => null, 'last_error' => $message, 'last_error_at' => now(),
                'next_poll_at' => now()->addMinutes(self::BACKOFF[max(0, $s->tries - 1)]),
            ])->save();

            return $this->result($s) + ['retrying' => true];
        }

        $s->forceFill(['job' => 'failed', 'active_key' => null, 'claimed_at' => null, 'last_error' => $message, 'last_error_at' => now(), 'next_poll_at' => null])->save();
        Log::error('quiqup: booking failed', ['order' => $s->order_id, 'env' => $s->env, 'status' => $r->status, 'error' => $r->error]);
        $this->note($s, 'Quiqup booking failed: ' . $message);

        return $this->result($s);
    }

    /**
     * Cancel one booking at Quiqup. Our order's status is NOT changed:
     * cancelling a delivery is not cancelling the sale (§3.3).
     */
    public function cancel(Shipment $s, string $reason, string $by): QuiqupResult
    {
        if (! $s->isCancellable()) {
            return new QuiqupResult(0, null, '', 'A courier is already assigned (' . QuiqupStates::adminLabel($s->state) . '): change it in Quiqdash or ask Quiqup support.');
        }

        $r = $this->client->cancel($s->env, (int) $s->qq_id, $reason !== '' ? $reason : 'Cancelled by the shop');

        if (! $r->ok()) {
            $this->note($s, 'Quiqup refused the cancel: ' . $r->message());

            return $r;
        }

        $obs = ['state' => 'cancelled', 'state_at' => \Carbon\CarbonImmutable::now()->utc(), 'attempts' => null, 'reason' => null,
            'facts' => ['collection_after' => null, 'collection_before' => null, 'delivery_after' => null, 'delivery_before' => null, 'delivered_at' => null]];
        $this->sync->observeAndApply($s, $obs, 'admin');
        $s = Shipment::query()->findOrFail($s->id);

        if ($s->job !== 'cancelled') {
            $s->forceFill(['job' => 'cancelled', 'active_key' => null, 'next_poll_at' => null])->save();
        }

        $this->note($s, 'Quiqup booking #' . $s->qq_id . ' cancelled by ' . $by . ($reason !== '' ? ': ' . mb_substr($reason, 0, 200) : '') . '. The order itself was not changed.');

        return $r;
    }

    /** Write one result row the sheet can paint. @return array<string, mixed> */
    public function result(Shipment $s): array
    {
        return [
            'order_id' => (int) $s->order_id,
            'job' => $s->job,
            'ok' => $s->job === 'created',
            'qq_id' => $s->qq_id,
            'tracking_url' => $s->tracking_url,
            'state' => $s->state,
            'state_label' => QuiqupStates::adminLabel($s->state),
            'error' => $s->job === 'created' ? null : $s->last_error,
            'env' => $s->env,
        ];
    }

    private function note(Shipment $s, string $text): void
    {
        OrderNote::create([
            'order_id' => $s->order_id,
            'author' => 'Quiqup',
            'is_customer_note' => false,
            'content' => mb_substr($text . ($s->env === 'live' ? '' : ' [STAGING]'), 0, 1000),
        ]);
    }
}
