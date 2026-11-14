<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderNote;
use App\Models\Shipment;
use App\Services\Quiqup\OrderDraft;
use App\Services\Quiqup\QuiqupClient;
use App\Services\Quiqup\QuiqupPoller;
use App\Services\Quiqup\QuiqupSettings;
use App\Services\Quiqup\QuiqupStates;
use App\Services\Quiqup\ShipmentCreator;
use App\Support\AdminRoles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Store -> Orders -> Schedule with Quiqup, labels, the Delivery card
 * (QUIQUP-PLAN §3.2, §3.3, §3.6). Capabilities in AdminCapabilities::RULES:
 * preview / shipment / refresh = quiqup.view; schedule / step / cancel /
 * rebook / labels = quiqup.ship. Every path named, failing closed.
 */
final class QuiqupShipController extends Controller
{
    public const MAX_IDS = 100;

    public function __construct(
        private QuiqupSettings $settings,
        private OrderDraft $drafts,
        private ShipmentCreator $creator,
    ) {}

    /** One request for the whole selection: each order's draft and checks. */
    public function preview(Request $request): JsonResponse
    {
        $ids = $this->ids($request);

        if ($ids === null) {
            return response()->json(['ok' => false, 'message' => 'Choose between 1 and ' . self::MAX_IDS . ' orders.'], 422);
        }

        $opts = $this->settings->options();

        return response()->json([
            'ok' => true,
            'mode' => $this->settings->mode(),
            'env' => $this->settings->env(),
            'services' => array_intersect_key(QuiqupSettings::SERVICES, array_flip($opts['services']['enabled'])),
            'default_service' => $opts['services']['default'],
            'parcels' => $opts['parcels'],
            'emirates' => array_keys(QuiqupSettings::EMIRATE_MAP),
            'rows' => array_map(fn ($d) => $this->publicRow($d), $this->drafts->drafts($ids, $this->defaults($request), $this->rows($request))),
        ]);
    }

    /** Write the queued rows in one transaction and return at once. */
    public function schedule(Request $request): JsonResponse
    {
        $ids = $this->ids($request);

        if ($ids === null) {
            return response()->json(['ok' => false, 'message' => 'Choose between 1 and ' . self::MAX_IDS . ' orders.'], 422);
        }

        $out = $this->creator->schedule($ids, $this->defaults($request), $this->rows($request), $this->by());

        return response()->json(['ok' => true] + $out);
    }

    /** Send up to 5 queued creates for these orders; the sheet calls again until none remain. */
    public function step(Request $request): JsonResponse
    {
        $ids = $this->ids($request);

        if ($ids === null) {
            return response()->json(['ok' => false, 'message' => 'No orders.'], 422);
        }

        $results = $this->creator->step($ids, 5);
        $remaining = Shipment::query()->whereIn('order_id', $ids)->whereIn('job', ['queued', 'creating'])->count();
        $waiting = Shipment::query()->whereIn('order_id', $ids)->where('job', 'queued')->where('next_poll_at', '>', now()->format('Y-m-d H:i:s.u'))->count();

        return response()->json(['ok' => true, 'results' => $results, 'remaining' => $remaining, 'waiting' => $waiting]);
    }

    public function cancel(Request $request): JsonResponse
    {
        $s = $this->active((int) $request->input('order_id'));

        if ($s === null) {
            return response()->json(['ok' => false, 'message' => 'This order has no live Quiqup booking.'], 404);
        }

        $r = $this->creator->cancel($s, mb_substr(trim((string) $request->input('reason', '')), 0, 200), $this->by());

        return $r->ok()
            ? response()->json(['ok' => true, 'message' => 'Cancelled at Quiqup. The order itself was not changed.', 'shipment' => $this->card((int) $s->order_id)])
            : response()->json(['ok' => false, 'message' => $r->message(), 'dashboard' => QuiqupSettings::DASHBOARDS[$s->env]], 422);
    }

