<?php

declare(strict_types=1);

namespace App\Http\Controllers\OwnerApp;

use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\AdminOrderController;
use App\Http\Controllers\Admin\OrderDetailController;
use App\Http\Controllers\Admin\OrdersApiController;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\OwnerApp\OwnerAppEvents;
use App\Support\ImageVariants;
use App\Support\Money;
use App\Support\OrderPaymentPanel;
use App\Support\StoreTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Orders in the owner app (Lane MAC): the list, one order, and the four
 * things a member does to an order from a phone.
 *
 * ── THE WRITES ARE THE ADMIN'S OWN ─────────────────────────────────────────
 *
 * Status (one or many), notes and "mark as paid" are not reimplemented here.
 * Each is handed to the exact controller method the admin console calls —
 * OrdersApiController::bulkStatus(), AdminController::updateOrderStatus(),
 * AdminOrderController::addNote(), OrderDetailController::markPaid() — after
 * this class has checked the member's capability. So the revenue guard, the
 * stock that goes back on the shelf, the coupon that is released, the email
 * the customer gets and the order note all behave exactly as they do in the
 * admin, because they ARE the admin's. Only the response is rebuilt: the app
 * gets its own allowlisted shape, never the admin payload.
 *
 * ── THE READS ARE AN ALLOWLIST ─────────────────────────────────────────────
 *
 * Every row is built field by field from a query that selects named columns.
 * `orders.ip_address`, `customers.password` and the WooCommerce ids are never
 * selected, so they cannot be returned by mistake. Customer email, phone and
 * address ARE returned — to a member holding orders.view, the same people who
 * see them in the admin.
 */
final class OrdersController extends Controller
{
    use Concerns;

    public const PER_PAGE = 30;

    public const STATUSES = ['pending', 'processing', 'onhold', 'shipped', 'completed', 'cancelled', 'refunded', 'failed', 'draft'];

    /** What the bulk bar and the status sheet may set: the admin's own BULK_SETTABLE. */
    public const SETTABLE = ['pending', 'processing', 'onhold', 'shipped', 'completed', 'cancelled'];

    private const LIKE_ESCAPE = '!';

    /* ----------------------------------------------------------------- list */

    public function index(Request $request): JsonResponse
    {
        if ($r = $this->refuse($request, 'orders.view')) {
            return $r;
        }

        $base = $this->filtered($request);
        $status = (string) $request->query('status', '');
        $before = max(0, (int) $request->query('before', 0));

        $rows = (clone $base)
            ->when(in_array($status, self::STATUSES, true), fn (Builder $q) => $q->where('o.status', $status))
            ->when($before > 0, fn (Builder $q) => $q->where('o.id', '<', $before))
            ->leftJoin('customers as c', 'c.id', '=', 'o.customer_id')
            ->orderByDesc('o.id')
            ->limit(self::PER_PAGE + 1)
            ->get([
                'o.id', 'o.order_number', 'o.status', 'o.total', 'o.created_at', 'o.payment_method',
                'o.payment_method_title', 'o.billing_address', 'c.name as c_name',
            ]);

        $more = $rows->count() > self::PER_PAGE;
        $rows = $rows->take(self::PER_PAGE);

        $out = [
            'ok' => true,
            'orders' => $rows->map(fn ($o) => self::row($o))->values(),
            'next' => $more ? (int) $rows->last()->id : null,
            'today' => StoreTime::today()->format('Y-m-d'),
            'yesterday' => StoreTime::today()->subDay()->format('Y-m-d'),
        ];

        // The chip counts only with the first page: one GROUP BY over the same
        // filters, not one per status and not one per page.
        if ($before === 0) {
            $out['counts'] = (clone $base)->groupBy('o.status')->selectRaw('o.status as s, COUNT(*) as n')
                ->pluck('n', 's')->map(fn ($n) => (int) $n);
            $out['payments'] = self::paymentMethods();
        }

        return response()->json($out);
    }

