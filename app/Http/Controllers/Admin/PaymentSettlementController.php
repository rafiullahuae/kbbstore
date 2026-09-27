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
        ], $result->ok ? 200 : $this->statusFor($result->code));
    }

    /**
     * Which HTTP status a refusal deserves.
     *
     * 502 for a gateway that could not be reached or said no: the request was
     * fine, the provider was not, and a retry may well work.
     *
     * 422 for the refusals this shop made on its own, before anything left the
     * building. Those will say the same thing for ever — the order is cancelled,
     * the gateway cannot be captured, the authorisation has been released — so
     * answering 502 tells the screen the provider is having a bad morning and
     * invites a retry that cannot succeed. That is the same "reports a failure
     * as something it is not" defect this console keeps finding, one layer down
     * from the toast.
     *
     * PaymentVoidController::statusFor() has drawn exactly this line since the
     * release button shipped, and its docblock says it is "the same distinction
     * PaymentSettlementController::capture() draws" — which was not true: this
     * method answered a flat 502 to everything, including `order_not_live` and
     * `unsupported_gateway`. Now it is true, and the two endpoints in the same
     * family answer the same way.
     *
     * No existing test moves: the two 502s pinned in PaymentSettlementTest are
     * both real gateway failures (`capture_failed` from Tabby, a Stripe refund
     * that comes back `failed` on a 200), and both still answer 502.
     */
    private function statusFor(string $code): int
    {
        return in_array($code, [
            'unsupported_gateway',
            'order_trashed',
            'order_not_live',
            'nothing_to_capture',
            'not_authorised',
            'authorisation_released',
            'not_configured',
        ], true) ? 422 : 502;
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
            /*
             * The third verb, on the endpoint the order screen already reads.
             *
             * A NESTED KEY rather than more top-level ones, so the screen can
             * tell "this order's authorisation has been released" from "this
             * order has been captured" without the two vocabularies growing into
             * each other. PaymentVoider::status() never calls a provider, for
             * the same reason PaymentCapturer::status() does not: this is
             * rendered with the order page.
             *
             * ADDITIVE. Nothing that reads this response today looks for `void`,
             * so nothing changes for any existing caller — see
             * App\Services\Payments\VoidsAuthorisation for why the key had to
             * exist at all.
             */
            'void' => app(\App\Services\Payments\PaymentVoider::class)->status($order),
        ];
    }
}
