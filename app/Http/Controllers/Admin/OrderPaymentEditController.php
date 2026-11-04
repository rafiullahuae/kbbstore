<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Orders\ManualPayment;
use App\Services\Orders\OrderPaymentEdit;
use App\Services\Orders\OrderReviveRefused;
use App\Support\StoreTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Store -> Orders -> (an order) -> Payment -> "Change payment method", and
 * Items -> "Apply coupon" / "Remove coupon". (2.60.641) routes/order-payment-edit.php.
 *
 * orders.payment changes the method (the "Mark as paid" capability: owner and
 * manager); orders.money puts a coupon on or takes it off (the item-editing
 * capability: owner and manager). EnforceAdminCapability refuses everybody
 * else before this class runs; the map fails closed. Every rule about what may
 * change is in App\Services\Orders\OrderPaymentEdit.
 */
class OrderPaymentEditController extends Controller
{
    public function paymentMethod(Request $request, int $id, OrderPaymentEdit $edit): JsonResponse
    {
        $order = Order::find($id);

        if ($order === null) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $data = $request->validate([
            'payment_method' => ['required', 'string', 'max:64'],
            'cod_fee' => ['sometimes', 'boolean'],
            'status' => ['nullable', 'string', Rule::in(['processing'])],
            'paid' => ['sometimes', 'boolean'],
            'reference' => ['nullable', 'string', 'max:' . ManualPayment::REFERENCE_MAX, 'regex:/^[^\x00-\x1F\x7F<>]*$/u'],
            'paid_at' => ['nullable', 'required_if:paid,true,1', 'date'],
            'notify' => ['sometimes', 'nullable', 'boolean'],
        ], [
            'reference.regex' => 'The reference cannot contain line breaks or angle brackets.',
            'paid_at.required_if' => 'Enter the date the payment was made.',
        ]);

        $paidAt = null;

        if ($request->boolean('paid')) {
            // The shop's clock, as "Mark as paid" reads it (OrderDetailController::markPaid).
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
        }

        try {
            $result = $edit->changeMethod($order, [
                'method' => (string) $data['payment_method'],
                'cod_fee' => $request->has('cod_fee') ? $request->boolean('cod_fee') : true,
                'status' => $data['status'] ?? null,
                'paid' => $request->boolean('paid'),
                'reference' => $data['reference'] ?? null,
                'paid_at' => $paidAt,
                'notify' => $request->has('notify') ? $request->boolean('notify') : null,
            ], $this->by());
        } catch (OrderReviveRefused $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    public function applyCoupon(Request $request, int $id, OrderPaymentEdit $edit): JsonResponse
    {
        $order = Order::find($id);

        if ($order === null) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $data = $request->validate([
            'code' => ['required', 'string', 'max:' . OrderPaymentEdit::CODE_MAX, 'regex:/^[^\x00-\x1F\x7F<>]*$/u'],
        ]);

        $result = $edit->applyCoupon($order, (string) $data['code'], $this->by());

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    public function removeCoupon(int $id, OrderPaymentEdit $edit): JsonResponse
    {
        $order = Order::find($id);

        if ($order === null) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $result = $edit->removeCoupon($order, $this->by());

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    private function by(): string
    {
        return auth('admin')->user()?->name ?: 'Admin';
    }
}
