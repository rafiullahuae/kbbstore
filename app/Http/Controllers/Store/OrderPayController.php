<?php

declare(strict_types=1);

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Payments\GatewayRegistry;
use App\Services\Payments\PaymentGateway;
use App\Support\OrderLinks;
use App\Support\Url;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * "Complete your order": pay for ONE unpaid order from a link in an email
 * (Lane RL).
 *
 * The button in the 30-minute and 24-hour reminders and in the payment-failed
 * email opens GET /checkout/order-pay?order=<number>&t=<signed>, on any device.
 * The token is App\Support\OrderLinks' (purpose `order.pay`, this order's id
 * and number, 7 days). This page shows that order and the ways to pay the
 * checkout already offers, and paying goes through the SAME gateway code the
 * checkout uses — PaymentGateway::start() on this order. No second order is
 * created, ever: the order in the email is the order that gets paid.
 *
 * WHAT THE LINK IS ALLOWED TO DO, AND NO MORE.
 *
 *   - It opens one order. A token for another order, a forged one and an
 *     expired one are the same 404 after the same work (OrderLinks::resolve).
 *   - It marks THIS browser as the one that may finish THIS order, by writing
 *     the session marker the checkout itself writes (`kbb_last_order`). That
 *     is what lets the existing endpoints downstream recognise the shopper —
 *     the card confirmation (/checkout/card/paid), the instalment providers'
 *     return leg (/checkout/pending) and the order-received page — without one
 *     line of them changing. Holding the emailed link is the same proof as
 *     having placed the order: it was sent to the address on it.
 *   - It never shows the delivery address or the email, and it cannot change
 *     either. What it shows is what the email already showed.
 *
 * WHICH WAYS TO PAY. Every gateway the checkout would offer for this order's
 * amount and country, EXCEPT any whose surcharge differs from the one already
 * inside this order's total. The total was fixed when the order was placed and
 * this page does not re-price an order; offering cash on delivery with a
 * handling fee for an order placed by card would mean charging a figure the
 * order does not carry. A method with the same surcharge (nearly always none)
 * is offered and the order's payment method is updated to it.
 *
 * ALREADY PAID, CANCELLED, SHIPPED... The link shows the order's status
 * instead: it forwards to the signed status page (OrderLinks::trackUrl).
 *
 * A FAILED ORDER is brought back to `pending` through the OrderStatus funnel
 * before paying, which takes back the stock and coupon use its failure handed
 * out. If they are gone, the funnel refuses and the shopper is told plainly
 * that the order cannot be completed here.
 */
class OrderPayController extends Controller
{
    public const PAYABLE = ['pending', 'failed'];

    public function __construct(private GatewayRegistry $gateways) {}

    public function show(Request $request): Response|RedirectResponse
    {
        $order = $this->resolve($request);

        if ($order === null) {
            return $this->notFound();
        }

        if (! $this->payable($order)) {
            return redirect()->away(OrderLinks::trackUrl($order));
        }

        $request->session()->put('kbb_last_order', (string) $order->order_number);

        $order->loadMissing(['items' => fn ($q) => $q->orderBy('id')]);

        $methods = $this->methods($order);
        $stripe = collect($methods)->first(fn (array $m) => $m['id'] === 'stripe');
        $stripeGateway = $stripe ? $this->gateways->find('stripe') : null;

        return response()->view('store.order-pay', [
            'order' => $order,
            'methods' => $methods,
            'token' => (string) $request->query('t', ''),
            'stripeKey' => $stripeGateway instanceof \App\Services\Payments\Gateways\StripeGateway
                ? $stripeGateway->publishableKey()
                : '',
        ]);
    }

