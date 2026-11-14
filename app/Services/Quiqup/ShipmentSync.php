<?php

declare(strict_types=1);

namespace App\Services\Quiqup;

use App\Models\Order;
use App\Models\OrderNote;
use App\Models\QuiqupConfig;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Services\Orders\OrderStatus;
use App\Support\StoreTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The ONE place a Quiqup state reaches the shop (QUIQUP-PLAN §6.1).
 *
 * Every source -- webhook, poll, our own create/cancel answer -- goes through
 * record() (durable, deduplicated on the documented key) and then
 * applyEvent(). Nothing else writes shipments.state or orders.ship_state.
 *
 * NEVER REGRESS. An event whose state_updated_at is not newer than what the
 * shipment already holds is stored for the history and changes nothing.
 * Quiqup says events "may be redelivered and can arrive out of order".
 *
 * NEVER BACKWARDS ON THE ORDER. forward() only moves processing/onhold ->
 * shipped -> completed; cancelled only from shipped and only if the owner
 * mapped it; refunded/cancelled/failed/draft/pending orders are never touched.
 */
final class ShipmentSync
{
    private const RANK = ['processing' => 1, 'onhold' => 1, 'shipped' => 2, 'completed' => 3];

    private const TS = 'Y-m-d H:i:s.u';

    /** A courier moment older than this is history, not news: no push. */
    public const PUSH_FRESH_HOURS = 6;

    public function __construct(private QuiqupSettings $settings) {}

    /**
     * Normalise one Quiqup order object -- a webhook `payload`, a GET
     * /orders/{id} `order` or a list `results[]` entry -- into the facts we
     * keep. Null when it is not usable (no state, or no parseable time).
     *
     * Only non-personal facts survive: no names, phones, addresses,
     * coordinates, courier or mission ids, and never `otp_code` (§4.2).
     *
     * @param  array<string, mixed>  $o
     * @return array{state: string, state_at: CarbonImmutable, attempts: ?int, reason: ?string, facts: array<string, ?string>, tracking_url: ?string, uuid: ?string, id: ?int}|null
     */
    public static function observe(array $o): ?array
    {
        $state = $o['state'] ?? null;

        if (! QuiqupStates::wellFormed($state)) {
            return null;
        }

        $at = self::time($o['state_updated_at'] ?? null);

        if ($at === null) {
            return null;
        }

        $win = static fn (string $k, string $group) => self::time($o[$k] ?? ($o[$group][$k] ?? null))?->format(self::TS);
        $reason = $o['delivery_failure_reason'] ?? null;
        $reason = is_string($reason) && trim($reason) !== '' ? mb_substr(trim($reason), 0, 300) : null;
        $attempts = $o['delivery_attempts'] ?? null;

        return [
            'state' => (string) $state,
            'state_at' => $at,
            'attempts' => is_numeric($attempts) ? max(0, min(255, (int) $attempts)) : null,
            'reason' => $reason === null ? null : QuiqupErrors::mask($reason),
            'facts' => [
                'collection_after' => $win('collection_after', 'collection_time'),
                'collection_before' => $win('collection_before', 'collection_time'),
                'delivery_after' => $win('delivery_after', 'delivery_time'),
                'delivery_before' => $win('delivery_before', 'delivery_time'),
                'delivered_at' => self::time($o['delivered_at'] ?? null)?->format(self::TS),
            ],
            'tracking_url' => self::trackingUrl($o['tracking_url'] ?? null),
            'uuid' => is_string($o['uuid'] ?? null) && preg_match('/^[0-9a-f-]{36}$/i', $o['uuid']) === 1 ? strtolower($o['uuid']) : null,
            'id' => is_numeric($o['id'] ?? null) ? (int) $o['id'] : null,
        ];
    }

