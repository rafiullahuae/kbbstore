<?php

declare(strict_types=1);

namespace App\Services\Autopilot;

use App\Services\Pixels\CatalogIds;
use App\Support\StoreTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What the Autopilot API answers with. (Lane AP-API)
 *
 * ── ALLOWLISTED, NEVER A MODEL ──────────────────────────────────────────────
 * Every row is built here key by key from named columns. No email, phone,
 * name, address, IP, customer id, note or payment reference ever leaves: the
 * one customer fact is `new_customer`, worked out in SQL and returned as a
 * boolean. AutopilotApiTest sweeps every answer for planted PII.
 *
 * ── FLAT COST ───────────────────────────────────────────────────────────────
 * A page of orders is THREE queries however many orders and lines it holds
 * (orders with their refund sums as sub-selects, every line of the page, and
 * one grouped "first order per email" lookup). A page of products is two.
 * The day summary is three, whatever the range. Paging is by id cursor
 * (`after`), so there is no COUNT(*) and no OFFSET scan.
 *
 * Money is AED with two decimals (the schema stores fils). Days are the
 * shop's calendar (StoreTime, Asia/Dubai unless the owner changed it).
 */
final class ShopData
{
    /** Orders that are sales. `refunded` stays a sale whose net is what was kept. */
    public const SALE_STATUSES = ['processing', 'onhold', 'shipped', 'completed', 'refunded'];

    public const CANCELLED_STATUSES = ['cancelled', 'failed'];

    /** Refund rows that moved money. Pending ones are in flight, not yet net. */
    public const REFUND_DONE = ['succeeded'];

    public const MAX_PAGE = 200;

