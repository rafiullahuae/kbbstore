<?php

declare(strict_types=1);

namespace App\Services\CartTracking;

use App\Models\IpBlock;
use App\Services\Security\IpBlockList;
use App\Support\Countries;
use App\Support\IpRange;
use App\Support\Money;
use App\Support\StoreTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Everything Growth & Marketing → Cart Tracking reads.             (Lane CT)
 *
 * ALLOWLISTED ROWS, NEVER MODELS. Every row this returns is built key by key
 * here. A cart carries a token that IS the shopper's basket (whoever holds it
 * can read and change that cart); orders carry addresses and phone numbers;
 * customers carry password hashes. None of those columns is ever selected, let
 * alone returned. The screen is behind `carttracking.view`, and this is
 * still written as though it were not.
 *
 * BUILT FOR 100,000 CARTS. Every filter and every sort is a column of `carts`
 * with an index (see the migration); a page is LIMIT 50 plus six batched
 * look-ups for those fifty rows — lines, removed products, product names,
 * customers, orders, recovery emails — never one per row. Product rankings
 * read the covering (type, created_at, product_id, cart_id) index and are
 * cached for five minutes, like Search Terms.
 */
final class CartTrackingReport
{
    public const PER_PAGE = 50;

    public const TOP = 40;

    public const PERIODS = [
        'today' => 'Today',
        'yesterday' => 'Yesterday',
        '7d' => 'Last 7 days',
        '30d' => 'Last 30 days',
        'all' => 'All time',
        'custom' => 'Custom',
    ];

    /** The four the product tabs show (the owner: "Today / Last 7 days / All time", plus 30 days). */
    public const PRODUCT_PERIODS = ['today', '7d', '30d', 'all'];

    public const SORTS = ['id' => 'c.id', 'last' => 'c.ct_last_at', 'value' => 'c.ct_value'];

    public function __construct(private CartTrackingSettings $settings) {}

    /* ═══════════════════════════════════════════════ periods ═══ */

    /**
     * [from, to) in UTC for a period, in the STORE's time zone — "today" in
     * Dubai starts at 20:00 UTC the evening before, which is exactly the
     * mistake App\Support\StoreTime exists to stop.
     *
     * @return array{0:?CarbonImmutable, 1:?CarbonImmutable}
     */
    public function range(string $period, ?string $from = null, ?string $to = null): array
    {
        $today = StoreTime::startOfDayUtc();

        return match ($period) {
            'today' => [$today, null],
            'yesterday' => [StoreTime::startOfDayUtc(StoreTime::today()->subDay()), $today],
            '7d' => [StoreTime::startOfDayUtc(StoreTime::today()->subDays(6)), null],
            '30d' => [StoreTime::startOfDayUtc(StoreTime::today()->subDays(29)), null],
            'custom' => [
                $this->day($from),
                ($end = $this->day($to)) === null ? null : $end->addDay(),
            ],
            default => [null, null],
        };
    }

    private function day(?string $ymd): ?CarbonImmutable
    {
        if ($ymd === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd) !== 1) {
            return null;
        }

