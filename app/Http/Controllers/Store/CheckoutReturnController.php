<?php

declare(strict_types=1);

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\CartService;
use App\Services\Checkout\BasketRelease;
use App\Services\Checkout\PlacementState;
use App\Services\Checkout\UnfinishedPayment;
use App\Services\Payments\GatewayRegistry;
use App\Support\Url;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Coming back from Tabby or Tamara without having paid. (Lane PLC, Lane BK)
 *
 * RemoteGateway::returnUrl() points the `cancel` and `failure` legs of both
 * instalment providers at GET /checkout/pending?order=<number>.
 *
 * ── WHAT CHANGED IN LANE BK, AND WHY ────────────────────────────────────────
 *
 * Until 2.60.440 this page wrote nothing: it landed the shopper on an EMPTY
 * basket ("Your Bag (0 items)"), because place() had marked it `converted`, and
 * offered a "Put my basket back" button for them to find and press. The owner,
 * on his phone, after a payment he cancelled: "the cart should remain same,
 * cart must not be empty in any case, so the user can re-try".
 *
 * So the return itself gives the basket back, through UnfinishedPayment — the
 * same locked release the button made, plus asking the provider first. The
 * reasons the button was preferred are answered there rather than ignored:
 *
 *   - "a GET must not write": the write is gated on THIS session's own
 *     `kbb_last_order` and this browser's own basket, so a link scanner or a
 *     prefetcher (instant navigation excludes /checkout anyway) has neither and
 *     writes nothing; the order number in the address must match the session.
 *   - "the shopper might still finish at the provider": the provider is asked
 *     before anything is released (SettlesBeforeRelease), and a payment it has
 *     taken is applied — paid wins. This is the provider's own cancel/failure
 *     address; it does not send a shopper here mid-payment.
 *   - "they may not want it back": a basket is a basket, not a purchase. It
 *     costs them nothing to hold, and an empty one costs them the whole search.
 *
 * ── IT NEVER TELLS A PAID SHOPPER THEIR PAYMENT FAILED ──────────────────────
 *
 * An order PlacementState calls CONFIRMED — or one the provider turns out to
 * have approved — forwards to its receipt, which is where the success leg
 * would have sent them.
 *
 * ── AND IT IS NOT AN ORACLE ─────────────────────────────────────────────────
 *
 * The number in the query string is never trusted on its own (it is sequential
 * and guessable): it must hash_equals `kbb_last_order`. A stranger's number and
 * a typo get the same generic sentence and the same destination.
 */
class CheckoutReturnController extends Controller
{
    /** The order the old "Put my basket back" button was offered for, by number. */
    public const RESTORABLE_KEY = 'kbb_restorable';

    /** The TOKEN of the basket that old offer belonged to. */
    public const RESTORABLE_CART_KEY = 'kbb_restorable_cart';

    /** Set for exactly one request after a basket has been given back. */
    public const RESTORED_KEY = UnfinishedPayment::BACK_KEY;

    /**
     * The order an OLD button may still be pressed for, or ''.
     *
     * Nothing writes the offer any more: the basket comes back by itself. A
     * session from before 2.60.440 can still hold one, and a page left open
     * from then can still post the button, so restore() keeps honouring it —
     * and keeps refusing one that no longer names `kbb_last_order`, so a stale
     * button can never release the shopper's NEWER order. Session only.
     */
    public static function offeredOrderNumber(): string
    {
        $mine = trim((string) session('kbb_last_order', ''));
        $offered = trim((string) session(self::RESTORABLE_KEY, ''));

        if ($mine === '' || $offered === '' || ! hash_equals($mine, $offered)) {
            return '';
        }

        return $offered;
    }

    public function __construct(
        private readonly PlacementState $placement,
        private readonly GatewayRegistry $gateways,
        private readonly CartService $carts,
        private readonly UnfinishedPayment $unfinished,
    ) {}