    /** UTC, with the offset parsed ("Z" in webhooks, "+04:00" in API answers). */
    public static function time(mixed $v): ?CarbonImmutable
    {
        if (! is_string($v) || trim($v) === '' || strlen($v) > 40) {
            return null;
        }

        try {
            return CarbonImmutable::parse($v)->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    /** Only Quiqup's own tracking hosts, else nothing (§5.1). */
    public static function trackingUrl(mixed $url): ?string
    {
        return is_string($url) && preg_match('#^https://track-(parcel|ae)\.quiqup\.com/[A-Za-z0-9-]{16,64}$#', $url) === 1 ? $url : null;
    }

    /**
     * Store one observation durably. Null when it is a duplicate of one
     * already stored (the documented dedupe triple).
     *
     * @param  array{state: string, state_at: CarbonImmutable, attempts: ?int, reason: ?string, facts: array<string, ?string>}  $obs
     */
    public function record(Shipment $shipment, array $obs, string $source, ?string $sha = null): ?ShipmentEvent
    {
        $inserted = DB::table('shipment_events')->insertOrIgnore([
            'shipment_id' => $shipment->getKey(),
            'state' => $obs['state'],
            'state_at' => $obs['state_at']->format(self::TS),
            'source' => $source,
            'attempts' => $obs['attempts'],
            'reason' => $obs['reason'],
            'body_sha256' => $sha,
            'facts' => json_encode($obs['facts']),
            'received_at' => now()->format(self::TS),
            'applied_at' => null,
        ]);

        if ($inserted !== 1) {
            return null;
        }

        return ShipmentEvent::query()
            ->where('shipment_id', $shipment->getKey())
            ->where('state', $obs['state'])
            ->where('state_at', $obs['state_at']->format(self::TS))
            ->first();
    }

    /**
     * Apply one stored event: the shipment, the order's ship_state and, if
     * the mapping says so and it is forward, the order status. One
     * transaction: if any part throws, nothing is applied and applied_at stays
     * NULL for the minute cron to try again.
     */
    public function applyEvent(ShipmentEvent $event): void
    {
        $moved = DB::transaction(function () use ($event) {
            $fresh = ShipmentEvent::query()->whereKey($event->getKey())->lockForUpdate()->first();

            if ($fresh === null || $fresh->applied_at !== null) {
                return null;
            }

            $shipment = Shipment::query()->whereKey($fresh->shipment_id)->lockForUpdate()->first();

            if ($shipment === null) {
                $fresh->forceFill(['applied_at' => now()])->save();

                return null;
            }

            $newer = $shipment->state_at === null || ($fresh->state_at !== null && $fresh->state_at->greaterThan($shipment->state_at));

            if (! $newer) {
                // Late or out of order: kept for the history, moves nothing.
                $fresh->forceFill(['applied_at' => now()])->save();

                return null;
            }

            $facts = is_array($fresh->facts) ? $fresh->facts : [];
            $state = (string) $fresh->state;
            $update = ['state' => $state, 'state_at' => $fresh->state_at];

            foreach (['collection_after', 'collection_before', 'delivery_after', 'delivery_before', 'delivered_at'] as $k) {
                if (! empty($facts[$k])) {
                    $update[$k] = $facts[$k];
                }
            }

            if ($fresh->attempts !== null) {
                $update['attempts'] = $fresh->attempts;
            }

            if ($fresh->reason !== null) {
                $update['last_reason'] = $fresh->reason;
            }

            if ($state === 'cancelled') {
                // Cancelled at Quiqup (by us or in Quiqdash): the booking is
                // no longer live, so the order may be booked again.
                $update['job'] = 'cancelled';
                $update['active_key'] = null;
            } elseif ($shipment->job !== 'cancelled') {
                $update['job'] = 'created';
            }

            $shipment->forceFill($update);
            $shipment->next_poll_at = $this->nextPoll($shipment);
            $shipment->save();

            DB::table('orders')->where('id', $shipment->order_id)->update([
                'ship_state' => $state,
                'ship_state_at' => $fresh->state_at?->format('Y-m-d H:i:s'),
            ]);

            $this->moveOrder($shipment, $state, $fresh->state_at);

            $fresh->forceFill(['applied_at' => now()])->save();

            return [(int) $shipment->order_id, $state, (int) ($fresh->attempts ?? $shipment->attempts ?? 0), $fresh->state_at];
        });

        if (is_array($moved)) {
            $this->notifyCustomer(...$moved);
        }
    }

    /**
     * (Quiqup P3) Web Push for the moments the owner ticked "Push" for on
     * Statuses -- out for delivery and a missed attempt by default (§6.2).
     * After the commit, never inside it; a moment older than PUSH_FRESH_HOURS
     * (a poll catching up on yesterday) is not announced; a failure here never
     * fails the apply.
     */
    private function notifyCustomer(int $orderId, string $state, int $attempts, ?\DateTimeInterface $at): void
    {
        try {
            if ($at === null || CarbonImmutable::instance($at)->lt(now()->subHours(self::PUSH_FRESH_HOURS))) {
                return;
            }

            $row = $this->settings->options()['statuses'][$state] ?? null;

            if (! is_array($row) || ! ($row['push'] ?? false) || \App\Support\DeliveryTimeline::label($state, 1, null) === '') {
                return;
            }

            $order = Order::query()->whereKey($orderId)->first();

            if ($order === null || ! \App\Support\DeliveryTimeline::visible($order)) {
                return;
            }

            $wording = json_encode($row);
            app(\App\Services\Push\PushAutomations::class)->delivery($order, $state, $attempts, static function (string $locale) use ($state, $attempts, $wording): string {
                $was = app()->getLocale();
                app()->setLocale($locale === 'ar' ? 'ar' : 'en');

                try {
                    return \App\Support\DeliveryTimeline::label($state, $attempts, $wording);
                } finally {
                    app()->setLocale($was);
                }
            });
        } catch (\Throwable $e) {
            Log::error('quiqup: delivery push failed', ['order' => $orderId, 'state' => $state, 'error' => mb_substr($e->getMessage(), 0, 200)]);
        }
    }

    /**
     * Record and, if new, apply. The convenience every non-webhook source
     * uses (poll, our own create and cancel answers).
     *
     * @param  array{state: string, state_at: CarbonImmutable, attempts: ?int, reason: ?string, facts: array<string, ?string>}  $obs
     */
    public function observeAndApply(Shipment $shipment, array $obs, string $source): bool
    {
        $event = $this->record($shipment, $obs, $source);

        if ($event === null) {
            return false;
        }

        try {
            $this->applyEvent($event);
        } catch (\Throwable $e) {
            Log::error('quiqup: apply failed, the minute cron will retry', ['shipment' => $shipment->getKey(), 'state' => $obs['state'], 'error' => mb_substr($e->getMessage(), 0, 200)]);
            self::markDue((int) $shipment->getKey());

            return false;
        }

        return true;
    }

    /** Re-apply what a crashed apply left behind. Bounded; called by the cron. */
    public function reapplyPending(int $limit = 50): int
    {
        $done = 0;

        $events = ShipmentEvent::query()
            ->whereNull('applied_at')
            ->where('received_at', '>=', now()->subDay()->format(self::TS))
            ->orderBy('state_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($events as $event) {
            try {
                $this->applyEvent($event);
                $done++;
            } catch (\Throwable $e) {
                Log::error('quiqup: re-apply failed', ['event' => $event->getKey(), 'error' => mb_substr($e->getMessage(), 0, 200)]);
            }
        }

        return $done;
    }

    /** Make the minute cron look at this shipment on its next tick. */
    public static function markDue(int $shipmentId): void
    {
        try {
            Shipment::query()->whereKey($shipmentId)->update(['next_poll_at' => now()->format(self::TS)]);
        } catch (\Throwable) {
            // The database that refused the apply may refuse this too; the
            // event row's NULL applied_at is still there for the cron.
        }
    }

    /**
     * May the order move from $from to $to? Forward only (§6.1).
     */
    public static function forward(string $from, string $to): bool
    {
        if ($to === '' || $from === $to || ! isset(self::RANK[$from])) {
            return false;
        }

        return match ($to) {
            'shipped', 'completed' => self::RANK[$to] > self::RANK[$from],
            'onhold' => $from === 'processing',
            'cancelled' => $from === 'shipped',
            default => false,
        };
    }

    private function moveOrder(Shipment $shipment, string $state, ?\DateTimeInterface $at): void
    {
        $order = Order::query()->whereKey($shipment->order_id)->first(['id', 'status']);

        if ($order === null) {
            return;
        }

        $label = QuiqupStates::adminLabel($state);
        $when = $at === null ? '' : ' (' . StoreTime::formatDate($at, 'j M, H:i') . ')';
        $env = $shipment->env === 'live' ? '' : ' [SANDBOX]';

        OrderNote::create([
            'order_id' => $order->id,
            'author' => 'Quiqup',
            'is_customer_note' => false,
            'content' => 'Quiqup: ' . $label . $when . $env,
        ]);

        $map = $this->settings->options()['statuses'][$state]['status'] ?? '';

        if (! is_string($map) || ! self::forward((string) $order->status, $map)) {
            return;
        }

        app(OrderStatus::class)->moveTo($order, $map, 'Quiqup', 'Quiqup: ' . $label . $env);
    }

    /**
     * When the cron should next read this shipment from Quiqup (§6.3):
     * moving states every 10 min, other in-flight states every 30, pending
     * every 60; doubled while webhooks are healthy; never at an end state or
     * after 30 days.
     */
    public function nextPoll(Shipment $s): ?CarbonImmutable
    {
        $state = (string) $s->state;

        if (in_array($state, QuiqupStates::END, true) || $s->job === 'cancelled' || $s->job === 'failed') {
            return null;
        }

        if ($s->created_at !== null && $s->created_at->lt(now()->subDays(30))) {
            return null;
        }

        $minutes = match ($state) {
            'out_for_delivery', 'out_for_collection' => 10,
            'pending' => 60,
            default => 30,
        };

        $last = QuiqupConfig::query()->whereKey($s->env)->value('last_webhook_at');

        if ($last !== null && CarbonImmutable::parse($last)->gt(now()->subHours(2))) {
            $minutes *= 2;
        }

        return CarbonImmutable::now()->addMinutes($minutes);
    }
}
