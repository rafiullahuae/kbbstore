<?php

declare(strict_types=1);

namespace App\Services\OwnerApp;

use App\Models\Order;
use App\Support\AdminRoles;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * What happened in the shop, in one append-only table, and who hears about it.
 *
 * Three readers share `owner_app_events`:
 *
 *   - the app's live cursor: "everything with id > N" while the app is open,
 *     one indexed range query every 25 seconds;
 *   - the app's Notifications screen;
 *   - Web Push, for when the app is closed.
 *
 * ── NEVER IN THE SHOPPER'S WAY ─────────────────────────────────────────────
 *
 * Writing an event is one INSERT, after the order's transaction commits.
 * Sending is NOT done there: events are buffered for the request and sent
 * from app()->terminating(), which Laravel runs after the response has gone
 * (fastcgi_finish_request on PHP-FPM). The checkout's customer has their page
 * before this server has opened a single connection to a push service. The
 * host has no queue worker, so this is the lightest place that still runs.
 *
 * NOTHING HERE MAY THROW. Every hook calls in through a try/catch, and the
 * inserts use the query builder, so a failed write cannot leave a dirty model
 * behind for somebody else's save (CLAUDE.md, the recordManifest landmine).
 */
final class OwnerAppEvents
{
    public const TYPES = ['order.new', 'order.status', 'order.failed', 'order.refunded', 'stock.low', 'stock.out'];

    /** What a member can switch on and off, and which events each switch covers. */
    public const GROUPS = [
        'orders' => ['order.new'],
        'status' => ['order.status'],
        'payments' => ['order.failed', 'order.refunded'],
        'stock' => ['stock.low', 'stock.out'],
    ];

    public const GROUP_LABELS = [
        'orders' => 'New orders',
        'status' => 'Order status changes',
        'payments' => 'Failed and refunded payments',
        'stock' => 'Low and out-of-stock products',
    ];

    /** New members hear about everything except routine status moves. */
    public const DEFAULT_GROUPS = ['orders', 'payments', 'stock'];

    public const PLACED = ['processing', 'onhold', 'shipped', 'completed'];

    private const UNPLACED = ['pending', 'failed', 'draft', 'checkout-draft'];

    private const KEEP_DAYS = 30;

    /** @var list<array{id:int,type:string,ref:?int,title:string,body:?string}> */
    private static array $pending = [];

    /** The capability a member needs to be told about an event of this type. */
    public static function capabilityFor(string $type): string
    {
        return str_starts_with($type, 'stock.') ? 'catalog.view' : 'orders.view';
    }

    public static function groupOf(string $type): ?string
    {
        foreach (self::GROUPS as $group => $types) {
            if (in_array($type, $types, true)) {
                return $group;
            }
        }

        return null;
    }

    /* ---------------------------------------------------------------- hooks */

    public static function orderCreated(Order $order): void
    {
        if ($order->wc_order_id !== null || (string) $order->origin === 'sample') {
            return;
        }

        if (in_array((string) $order->status, self::PLACED, true)) {
            self::newOrder($order);
        }
    }

    public static function orderMoved(Order $order, ?string $from, string $to): void
    {
        if ((string) $order->origin === 'sample' || $from === $to) {
            return;
        }

        $number = self::number($order);

        if (in_array($to, self::PLACED, true) && ($from === null || in_array($from, self::UNPLACED, true))) {
            self::newOrder($order);

            return;
        }

        if ($to === 'failed') {
            self::record('order.failed', (int) $order->id, "Payment failed · #{$number}", self::money($order).' · '.$order->paymentLabel());
        } elseif ($to === 'refunded') {
            self::record('order.refunded', (int) $order->id, "Refunded · #{$number}", self::money($order));
        } elseif ($from !== null && ! in_array($from, ['draft', 'checkout-draft'], true)) {
            self::record('order.status', (int) $order->id, "#{$number} is now ".self::statusWord($to), self::statusWord((string) $from).' → '.self::statusWord($to).' · '.self::money($order));
        }
    }

