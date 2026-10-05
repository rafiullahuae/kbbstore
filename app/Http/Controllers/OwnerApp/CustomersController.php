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
            ->first(['city', 'state', 'country']);

        $top = DB::table('order_items as i')->join('orders as o', 'o.id', '=', 'i.order_id')
            ->where('o.customer_id', $id)->whereIn('o.status', Order::REAL_STATUSES)->whereNull('o.deleted_at')
            ->groupBy('i.name')->orderByRaw('SUM(i.quantity) DESC')->limit(5)
            ->selectRaw('i.name as name, SUM(i.quantity) as qty')->get();

        $paid = (int) ($agg->paid ?? 0);
        $spent = (int) ($agg->spent ?? 0);

        return response()->json([
            'ok' => true,
            'customer' => self::row($c, $agg) + [
                'whatsapp' => (bool) $c->whatsapp_optin,
                'since' => StoreTime::iso($c->created_at),
                'place' => $address ? trim(implode(', ', array_filter([(string) $address->city, (string) $address->state, (string) $address->country]))) : '',
                'first_order_at' => StoreTime::iso($agg->first_at ?? null),
                'average_display' => $paid > 0 ? Money::plain((int) round($spent / $paid)) : null,
                'top_products' => $top->map(fn ($t) => ['name' => (string) $t->name, 'qty' => (int) $t->qty])->values(),
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
