<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Order;
use App\Services\Quiqup\QuiqupStates;
use App\Services\Quiqup\ShipmentSync;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * THE CUSTOMER'S DELIVERY "ROAD MAP" (Quiqup P3, QUIQUP-PLAN §7.1).
 *
 *   Placed → Confirmed → Booked with courier → Picked up → At the delivery hub
 *   → Out for delivery → Delivered
 *
 * plus side steps where they happened: a missed delivery attempt and Delayed
 * (amber), Returning to us and Returned to us (red; Returned ends the list).
 *
 * VISIBLE ONLY WHEN THE ORDER HAS A SHIPMENT. `orders.ship_state` is written
 * only by ShipmentSync, so visible() is a look at a column the page already
 * has: an order with no booking costs NO query and renders today's page byte
 * for byte. In Internal testing only test orders can be booked, so every other
 * shopper sees exactly today's pages.
 *
 * ONE QUERY for an order that has one (load()): the newest shipment, its
 * applied events in time order, and -- per event, as a scalar subquery -- the
 * owner's own wording for that status from Store -> Quiqup -> Statuses.
 *
 * NEVER REGRESSES. Events are walked in Quiqup's own time order and the road
 * only ever moves forward: a late or out-of-order event, or Quiqup's own loop
 * (delivery_failed -> at_depot), cannot move a ticked step back. A stage that
 * recurs after a side step (out for delivery again, the day after a missed
 * attempt) moves to the end with its new time, which is the plan's picture.
 *
 * NOTHING PERSONAL. Only states, times, an attempt count, the delivery window
 * and Quiqup's own tracking link (re-checked against its two hosts). The
 * courier's free-text failure reason never leaves the admin.
 */
final class DeliveryTimeline
{
    /** state => [stage, kind, string key]. Stage 2..6 on the road; side steps carry the stage they happened at (unused). */
    private const MAP = [
        'pending' => [2, 'main', 'booked'],
        'ready_for_collection' => [2, 'main', 'booked'],
        'out_for_collection' => [2, 'main', 'collecting'],
        'collected' => [3, 'main', 'picked'],
        'received_at_depot' => [4, 'main', 'hub'],
        'at_depot' => [4, 'main', 'hub'],
        'in_transit' => [4, 'main', 'transit'],
        'scheduled' => [4, 'main', 'scheduled'],
        'out_for_delivery' => [5, 'main', 'out'],
        'delivery_complete' => [6, 'main', 'delivered'],
        'delivery_failed' => [0, 'warn', 'missed'],
        'on_hold' => [0, 'warn', 'delayed'],
        'return_to_origin' => [0, 'bad', 'returning'],
        'out_for_return' => [0, 'bad', 'returning'],
        'returned_to_origin' => [0, 'bad', 'returned'],
    ];

    /** The road's own name for each stage, for a stage not reached yet or skipped. */
    private const STAGE_KEYS = [2 => 'booked', 3 => 'picked', 4 => 'hub', 5 => 'out', 6 => 'delivered'];

    private const ICONS = ['placed' => 'bag', 'confirmed' => 'pack', 'booked' => 'box', 'collecting' => 'truck', 'picked' => 'truck',
        'hub' => 'pin', 'transit' => 'truck', 'scheduled' => 'clock', 'out' => 'truck', 'delivered' => 'home',
        'missed' => 'clock', 'delayed' => 'pause', 'returning' => 'back', 'returned' => 'x'];

    /**
     * Does this order get the timeline? A look at its own columns: no query.
     * A booking cancelled at Quiqup hides the courier steps (§6.2), and a
     * cancelled/refunded/failed order keeps today's page.
     */
    public static function visible(Order $order): bool
    {
        $state = (string) ($order->ship_state ?? '');

        return $state !== '' && $state !== 'cancelled' && OrderProgress::tone((string) $order->status) !== 'off';
    }

    /** The timeline for an order, or null (not visible, or nothing to show). One query when visible. */
    public static function for(Order $order): ?array
    {
        return self::visible($order) ? self::build($order, self::load((int) $order->getKey())) : null;
    }