        try {
            return StoreTime::startOfDayUtc($ymd);
        } catch (\Throwable) {
            return null;
        }
    }

    /* ═══════════════════════════════════════════════ the Carts tab ═══ */

    /**
     * @param  array{period?:string, from?:?string, to?:?string, q?:?string, bot?:?string, bought?:?string, country?:?string, sort?:string, dir?:string, page?:int}  $p
     */
    public function carts(array $p): array
    {
        $threshold = (int) $this->settings->get('bot_threshold');
        $query = $this->filtered($p, $threshold);

        $total = (clone $query)->count();
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = max(1, min($pages, (int) ($p['page'] ?? 1)));

        $sort = self::SORTS[$p['sort'] ?? 'last'] ?? self::SORTS['last'];
        $dir = ($p['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $rows = $query
            ->orderBy($sort, $dir)
            ->orderBy('c.id', $dir)
            ->forPage($page, self::PER_PAGE)
            ->get($this->cartColumns());

        return [
            'rows' => $this->present($rows->all(), $threshold),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'per_page' => self::PER_PAGE,
            'summary' => $this->summary($p, $threshold),
        ];
    }

    /** The ids every row of the current filter matches, for "block all" / export. */
    public function matchingIds(array $p, int $limit = 100000): array
    {
        return $this->filtered($p, (int) $this->settings->get('bot_threshold'))
            ->orderBy('c.id', 'desc')
            ->limit($limit)
            ->pluck('c.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function filtered(array $p, int $threshold): Builder
    {
        $q = DB::table('carts as c')->whereNotNull('c.ct_last_at');

        [$from, $to] = $this->range((string) ($p['period'] ?? '7d'), $p['from'] ?? null, $p['to'] ?? null);

        if ($from !== null) {
            $q->where('c.ct_last_at', '>=', $from);
        }

        if ($to !== null) {
            $q->where('c.ct_last_at', '<', $to);
        }

        match ($p['bot'] ?? '') {
            'yes' => $q->where('c.ct_bot_score', '>=', $threshold),
            'no' => $q->where('c.ct_bot_score', '<', $threshold),
            default => null,
        };

        match ($p['bought'] ?? '') {
            'yes' => $q->whereNotNull('c.ct_order_id'),
            'no' => $q->whereNull('c.ct_order_id'),
            default => null,
        };

        $country = strtoupper((string) ($p['country'] ?? ''));

        if ($country === '--') {
            $q->whereNull('c.ct_country');
        } elseif (preg_match('/^[A-Z]{2}$/', $country) === 1) {
            $q->where('c.ct_country', $country);
        }

        $this->search($q, trim((string) ($p['q'] ?? '')));

        return $q;
    }

    /**
     * One box, five kinds of answer, decided by the shape of what was typed:
     * an address or range, an email, a cart or order number, a country code,
     * or a product name. Every branch is an indexed lookup.
     */
    private function search(Builder $q, string $term): void
    {
        if ($term === '') {
            return;
        }

        $term = mb_substr($term, 0, 80);

        // An address or a range: 203.0.113.7 · 203.0.113.0/24 · 2001:db8::/64
        if (preg_match('#^[0-9a-f:.]+(/\d{1,3})?$#i', $term) === 1 && (str_contains($term, '.') || substr_count($term, ':') >= 2)) {
            $range = IpRange::parse($term);

            if ($range !== null && str_contains($term, '/')) {
                $exact = IpRange::RANGE_PREFIX[$range['family']] === $range['prefix'];
                if ($exact) {
                    $q->where('c.ct_net', $range['cidr']);
                } elseif ($range['family'] === 4) {
                    $this->ipv4Range($q, explode('/', $range['cidr'])[0], $range['prefix']);
                } else {
                    // An IPv6 prefix other than /64: the hextets as typed, closed
                    // with a colon so "2001:db8" cannot also match "2001:db81:".
                    $q->where('c.ct_ip', 'like', $this->like(rtrim(explode('/', $term)[0], ':').':').'%');
                }
            } elseif ($range !== null) {
                $q->where('c.ct_ip', IpRange::normalise($term));
            } else {
                $q->where('c.ct_ip', 'like', $this->like($term).'%');
            }

            return;
        }

        if (str_contains($term, '@')) {
            $like = $this->like(mb_strtolower($term)).'%';

            $q->where(function (Builder $w) use ($like) {
                $w->whereIn('c.customer_id', DB::table('customers')->select('id')->where('email', 'like', $like))
                    ->orWhereIn('c.ct_order_id', DB::table('orders')->select('id')->where('email', 'like', $like))
                    ->orWhereIn('c.id', DB::table('cart_recoveries')->select('cart_id')->where('email', 'like', $like));
            });

            return;
        }

        $digits = ltrim($term, '#');

        if (preg_match('/^[A-Za-z0-9-]{1,40}$/', $digits) === 1 && preg_match('/\d/', $digits) === 1) {
            $q->where(function (Builder $w) use ($digits) {
                if (ctype_digit($digits)) {
                    $w->where('c.id', (int) $digits);
                }

                $w->orWhereIn('c.ct_order_id', DB::table('orders')->select('id')->where('order_number', $digits));
            });

            return;
        }

        if (preg_match('/^[A-Za-z]{2}$/', $term) === 1 && isset(Countries::NAMES[strtoupper($term)])) {
            $q->where('c.ct_country', strtoupper($term));

            return;
        }

        $productIds = DB::table('products')
            ->where('name', 'like', '%'.$this->like($term).'%')
            ->limit(200)
            ->pluck('id')
            ->all();

        if ($productIds === []) {
            $q->whereRaw('1 = 0');

            return;
        }

        $q->where(function (Builder $w) use ($productIds) {
            $w->whereIn('c.id', DB::table('cart_events')->select('cart_id')->whereIn('product_id', $productIds))
                ->orWhereIn('c.id', DB::table('cart_items')->select('cart_id')->whereIn('product_id', $productIds));
        });
    }

    /**
     * Exactly the IPv4 addresses inside $network/$prefix, as indexable prefix
     * matches on the stored dotted form. The whole octets the prefix fixes
     * become "203.0." and a partly fixed octet is enumerated — /20 is sixteen
     * third-octet values — so 10.0.0.0/8 can never match "1.2.3.4" or
     * "100.1.1.1", and /20 does not widen to the /16 around it. At most 128
     * alternatives (/1, /9, /17, /25); /0 is every IPv4 cart.
     */
    private function ipv4Range(Builder $q, string $network, int $prefix): void
    {
        $octets = array_map('intval', explode('.', $network));

        if ($prefix === 32) {
            $q->where('c.ct_ip', $network);

            return;
        }

        if ($prefix === 0) {
            $q->where('c.ct_ip', 'not like', '%:%');

            return;
        }

        $whole = intdiv($prefix, 8);
        $head = $whole > 0 ? implode('.', array_slice($octets, 0, $whole)).'.' : '';
        $spare = $prefix % 8;

        if ($spare === 0) {
            $q->where('c.ct_ip', 'like', $head.'%');

            return;
        }

        $values = range($octets[$whole], $octets[$whole] + (1 << (8 - $spare)) - 1);

        if ($whole === 3) {
            $q->whereIn('c.ct_ip', array_map(fn (int $v) => $head.$v, $values));

            return;
        }

        $q->where(function (Builder $w) use ($head, $values) {
            foreach ($values as $v) {
                $w->orWhere('c.ct_ip', 'like', $head.$v.'.%');
            }
        });
    }

    private function like(string $s): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
    }

    /** The tiles over the list: carts, bought, bot share, top countries — for the same filter minus search. */
    private function summary(array $p, int $threshold): array
    {
        $key = 'kbb.ct.summary.'.md5(json_encode([$p['period'] ?? '7d', $p['from'] ?? null, $p['to'] ?? null, $threshold]));

        return Cache::remember($key, 60, function () use ($p, $threshold) {
            $base = $this->filtered(['period' => $p['period'] ?? '7d', 'from' => $p['from'] ?? null, 'to' => $p['to'] ?? null], $threshold);

            $t = (clone $base)->selectRaw(
                'COUNT(*) AS carts, SUM(CASE WHEN c.ct_order_id IS NOT NULL THEN 1 ELSE 0 END) AS bought,'
                .' SUM(CASE WHEN c.ct_bot_score >= ? THEN 1 ELSE 0 END) AS bots,'
                .' SUM(CASE WHEN c.ct_order_id IS NOT NULL THEN c.ct_value ELSE 0 END) AS bought_value,'
                .' SUM(CASE WHEN c.ct_order_id IS NULL AND c.status = ? THEN c.ct_value ELSE 0 END) AS open_value',
                [$threshold, 'active']
            )->first();

            $countries = (clone $base)
                ->selectRaw('c.ct_country AS code, COUNT(*) AS n')
                ->groupBy('c.ct_country')
                ->orderByDesc('n')
                ->limit(12)
                ->get();

            $carts = (int) ($t->carts ?? 0);

            return [
                'carts' => $carts,
                'bought' => (int) ($t->bought ?? 0),
                'conversion' => $carts > 0 ? round(100 * (int) $t->bought / $carts, 1) : 0.0,
                'bots' => (int) ($t->bots ?? 0),
                'bot_share' => $carts > 0 ? round(100 * (int) $t->bots / $carts, 1) : 0.0,
                'bought_value' => Money::plain((int) ($t->bought_value ?? 0)),
                'open_value' => Money::plain((int) ($t->open_value ?? 0)),
                'countries' => $countries->map(fn ($r) => [
                    'code' => $r->code,
                    'name' => $r->code === null ? 'Unknown' : (Countries::NAMES[$r->code] ?? $r->code),
                    'n' => (int) $r->n,
                ])->all(),
            ];
        });
    }

    /** @return list<string> */
    private function cartColumns(): array
    {
        return ['c.id', 'c.status', 'c.customer_id', 'c.created_at', 'c.ct_ip', 'c.ct_net', 'c.ct_country',
            'c.ct_ua', 'c.ct_bot_flags', 'c.ct_bot_score', 'c.ct_speed_ms', 'c.ct_value', 'c.ct_added',
            'c.ct_removed', 'c.ct_first_at', 'c.ct_last_at', 'c.ct_order_id'];
    }

    /**
     * Fifty rows in, fifty allowlisted arrays out, with six batched look-ups.
     *
     * @param  list<object>  $rows
     */
    private function present(array $rows, int $threshold): array
    {
        if ($rows === []) {
            return [];
        }

        $ids = array_map(fn ($r) => (int) $r->id, $rows);

        $lines = DB::table('cart_items')->whereIn('cart_id', $ids)
            ->orderBy('id')
            ->get(['cart_id', 'product_id', 'quantity'])
            ->groupBy('cart_id');

        $removed = DB::table('cart_events')->whereIn('cart_id', $ids)->where('type', CartTracker::REMOVE)
            ->orderBy('id')
            ->get(['cart_id', 'product_id', 'qty'])
            ->groupBy('cart_id');

        $productIds = [];

        foreach ([$lines, $removed] as $set) {
            foreach ($set as $group) {
                foreach ($group as $l) {
                    if ($l->product_id !== null) {
                        $productIds[(int) $l->product_id] = true;
                    }
                }
            }
        }

        $products = $this->productNames(array_keys($productIds));

        $customerIds = array_values(array_unique(array_filter(array_map(fn ($r) => $r->customer_id, $rows))));
        $customers = $customerIds === [] ? collect() : DB::table('customers')->whereIn('id', $customerIds)
            ->get(['id', 'name', 'first_name', 'last_name', 'email'])->keyBy('id');

        $orderIds = array_values(array_unique(array_filter(array_map(fn ($r) => $r->ct_order_id, $rows))));
        $orders = $orderIds === [] ? collect() : DB::table('orders')->whereIn('id', $orderIds)
            ->get(['id', 'order_number', 'status', 'total', 'payment_method', 'email', 'created_at'])->keyBy('id');

        $emails = DB::table('cart_recoveries')->whereIn('cart_id', $ids)->pluck('email', 'cart_id');

        $settings = $this->settings->all();
        $out = [];

        foreach ($rows as $r) {
            $id = (int) $r->id;
            $customer = $r->customer_id !== null ? $customers->get($r->customer_id) : null;
            $order = $r->ct_order_id !== null ? $orders->get($r->ct_order_id) : null;

            $out[] = $this->cartRow($r, $threshold, $settings)
                + [
                    'products' => $this->lineList($lines->get($id) ?? collect(), $products, 'quantity'),
                    'removed' => $this->lineList($removed->get($id) ?? collect(), $products, 'qty', true),
                    'customer' => $customer === null ? null : $this->customerRow($customer),
                    'email' => $customer?->email ?? $order?->email ?? $emails->get($id),
                    'order' => $order === null ? null : $this->orderRow($order),
                ];
        }

        return $out;
    }

    /** The parts of a cart row every view shares. */
    private function cartRow(object $r, int $threshold, array $settings): array
    {
        $ip = $r->ct_ip;
        $single = $ip !== null ? IpBlockList::match($ip) : null;

        return [
            'id' => (int) $r->id,
            'status' => (string) $r->status,
            'first' => StoreTime::iso($r->ct_first_at),
            'last' => StoreTime::iso($r->ct_last_at),
            'ip' => $ip,
            'net' => $r->ct_net,
            'country' => $r->ct_country,
            'country_name' => $r->ct_country === null ? null : (Countries::NAMES[$r->ct_country] ?? $r->ct_country),
            'bot' => (int) $r->ct_bot_score >= $threshold,
            'score' => (int) $r->ct_bot_score,
            'reasons' => BotSignals::reasons((int) $r->ct_bot_flags, $r->ct_ua, $r->ct_speed_ms !== null ? (int) $r->ct_speed_ms : null, $settings),
            'value' => (int) $r->ct_value,
            'value_label' => Money::plain((int) $r->ct_value),
            'added' => (int) $r->ct_added,
            'removed_count' => (int) $r->ct_removed,
            'blocked' => $single === null ? null : ['id' => $single[0], 'cidr' => $single[1]],
        ];
    }

    private function customerRow(object $c): array
    {
        $name = trim((string) ($c->name ?: trim(($c->first_name ?? '').' '.($c->last_name ?? ''))));

        return ['id' => (int) $c->id, 'name' => $name !== '' ? $name : null, 'email' => $c->email];
    }

    private function orderRow(object $o): array
    {
        return [
            'id' => (int) $o->id,
            'number' => (string) $o->order_number,
            'status' => (string) $o->status,
            'total' => Money::plain((int) $o->total),
            'payment' => $o->payment_method,
            'cod' => in_array(strtolower((string) $o->payment_method), ['cod', 'cash_on_delivery'], true),
            'at' => StoreTime::iso($o->created_at),
        ];
    }

    /** @return array<int, array{id:int, name:string, url:string}> */
    private function productNames(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $out = [];

        foreach (DB::table('products')->whereIn('id', $ids)->get(['id', 'name', 'slug']) as $p) {
            $out[(int) $p->id] = [
                'id' => (int) $p->id,
                'name' => (string) $p->name,
                'url' => \App\Support\Url::to('/product/'.$p->slug.'/'),
            ];
        }

        return $out;
    }

    /** Lines (or removals) collapsed per product: "Name ×3". */
    private function lineList($lines, array $products, string $qtyField, bool $negate = false): array
    {
        $by = [];

        foreach ($lines as $l) {
            $pid = $l->product_id !== null ? (int) $l->product_id : 0;
            $qty = (int) $l->{$qtyField};
            $qty = $negate ? abs($qty) : $qty;

            $by[$pid] ??= [
                'id' => $pid ?: null,
                'name' => $products[$pid]['name'] ?? 'Deleted product',
                'url' => $products[$pid]['url'] ?? null,
                'qty' => 0,
            ];
            $by[$pid]['qty'] += $qty;
        }

        return array_values($by);
    }

    /* ═══════════════════════════════════════════════ one cart ═══ */

    public function cart(int $id): ?array
    {
        $r = DB::table('carts as c')->where('c.id', $id)->first(array_merge($this->cartColumns(), ['c.converted_at']));

        if ($r === null) {
            return null;
        }

        $threshold = (int) $this->settings->get('bot_threshold');
        $settings = $this->settings->all();
        $row = $this->present([$r], $threshold)[0];

        $events = DB::table('cart_events')->where('cart_id', $id)->orderBy('id')->limit(500)
            ->get(['id', 'type', 'product_id', 'variant_id', 'qty', 'qty_after', 'unit_price', 'ip', 'country', 'created_at']);

        $variantIds = $events->pluck('variant_id')->filter()->unique()->values()->all();
        $variants = $variantIds === [] ? [] : $this->variantLabels($variantIds);
        $products = $this->productNames($events->pluck('product_id')->filter()->unique()->values()->all());

        $timeline = $events->map(fn ($e) => [
            'type' => [CartTracker::ADD => 'add', CartTracker::REMOVE => 'remove', CartTracker::QTY => 'qty'][(int) $e->type] ?? 'add',
            'at' => StoreTime::iso($e->created_at),
            'product' => $products[(int) $e->product_id]['name'] ?? 'Deleted product',
            'url' => $products[(int) $e->product_id]['url'] ?? null,
            'variant' => $e->variant_id !== null ? ($variants[(int) $e->variant_id] ?? null) : null,
            'qty' => (int) $e->qty,
            'qty_after' => (int) $e->qty_after,
            'price' => Money::plain((int) $e->unit_price),
            'ip' => $e->ip,
            'country' => $e->country,
        ])->all();

        $row['ua'] = $r->ct_ua;
        $row['speed_ms'] = $r->ct_speed_ms !== null ? (int) $r->ct_speed_ms : null;
        $row['created'] = StoreTime::iso($r->created_at);
        $row['converted'] = StoreTime::iso($r->converted_at);
        $row['timeline'] = $timeline;
        $row['hosting'] = $r->ct_ip !== null && HostingNetworks::contains($r->ct_ip);
        $row['range_blocked'] = null;

        if ($r->ct_net !== null) {
            $hit = IpBlock::query()->where('cidr', $r->ct_net)->first(['id', 'cidr']);
            $row['range_blocked'] = $hit === null ? null : ['id' => (int) $hit->id, 'cidr' => (string) $hit->cidr];
        }

        $row['related'] = $this->related($r, $row['email']);

        return $row;
    }

    /** The same shopper's other carts and orders — by account, by email, by address. */
    private function related(object $r, ?string $email): array
    {
        $carts = DB::table('carts as c')
            ->whereNotNull('c.ct_last_at')
            ->where('c.id', '!=', $r->id)
            ->where(function (Builder $w) use ($r) {
                if ($r->customer_id !== null) {
                    $w->orWhere('c.customer_id', $r->customer_id);
                }

                if ($r->ct_ip !== null) {
                    $w->orWhere('c.ct_ip', $r->ct_ip);
                }

                if ($r->customer_id === null && $r->ct_ip === null) {
                    $w->whereRaw('1 = 0');
                }
            })
            ->orderByDesc('c.ct_last_at')
            ->limit(20)
            ->get(['c.id', 'c.customer_id', 'c.ct_ip', 'c.ct_value', 'c.ct_last_at', 'c.ct_order_id', 'c.ct_bot_score', 'c.status']);

        $orders = DB::table('orders')
            ->whereNull('deleted_at')
            ->where(function (Builder $w) use ($r, $email) {
                $any = false;

                if ($r->customer_id !== null) {
                    $w->orWhere('customer_id', $r->customer_id);
                    $any = true;
                }

                if ($email !== null && $email !== '') {
                    $w->orWhere('email', $email);
                    $any = true;
                }

                if ($r->ct_ip !== null) {
                    $w->orWhere('ip_address', $r->ct_ip);
                    $any = true;
                }

                if (! $any) {
                    $w->whereRaw('1 = 0');
                }
            })
            ->orderByDesc('id')
            ->limit(20)
            ->get(['id', 'order_number', 'status', 'total', 'payment_method', 'email', 'created_at', 'customer_id', 'ip_address']);

        $threshold = (int) $this->settings->get('bot_threshold');

        return [
            'carts' => $carts->map(fn ($c) => [
                'id' => (int) $c->id,
                'last' => StoreTime::iso($c->ct_last_at),
                'value' => Money::plain((int) $c->ct_value),
                'bought' => $c->ct_order_id !== null,
                'bot' => (int) $c->ct_bot_score >= $threshold,
                'status' => (string) $c->status,
                'why' => $r->customer_id !== null && (int) $c->customer_id === (int) $r->customer_id ? 'account' : 'address',
            ])->all(),
            'orders' => $orders->map(fn ($o) => $this->orderRow($o) + [
                'why' => $r->customer_id !== null && (int) $o->customer_id === (int) $r->customer_id
                    ? 'account'
                    : ($email !== null && strcasecmp((string) $o->email, $email) === 0 ? 'email' : 'address'),
            ])->all(),
        ];
    }

    /** "50 ml · Rose" for each variant id. */
    private function variantLabels(array $ids): array
    {
        try {
            $rows = DB::table('product_variant_attribute_value as pv')
                ->join('attribute_values as av', 'av.id', '=', 'pv.attribute_value_id')
                ->whereIn('pv.product_variant_id', $ids)
                ->get(['pv.product_variant_id', 'av.name']);
        } catch (\Throwable) {
            return [];
        }

        $out = [];

        foreach ($rows as $row) {
            $out[(int) $row->product_variant_id][] = (string) $row->name;
        }

        return array_map(fn ($names) => implode(' · ', $names), $out);
    }

    /* ═══════════════════════════════════════════════ Added / Removed ═══ */

    /**
     * Top 40 products added (or removed) in a period.
     *
     * "Times" counts events; "carts" counts different carts; for added, "ordered"
     * counts the carts that went on to become an order, and conversion is
     * ordered ÷ carts. "All time" adds what the retention sweep has rolled up
     * into cart_product_totals, so deleting old events never shrinks it.
     */
    public function products(string $kind, string $period): array
    {
        $kind = $kind === 'removed' ? 'removed' : 'added';
        $period = in_array($period, self::PRODUCT_PERIODS, true) ? $period : '7d';

        return Cache::remember('kbb.ct.products.'.$kind.'.'.$period, 300, function () use ($kind, $period) {
            $type = $kind === 'added' ? CartTracker::ADD : CartTracker::REMOVE;
            [$from] = $this->range($period);

            $q = DB::table('cart_events')->where('type', $type)->whereNotNull('product_id');

            if ($from !== null) {
                $q->where('created_at', '>=', $from);
            }

            $live = $q->selectRaw('product_id, COUNT(*) AS times, COUNT(DISTINCT cart_id) AS carts')
                ->groupBy('product_id')
                ->get()
                ->keyBy('product_id');

            $rolled = $period === 'all'
                ? DB::table('cart_product_totals')->get()->keyBy('product_id')
                : collect();

            $merged = [];

            foreach ($live as $pid => $r) {
                $merged[(int) $pid] = ['times' => (int) $r->times, 'carts' => (int) $r->carts, 'ordered' => 0];
            }

            foreach ($rolled as $pid => $r) {
                $t = $kind === 'added' ? (int) $r->added : (int) $r->removed;
                $c = $kind === 'added' ? (int) $r->added_carts : (int) $r->removed_carts;

                if ($t === 0) {
                    continue;
                }

                $merged[(int) $pid] ??= ['times' => 0, 'carts' => 0, 'ordered' => 0];
                $merged[(int) $pid]['times'] += $t;
                $merged[(int) $pid]['carts'] += $c;
                $merged[(int) $pid]['ordered'] += (int) $r->ordered_carts;
            }

            uasort($merged, fn ($a, $b) => [$b['carts'], $b['times']] <=> [$a['carts'], $a['times']]);
            $top = array_slice($merged, 0, self::TOP, true);

            if ($kind === 'added' && $top !== []) {
                $oq = DB::table('cart_events as e')
                    ->join('carts as c', 'c.id', '=', 'e.cart_id')
                    ->where('e.type', CartTracker::ADD)
                    ->whereIn('e.product_id', array_keys($top))
                    ->whereNotNull('c.ct_order_id');

                if ($from !== null) {
                    $oq->where('e.created_at', '>=', $from);
                }

                foreach ($oq->selectRaw('e.product_id, COUNT(DISTINCT e.cart_id) AS n')->groupBy('e.product_id')->get() as $o) {
                    $top[(int) $o->product_id]['ordered'] += (int) $o->n;
                }
            }

            $names = $this->productNames(array_keys($top));
            $rows = [];
            $rank = 0;

            foreach ($top as $pid => $t) {
                $rows[] = [
                    'rank' => ++$rank,
                    'id' => $pid,
                    'name' => $names[$pid]['name'] ?? 'Deleted product #'.$pid,
                    'url' => $names[$pid]['url'] ?? null,
                    'times' => $t['times'],
                    'carts' => $t['carts'],
                    'ordered' => $kind === 'added' ? min($t['ordered'], $t['carts']) : null,
                    'conversion' => $kind === 'added' && $t['carts'] > 0 ? round(100 * min($t['ordered'], $t['carts']) / $t['carts'], 1) : null,
                ];
            }

            return ['kind' => $kind, 'period' => $period, 'rows' => $rows, 'max' => $rows[0]['carts'] ?? 0];
        });
    }

    /* ═══════════════════════════════════════════════ Blocked ═══ */

    public function blocks(): array
    {
        return IpBlock::query()->orderByDesc('id')->limit(2000)->get()
            ->map(fn (IpBlock $b) => IpBlockList::row($b))
            ->all();
    }

    /**
     * A warning, when the shop seems to see one address for everybody — the
     * shape of a proxy whose real-client-IP restoration has broken.
     */
    public function addressHealth(): ?string
    {
        return Cache::remember('kbb.ct.address-health', 600, function () {
            $recent = DB::table('carts')->whereNotNull('ct_ip')->where('ct_first_at', '>=', now()->subDay())
                ->selectRaw('COUNT(*) AS n, COUNT(DISTINCT ct_ip) AS ips')->first();

            if ((int) ($recent->n ?? 0) >= 30 && (int) $recent->ips <= 2) {
                return sprintf(
                    'All %d carts in the last 24 hours came from %d address%s. That is the shape of a proxy hiding the shoppers\' real addresses, not of real traffic — check the hosting\'s real-IP setting before blocking anything.',
                    (int) $recent->n, (int) $recent->ips, (int) $recent->ips === 1 ? '' : 'es'
                );
            }

            return null;
        });
    }
}