    /** Cancel while Quiqup still allows it, then book again with generation + 1. */
    public function rebook(Request $request): JsonResponse
    {
        $orderId = (int) $request->input('order_id');
        $s = $this->active($orderId);

        if ($s !== null) {
            if ($s->env !== $this->settings->env()) {
                return response()->json(['ok' => false, 'message' => 'This booking was made in ' . ($s->env === 'live' ? 'Live' : 'Internal testing') . '; re-book it in that mode.'], 422);
            }

            $c = $this->creator->cancel($s, 'Re-booked by the shop', $this->by());

            if (! $c->ok()) {
                return response()->json(['ok' => false, 'message' => $c->message(), 'dashboard' => QuiqupSettings::DASHBOARDS[$s->env]], 422);
            }
        }

        $over = is_array($request->input('overrides')) ? $request->input('overrides') : [];
        $out = $this->creator->schedule([$orderId], ['allow_shipped' => true] + array_intersect_key($over, array_flip(['service', 'parcels'])), [$orderId => $over], $this->by());

        if ($out['queued'] === []) {
            $why = $out['blocked'][0]['blocks'][0]['text'] ?? $out['skipped'][0]['reason'] ?? 'It could not be queued.';

            return response()->json(['ok' => false, 'message' => $why], 422);
        }

        $results = $this->creator->step([$orderId], 1);
        $res = $results[0] ?? null;

        return response()->json([
            'ok' => (bool) ($res['ok'] ?? false),
            'message' => ($res['ok'] ?? false) ? 'Re-booked · Quiqup #' . $res['qq_id'] : (string) ($res['error'] ?? 'Queued; it will be sent again shortly.'),
            'shipment' => $this->card($orderId),
        ], ($res['ok'] ?? false) || ($res['job'] ?? '') === 'queued' ? 200 : 422);
    }

