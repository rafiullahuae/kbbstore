<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Payments\PaymentVoider;
use Illuminate\Http\JsonResponse;

/**
 * Release an uncaptured authorisation, from the order screen.
 *
 * PaymentSettlementController's third verb, kept in a file of its own because
 * that one belongs to another lane and this is not the lane to widen it in.
 *
 * WHERE THE MONEY CHECKS ARE, AND WHERE THEY ARE NOT
 *
 * Not here. This controller resolves an order and hands it to PaymentVoider,
 * which refuses a captured order, refuses a refunded one, claims `voided_at`
 * before it calls the provider and puts it back if the provider refuses. Nothing
 * in the request reaches an amount or a provider reference, and there is nothing
 * in the request to reach one with — the route takes an id and the body is
 * ignored entirely. A release that took a payment id from the caller would be a
 * way to close somebody else's authorisation.
 *
 * GUARD. Mounted inside the existing `admin-api` group in routes/web.php, which
 * carries `auth:admin` and NoStoreAdminApi, and mapped to `orders.money` — the
 * same capability as capture and refund. It is the same class of act: it decides
 * what happens to money a customer has committed. It is NOT `orders.view`, and
 * the difference is that this one can be used to release the hold on every live
 * order in the shop.
 */
class PaymentVoidController extends Controller
{
    public function __construct(private PaymentVoider $voider) {}

    /**
     * Whether this order's authorisation can be released.
     *
     * Reads only, never calls a provider. Rendered with the order page.
     */
    public function show(int $id): JsonResponse
    {
        $order = Order::withTrashed()->find($id);

        if ($order === null) {
            return response()->json(['error' => 'not_found'], 404);
        }

        return response()->json($this->voider->status($order));
    }

    /**
     * Release it.
     *
     * withTrashed(), unlike capture(): a trashed order is exactly the order most
     * likely to be holding a live authorisation nobody meant to leave open, and
     * refusing to release it would leave the customer's credit committed with no
     * way to free it but Tabby's own timer. Releasing money is safe on an order
     * in any state; TAKING it is not, which is why capture() does not do this.
     */
    public function store(int $id): JsonResponse
    {
        $order = Order::withTrashed()->find($id);

        if ($order === null) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $result = $this->voider->void($order, auth('admin')->user()?->name);

        $order->refresh();

        return response()->json([
            'ok' => $result->ok,
            'code' => $result->code,
            // Safe by construction: SettlementResult messages are written for
            // the admin and never carry an API body, a key or a buyer field.
            'message' => $result->message,
            'void' => $this->voider->status($order),
            // 502 rather than 422 on a gateway failure: the request was fine,
            // the provider was not, and the difference tells the admin whether
            // to fix something or to try again.
        ], $result->ok ? 200 : 502);
    }
}