    /** The newest shipment with its applied events, in Quiqup's time order. ONE query. */
    public static function load(int $orderId): Collection
    {
        $path = DB::connection()->getDriverName() === 'sqlite'
            ? "'$.statuses.' || e.state"
            : "CONCAT('$.statuses.', e.state)";

        return DB::table('shipments as s')
            ->leftJoin('shipment_events as e', function ($j) {
                $j->on('e.shipment_id', '=', 's.id')->whereNotNull('e.applied_at');
            })
            ->where('s.id', function ($q) use ($orderId) {
                $q->from('shipments')->where('order_id', $orderId)->selectRaw('max(id)');
            })
            ->orderBy('e.state_at')
            ->orderBy('e.id')
            ->select([
                's.state as s_state', 's.tracking_url', 's.delivery_before', 's.attempts as s_attempts',
                'e.state', 'e.state_at', 'e.attempts',
            ])
            ->selectRaw("(select json_extract(options, {$path}) from quiqup_config where id = 'sandbox') as wording")
            ->get();
    }

    /**
     * The road map from loaded rows. Pure: no query, no clock but $now.
     *
     * @return array{steps: list<array{key: string, kind: string, state: string, label: string, at: ?string, icon: string}>, stage: string, label: string, at_local: ?string, window_local: ?string, attempts: int, ended: bool, moving: bool, track_url: ?string, version: string}|null
     */
    public static function build(Order $order, Collection $rows, ?CarbonImmutable $now = null): ?array
    {
        $first = $rows->first();

        if ($first === null || (string) $first->s_state === '' || $first->s_state === 'cancelled') {
            return null;
        }

        $now ??= CarbonImmutable::now();
        $nodes = [
            ['key' => 'placed', 'kind' => 'main', 'stage' => 0, 'label' => (string) __('store.account.step_placed'), 'at' => $order->created_at],
            ['key' => 'confirmed', 'kind' => 'main', 'stage' => 1, 'label' => (string) __('store.account.step_confirmed'), 'at' => $order->paid_at],
        ];
        $max = 1;
        $missed = 0;

        foreach ($rows as $r) {
            $state = (string) ($r->state ?? '');

            if (! isset(self::MAP[$state])) {
                continue; // collection_failed, cancelled, error, unknown: no customer step (§6.2)
            }

            [$stage, $kind, $key] = self::MAP[$state];
            $at = $r->state_at === null ? null : CarbonImmutable::parse((string) $r->state_at, 'UTC');

            if ($state === 'delivery_failed') {
                $missed = max($missed + 1, (int) ($r->attempts ?? 0));
            }

            $node = ['key' => $key, 'kind' => $kind, 'stage' => $stage, 'label' => self::label($state, $missed, $r->wording ?? null), 'at' => $at];

            if ($kind !== 'main') {
                $last = array_key_last($nodes);

                if ($nodes[$last]['kind'] !== 'main' && $nodes[$last]['key'] === $key && $key !== 'missed') {
                    $nodes[$last]['at'] = $at; // returning, then out for return: one step
                } else {
                    $nodes[] = $node;
                }

                continue;
            }

            if ($stage < $max) {
                continue; // late, out of order, or Quiqup's own loop back: never regress
            }

            if ($stage === $max) {
                foreach ($nodes as $i => $n) {
                    if ($n['kind'] === 'main' && $n['stage'] === $stage) {
                        unset($nodes[$i]);
                    }
                }

                $nodes = array_values($nodes);
                $nodes[] = $node;

                continue;
            }

            for ($s = $max + 1; $s < $stage; $s++) {
                // A poll that skipped a stage: done, but with no invented time (§6.3).
                $nodes[] = ['key' => self::STAGE_KEYS[$s], 'kind' => 'main', 'stage' => $s, 'label' => (string) __('store.delivery.step_' . self::STAGE_KEYS[$s]), 'at' => null];
            }

            $nodes[] = $node;
            $max = $stage;
        }

        $last = $nodes[array_key_last($nodes)];
        $returning = in_array($last['key'], ['returning', 'returned'], true);
        $delivered = $last['key'] === 'delivered';
        $ended = $delivered || $last['key'] === 'returned' || in_array((string) $first->s_state, QuiqupStates::END, true);
        $steps = [];
        $count = count($nodes);

        foreach ($nodes as $i => $n) {
            $isLast = $i === $count - 1;
            $state = match (true) {
                $n['kind'] === 'warn' => $isLast ? 'warn now' : 'warn',
                $n['kind'] === 'bad' => $isLast ? 'bad now' : 'bad',
                $isLast && ! $delivered => 'now',
                default => 'done',
            };
            $steps[] = ['key' => $n['key'], 'kind' => $n['kind'], 'state' => $state, 'label' => $n['label'], 'at' => self::when($n['at']), 'icon' => self::ICONS[$n['key']] ?? 'box'];
        }

        if (! $returning && ! $delivered) {
            for ($s = $max + 1; $s <= 6; $s++) {
                $k = self::STAGE_KEYS[$s];
                $steps[] = ['key' => $k, 'kind' => 'main', 'state' => 'wait', 'label' => (string) __('store.delivery.step_' . $k), 'at' => null, 'icon' => self::ICONS[$k]];
            }
        }

        $window = null;

        if (! $ended && ! $returning && $first->delivery_before !== null) {
            $before = CarbonImmutable::parse((string) $first->delivery_before, 'UTC');

            if ($before->greaterThan($now)) {
                $local = StoreTime::display($before);
                $today = StoreTime::display($now);
                $window = (string) __('store.delivery.expected_by', ['time' => LocalDate::format($local, $local->isSameDay($today) ? 'H:i' : 'j M, H:i')]);
            }
        }

        $url = $ended ? null : ShipmentSync::trackingUrl($first->tracking_url ?? null);

        return [
            'steps' => $steps,
            'stage' => $last['key'],
            'label' => $last['label'],
            'at_local' => self::when($last['at']),
            'window_local' => $window,
            'attempts' => (int) ($first->s_attempts ?? 0),
            'ended' => $ended,
            'moving' => ! $ended,
            'track_url' => $url,
            'version' => self::version($order),
        ];
    }