    /** The list's filters, shared by the rows and the counts. */
    private function filtered(Request $request): Builder
    {
        $q = DB::table('orders as o')->whereNull('o.deleted_at')->where('o.status', '!=', 'checkout-draft');

        $from = self::dayStart((string) $request->query('from', ''));
        $to = self::dayStart((string) $request->query('to', ''), 1);
        if ($from !== null) {
            $q->where('o.created_at', '>=', $from);
        }
        if ($to !== null) {
            $q->where('o.created_at', '<', $to);
        }

        $payment = trim((string) $request->query('payment', ''));
        if ($payment !== '' && preg_match('/^[a-z0-9_\-]{1,40}$/i', $payment)) {
            $q->where('o.payment_method', $payment);
        }

        $term = trim(mb_substr((string) $request->query('q', ''), 0, 80));
        if ($term !== '') {
            self::search($q, $term);
        }

        return $q;
    }

    /**
     * Number, name, email, phone or a product on the order.
     *
     * "#33447" or a bare number goes straight to the order-number index; an
     * address with @ looks at email; a run of digits also looks at phones; a
     * word looks at names (customer row and the billing name of a guest) and
     * at the order's lines, by product name or SKU.
     */
    private static function search(Builder $q, string $term): void
    {
        $plain = ltrim($term, '#');
        $like = '%'.self::escape($term).'%';

        if ($plain !== '' && ctype_digit($plain) && strlen($plain) <= 9) {
            $q->where(function (Builder $w) use ($plain) {
                $w->where('o.order_number', $plain)
                    ->orWhere('o.order_number', 'like', self::escape($plain).'%')
                    ->orWhere('o.phone', 'like', '%'.self::escape($plain).'%');
            });

            return;
        }

        $q->where(function (Builder $w) use ($like) {
            foreach (['o.email', 'o.phone', 'o.order_number', 'o.billing_address'] as $col) {
                $w->orWhereRaw($col." like ? escape '".self::LIKE_ESCAPE."'", [$like]);
            }
            $w->orWhereExists(function (Builder $c) use ($like) {
                $c->selectRaw('1')->from('customers as cs')->whereColumn('cs.id', 'o.customer_id')
                    ->where(fn (Builder $n) => $n->whereRaw("cs.name like ? escape '".self::LIKE_ESCAPE."'", [$like])
                        ->orWhereRaw("cs.phone like ? escape '".self::LIKE_ESCAPE."'", [$like]));
            });
            $w->orWhereExists(function (Builder $i) use ($like) {
                $i->selectRaw('1')->from('order_items as oi')->whereColumn('oi.order_id', 'o.id')
                    ->where(fn (Builder $n) => $n->whereRaw("oi.name like ? escape '".self::LIKE_ESCAPE."'", [$like])
                        ->orWhereRaw("oi.sku like ? escape '".self::LIKE_ESCAPE."'", [$like]));
            });
        });
    }

    /** @return array<string,mixed> */
    public static function row(object $o): array
    {
        $billing = is_string($o->billing_address ?? null) ? (json_decode($o->billing_address, true) ?: []) : (array) ($o->billing_address ?? []);
        $name = trim((string) ($o->c_name ?? ''));
        if ($name === '') {
            $name = trim(($billing['first_name'] ?? '').' '.($billing['last_name'] ?? ''));
        }

        return [
            'id' => (int) $o->id,
            'number' => (string) ($o->order_number ?: $o->id),
            'status' => (string) $o->status,
            'status_label' => OwnerAppEvents::statusWord((string) $o->status),
            'total' => (int) $o->total,
            'total_display' => Money::plain((int) $o->total),
            'name' => $name !== '' ? $name : 'Guest',
            'payment' => self::paymentKind((string) $o->payment_method),
            'payment_title' => (string) ($o->payment_method_title ?: ''),
            'created_at' => StoreTime::iso($o->created_at),
            'day' => StoreTime::dayKey($o->created_at),
        ];
    }

    public static function paymentKind(string $method): string
    {
        $m = strtolower($method);

        return match (true) {
            $m === 'tabby' => 'tabby',
            $m === 'tamara' => 'tamara',
            $m === 'cod' => 'cod',
            in_array($m, ['stripe', 'card', 'stripe_cc', 'apple_pay', 'google_pay'], true) => 'card',
            $m === '' => 'none',
            default => 'other',
        };
    }

