<?php

declare(strict_types=1);

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\CartService;
use App\Services\Checkout\PlacementState;
use App\Services\Payments\GatewayRegistry;
use App\Support\Url;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Coming back from Tabby or Tamara without having paid. (Lane PLC)
 *
 * ── WHAT WAS HERE BEFORE, AND WHY IT WAS NOT ENOUGH ─────────────────────────
 *
 * routes/web.php answered GET /checkout/pending with one closure:
 *
 *     Route::get('/checkout/pending', fn () => redirect(Url::redirect('/checkout/')))
 *
 * App\Services\Payments\Gateways\RemoteGateway::returnUrl() points BOTH the
 * `cancel` and the `failure` legs of both instalment providers at that address,
 * with the order number in the query string. So a shopper who pressed the back
 * arrow at Tamara, or whose plan was declined, was bounced to a checkout that
 * said nothing at all about what had just happened — and the order number the
 * provider had carefully handed back was thrown away by the closure's `fn ()`,
 * which takes no request.
 *
 * A shopper who has just been refused credit and lands on an unchanged form is
 * a shopper who presses Place order again.
 *
 * ── THIS CONTROLLER WRITES NOTHING. ─────────────────────────────────────────
 *
 * It is a GET, and a GET that changes the state of an order is a GET that a
 * link prefetcher, a corporate mail scanner or an antivirus browser extension
 * can fire on the shopper's behalf. The obvious thing to do here — mark the
 * order `failed`, hand the stock and the coupon back, put the basket back the
 * way Store\CheckoutController::cardAbandoned() does for a declined card — is
 * therefore NOT done here, and deliberately: it belongs behind a POST, and the
 * stock path is another lane's this round.
 *
 * What is left is honest and complete on its own: work out what actually
 * happened to this order, and say so where the shopper will see it.
 *
 * ── AND IT NEVER TELLS A PAID SHOPPER THEIR PAYMENT FAILED ──────────────────
 *
 * The first thing it does is ask App\Services\Checkout\PlacementState. A
 * provider that sends an APPROVED order to its cancel URL — a mis-click on
 * their page, a retry, a webhook that landed first — must not produce "your
 * payment was not completed" over an order that is paid for. Such a visitor is
 * forwarded to their order-received page instead, which is where the success
 * leg would have put them.
 *
 * ── HOW THE ORDER IS IDENTIFIED, AND WHY IT IS NOT AN ORACLE ────────────────
 *
 * By `kbb_last_order` and hash_equals, exactly as
 * CheckoutController::orderThisSessionPlaced() does it. The number in the query
 * string is never trusted on its own: an order number is sequential and
 * guessable (see nextOrderNumber()), and a page that answered differently for a
 * real number than for a made-up one would say which numbers exist. A mismatch
 * and a typo get the same generic sentence and the same destination.
 */
class CheckoutReturnController extends Controller
{
    public function __construct(
        private readonly PlacementState $placement,
        private readonly GatewayRegistry $gateways,
        private readonly CartService $carts,
    ) {}

    public function pending(Request $request): RedirectResponse
    {
        $order = $this->orderThisSessionPlaced($request);

        // Paid after all. Where the success leg would have sent them.
        if ($order !== null && $this->placement->forOrder($order) === PlacementState::CONFIRMED) {
            return redirect(
                Url::redirect('/checkout/success', $request).'?order='.urlencode((string) $order->order_number)
            );
        }

        $reason = $this->reason($order);

        /*
         * WHERE THEY LAND, decided here rather than left to chance.
         *
         * CheckoutController::place() marks the basket `converted` inside the
         * transaction that writes the order, and nothing on the return leg puts
         * it back — so a shopper who abandoned at the provider arrives with no
         * active basket. Sending them to /checkout/ would then bounce them
         * straight on to /cart/ by that page's own empty-basket guard, and the
         * flashed message would be consumed by the hop that did not render it.
         * The sentence would vanish, which is the whole failure this controller
         * exists to fix, reintroduced one redirect later.
         *
         * So the destination is chosen from whether there is a basket to check
         * out at all. Both pages render the message: /checkout/ has always
         * printed $errors->first() in its .co-notices band, and /cart/ gains
         * partials/checkout/return-notice, which draws nothing without one.
         */
        $cart = $this->carts->current($request, create: false);
        $hasBasket = $cart !== null && $cart->items()->exists();

        return redirect(Url::redirect($hasBasket ? '/checkout/' : '/cart/', $request))
            ->withErrors($reason);
    }

    /**
     * What to tell them, in as much detail as the shop actually has.
     *
     * The shop does NOT know whether the shopper was declined or simply changed
     * their mind: both outcomes come back through the same two addresses and
     * neither carries a reason. Inventing one would be worse than saying less —
     * "your card was declined" to somebody who pressed Back is an accusation.
     *
     * So the sentence states the two things that are certainly true — the
     * payment was not completed and nothing has been charged — and names the
     * provider, which is the part the shopper needs in order to know which of
     * several tabs went wrong. The name is the gateway's own title, the same
     * string the checkout printed beside its radio button.
     */
    private function reason(?Order $order): string
    {
        $gateway = $order === null ? null : $this->gateways->find((string) $order->payment_method);

        if ($gateway === null) {
            return __('store.checkout.return_not_completed');
        }

        return __('store.checkout.return_not_completed_at', ['provider' => $gateway->title()]);
    }

    /**
     * The order this browser placed a moment ago, or null.
     *
     * The same test CheckoutController::orderThisSessionPlaced() applies, minus
     * its `payment_method = stripe` clause, which is that method's own business.
     * hash_equals rather than === so a mismatch costs the same time whatever is
     * wrong with it.
     */
    private function orderThisSessionPlaced(Request $request): ?Order
    {
        $number = trim((string) $request->query('order', ''));
        $mine = trim((string) $request->session()->get('kbb_last_order', ''));

        if ($number === '' || $mine === '' || ! hash_equals($mine, $number)) {
            return null;
        }

        return Order::where('order_number', $number)->first();
    }
}
