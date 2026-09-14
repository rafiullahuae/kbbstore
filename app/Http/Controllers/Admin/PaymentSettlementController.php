<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Payments\PaymentCapturer;
use App\Services\Payments\PaymentRefunder;
use App\Support\Money;
use Illuminate\Http\JsonResponse;

/**
 * Capture, from the order screen.
 *
 * Refund already had a home — AdminOrderController::refund, wired since the
 * order detail page was built — so it stayed there and grew a real gateway
 * call underneath it rather than moving to a second screen the owner would
 * have to learn. Capture had none, and this is it.
 *
 * WHERE THE MONEY CHECKS ARE, AND WHERE THEY ARE NOT
 *
 * Not here. This controller resolves an order and hands it to PaymentCapturer;
 * the amount comes from the order's own `total` column and the idempotency
 * guard is a conditional UPDATE inside that service. Nothing in the request
 * reaches an amount, which is the point: a controller that accepted an amount
 * would be a controller somebody could POST a different one to.
 *
 * GUARD. Every route here is mounted inside the existing `admin-api` group in
 * routes/web.php, which carries `auth:admin` and NoStoreAdminApi. That is not
 * a detail: an unauthenticated capture endpoint is a way for a stranger to
 * take a customer's money, and an unauthenticated refund endpoint is a way to
 * empty the merchant's account. See routes/payments-settlement.php.
 */
class PaymentSettlementController extends Controller
{
    public function __construct(
        private PaymentCapturer $capturer,
        private PaymentRefunder $refunder,
    ) {}

    /**
     * What the screen needs to render the capture panel.
     *
     * Reads only, and never calls a provider — this is rendered with the order
     * page and a BNPL API having a slow morning must not be the reason an
     * admin cannot look at an order.
     */
    public function show(int $id): JsonResponse
    {
        $order = Order::withTrashed()->find($id);

        if ($order === null) {
            return response()->json(['error' => 'not_found'], 404);
        }

        return response()->json($this->state($order));
    }

    /**
     * Take the authorised money.
     *
     * Idempotent by construction: PaymentCapturer claims `captured_at` before
     * it calls the provider, so a double-clicked button captures once and the
     * second click is answered `already_captured` without a second call.
     */
    public function capture(int $id): JsonResponse
    {
        $order = Order::find($id);

        if ($order === null) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $result = $this->capturer->capture($order, auth('admin')->user()?->name);

        $order->refresh();

        return response()->json([
            'ok' => $result->ok,
            'code' => $result->code,
            // Safe by construction: SettlementResult messages are written for
            // the admin and never carry an API body, a key or a buyer field.
            'message' => $result->message,
            'settlement' => $this->state($order),
            // 502 rather than 422 on a gateway failure: the request was fine,
            // the provider was not, and the difference tells the admin whether
            // to fix something or to try again.
        ], $result->ok ? 200 : 502);
    }

    /**
     * The shape the order screen reads, on load and after every action.
     *
     * @return array<string, mixed>
     */
    private function state(Order $order): array
    {
        $refunded = $this->refunder->refundedFils($order);
        $captured = $this->refunder->capturedFils($order);

        return $this->capturer->status($order) + [
            'refunded_total_aed' => Money::toAed($refunded),
            'refundable_aed' => Money::toAed(max(0, $captured - $refunded)),
            'refundable_fils' => max(0, $captured - $refunded),
        ];
    }
}
