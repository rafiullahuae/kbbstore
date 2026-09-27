<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Payments\PaymentVoider;
use Illuminate\Http\JsonResponse;

/**
 * Release a BNPL authorisation, from the order screen.
 *
 * The third member of the family PaymentSettlementController opened: capture
 * takes the money, refund gives back money that was taken, and this gives back
 * the RIGHT to take it — see App\Services\Payments\VoidsAuthorisation for what
 * a cancelled order costs the customer without it.
 *
 * DELIBERATELY GATEWAY-AGNOSTIC. Nothing in this controller names Tamara. It
 * resolves an order, hands it to PaymentVoider, and PaymentVoider asks the
 * registry whether that order's gateway implements VoidsAuthorisation. Tamara
 * is the only one that does today; when Tabby gains the same method — it has
 * the same close-endpoint on its API and the same problem without it — this
 * endpoint works for it with no edit at all. A controller that tested
 * `$order->payment_method === 'tamara'` would have to be found and changed, and
 * PaymentsGatewayTabsTest already forbids that shape on the settings screen for
 * the same reason.
 *
 * NO AMOUNT CROSSES THE REQUEST. Same rule as capture: the amount released is
 * the order's own `total` column, read inside the service. A controller that
 * accepted an amount would be a controller somebody could POST a different one
 * to, and on this endpoint the consequence is an amount block Tamara refuses —
 * or, worse, a partial release nobody asked for.
 *
 * GUARD. Mounted inside the existing `admin-api` group in routes/web.php, which
 * carries `auth:admin` and NoStoreAdminApi, and mapped in
 * App\Support\AdminCapabilities to `orders.money`. Unauthenticated, this is a
 * way for a stranger to cancel the payment plan behind every order in the shop.
 * See routes/payments-tamara.php.
 */
class PaymentVoidController extends Controller
{
    public function __construct(private PaymentVoider $voider) {}

    /**
     * Release the authorisation.
     *
     * Idempotent by construction: PaymentVoider claims `voided_at` with one
     * conditional UPDATE before it calls the provider, so a double-clicked
     * button releases once and the second click is answered `already_voided`
     * without a second call.
     */
    public function void(int $id): JsonResponse
    {
        $order = Order::find($id);

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
        ], $result->ok ? 200 : $this->statusFor($result->code));
    }

    /**
     * Which HTTP status a refusal deserves.
     *
     * 502 for a provider that could not be reached or refused — the request was
     * fine and a retry may work, which is the same distinction
     * PaymentSettlementController::capture() draws. 422 for the refusals this
     * shop made on its own: the order is still live, the money is already
     * captured, the gateway cannot do this. Those will say the same thing for
     * ever and a retry is the wrong next action, so telling the screen to offer
     * one would be a lie.
     */
    private function statusFor(string $code): int
    {
        return in_array($code, [
            'unsupported_gateway',
            'order_trashed',
            'already_captured',
            'order_still_live',
            'not_configured',
            'reference_mismatch',
        ], true) ? 422 : 502;
    }
}
