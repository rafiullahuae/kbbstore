<?php

declare(strict_types=1);

namespace App\Http\Controllers\OwnerApp;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Support\Money;
use App\Support\StoreTime;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Customers in the owner app (Lane MAC): search by name, email or phone, and
 * one customer with their whole purchase history and lifetime value.
 *
 * `customers` carries password, legacy_password and remember_token; none of
 * them is ever selected. The figures are paid orders only (Order::
 * REAL_STATUSES), the definition the admin's Customers screen uses.
 *
 * Two queries per page whatever its length: the page of customers, then one
 * GROUP BY over their orders. Never one per row.
 */
final class CustomersController extends Controller
{
    use Concerns;

    public const PER_PAGE = 30;

    /** Lifetime paid spend, in fils, that makes a customer "VIP" in the app's filter. */
    public const VIP_FILS = 200000;

    public const FILTERS = ['all', 'vip', 'repeat', 'new'];

    private const LIKE_ESCAPE = '!';

    public function index(Request $request): JsonResponse
    {
        if ($r = $this->refuse($request, 'customers.view')) {
            return $r;
        }

        $before = max(0, (int) $request->query('before', 0));
        $q = DB::table('customers as c')->whereNull('c.deleted_at');

        $term = trim(mb_substr((string) $request->query('q', ''), 0, 80));
        if ($term !== '') {
            $like = '%'.str_replace([self::LIKE_ESCAPE, '%', '_'], [self::LIKE_ESCAPE.self::LIKE_ESCAPE, self::LIKE_ESCAPE.'%', self::LIKE_ESCAPE.'_'], $term).'%';
            $q->where(function (Builder $w) use ($like) {
                foreach (['c.name', 'c.first_name', 'c.last_name', 'c.email', 'c.phone'] as $col) {
                    $w->orWhereRaw($col." like ? escape '".self::LIKE_ESCAPE."'", [$like]);
                }
            });
        }

        $filter = in_array($request->query('filter'), self::FILTERS, true) ? (string) $request->query('filter') : 'all';
        if ($filter === 'new') {
            $q->where('c.created_at', '>=', StoreTime::now()->startOfMonth()->utc());
        } elseif ($filter === 'vip' || $filter === 'repeat') {
            $real = Order::REAL_STATUSES;
            $q->whereIn('c.id', DB::table('orders')->whereNull('deleted_at')->whereNotNull('customer_id')->whereIn('status', $real)
                ->groupBy('customer_id')
                ->havingRaw($filter === 'vip' ? 'SUM(total) >= ?' : 'COUNT(*) >= ?', [$filter === 'vip' ? self::VIP_FILS : 2])
                ->select('customer_id'));
        }

        $head = null;
        if ($before === 0 && $term === '' && $filter === 'all') {
            $head = DB::table('customers')->whereNull('deleted_at')
                ->selectRaw('COUNT(*) as n, SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as fresh', [StoreTime::now()->startOfMonth()->utc()])
                ->first();
        }

        $rows = $q->when($before > 0, fn (Builder $w) => $w->where('c.id', '<', $before))
            ->orderByDesc('c.id')->limit(self::PER_PAGE + 1)
            ->get(['c.id', 'c.name', 'c.first_name', 'c.last_name', 'c.email', 'c.phone', 'c.created_at']);

        $more = $rows->count() > self::PER_PAGE;
        $rows = $rows->take(self::PER_PAGE);
        $agg = self::aggregates($rows->pluck('id')->all());

        return response()->json([
            'ok' => true,
            'customers' => $rows->map(fn ($c) => self::row($c, $agg[(int) $c->id] ?? null))->values(),
            'next' => $more ? (int) $rows->last()->id : null,
            'total' => $head ? (int) $head->n : null,
            'new_this_month' => $head ? (int) $head->fresh : null,
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        if ($r = $this->refuse($request, 'customers.view')) {
            return $r;
        }

        $c = DB::table('customers')->whereNull('deleted_at')->where('id', $id)
            ->first(['id', 'name', 'first_name', 'last_name', 'email', 'phone', 'created_at', 'whatsapp_optin']);

        if ($c === null) {
            return response()->json(['ok' => false, 'code' => 'not_found'], 404);
        }

        $agg = self::aggregates([$id])[$id] ?? null;

        $orders = DB::table('orders as o')->whereNull('o.deleted_at')->where('o.customer_id', $id)
            ->where('o.status', '!=', 'checkout-draft')
            ->orderByDesc('o.id')->limit(50)
            ->get(['o.id', 'o.order_number', 'o.status', 'o.total', 'o.created_at', 'o.payment_method', 'o.payment_method_title', 'o.billing_address', DB::raw("'' as c_name")]);

        $address = DB::table('addresses')->where('customer_id', $id)->orderByDesc('is_default')->orderBy('id')
            ->first(['line1', 'line2', 'city', 'state', 'country']);

        $top = DB::table('order_items as i')->join('orders as o', 'o.id', '=', 'i.order_id')
            ->leftJoin('products as p', 'p.id', '=', 'i.product_id')
            ->where('o.customer_id', $id)->whereIn('o.status', Order::REAL_STATUSES)->whereNull('o.deleted_at')
            ->groupBy('i.product_id', 'i.name', 'p.image')->orderByRaw('SUM(i.quantity) DESC')->limit(5)
            ->selectRaw('i.product_id as id, i.name as name, p.image as image, SUM(i.quantity) as qty')->get();

        // Spend by month, the last eight, from the paid orders already loaded
        // below plus nothing else: one more query would buy nothing.
        $months = [];
        $m0 = StoreTime::now()->startOfMonth();
        for ($i = 7; $i >= 0; $i--) {
            $months[$m0->subMonthsNoOverflow($i)->format('Y-m')] = 0;
        }
        $spend = DB::table('orders')->whereNull('deleted_at')->where('customer_id', $id)->whereIn('status', Order::REAL_STATUSES)
            ->where('created_at', '>=', $m0->subMonthsNoOverflow(7)->utc())->get(['created_at', 'total']);
        foreach ($spend as $o) {
            $k = StoreTime::display($o->created_at)?->format('Y-m');
            if ($k !== null && isset($months[$k])) {
                $months[$k] += (int) $o->total;
            }
        }

        $paid = (int) ($agg->paid ?? 0);
        $spent = (int) ($agg->spent ?? 0);

        return response()->json([
            'ok' => true,
            'customer' => self::row($c, $agg) + [
                'whatsapp' => (bool) $c->whatsapp_optin,
                'since' => StoreTime::iso($c->created_at),
                'place' => $address ? trim(implode(', ', array_filter([(string) $address->city, (string) $address->state]))) : '',
                'address' => $address ? array_values(array_filter([(string) $address->line1, (string) $address->line2,
                    trim(implode(', ', array_filter([(string) $address->city, (string) $address->state]))), (string) $address->country])) : [],
                'tags' => array_values(array_filter([
                    $paid >= 2 ? 'Repeat buyer' : null,
                    $spent >= self::VIP_FILS ? 'VIP' : null,
                    (bool) $c->whatsapp_optin ? 'WhatsApp' : null,
                ])),
                'months' => array_map(fn ($k, $v) => ['label' => \Carbon\CarbonImmutable::createFromFormat('Y-m-d', $k.'-01')->format('M'), 'fils' => $v],
                    array_keys($months), array_values($months)),
                'first_order_at' => StoreTime::iso($agg->first_at ?? null),
                'average_display' => $paid > 0 ? Money::plain((int) round($spent / $paid)) : null,
                'top_products' => $top->map(fn ($t) => ['id' => $t->id === null ? null : (int) $t->id, 'name' => (string) $t->name,
                    'qty' => (int) $t->qty, 'thumb' => OrdersController::thumb($t->image)])->values(),
                'history' => $orders->map(fn ($o) => OrdersController::row($o))->values(),
            ],
        ]);
    }

    /** @return array<int,object> customer id => {paid, spent, all_n, last_at, first_at} */
    private static function aggregates(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $real = Order::REAL_STATUSES;
        $marks = implode(',', array_fill(0, count($real), '?'));

        return DB::table('orders')->whereNull('deleted_at')->whereIn('customer_id', $ids)
            ->groupBy('customer_id')
            ->selectRaw(
                "customer_id,
                 SUM(CASE WHEN status IN ($marks) THEN 1 ELSE 0 END) as paid,
                 SUM(CASE WHEN status IN ($marks) THEN total ELSE 0 END) as spent,
                 COUNT(*) as all_n,
                 MAX(created_at) as last_at,
                 MIN(CASE WHEN status IN ($marks) THEN created_at END) as first_at",
                array_merge($real, $real, $real),
            )
            ->get()->keyBy(fn ($r) => (int) $r->customer_id)->all();
    }

    /** @return array<string,mixed> */
    private static function row(object $c, ?object $agg): array
    {
        $name = trim((string) $c->name) ?: trim(((string) $c->first_name).' '.((string) $c->last_name));

        return [
            'id' => (int) $c->id,
            'name' => $name !== '' ? $name : (string) $c->email,
            'email' => (string) $c->email,
            'phone' => (string) ($c->phone ?? ''),
            'orders' => (int) ($agg->all_n ?? 0),
            'paid_orders' => (int) ($agg->paid ?? 0),
            'spent_display' => Money::plain((int) ($agg->spent ?? 0)),
            'last_order_at' => StoreTime::iso($agg->last_at ?? null),
        ];
    }
}