    /** A product's own stock column moved (an edit in the admin or the app). */
    public static function stockChanged(int $productId, string $name, ?int $before, ?int $after, bool $managed): void
    {
        if (! $managed || $after === null) {
            return;
        }

        $at = OwnerAppSettings::lowStock();
        $wasAbove = $before === null || $before > $at;

        if ($after <= 0 && ($before === null || $before > 0)) {
            self::record('stock.out', $productId, 'Out of stock: '.self::clip($name, 90), 'Nothing left to sell.');
        } elseif ($after > 0 && $after <= $at && $wasAbove) {
            self::record('stock.low', $productId, 'Low stock: '.self::clip($name, 90), $after.' left');
        }
    }

    /* ------------------------------------------------------------- recorder */

    public static function record(string $type, ?int $ref, string $title, ?string $body = null): void
    {
        try {
            $id = (int) DB::table('owner_app_events')->insertGetId([
                'type' => $type,
                'ref_id' => $ref,
                'title' => self::clip($title, 120),
                'body' => $body === null ? null : self::clip($body, 255),
                'created_at' => now(),
            ]);

            if (random_int(1, 200) === 1) {
                DB::table('owner_app_events')->where('created_at', '<', now()->subDays(self::KEEP_DAYS))->delete();
                DB::table('owner_app_logins')->where('created_at', '<', now()->subDays(90))->delete();
            }
        } catch (\Throwable) {
            return;
        }

        self::$pending[] = ['id' => $id, 'type' => $type, 'ref' => $ref, 'title' => $title, 'body' => $body];
        self::arm();
    }

    /** @return list<array{id:int,type:string,ref:?int,title:string,body:?string}> */
    public static function pending(): array
    {
        return self::$pending;
    }

    public static function forget(): void
    {
        self::$pending = [];
    }

    /**
     * Send the buffered events. Called from app()->terminating() — after the
     * response — and directly by the tests.
     */
    public static function flush(): int
    {
        $events = self::$pending;
        self::$pending = [];

        if ($events === []) {
            return 0;
        }

        try {
            return self::deliver($events);
        } catch (\Throwable $e) {
            Log::warning('owner app push failed', ['exception' => class_basename($e)]);

            return 0;
        }
    }

    /**
     * One push per device however many events the request produced: a bulk
     * status change of forty orders is one notification that says forty, not
     * forty buzzes.
     *
     * @param list<array{id:int,type:string,ref:?int,title:string,body:?string}> $events
     */
    public static function deliver(array $events): int
    {
        $subs = DB::table('owner_app_push_subscriptions as s')
            ->join('owner_app_devices as d', 'd.id', '=', 's.device_id')
            ->join('owner_app_members as m', 'm.id', '=', 'd.member_id')
            ->join('admin_users as u', 'u.id', '=', 'm.admin_user_id')
            ->whereNull('d.revoked_at')
            ->where('m.enabled', true)
            ->whereNotNull('m.pin_hash')
            ->get(['s.id', 's.endpoint', 's.p256dh', 's.auth', 'm.notify', 'm.admin_user_id']);

        if ($subs->isEmpty()) {
            return 0;
        }

        $users = \App\Models\AdminUser::query()->whereIn('id', $subs->pluck('admin_user_id')->unique()->all())->get()->keyBy('id');

        $payloads = [];
        $targets = [];
        foreach ($subs as $s) {
            $user = $users[(int) $s->admin_user_id] ?? null;
            $groups = self::groupsFrom($s->notify);
            $mine = array_values(array_filter($events, static fn (array $e) => in_array(self::groupOf($e['type']), $groups, true)
                && AdminRoles::can($user, self::capabilityFor($e['type']))));

            if ($mine === []) {
                continue;
            }

            $payloads[(int) $s->id] = self::payload($mine);
            $targets[] = $s;
        }

        return WebPush::send($targets, $payloads);
    }

    /** @return list<string> */
    public static function groupsFrom(mixed $notify): array
    {
        if (is_string($notify)) {
            $notify = json_decode($notify, true);
        }

        if (! is_array($notify)) {
            return self::DEFAULT_GROUPS;
        }

        return array_values(array_intersect(array_keys(self::GROUPS), $notify));
    }