    public function pending(Request $request): RedirectResponse
    {
        $order = $this->orderThisSessionPlaced($request);

        // (Lane TM) the Payment journey's "came back" step. This session's own
        // order only, once per order; never stands in the shopper's way.
        if ($order !== null) {
            \App\Support\PaymentJourney::returned($request, $order, 'pending');
        }

        // Paid after all. Where the success leg would have sent them.
        if ($order !== null && $this->placement->forOrder($order) === PlacementState::CONFIRMED) {
            return $this->toReceipt($request, $order);
        }

        [$outcome, $settled] = $this->unfinished->recover(
            $request,
            shopperCameBack: true,
            number: (string) $request->query('order', ''),
        );

        if ($outcome === UnfinishedPayment::PAID && $settled !== null) {
            return $this->toReceipt($request, $settled);
        }

        if ($outcome === BasketRelease::RESTORED || $outcome === BasketRelease::MERGED) {
            return redirect(UnfinishedPayment::cartUrl($request, $outcome))->with(UnfinishedPayment::BACK_KEY, $outcome);
        }

        /*
         * Nothing to give back from here: not this session's order, a basket
         * already given back by an earlier return, or a provider that could not
         * say. Said honestly, on whichever page has a basket to show. /checkout/
         * prints $errors->first(); /cart/ draws partials/checkout/return-notice.
         */
        $cart = $this->carts->current($request, create: false);
        $hasBasket = $cart !== null && $cart->items()->exists();

        return redirect(Url::redirect($hasBasket ? '/checkout/' : '/cart/', $request))
            ->withErrors($this->reason($order));
    }

    /**
     * The old "Put my basket back" button. KEPT WORKING FOR OLD PAGES, never
     * needed: the basket now comes back on the return itself.
     *
     * Gated as it always was, on an offer that still names `kbb_last_order`,
     * then handed to the same UnfinishedPayment::recover() every return uses,
     * so there is one release path in the shop, not two.
     */
    public function restore(Request $request): RedirectResponse
    {
        $offered = self::offeredOrderNumber();
        $this->forgetOffer($request);

        if ($offered === '') {
            return $this->nothingToPutBack($request);
        }

        [$outcome, $order] = $this->unfinished->recover($request, shopperCameBack: true, number: $offered);

        if ($outcome === UnfinishedPayment::PAID && $order !== null) {
            return $this->toReceipt($request, $order);
        }

        if ($outcome === BasketRelease::RESTORED || $outcome === BasketRelease::MERGED) {
            return redirect(UnfinishedPayment::cartUrl($request, $outcome))->with(self::RESTORED_KEY, $outcome);
        }

        return $this->nothingToPutBack($request);
    }

    /**
     * Nothing was found to put back, said the same way whatever the reason —
     * a number that is not this session's, a second press, a basket already
     * given back. Telling them apart would say which order numbers are real.
     */
    private function nothingToPutBack(Request $request): RedirectResponse
    {
        return redirect(Url::redirect('/cart/', $request))
            ->withErrors(__('store.checkout.restore_gone'));
    }

    /** Where an order that turned out to stand sends its shopper. */
    private function toReceipt(Request $request, Order $order): RedirectResponse
    {
        return redirect(
            Url::redirect('/checkout/success', $request).'?order='.urlencode((string) $order->order_number)
        );
    }

    /** Drop the old offer, both halves together. */
    private function forgetOffer(Request $request): void
    {
        $request->session()->forget(self::RESTORABLE_KEY);
        $request->session()->forget(self::RESTORABLE_CART_KEY);
    }

    /**
     * What to tell them when nothing could be given back. Neither sentence
     * guesses: a declined shopper and one who pressed Back arrive at the same
     * address with no reason attached, so only what is certainly true is said.
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
     * The order this browser placed a moment ago, or null. hash_equals so a
     * mismatch costs the same time whatever is wrong with it.
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