    /** @return list<array{id:string,title:string}> the methods orders actually carry */
    private static function paymentMethods(): array
    {
        return DB::table('orders')->whereNotNull('payment_method')->where('payment_method', '!=', '')
            ->groupBy('payment_method')->orderBy('payment_method')->limit(12)
            ->selectRaw('payment_method as id, MAX(payment_method_title) as title')
            ->get()->map(fn ($r) => ['id' => (string) $r->id, 'title' => (string) ($r->title ?: ucfirst((string) $r->id))])->all();
    }

    /* --------------------------------------------------------------- detail */

    public function show(Request $request, int $id): JsonResponse
    {
        if ($r = $this->refuse($request, 'orders.view')) {
            return $r;
        }

        $order = Order::query()->with([
            'items:id,order_id,product_id,product_variant_id,name,sku,variant_attributes,quantity,unit_price,total',
            'items.product:id,image',
            'notes:id,order_id,author,is_customer_note,content,created_at',
            'customer:id,name,email,phone',
        ])->find($id);

        if ($order === null) {
            return response()->json(['ok' => false, 'code' => 'not_found'], 404);
        }

        $refunded = (int) DB::table('refunds')->where('order_id', $order->id)
            ->whereIn('status', \App\Services\Payments\PaymentRefunder::COUNTED)->sum('amount');

        $history = $order->customer_id
            ? DB::table('orders')->whereNull('deleted_at')->where('customer_id', $order->customer_id)
            : DB::table('orders')->whereNull('deleted_at')->where('email', (string) $order->email);
        $history = $history->selectRaw('COUNT(*) as n, SUM(CASE WHEN status IN (?,?,?,?) THEN total ELSE 0 END) as spent', Order::REAL_STATUSES)->first();

        $prev = DB::table('orders')->whereNull('deleted_at')->where('id', '<', $order->id)->where('status', '!=', 'checkout-draft')->max('id');
        $next = DB::table('orders')->whereNull('deleted_at')->where('id', '>', $order->id)->where('status', '!=', 'checkout-draft')->min('id');

        $mayPay = $this->may($request, 'orders.payment') && in_array((string) $order->status, OrderPaymentPanel::UNPAID_STATUSES, true);

        return response()->json([
            'ok' => true,
            'order' => [
                'id' => (int) $order->id,
                'number' => (string) ($order->order_number ?: $order->id),
                'status' => (string) $order->status,
                'status_label' => OwnerAppEvents::statusWord((string) $order->status),
                'created_at' => self::iso($order->created_at),
                'paid_at' => self::iso($order->paid_at),
                'completed_at' => self::iso($order->completed_at),
                'email' => (string) $order->email,
                'phone' => (string) ($order->phone ?? ''),
                'billing' => self::address($order->billing_address),
                'shipping' => self::address($order->shipping_address),
                'shipping_method' => (string) ($order->shipping_method ?? ''),
                'payment' => [
                    'kind' => self::paymentKind((string) $order->payment_method),
                    'title' => $order->paymentLabel(),
                    'paid' => in_array((string) $order->status, OrderPaymentPanel::PAID_STATUSES, true) || $order->paid_at !== null,
                ],
                'items' => $order->items->map(fn ($i) => [
                    'name' => (string) $i->name,
                    'sku' => (string) ($i->sku ?? ''),
                    'variant' => self::variantText($i->variant_attributes),
                    'qty' => (int) $i->quantity,
                    'unit_display' => Money::plain((int) $i->unit_price),
                    'total_display' => Money::plain((int) $i->total),
                    'product_id' => $i->product_id === null ? null : (int) $i->product_id,
                    'thumb' => self::thumb($i->product?->image),
                ])->values(),
                'totals' => [
                    'subtotal' => Money::plain((int) $order->subtotal),
                    'discount' => (int) $order->discount_total > 0 ? Money::plain((int) $order->discount_total) : null,
                    'coupon' => (string) ($order->coupon_code ?? ''),
                    'shipping' => Money::plain((int) $order->shipping_total),
                    'fees' => (int) $order->fee_total > 0 ? Money::plain((int) $order->fee_total) : null,
                    'tax' => (int) $order->tax_total > 0 ? Money::plain((int) $order->tax_total) : null,
                    'total' => Money::plain((int) $order->total),
                    'refunded' => $refunded > 0 ? Money::plain($refunded) : null,
                ],
                'customer_note' => (string) ($order->customer_note ?? ''),
                'origin' => (string) ($order->origin ?? ''),
                'customer' => [
                    'id' => $order->customer?->id,
                    'name' => (string) ($order->customer?->name ?: trim(($order->billing_address['first_name'] ?? '').' '.($order->billing_address['last_name'] ?? ''))),
                    'orders' => (int) ($history->n ?? 0),
                    'spent_display' => Money::plain((int) ($history->spent ?? 0)),
                ],
                'notes' => $order->notes->map(fn ($n) => [
                    'id' => (int) $n->id,
                    'author' => (string) ($n->author ?? ''),
                    'customer' => (bool) $n->is_customer_note,
                    'content' => (string) $n->content,
                    'at' => self::iso($n->created_at),
                ])->values(),
                'prev_id' => $prev === null ? null : (int) $prev,
                'next_id' => $next === null ? null : (int) $next,
                'can' => [
                    'status' => $this->may($request, 'orders.manage'),
                    'note' => $this->may($request, 'orders.manage'),
                    'paid' => $mayPay,
                ],
                'paid_methods' => $mayPay ? app(\App\Services\Orders\ManualPayment::class)->methodsFor($order) : [],
                'settable' => self::SETTABLE,
            ],
        ]);
    }

