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
 * DELIBERATELY GATEWAY-AGNOSTIC. Nothing in this controller names a provider. It
 * resolves an order, hands it to PaymentVoider, and PaymentVoider asks the
 * registry whether that order's gateway implements VoidsAuthorisation. Tamara and
 * Tabby both do — two lanes arrived at this same endpoint independently in one
 * round, which is the strongest evidence available that it belongs to neither of
 * them — and the next gateway with a close endpoint works here with no edit at
 * all. A controller that tested `$order->payment_method === 'tamara'` would have
 * to be found and changed; PaymentsGatewayTabsTest already forbids that shape on
 * the settings screen for the same reason.
 *
 * NO AMOUNT CROSSES THE REQUEST. Same rule as capture: the amount released is
 * the order's own `total` column, read inside the service. A controller that
 * accepted an amount would be a controller somebody could POST a different one
 * to, and on this endpoint the consequence is an amount block the provider
 * refuses — or, worse, a partial release nobody asked for. There is nothing in
 * the request to reach a provider reference with either: the route takes an id
 * and the body is ignored entirely.
 *
 * WHY BOTH VERBS RESOLVE WITH withTrashed(). A trashed order is the order most
 * likely to be sitting on a hold nobody meant to leave open, and `Order::find()`
 * would answer 404 on it — which reads as "no such order" rather than as the
 * thing it is. PaymentVoider decides whether the release is allowed; see its own
 * note on why being in the trash is not one of the refusals.
 *
 * GUARD. Mounted inside the existing `admin-api` group in routes/web.php, which
 * carries `auth:admin` and NoStoreAdminApi, and mapped in
 * App\Support\AdminCapabilities to `orders.money` — the same capability as
 * capture and refund, because it is the same class of act. It is NOT
 * `orders.view`: unauthenticated or under-guarded, this is a way for a stranger
 * to cancel the payment plan behind every order in the shop. See
 * routes/payments-void.php.
 */
class PaymentVoidController extends Controller
{
    public function __construct(private PaymentVoider $voider) {}

    /**
     * Whether this order's authorisation can be released, and why not if not.
     *
     * Reads only, and never calls a provider: it is rendered with the order page
     * and a BNPL API having a slow morning must not be why an admin cannot look
     * at an order.
     */
    public function show(int $id): JsonResponse
    {
        $order = Order::withTrashed()->find($id);

        if ($order === null) {
            return response()->json(['error' => 'not_found'], 404);
        }

        return response()->json($this->voider->status($order) + [
            /*
             * THE FACTS THE OPERATOR CONFIRMS, ON THE READ THAT DECIDES WHETHER
             * TO OFFER THE BUTTON AT ALL.
             *
             * A nested key, so every top-level field this endpoint has ever
             * answered is byte-identical and PaymentVoider::status() — which is
             * also embedded in the order-detail and settlement payloads — is
             * untouched. Only this endpoint gained anything.
             *
             * It exists because the release control is appended to the order
             * screen from OUTSIDE app.blade.php (see
             * resources/views/admin/partials/order-release-hold.blade.php), so
             * the only order id it has is one it read off a click. `order_number`
             * lets the screen check that id against the order heading it can
             * see before it draws a button that spends somebody's credit, and
             * `amount`/`consequence` mean the confirmation sentence is the
             * SERVER's, not a figure the browser assembled.
             *
             * Reads only, and still calls no provider.
             */
            'confirm' => $this->voider->confirmation($order),
        ]);
    }

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
            'already_captured',
            'order_still_live',
            'not_configured',
            'reference_mismatch',
        ], true) ? 422 : 502;
    }
}
