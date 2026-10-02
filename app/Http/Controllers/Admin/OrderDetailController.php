<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Order;
use App\Services\Mail\OrderStatusMailPolicy;
use App\Services\Orders\ManualPayment;
use App\Services\Orders\OrderReviveRefused;
use App\Support\Money;
use App\Support\OrderCustomerHistory;
use App\Support\OrderPaymentPanel;
use App\Support\StoreTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Store -> Orders -> (an order): the four things the owner asked the order
 * screen to do that it could not. (Lane PU, routes/order-detail-admin.php)
 *
 *   GET  orders/{id}/customer-orders   "Order history" popup        orders.view
 *   POST orders/{id}/mark-paid         "Mark as paid" modal         orders.payment
 *   PUT  orders/{id}/customer          change the order's customer  orders.customer
 *   GET  order-customer-search         the customer picker for it   orders.customer
 *
 * Billing / Shipping editing is AdminOrderController::updateAddress, on the
 * route that already existed, now validated and audited.
 *
 * Each path is its own row in App\Support\AdminCapabilities and fails closed:
 * a support account reads the history, and is refused the payment record and
 * the customer change.
 */
class OrderDetailController extends Controller
{
    private const HISTORY_PER_PAGE = 10;

    private const SEARCH_LIMIT = 12;

    private const LIKE_ESCAPE = '!';

    /**
     * The customer's orders, newest first, ten to a page, with the same totals
     * as the Customer history card (OrderCustomerHistory -- one definition).
     *
     * WHAT IT REPLACED: `odCustHist.onclick = go('customers')`, which dropped
     * the operator on the whole customer list with nobody selected -- "it goes
     * to all customers page, which is incorrect".
     */
    public function customerOrders(Request $request, int $id): JsonResponse
    {
        $order = Order::withTrashed()->find($id, ['id', 'customer_id', 'email']);

        if ($order === null) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $page = max(1, min(1000, (int) $request->query('page', 1)));
        $customerId = $order->customer_id ? (int) $order->customer_id : null;
        $email = (string) $order->email;

        $total = OrderCustomerHistory::query($customerId, $email)->count();
        $lastPage = max(1, (int) ceil($total / self::HISTORY_PER_PAGE));
        $page = min($page, $lastPage);

        $rows = OrderCustomerHistory::query($customerId, $email)
            ->withSum('items as items_qty', 'quantity')
            ->orderByDesc('created_at')->orderByDesc('id')
            ->forPage($page, self::HISTORY_PER_PAGE)
            ->get(['id', 'order_number', 'status', 'total', 'created_at', 'deleted_at', 'payment_method', 'payment_method_title']);

        $customer = $customerId ? Customer::query()->find($customerId, ['id', 'name', 'first_name', 'last_name', 'email']) : null;

        return response()->json([
            // Allowlisted: a name and an email, which the order screen already
            // shows. Nothing else of the customer row leaves this endpoint.
            'customer' => [
                'id' => $customer?->id,
                'name' => $customer ? (trim((string) $customer->name) ?: trim($customer->first_name . ' ' . $customer->last_name)) : null,
                'email' => $customer?->email ?? $email,
                'guest' => $customer === null,
            ],
            'summary' => OrderCustomerHistory::summary($customerId, $email),
            'orders' => $rows->map(fn (Order $o) => [
                'id' => $o->id,
                'order_number' => (string) ($o->order_number ?? $o->id),
                'created_at' => StoreTime::iso($o->created_at),
                'date_label' => StoreTime::formatDate($o->created_at, 'j M Y'),
                'status' => (string) $o->status,
                'items' => (int) ($o->items_qty ?? 0),
                'total_aed' => Money::toAed((int) $o->total),
                'method' => $o->paymentLabel(),
                'trashed' => $o->deleted_at !== null,
                'current' => (int) $o->id === (int) $order->id,
            ])->values(),
            'page' => $page,
            'per_page' => self::HISTORY_PER_PAGE,
            'total' => $total,
            'last_page' => $lastPage,
        ]);
    }