    /* --------------------------------------------------------------- writes */

    public function bulkStatus(Request $request): JsonResponse
    {
        if ($r = $this->refuse($request, 'orders.manage')) {
            return $r;
        }

        $sub = self::subRequest($request, [
            'ids' => array_values(array_filter((array) $request->input('ids', []), 'is_numeric')),
            'status' => (string) $request->input('status', ''),
        ] + self::optional($request, ['notify', 'force']));

        $res = $this->delegate(fn () => app(OrdersApiController::class)->bulkStatus($sub));
        if ($res instanceof JsonResponse && $res->getStatusCode() !== 200) {
            return $res;
        }

        $d = $res->getData(true);

        return response()->json([
            'ok' => true,
            'status' => (string) ($d['status'] ?? ''),
            'changed' => (int) ($d['changed'] ?? 0),
            'skipped' => array_map(fn ($s) => [
                'id' => (int) ($s['id'] ?? 0),
                'number' => (string) ($s['label'] ?? ''),
                'reason' => (string) ($s['reason'] ?? ''),
                'forceable' => (bool) ($s['forceable'] ?? false),
            ], (array) ($d['skipped'] ?? [])),
        ]);
    }

    public function status(Request $request, int $id): JsonResponse
    {
        if ($r = $this->refuse($request, 'orders.manage')) {
            return $r;
        }

        $status = (string) $request->input('status', '');
        if (! in_array($status, self::SETTABLE, true)) {
            return response()->json(['ok' => false, 'message' => 'Choose one of the listed statuses.'], 422);
        }

        $sub = self::subRequest($request, ['status' => $status] + self::optional($request, ['notify']), 'PUT');
        $res = $this->delegate(fn () => app(AdminController::class)->updateOrderStatus($sub, $id));

        return $this->relay($res, fn ($d) => ['status' => (string) ($d['status'] ?? $status)]);
    }

    public function note(Request $request, int $id): JsonResponse
    {
        if ($r = $this->refuse($request, 'orders.manage')) {
            return $r;
        }

        $sub = self::subRequest($request, ['content' => (string) $request->input('content', '')]);
        $res = $this->delegate(fn () => app(AdminOrderController::class)->addNote($sub, $id));

        return $this->relay($res, fn ($d) => ['note' => [
            'id' => (int) ($d['note']['id'] ?? 0),
            'author' => (string) ($d['note']['author'] ?? ''),
            'customer' => false,
            'content' => (string) ($d['note']['content'] ?? ''),
            'at' => $d['note']['created_at'] ?? null,
        ]]);
    }

    public function markPaid(Request $request, int $id): JsonResponse
    {
        if ($r = $this->refuse($request, 'orders.payment')) {
            return $r;
        }

        $sub = self::subRequest($request, [
            'payment_method' => (string) $request->input('payment_method', ''),
            'reference' => $request->input('reference'),
            'paid_at' => (string) ($request->input('paid_at') ?: StoreTime::now()->format('Y-m-d H:i:s')),
            'status' => $request->input('status'),
        ] + self::optional($request, ['notify']));

        $res = $this->delegate(fn () => app()->call([app(OrderDetailController::class), 'markPaid'], ['request' => $sub, 'id' => $id]));

        return $this->relay($res, fn ($d) => ['status' => (string) ($d['status'] ?? ''), 'message' => (string) ($d['message'] ?? '')]);
    }