    /** @param non-empty-list<array{id:int,type:string,ref:?int,title:string,body:?string}> $events */
    private static function payload(array $events): string
    {
        $last = $events[count($events) - 1];
        $n = count($events);

        if ($n === 1) {
            $title = $last['title'];
            $body = (string) $last['body'];
            $url = str_starts_with($last['type'], 'stock.') ? '#/products/'.(int) $last['ref'] : '#/orders/'.(int) $last['ref'];
        } else {
            $new = count(array_filter($events, static fn ($e) => $e['type'] === 'order.new'));
            $title = $new > 0 ? "{$new} new order".($new === 1 ? '' : 's').($n > $new ? ' and '.($n - $new).' more updates' : '') : "{$n} shop updates";
            $body = $last['title'];
            $url = '#/notifications';
        }

        return (string) json_encode([
            't' => self::clip($title, 120),
            'b' => self::clip($body, 200),
            'u' => $url,
            'g' => $n === 1 ? $last['type'].':'.(int) $last['ref'] : 'batch',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function newOrder(Order $order): void
    {
        $number = self::number($order);
        $first = trim((string) strtok(trim((string) (($order->billing_address['first_name'] ?? null) ?: ($order->shipping_address['first_name'] ?? ''))), ' '));

        self::record('order.new', (int) $order->id, "New order #{$number}", self::money($order).($first !== '' ? ' · '.$first : '').' · '.$order->paymentLabel());
        self::lowStockAfter((int) $order->id);
    }

    /**
     * The shelves an order just emptied. Stock leaves through StockClaim's raw
     * UPDATEs, which fire no model event, so it is read here: one query over
     * this order's lines. A product already reported in the last day is not
     * reported again for every later sale.
     */
    private static function lowStockAfter(int $orderId): void
    {
        try {
            $at = OwnerAppSettings::lowStock();

            $rows = DB::table('order_items as i')
                ->join('products as p', 'p.id', '=', 'i.product_id')
                ->leftJoin('product_variants as v', 'v.id', '=', 'i.product_variant_id')
                ->where('i.order_id', $orderId)
                ->get([
                    'p.id', 'p.name', 'i.name as line_name',
                    'p.manage_stock as p_managed', 'p.stock as p_stock',
                    'v.id as v_id', 'v.manage_stock as v_managed', 'v.stock as v_stock',
                ]);

            $hits = [];
            foreach ($rows as $r) {
                $variant = $r->v_id !== null && (bool) $r->v_managed;
                $managed = $variant || (bool) $r->p_managed;
                $stock = $variant ? $r->v_stock : $r->p_stock;

                if (! $managed || $stock === null || (int) $stock > $at) {
                    continue;
                }
                $hits[(int) $r->id] = [(int) $stock, $variant ? (string) $r->line_name : (string) $r->name];
            }

            if ($hits === []) {
                return;
            }

            $recent = DB::table('owner_app_events')
                ->whereIn('type', ['stock.low', 'stock.out'])
                ->whereIn('ref_id', array_keys($hits))
                ->where('created_at', '>=', now()->subDay())
                ->pluck('ref_id')->map(fn ($v) => (int) $v)->all();

            foreach ($hits as $productId => [$stock, $name]) {
                if (in_array($productId, $recent, true)) {
                    continue;
                }
                $stock <= 0
                    ? self::record('stock.out', $productId, 'Out of stock: '.self::clip($name, 90), 'Nothing left to sell.')
                    : self::record('stock.low', $productId, 'Low stock: '.self::clip($name, 90), $stock.' left');
            }
        } catch (\Throwable) {
        }
    }

    private static function arm(): void
    {
        $app = app();
        if ($app->bound('owner-app.events.armed')) {
            return;
        }
        $app->instance('owner-app.events.armed', true);
        $app->terminating(static fn () => self::flush());
    }

    private static function number(Order $order): string
    {
        return (string) ($order->order_number ?: $order->id);
    }

    private static function money(Order $order): string
    {
        return Money::plain((int) $order->total);
    }

    public static function statusWord(string $status): string
    {
        return match ($status) {
            'onhold', 'on-hold' => 'On hold',
            'pending' => 'Pending payment',
            default => ucfirst(str_replace(['-', '_'], ' ', $status)),
        };
    }

    private static function clip(string $s, int $max): string
    {
        $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');

        return mb_strlen($s) > $max ? rtrim(mb_substr($s, 0, $max - 1)).'…' : $s;
    }
}