    /**
     * Record a payment taken outside the checkout, optionally moving the
     * status in the same write. See App\Services\Orders\ManualPayment.
     */
    public function markPaid(Request $request, int $id, ManualPayment $payments): JsonResponse
    {
        $order = Order::find($id);

        if ($order === null) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $data = $request->validate([
            'payment_method' => ['required', 'string', 'max:64'],
            'reference' => ['nullable', 'string', 'max:' . ManualPayment::REFERENCE_MAX, 'regex:/^[^\x00-\x1F\x7F<>]*$/u'],
            'paid_at' => ['required', 'date'],
            'status' => ['nullable', 'string', Rule::in(OrderPaymentPanel::PAID_STATUSES)],
            'notify' => ['sometimes', 'nullable', 'boolean'],
        ], [
            'reference.regex' => 'The reference cannot contain line breaks or angle brackets.',
        ]);

        /*
         * The date is read on the SHOP's clock -- the modal's datetime-local
         * box carries no zone, and the owner types the time he sees on his
         * gateway's dashboard, which is Dubai time. Refused when it is in the
         * future (beyond a minute of clock skew) or before the order existed.
         */
        try {
            $paidAt = CarbonImmutable::parse((string) $data['paid_at'], StoreTime::timezone())->utc();
        } catch (\Throwable) {
            return response()->json(['ok' => false, 'message' => 'That date is not a date.'], 422);
        }

        if ($paidAt->greaterThan(now()->addMinute())) {
            return response()->json(['ok' => false, 'message' => 'The payment date cannot be in the future.'], 422);
        }

        if ($order->created_at !== null && $paidAt->lessThan(CarbonImmutable::parse($order->created_at)->subDay())) {
            return response()->json(['ok' => false, 'message' => 'The payment date is before the order was placed.'], 422);
        }

        $to = $data['status'] ?? null;

        /*
         * The status leg only ever goes unpaid -> paid. A move between paid
         * statuses never asks (the owner's rule), so it never arrives here, and
         * a request that tries is refused rather than quietly treated as one.
         */
        if ($to !== null && ! in_array((string) $order->status, OrderPaymentPanel::UNPAID_STATUSES, true) && $to !== (string) $order->status) {
            return response()->json([
                'ok' => false,
                'message' => 'Only an unpaid order (pending, on hold, draft or failed) is marked paid with a status change.',
            ], 422);
        }

        if ($to !== null) {
            app(OrderStatusMailPolicy::class)->decideFor($order, $request->has('notify') ? $request->boolean('notify') : null);
        }

        try {
            $result = $payments->record(
                $order,
                (string) $data['payment_method'],
                $data['reference'] ?? null,
                $paidAt,
                auth('admin')->user()?->name ?: 'Admin',
                $to,
            );
        } catch (OrderReviveRefused $e) {
            return response()->json([
                'ok' => false,
                'error' => 'revive_refused',
                'message' => $e->getMessage(),
                'status' => (string) $order->fresh()?->status,
            ], 422);
        }

        if (! $result['ok']) {
            return response()->json(['ok' => false, 'message' => $result['message']], 422);
        }

        $order->refresh();

        return response()->json([
            'ok' => true,
            'message' => $result['message'],
            'status' => (string) $order->status,
        ]);
    }

    /**
     * Point the order at a different customer account, or at none (a guest).
     *
     * Its own capability and not `orders.edit`: attaching an order to an
     * account puts it, with its addresses, in that person's My Account. The
     * order's email is NOT changed -- that is the billing editor's job, with
     * its own note -- so a mis-click here moves the order between accounts and
     * nothing else.
     */
    public function updateCustomer(Request $request, int $id): JsonResponse
    {
        $order = Order::find($id);

        if ($order === null) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $data = $request->validate([
            'customer_id' => ['present', 'nullable', 'integer', 'min:1'],
        ]);

        $newId = $data['customer_id'] !== null ? (int) $data['customer_id'] : null;
        $new = $newId !== null ? Customer::query()->find($newId, ['id', 'name', 'first_name', 'last_name', 'email']) : null;

        if ($newId !== null && $new === null) {
            return response()->json(['ok' => false, 'message' => 'That customer does not exist.'], 422);
        }

        $oldId = $order->customer_id ? (int) $order->customer_id : null;

        if ($oldId === $newId) {
            return response()->json(['ok' => true, 'changed' => 0, 'message' => 'Nothing changed.']);
        }

        $old = $oldId !== null ? Customer::query()->find($oldId, ['id', 'name', 'first_name', 'last_name', 'email']) : null;

        $order->forceFill(['customer_id' => $newId])->save();

        $by = auth('admin')->user()?->name ?: 'Admin';
        $order->notes()->create([
            'content' => sprintf('Customer changed by %s: %s → %s.', $by, self::who($old, $oldId), self::who($new, $newId)),
            'author' => $by,
            'is_customer_note' => false,
        ]);

        return response()->json(['ok' => true, 'changed' => 1, 'message' => 'Customer updated']);
    }

    /** The picker behind "Change customer". Name or email, twelve rows. */
    public function customerSearch(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('search', ''));

        if (mb_strlen($term) < 2) {
            return response()->json(['customers' => []]);
        }

        $like = '%' . str_replace([self::LIKE_ESCAPE, '%', '_'], [self::LIKE_ESCAPE . self::LIKE_ESCAPE, self::LIKE_ESCAPE . '%', self::LIKE_ESCAPE . '_'], mb_substr($term, 0, 100)) . '%';
        $escape = " escape '" . self::LIKE_ESCAPE . "'";

        $rows = Customer::query()
            ->where(function ($q) use ($like, $escape) {
                $q->whereRaw('email like ?' . $escape, [$like])
                    ->orWhereRaw('name like ?' . $escape, [$like])
                    ->orWhereRaw('first_name like ?' . $escape, [$like])
                    ->orWhereRaw('last_name like ?' . $escape, [$like]);
            })
            ->orderBy('email')
            ->limit(self::SEARCH_LIMIT)
            ->get(['id', 'name', 'first_name', 'last_name', 'email']);

        return response()->json([
            'customers' => $rows->map(fn (Customer $c) => [
                'id' => $c->id,
                'name' => trim((string) $c->name) ?: trim($c->first_name . ' ' . $c->last_name),
                'email' => $c->email,
            ])->values(),
        ]);
    }

    private static function who(?Customer $c, ?int $id): string
    {
        if ($id === null) {
            return 'guest checkout';
        }

        if ($c === null) {
            return 'customer #' . $id;
        }

        $name = trim((string) $c->name) ?: trim($c->first_name . ' ' . $c->last_name);

        return ($name !== '' ? $name . ' ' : '') . '(' . $c->email . ', #' . $c->id . ')';
    }
}