    /* -------------------------------------------------------------- helpers */

    /**
     * A copy of the request carrying only the fields the admin method reads.
     * The member's other input never reaches it.
     */
    private static function subRequest(Request $request, array $input, string $method = 'POST'): Request
    {
        $sub = Request::create($request->getRequestUri(), $method, [], [], [], $request->server->all());
        $sub->headers->set('Accept', 'application/json');
        $sub->setJson(new \Symfony\Component\HttpFoundation\InputBag($input));
        $sub->request = new \Symfony\Component\HttpFoundation\InputBag($input);
        $sub->setUserResolver(fn () => auth('admin')->user());

        return $sub;
    }

    /** @return array<string,mixed> */
    private static function optional(Request $request, array $keys): array
    {
        $out = [];
        foreach ($keys as $k) {
            if ($request->has($k) && $request->input($k) !== null) {
                $out[$k] = (bool) $request->boolean($k);
            }
        }

        return $out;
    }

    private function delegate(\Closure $call): \Symfony\Component\HttpFoundation\Response
    {
        try {
            $res = $call();
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'message' => collect($e->errors())->flatten()->first() ?? 'Check the form.', 'errors' => $e->errors()], 422);
        }

        return $res instanceof \Symfony\Component\HttpFoundation\Response ? $res : response()->json(['ok' => false], 500);
    }

    private function relay(\Symfony\Component\HttpFoundation\Response $res, \Closure $shape): JsonResponse
    {
        $d = $res instanceof JsonResponse ? (array) $res->getData(true) : [];

        if ($res->getStatusCode() !== 200 || (isset($d['ok']) && $d['ok'] === false)) {
            return response()->json([
                'ok' => false,
                'code' => (string) ($d['error'] ?? $d['code'] ?? 'refused'),
                'message' => (string) ($d['message'] ?? 'That did not go through.'),
            ], $res->getStatusCode() === 200 ? 422 : $res->getStatusCode());
        }

        return response()->json(['ok' => true] + $shape($d));
    }

    /** @return array<string,string>|null */
    private static function address(mixed $a): ?array
    {
        if (! is_array($a) || $a === []) {
            return null;
        }
        $pick = static fn (string $k) => trim((string) ($a[$k] ?? ''));

        return [
            'name' => trim($pick('first_name').' '.$pick('last_name')),
            'company' => $pick('company'),
            'line1' => $pick('line1') ?: $pick('address_1'),
            'line2' => $pick('line2') ?: $pick('address_2'),
            'city' => $pick('city'),
            'state' => $pick('state'),
            'country' => $pick('country'),
            'phone' => $pick('phone'),
        ];
    }

    private static function variantText(mixed $attrs): string
    {
        if (is_string($attrs)) {
            $attrs = json_decode($attrs, true);
        }
        if (! is_array($attrs)) {
            return '';
        }

        $parts = [];
        foreach ($attrs as $k => $v) {
            if (is_scalar($v) && trim((string) $v) !== '') {
                $parts[] = (is_string($k) ? ucfirst(str_replace(['attribute_pa_', 'pa_', '_', '-'], ['', '', ' ', ' '], $k)).': ' : '').$v;
            }
        }

        return implode(' · ', $parts);
    }

    public static function thumb(?string $image): ?string
    {
        $image = trim((string) $image);

        return $image === '' ? null : ImageVariants::variantUrl($image, 200);
    }

    private static function escape(string $s): string
    {
        return str_replace([self::LIKE_ESCAPE, '%', '_'], [self::LIKE_ESCAPE.self::LIKE_ESCAPE, self::LIKE_ESCAPE.'%', self::LIKE_ESCAPE.'_'], $s);
    }

    private static function dayStart(string $day, int $plusDays = 0): ?CarbonImmutable
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            return null;
        }
        try {
            return CarbonImmutable::createFromFormat('Y-m-d', $day, StoreTime::timezone())->startOfDay()->addDays($plusDays)->utc();
        } catch (\Throwable) {
            return null;
        }
    }
}