    /**
     * The words for one Quiqup state: the owner's own from Store -> Quiqup ->
     * Statuses when he changed them from the shipped default, else the shop's
     * translated string (editable on the Translations screen like every other).
     */
    public static function label(string $state, int $n, ?string $wording): string
    {
        $key = self::MAP[$state][2] ?? null;

        if ($key === null) {
            return '';
        }

        $n = max(1, $n);

        if (is_string($wording) && $wording !== '') {
            $row = json_decode($wording, true);
            $loc = Locale::current() === 'ar' ? 'ar' : 'en';
            $own = is_array($row) && is_string($row[$loc] ?? null) ? trim($row[$loc]) : '';

            if ($own !== '' && $own !== (QuiqupStates::DEFAULTS[$state][$loc] ?? '')) {
                return str_replace('{n}', (string) $n, $own);
            }
        }

        return (string) __('store.delivery.step_' . $key, ['n' => $n]);
    }

    /**
     * The live refresh's ETag: the order's last delivery change and the
     * page's language. Read off the order row alone, so a 304 costs no
     * shipment query.
     */
    public static function version(Order $order): string
    {
        return substr(sha1($order->getKey() . '|' . $order->ship_state . '|' . $order->ship_state_at . '|' . Locale::current()), 0, 16);
    }

    /** The current stage's words for the list band: no query, off the order row. */
    public static function bandLabel(Order $order): ?string
    {
        if (! self::visible($order)) {
            return null;
        }

        $state = (string) $order->ship_state;

        if (! isset(self::MAP[$state])) {
            return null;
        }

        // The list has no attempt count to hand, so a missed attempt reads without one.
        return $state === 'delivery_failed' ? (string) __('store.delivery.band_missed') : self::label($state, 1, null);
    }

    private static function when(mixed $at): ?string
    {
        if ($at === null) {
            return null;
        }

        return LocalDate::format(StoreTime::display($at), 'j M, H:i');
    }
}