    /**
     * One PDF for up to 25 of our orders (§3.3). Quiqup's zero-byte "label"
     * for an unknown id is refused, never streamed.
     */
    public function labels(Request $request, QuiqupClient $client): Response|JsonResponse
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) $request->query('ids', ''))), fn ($i) => $i > 0)));

        if ($ids === [] || count($ids) > 25) {
            return response()->json(['ok' => false, 'message' => 'Between 1 and 25 orders per label file.'], 422);
        }

        $ships = Shipment::query()->whereIn('order_id', $ids)->where('job', 'created')->whereNotNull('qq_id')
            ->whereNotNull('active_key')->get(['id', 'order_id', 'env', 'qq_id']);

        if ($ships->isEmpty()) {
            return response()->json(['ok' => false, 'message' => 'None of these orders is booked with Quiqup yet.'], 422);
        }

        if ($ships->pluck('env')->unique()->count() > 1) {
            return response()->json(['ok' => false, 'message' => 'Print staging and live labels separately.'], 422);
        }

        $r = $client->labels((string) $ships->first()->env, $ships->pluck('qq_id')->map(fn ($i) => (int) $i)->all());

        if (! $r->ok()) {
            return response()->json(['ok' => false, 'message' => $r->message()], 422);
        }

        foreach ($ships as $s) {
            OrderNote::create(['order_id' => $s->order_id, 'author' => $this->by(), 'is_customer_note' => false, 'content' => 'Quiqup label downloaded (#' . $s->qq_id . ').']);
        }

        return response($r->body, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="quiqup-labels-' . now('Asia/Dubai')->format('Ymd-His') . '.pdf"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** The Delivery card on an order. */
    public function shipment(Request $request): JsonResponse
    {
        $id = (int) $request->query('order');
        $order = Order::withTrashed()->find($id, ['id', 'order_number', 'email', 'phone', 'shipping_address']);

        if ($order === null) {
            return response()->json(['ok' => false, 'message' => 'No such order.'], 404);
        }

        return response()->json(['ok' => true, 'order_number' => $order->order_number] + $this->card($id, $order));
    }

    public function refresh(Request $request, QuiqupPoller $poller): JsonResponse
    {
        $s = Shipment::query()->where('order_id', (int) $request->input('order_id'))->whereNotNull('qq_id')->orderByDesc('id')->first();

        if ($s === null) {
            return response()->json(['ok' => false, 'message' => 'This order has no Quiqup booking.'], 404);
        }

        $r = $poller->refresh($s);

        return $r->ok()
            ? response()->json(['ok' => true, 'message' => 'Up to date with Quiqup.', 'shipment' => $this->card((int) $s->order_id)])
            : response()->json(['ok' => false, 'message' => $r->message()], 422);
    }

    /** @return array<string, mixed> */
    private function card(int $orderId, ?Order $order = null): array
    {
        $order ??= Order::withTrashed()->find($orderId, ['id', 'order_number', 'email', 'phone', 'shipping_address']);
        $all = Shipment::query()->where('order_id', $orderId)->orderByDesc('id')->get();
        $s = $all->first();
        $events = $s === null ? collect() : $s->events()->get(['state', 'state_at', 'source', 'attempts']);
        $admin = auth('admin')->user();

        return [
            'mode' => $this->settings->mode(),
            'is_test' => $order !== null && $this->settings->isTestOrder($order),
            'can_flag' => $admin !== null && AdminRoles::can($admin, 'quiqup.settings'),
            'can_ship' => $admin !== null && AdminRoles::can($admin, 'quiqup.ship'),
            'shipment' => $s === null ? null : [
                'env' => $s->env,
                'test' => (bool) $s->test,
                'job' => $s->job,
                'qq_id' => $s->qq_id,
                'tracking_url' => $s->tracking_url,
                'state' => $s->state,
                'state_label' => QuiqupStates::adminLabel($s->state),
                'state_at' => $s->state_at?->toIso8601String(),
                'service' => QuiqupSettings::SERVICES[$s->service_kind] ?? $s->service_kind,
                'payment' => $s->payment_mode === 'paid_on_delivery' ? 'COD AED ' . number_format(((int) $s->cod_fils) / 100, 2) : 'Prepaid',
                'parcels' => $s->parcels,
                'attempts' => $s->attempts,
                'reason' => $s->last_reason,
                'error' => $s->last_error,
                'delivery_before' => $s->delivery_before?->toIso8601String(),
                'cancellable' => $s->isCancellable(),
                'dashboard' => QuiqupSettings::DASHBOARDS[$s->env],
                'history' => $all->count(),
            ],
            'events' => $events->map(fn ($e) => ['state' => $e->state, 'label' => QuiqupStates::adminLabel($e->state), 'at' => $e->state_at?->toIso8601String(), 'source' => $e->source, 'attempts' => $e->attempts])->values(),
        ];
    }

    /** @param array<string, mixed> $d @return array<string, mixed> */
    private function publicRow(array $d): array
    {
        unset($d['products'], $d['four_hour_time'], $d['release'], $d['cod_fils']);

        return $d;
    }

    private function active(int $orderId): ?Shipment
    {
        return $orderId > 0 ? Shipment::query()->where('active_key', $orderId)->first() : null;
    }

    /** @return list<int>|null */
    private function ids(Request $request): ?array
    {
        $raw = $request->input('ids');

        if (! is_array($raw)) {
            return null;
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $raw), fn ($i) => $i > 0)));

        return $ids === [] || count($ids) > self::MAX_IDS ? null : $ids;
    }

    /** @return array<string, mixed> */
    private function defaults(Request $request): array
    {
        $d = is_array($request->input('defaults')) ? $request->input('defaults') : [];

        return array_filter([
            'service' => is_string($d['service'] ?? null) && array_key_exists($d['service'], QuiqupSettings::SERVICES) ? $d['service'] : null,
            'parcels' => isset($d['parcels']) ? max(1, min(10, (int) $d['parcels'])) : null,
            'allow_shipped' => ! empty($d['allow_shipped']),
        ], fn ($v) => $v !== null && $v !== false);
    }

    /** @return array<int, array<string, mixed>> */
    private function rows(Request $request): array
    {
        $rows = is_array($request->input('rows')) ? $request->input('rows') : [];
        $out = [];

        foreach (array_slice($rows, 0, self::MAX_IDS, true) as $id => $r) {
            if (! is_array($r)) {
                continue;
            }

            $out[(int) $id] = array_filter([
                'phone' => is_string($r['phone'] ?? null) ? mb_substr($r['phone'], 0, 40) : null,
                'address1' => is_string($r['address1'] ?? null) ? mb_substr($r['address1'], 0, 300) : null,
                'emirate' => is_string($r['emirate'] ?? null) && array_key_exists($r['emirate'], QuiqupSettings::EMIRATE_MAP) ? $r['emirate'] : null,
                'service' => is_string($r['service'] ?? null) && array_key_exists($r['service'], QuiqupSettings::SERVICES) ? $r['service'] : null,
                'parcels' => isset($r['parcels']) ? max(1, min(10, (int) $r['parcels'])) : null,
                'note' => is_string($r['note'] ?? null) ? mb_substr($r['note'], 0, 250) : null,
            ], fn ($v) => $v !== null);
        }

        return $out;
    }

    private function by(): string
    {
        return (string) (auth('admin')->user()?->name ?? 'admin');
    }
}