    public const MAX_DAYS = 400;

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: string, 3: string}|string start/end in UTC + the two days, or an error */
    public static function range(?string $from, ?string $to, int $defaultDays = 30): array|string
    {
        $zone = StoreTime::timezone();
        $today = StoreTime::now()->format('Y-m-d');

        foreach ([$from, $to] as $d) {
            if ($d !== null && $d !== '' && (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) !== 1 || ! checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4)))) {
                return 'from and to are days, YYYY-MM-DD.';
            }
        }

        $to = $to ?: $today;
        $from = $from ?: CarbonImmutable::createFromFormat('Y-m-d', $to, $zone)->subDays($defaultDays - 1)->format('Y-m-d');

        if ($from > $to) {
            return 'from is after to.';
        }

        $start = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $from . ' 00:00:00', $zone);
        $end = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $to . ' 23:59:59', $zone);

        if ($start->diffInDays($end) >= self::MAX_DAYS) {
            return 'At most ' . self::MAX_DAYS . ' days per call.';
        }

        return [$start->utc(), $end->utc(), $from, $to];
    }

    public static function money(int|string|null $fils): float
    {
        return round(((int) $fils) / 100, 2);
    }

    public static function family(?string $method): string
    {
        return match (strtolower((string) $method)) {
            'cod', 'cash_on_delivery' => 'cod',
            'stripe', 'card', 'apple_pay', 'google_pay', 'applepay', 'googlepay' => 'card',
            'tabby', 'tamara', 'postpay' => 'bnpl',
            '' => 'none',
            default => 'other',
        };
    }

    /** @return array{orders: list<array<string, mixed>>, next_after: int|null} */
    public function orders(CarbonImmutable $startUtc, CarbonImmutable $endUtc, int $after, int $limit): array
    {
        $limit = max(1, min(self::MAX_PAGE, $limit));
        $done = self::REFUND_DONE;

        $rows = DB::table('orders as o')
            ->whereNull('o.deleted_at')
            ->whereBetween('o.created_at', [$startUtc->format('Y-m-d H:i:s'), $endUtc->format('Y-m-d H:i:s')])
            ->where('o.id', '>', $after)
            ->orderBy('o.id')
            ->limit($limit + 1)
            ->select([
                'o.id', 'o.order_number', 'o.email', 'o.status', 'o.currency', 'o.subtotal', 'o.discount_total',
                'o.shipping_total', 'o.fee_total', 'o.tax_total', 'o.total', 'o.payment_method', 'o.shipping_address',
                'o.billing_address', 'o.coupon_code', 'o.src_channel', 'o.src_campaign', 'o.src_attr', 'o.created_at', 'o.updated_at',
            ])
            ->selectSub(DB::table('refunds')->whereColumn('refunds.order_id', 'o.id')->whereIn('status', $done)->selectRaw('COALESCE(SUM(amount), 0)'), 'refunded_fils')
            ->selectSub(DB::table('refunds')->whereColumn('refunds.order_id', 'o.id')->whereIn('status', $done)->selectRaw('MAX(created_at)'), 'refunded_last')
            ->get();

        $next = null;
        if ($rows->count() > $limit) {
            $rows = $rows->slice(0, $limit)->values();
            $next = (int) $rows->last()->id;
        }

        if ($rows->isEmpty()) {
            return ['orders' => [], 'next_after' => null];
        }

        $ids = $rows->pluck('id')->map(fn ($v) => (int) $v)->all();

        $lines = DB::table('order_items')->whereIn('order_id', $ids)->orderBy('id')
            ->get(['order_id', 'product_id', 'product_variant_id', 'sku', 'quantity', 'unit_price', 'total'])
            ->groupBy('order_id');

        $emails = $rows->pluck('email')->filter()->unique()->values()->all();
        $first = $emails === [] ? collect() : DB::table('orders')->whereNull('deleted_at')
            ->whereIn('email', $emails)->whereIn('status', self::SALE_STATUSES)
            ->groupBy('email')->selectRaw('email, MIN(id) as first_id')->pluck('first_id', 'email');

        $zone = StoreTime::timezone();
        $out = [];

        foreach ($rows as $r) {
            $total = (int) $r->total;
            $refunded = (int) $r->refunded_fils;
            $isSale = in_array((string) $r->status, self::SALE_STATUSES, true);
            $placed = CarbonImmutable::parse((string) $r->created_at, 'UTC')->setTimezone($zone);

            $items = [];
            foreach ($lines->get($r->id, collect()) as $l) {
                $pid = (int) $l->product_id;
                $vid = $l->product_variant_id ? (int) $l->product_variant_id : null;
                $items[] = [
                    'product_id' => $pid ? CatalogIds::product($pid) : null,
                    'catalog_id' => $pid ? CatalogIds::line($pid, $vid) : null,
                    'sku' => $l->sku !== null ? (string) $l->sku : null,
                    'qty' => (int) $l->quantity,
                    'unit_price' => self::money($l->unit_price),
                    'line_total' => self::money($l->total),
                ];
            }

            $out[] = [
                'id' => (int) $r->id,
                'number' => (string) $r->order_number,
                'placed_at' => $placed->toIso8601String(),
                'day' => $placed->format('Y-m-d'),
                'status' => (string) $r->status,
                'is_sale' => $isSale,
                'currency' => (string) ($r->currency ?: 'AED'),
                'subtotal' => self::money($r->subtotal),
                'discount' => self::money($r->discount_total),
                'shipping' => self::money($r->shipping_total),
                'fees' => self::money($r->fee_total),
                'vat' => self::money($r->tax_total),
                'total' => self::money($total),
                'refunded' => self::money($refunded),
                'refunded_at' => $r->refunded_last ? CarbonImmutable::parse((string) $r->refunded_last, 'UTC')->setTimezone($zone)->toIso8601String() : null,
                'cancelled_at' => in_array((string) $r->status, self::CANCELLED_STATUSES, true)
                    ? CarbonImmutable::parse((string) $r->updated_at, 'UTC')->setTimezone($zone)->toIso8601String() : null,
                'net' => $isSale ? self::money(max(0, $total - $refunded)) : 0.0,
                'coupon_used' => ($r->coupon_code ?? '') !== '',
                'payment_family' => self::family($r->payment_method),
                'payment_method' => self::slug($r->payment_method),
                'country' => self::country($r->shipping_address, $r->billing_address),
                'new_customer' => $r->email !== null && $r->email !== '' && isset($first[$r->email]) ? (int) $first[$r->email] === (int) $r->id : null,
                'source' => self::source($r->src_channel, $r->src_campaign, $r->src_attr),
                'items' => $items,
            ];
        }

        return ['orders' => $out, 'next_after' => $next];
    }

    /**
     * One row per shop day in the range, every day present (zeros included).
     *
     * @return list<array<string, mixed>>
     */
    public function daily(CarbonImmutable $startUtc, CarbonImmutable $endUtc, string $fromDay, string $toDay): array
    {
        $zone = StoreTime::timezone();
        $days = [];
        for ($d = CarbonImmutable::createFromFormat('Y-m-d', $fromDay, $zone); $d->format('Y-m-d') <= $toDay; $d = $d->addDay()) {
            $days[$d->format('Y-m-d')] = ['date' => $d->format('Y-m-d'), 'orders' => 0, 'gross' => 0, 'refunded_on_orders' => 0, 'net' => 0,
                'refunds_issued' => 0, 'cancelled' => 0, 'new_customers' => 0, 'cod_orders' => 0];
        }

        $s = $startUtc->format('Y-m-d H:i:s');
        $e = $endUtc->format('Y-m-d H:i:s');
        $done = self::REFUND_DONE;

        $orders = DB::table('orders as o')->whereNull('o.deleted_at')->whereBetween('o.created_at', [$s, $e])
            ->select(['o.id', 'o.email', 'o.status', 'o.total', 'o.payment_method', 'o.created_at'])
            ->selectSub(DB::table('refunds')->whereColumn('refunds.order_id', 'o.id')->whereIn('status', $done)->selectRaw('COALESCE(SUM(amount), 0)'), 'refunded_fils')
            ->orderBy('o.id')->get();

        // First sale per email, for only the emails that ordered in the range.
        $minId = (int) ($orders->min('id') ?? 0);
        $firstIds = $orders->isEmpty() ? [] : array_flip(DB::table('orders')->whereNull('deleted_at')->whereIn('status', self::SALE_STATUSES)
            ->whereNotNull('email')->groupBy('email')->havingRaw('MIN(id) >= ?', [$minId])
            ->selectRaw('MIN(id) as first_id')->pluck('first_id')->map(fn ($v) => (int) $v)->all());

        foreach ($orders as $o) {
            $day = CarbonImmutable::parse((string) $o->created_at, 'UTC')->setTimezone($zone)->format('Y-m-d');
            if (! isset($days[$day])) {
                continue;
            }

            if (in_array((string) $o->status, self::CANCELLED_STATUSES, true)) {
                $days[$day]['cancelled']++;

                continue;
            }

            if (! in_array((string) $o->status, self::SALE_STATUSES, true)) {
                continue;
            }

            $days[$day]['orders']++;
            $days[$day]['gross'] += (int) $o->total;
            $days[$day]['refunded_on_orders'] += (int) $o->refunded_fils;
            $days[$day]['net'] += max(0, (int) $o->total - (int) $o->refunded_fils);
            $days[$day]['new_customers'] += isset($firstIds[(int) $o->id]) ? 1 : 0;
            $days[$day]['cod_orders'] += self::family($o->payment_method) === 'cod' ? 1 : 0;
        }

        $refunds = DB::table('refunds')->whereIn('status', $done)->whereBetween('created_at', [$s, $e])->get(['amount', 'created_at']);
        foreach ($refunds as $r) {
            $day = CarbonImmutable::parse((string) $r->created_at, 'UTC')->setTimezone($zone)->format('Y-m-d');
            if (isset($days[$day])) {
                $days[$day]['refunds_issued'] += (int) $r->amount;
            }
        }

        return array_values(array_map(static function (array $d): array {
            foreach (['gross', 'refunded_on_orders', 'net', 'refunds_issued'] as $k) {
                $d[$k] = self::money($d[$k]);
            }

            return $d;
        }, $days));
    }

    /**
     * Refunds that moved money, and orders cancelled, in the range.
     *
     * @return array{refunds: list<array<string, mixed>>, cancellations: list<array<string, mixed>>}
     */
    public function refunds(CarbonImmutable $startUtc, CarbonImmutable $endUtc): array
    {
        $s = $startUtc->format('Y-m-d H:i:s');
        $e = $endUtc->format('Y-m-d H:i:s');
        $zone = StoreTime::timezone();
        $iso = static fn ($v) => $v ? CarbonImmutable::parse((string) $v, 'UTC')->setTimezone($zone)->toIso8601String() : null;

        $refunds = DB::table('refunds as r')->join('orders as o', 'o.id', '=', 'r.order_id')
            ->whereIn('r.status', self::REFUND_DONE)->whereBetween('r.created_at', [$s, $e])
            ->orderBy('r.id')->limit(2000)
            ->get(['r.id', 'r.order_id', 'o.order_number', 'r.amount', 'r.provider', 'r.created_at', 'o.created_at as placed_at', 'o.total'])
            ->map(static fn ($r) => [
                'id' => (int) $r->id,
                'order_id' => (int) $r->order_id,
                'order_number' => (string) $r->order_number,
                'amount' => self::money($r->amount),
                'order_total' => self::money($r->total),
                'provider' => self::slug($r->provider),
                'refunded_at' => $iso($r->created_at),
                'order_placed_at' => $iso($r->placed_at),
            ])->all();

        $cancelled = DB::table('orders')->whereNull('deleted_at')->whereIn('status', self::CANCELLED_STATUSES)
            ->whereBetween('updated_at', [$s, $e])->orderBy('id')->limit(2000)
            ->get(['id', 'order_number', 'status', 'total', 'payment_method', 'created_at', 'updated_at'])
            ->map(static fn ($o) => [
                'order_id' => (int) $o->id,
                'order_number' => (string) $o->order_number,
                'status' => (string) $o->status,
                'total' => self::money($o->total),
                'payment_family' => self::family($o->payment_method),
                'order_placed_at' => $iso($o->created_at),
                // The status change itself is not timestamped on the row; its last update is.
                'cancelled_at' => $iso($o->updated_at),
            ])->all();

        return ['refunds' => $refunds, 'cancellations' => $cancelled];
    }

    /** @return array{products: list<array<string, mixed>>, next_after: int|null} */
    public function products(int $after, int $limit): array
    {
        $limit = max(1, min(self::MAX_PAGE, $limit));

        $rows = DB::table('products')->whereNull('deleted_at')->where('id', '>', $after)->orderBy('id')->limit($limit + 1)
            ->get(['id', 'name', 'sku', 'type', 'status', 'is_visible', 'price', 'sale_price', 'sale_starts_at', 'sale_ends_at',
                'manage_stock', 'stock', 'stock_status', 'cost_price', 'brand_id']);

        $next = null;
        if ($rows->count() > $limit) {
            $rows = $rows->slice(0, $limit)->values();
            $next = (int) $rows->last()->id;
        }

        if ($rows->isEmpty()) {
            return ['products' => [], 'next_after' => null];
        }

        $variants = DB::table('product_variants')->whereIn('product_id', $rows->pluck('id')->all())->orderBy('position')->orderBy('id')
            ->get(['id', 'product_id', 'sku', 'price', 'sale_price', 'manage_stock', 'stock', 'stock_status'])
            ->groupBy('product_id');

        $now = now();
        $out = [];

        foreach ($rows as $p) {
            $price = $p->price !== null ? (int) $p->price : null;
            $sale = self::saleOn($p, $now) ? (int) $p->sale_price : null;
            $selling = $sale ?? $price;
            $cost = $p->cost_price !== null ? (int) $p->cost_price : null;

            $vs = [];
            foreach ($variants->get($p->id, collect()) as $v) {
                $vPrice = $v->price !== null ? (int) $v->price : $price;
                $vSale = $v->sale_price !== null && self::saleOn($p, $now, true) ? (int) $v->sale_price : null;
                $vs[] = [
                    'catalog_id' => CatalogIds::line((int) $p->id, (int) $v->id),
                    'sku' => $v->sku !== null ? (string) $v->sku : null,
                    'price' => $vPrice !== null ? self::money($vPrice) : null,
                    'sale_price' => $vSale !== null ? self::money($vSale) : null,
                    'stock' => $v->manage_stock ? (int) $v->stock : null,
                    'in_stock' => (string) $v->stock_status !== 'outofstock',
                ];
            }

            $out[] = [
                'id' => CatalogIds::product((int) $p->id),
                'name' => (string) $p->name,
                'sku' => $p->sku !== null ? (string) $p->sku : null,
                'type' => (string) $p->type,
                'published' => (string) $p->status === 'publish' && (bool) $p->is_visible,
                'price' => $price !== null ? self::money($price) : null,
                'sale_price' => $sale !== null ? self::money($sale) : null,
                'stock' => $p->manage_stock ? (int) $p->stock : null,
                'in_stock' => (string) $p->stock_status !== 'outofstock',
                'cost' => $cost !== null ? self::money($cost) : null,
                'margin_pct' => $cost !== null && $selling !== null && $selling > 0 ? round(($selling - $cost) * 100 / $selling, 1) : null,
                'variants' => $vs,
            ];
        }

        return ['products' => $out, 'next_after' => $next];
    }

    private static function saleOn(object $p, \DateTimeInterface $now, bool $windowOnly = false): bool
    {
        if (! $windowOnly && $p->sale_price === null) {
            return false;
        }

        $ts = $now->getTimestamp();
        if ($p->sale_starts_at !== null && strtotime((string) $p->sale_starts_at) > $ts) {
            return false;
        }

        return $p->sale_ends_at === null || strtotime((string) $p->sale_ends_at) >= $ts;
    }

    private static function slug(?string $v): ?string
    {
        $v = strtolower(trim((string) $v));

        return preg_match('/^[a-z0-9_\-]{1,32}$/', $v) === 1 ? $v : null;
    }

    private static function country(mixed $shipping, mixed $billing): ?string
    {
        foreach ([$shipping, $billing] as $a) {
            $a = is_string($a) ? json_decode($a, true) : $a;
            $c = is_array($a) ? strtoupper(trim((string) ($a['country'] ?? ''))) : '';
            if (preg_match('/^[A-Z]{2}$/', $c) === 1) {
                return $c;
            }
        }

        return null;
    }

    /** @return array<string, mixed>|null the last (or only) touch, without the landing path */
    private static function source(?string $channel, ?string $campaign, ?string $attr): ?array
    {
        if ($channel === null || $channel === '') {
            return null;
        }

        $j = is_string($attr) ? json_decode($attr, true) : null;
        $t = is_array($j) ? ($j['last'] ?? $j['first'] ?? null) : null;
        $t = is_array($t) ? $t : [];
        $first = is_array($j['first'] ?? null) ? $j['first'] : [];
        $click = ['g' => 'gclid', 'f' => 'fbclid', 't' => 'ttclid', 'm' => 'msclkid'];
        $clean = static fn ($v, int $n) => mb_substr(preg_replace('/[\x00-\x1F\x7F]+/u', '', (string) $v) ?? '', 0, $n);

        return [
            'channel' => $clean($channel, 16),
            'utm_source' => $clean($t['s'] ?? '', 60),
            'utm_medium' => $clean($t['m'] ?? '', 40),
            'utm_campaign' => $clean($t['c'] ?? $campaign ?? '', 100),
            'click_id' => $click[$t['k'] ?? ''] ?? null,
            // fbclid on either touch: the shop's own record of a Meta ad click.
            'fbclid' => ($t['k'] ?? '') === 'f' || ($first['k'] ?? '') === 'f',
            'days_to_order' => is_array($j) && isset($j['days']) ? (int) $j['days'] : null,
        ];
    }
}