    public function start(Request $request): JsonResponse|RedirectResponse|Response
    {
        $order = $this->resolve($request);

        if ($order === null) {
            return $request->expectsJson() ? response()->json(['ok' => false], 404) : $this->notFound();
        }

        if (! $this->payable($order)) {
            return $this->answer($request, ['ok' => true, 'action' => 'redirect', 'url' => OrderLinks::trackUrl($order)]);
        }

        $method = (string) $request->input('method', '');
        $offered = collect($this->methods($order))->firstWhere('id', $method);
        $gateway = $offered ? $this->gateways->find($method) : null;

        if ($gateway === null) {
            return $this->refuse($request, __('store.order_pay.method_unavailable'));
        }

        $request->session()->put('kbb_last_order', (string) $order->order_number);

        if ((string) $order->status === 'failed') {
            try {
                $reopened = app(\App\Services\Orders\OrderStatus::class)->moveTo(
                    $order,
                    'pending',
                    by: 'system',
                    reason: 'The customer reopened this order from the "Complete your order" link to pay for it.',
                    only: ['paid_at' => null, 'status' => 'failed'],
                );
            } catch (\App\Services\Orders\OrderReviveRefused) {
                return $this->refuse($request, __('store.order_pay.cannot_reopen'));
            }

            $order->refresh();

            // null: the precondition did not hold -- something else moved the
            // order (a late payment confirmation, an operator) between the
            // read above and the lock. Show its status rather than charge it.
            if ($reopened === null || (string) $order->status !== 'pending' || $order->paid_at !== null) {
                return $this->answer($request, ['ok' => true, 'action' => 'redirect', 'url' => OrderLinks::trackUrl($order)]);
            }
        }

        if ((string) $order->payment_method !== $gateway->id()) {
            $order->forceFill([
                'payment_method' => $gateway->id(),
                'payment_method_title' => (string) ($offered['title'] ?? $gateway->title()),
            ])->save();
        }

        try {
            $start = $gateway->start($order);
        } catch (\Throwable $e) {
            Log::error('order-pay start failed', ['order' => $order->order_number, 'exception' => class_basename($e)]);

            return $this->refuse($request, __('store.order_pay.start_failed'));
        }

        if (! $start->ok()) {
            return $this->refuse($request, $start->message ?? __('store.order_pay.start_failed'));
        }

        $success = Url::to('/checkout/success') . '?order=' . rawurlencode((string) $order->order_number);

        if ($start->redirectUrl !== null) {
            return $this->answer($request, ['ok' => true, 'action' => 'redirect', 'url' => $start->redirectUrl]);
        }

        if ($start->clientSecret !== null) {
            if (! $request->expectsJson()) {
                return $this->refuse($request, __('store.order_pay.needs_javascript'));
            }

            return response()->json([
                'ok' => true,
                'action' => 'confirm',
                'client_secret' => $start->clientSecret,
                'order' => (string) $order->order_number,
                'success_url' => $success,
            ]);
        }

        // Placed outright (cash on delivery): the receipt has gone through
        // OrderMailObserver as the order left `pending`.
        return $this->answer($request, ['ok' => true, 'action' => 'redirect', 'url' => $success]);
    }

    /**
     * The ways to pay this order, as the checkout lists them, minus any whose
     * surcharge would change the total. See the class header.
     *
     * @return list<array<string, mixed>>
     */
    public function methods(Order $order): array
    {
        $giftFee = max(0, (int) $order->gift_fee);
        $currentFee = max(0, (int) $order->fee_total - $giftFee);
        $base = max(0, (int) $order->total - $currentFee);

        $billing = is_array($order->billing_address) ? $order->billing_address : [];
        $shipping = is_array($order->shipping_address) ? $order->shipping_address : [];
        $country = (string) ($billing['country'] ?? $shipping['country'] ?? '');

        return collect($this->gateways->checkoutList($base, $country !== '' ? $country : null))
            ->filter(fn (array $m) => (int) ($m['fee_fils'] ?? 0) === $currentFee)
            ->values()
            ->all();
    }

    private function resolve(Request $request): ?Order
    {
        return OrderLinks::resolve(
            OrderLinks::PAY,
            (string) $request->input('order', ''),
            (string) $request->input('t', ''),
        );
    }

    private function payable(Order $order): bool
    {
        return in_array((string) $order->status, self::PAYABLE, true)
            && $order->paid_at === null
            && ! $order->trashed()
            && ! \App\Services\Orders\SampleOrder::is($order);
    }

    private function notFound(): Response
    {
        return response()->view('store.order-pay', ['order' => null, 'methods' => [], 'token' => '', 'stripeKey' => ''], 404);
    }

    private function refuse(Request $request, string $message): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['ok' => false, 'error' => $message], 422);
        }

        return back()->withErrors($message);
    }

    private function answer(Request $request, array $body): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json($body);
        }

        return redirect()->away((string) $body['url']);
    }
}
