<?php

declare(strict_types=1);

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\Order;
use App\Services\CartService;
use App\Services\Checkout\PlacementState;
use App\Services\Orders\OrderStatus;
use App\Services\Payments\GatewayRegistry;
use App\Support\Url;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
 * ── pending() WRITES NOTHING, AND restore() IS WHY IT DOES NOT HAVE TO ──────
 *
 * pending() is a GET, and a GET that changes the state of an order is a GET
 * that a link prefetcher, a corporate mail scanner or an antivirus browser
 * extension can fire on the shopper's behalf. So it works out what actually
 * happened, says so where the shopper will see it, and writes nothing at all.
 *
 * Putting the basket back is the other half, and it is a POST the shopper
 * presses — see restore() for the whole of why it is a button rather than a
 * script that fires on load.
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
    /** The order the "Put my basket back" button was offered for, by number. */
    public const RESTORABLE_KEY = 'kbb_restorable';

    /**
     * The TOKEN of the basket that offer belongs to.
     *
     * Remembered because the cookie does not survive the trip. The offer is
     * written on the request that lands the shopper; by the time they press the
     * button one request later, the basket page has asked for a cart with
     * create:true, found none active for their token, minted an empty one and
     * re-cookied the browser. Looking the basket up by the LIVE cookie at that
     * point finds the new empty cart and answers "there is nothing to put back"
     * — which is what it did, in a browser, in front of a screenshot.
     */
    public const RESTORABLE_CART_KEY = 'kbb_restorable_cart';

    /** Set for exactly one request after a basket has been put back. */
    public const RESTORED_KEY = 'kbb_basket_restored';

    /**
     * The order the button may be pressed for right now, or '' for none.
     *
     * ── ONE COMPARISON, READ BY BOTH THE PAGE AND THE ENDPOINT ──────────────
     *
     * restore() has always refused an offer that no longer names
     * `kbb_last_order`, because that key MOVES — place() overwrites it with
     * every order this browser makes, and a boolean offer would let the button
     * fail the shopper's NEWEST order. The partial that draws the button,
     * though, asked only whether RESTORABLE_KEY was set at all, so the two
     * gates were not the same gate.
     *
     * The shop therefore offered a way back that its own endpoint was about to
     * refuse: a shopper who abandoned at Tamara and then placed a second order
     * had a stale offer, and the next flashed error on their basket page drew
     * them a "Put my basket back" whose only possible answer was "There is
     * nothing to put back". A control that does nothing is its own defect —
     * this lane paid for that lesson once already, when the cart cookie did
     * not survive the trip.
     *
     * So the decision lives here, once, and the partial and restore() both
     * read it. They cannot drift apart again.
     *
     * READS THE SESSION AND NOTHING ELSE — no query, so the basket page costs
     * nothing to draw. hash_equals rather than === for the same reason
     * orderThisSessionPlaced() uses it: a mismatch costs the same time whatever
     * is wrong with it.
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

        $this->rememberRestorable($request, $order);

        return redirect(Url::redirect($hasBasket ? '/checkout/' : '/cart/', $request))
            ->withErrors($reason);
    }

    /**
     * Put the basket back, and let the order go. THE POST THE SHOPPER PRESSES.
     *
     * ═══════════════════════════════════════════════════════════════════════
     * WHY A BUTTON AND NOT A SCRIPT THAT FIRES ON ARRIVAL
     * ═══════════════════════════════════════════════════════════════════════
     *
     * The smoother thing is to post this from the page as it loads: the
     * shopper gets their basket back without being asked, and it reads as the
     * shop tidying up after itself. It was rejected, and not on the usual "a
     * GET must not write" grounds — this is a POST either way. Three reasons,
     * in the order they matter:
     *
     *   1. THIS IS NOT ONLY "PUT MY BASKET BACK". It moves the order to
     *      `failed`, and App\Services\Orders\OrderStatus hands the stock and
     *      the coupon back with it. That is a decision about an order, made on
     *      a page the shopper arrived at without asking for anything.
     *
     *   2. A SHOPPER CAN STILL FINISH AT THE PROVIDER. Tabby and Tamara open
     *      their own page; `cancel` is where they send a shopper who backed
     *      out of it, and a shopper who backs out and then thinks better of it
     *      has a browser Back button and a live plan waiting. Killing the order
     *      the instant they touch our return address takes that away from them
     *      silently. Pressing a button that says "Put my basket back" is them
     *      saying they are done with it.
     *
     *   3. THEY MAY NOT WANT IT BACK. Abandoning a payment is a decision too,
     *      and restoring a basket over it is the shop arguing.
     *
     * The cost of the button is one press. Measured on the page it lands on:
     * the basket is empty, the reason is the first thing on it, and the button
     * sits inside that same notice — see partials/checkout/return-notice and
     * the shots in docs/PLC-overlay-shots.
     *
     * ═══════════════════════════════════════════════════════════════════════
     * WHAT IT REFUSES, AND IT RE-CHECKS EVERY ONE UNDER A LOCK
     * ═══════════════════════════════════════════════════════════════════════
     *
     *   · an order that is not this session's         (kbb_last_order + hash_equals)
     *   · an order the button was not offered for     (kbb_restorable, same number)
     *   · an order that carries `paid_at`             (and again inside moveTo's `only`)
     *   · an order PlacementState calls CONFIRMED     (a late webhook, a COD order)
     *   · a basket that is not this browser's, or that was never converted
     *
     * The `paid_at` test is made twice on purpose. The first is a courtesy that
     * produces a good message; the second is `only: ['paid_at' => null]` inside
     * OrderStatus::moveTo(), which re-reads the row under `lockForUpdate` and
     * refuses to move it at all — so a webhook marking this order paid between
     * the read and the write cannot be overtaken by the button.
     *
     * ═══════════════════════════════════════════════════════════════════════
     * IT DOES NOT CALL THE PROVIDER, AND THAT IS DELIBERATE
     * ═══════════════════════════════════════════════════════════════════════
     *
     * Store\CheckoutController::cardAbandoned() cancels the Stripe intent
     * before it releases anything, because a card intent left confirmable is a
     * payment a stale tab can still take. This leg has no such thing to cancel:
     * RemoteGateway::returnUrl() points `cancel` and `failure` here, and both
     * mean the plan was never approved, so there is no authorisation to void.
     *
     * Reaching for Tabby or Tamara anyway would put a third party's latency in
     * front of a button a shopper is waiting on, and would make this endpoint
     * able to hang. An authorisation that somehow does exist is released from
     * Orders → (the order) → Items, which is what that control is for — see
     * docs/OD-RELEASE-THE-HOLD.md.
     *
     * ═══════════════════════════════════════════════════════════════════════
     * AND IT SENDS THEM TO THE BASKET, NOT TO THE CHECKOUT
     * ═══════════════════════════════════════════════════════════════════════
     *
     * App\Services\SetStockReconciler runs when the cart page and the checkout
     * are RENDERED, and a basket that has been away at Tamara can come back to
     * a shelf that moved under it — somebody else bought the last jar while
     * they were gone. So a restored basket holding a set and one of its members
     * loose has to be drawn before it is paid for, or this method hands back
     * exactly the basket the owner reported as unplaceable.
     *
     * Both pages reconcile, so either would satisfy that. The basket is the
     * right one anyway: the reconciler's own header says a shopper must not
     * "pay for a basket they never agreed to, at a total they never saw", and
     * dropping them on the checkout with a line silently trimmed and a
     * different total is that sentence exactly.
     */
    public function restore(Request $request): RedirectResponse
    {
        /*
         * BOTH FACTS COME OUT OF THIS BROWSER'S OWN SESSION, and NEITHER comes
         * out of the request.
         *
         * pending() identifies its order from `?order=` checked against
         * `kbb_last_order`, because a provider's return address is where that
         * number arrives. A POST from a button has no such address, and reading
         * an order number out of the form would be reading it from whatever was
         * posted — so this reads `kbb_last_order` directly and never trusts the
         * request at all. (The first version of this method called
         * orderThisSessionPlaced(), which looks in the QUERY STRING: every press
         * answered "there is nothing to put back" and every guard below was
         * untested. The suite caught it; the reason it could is that the cases
         * drive the button rather than the method.)
         *
         * THE SECOND HALF OF THE GATE IS NOT BELT AND BRACES, and it now lives
         * on offeredOrderNumber() so that the PARTIAL WHICH DRAWS THE BUTTON
         * reads the same comparison this does. `kbb_last_order` MOVES: place()
         * overwrites it with every order this browser makes. Without the
         * comparison a shopper who abandoned at Tamara and then placed a second
         * order successfully would still have the button on their basket page —
         * and pressing it would fail the NEW order, which is the one thing on
         * this page that must never happen. Without the partial reading it too,
         * the button was DRAWN in that state and merely refused when pressed,
         * which is the defect the header on offeredOrderNumber() describes.
         */
        $offered = self::offeredOrderNumber();

        if ($offered === '') {
            return $this->nothingToPutBack($request);
        }

        $order = Order::where('order_number', $offered)->first();

        if ($order === null) {
            return $this->nothingToPutBack($request);
        }

        if ($order->paid_at !== null || $this->placement->forOrder($order) === PlacementState::CONFIRMED) {
            // BOTH halves. This forgot the number and left the token behind,
            // which is the half-forgotten offer forgetOffer() exists to make
            // impossible: a token with no number is a row nothing will ever
            // look at again, kept in the session of a shopper who has paid.
            $this->forgetOffer($request);

            return $this->toReceipt($request, $order);
        }

        /*
         * THE BASKET THE OFFER WAS MADE FOR, BY THE TOKEN REMEMBERED WITH IT —
         * NOT BY THE COOKIE THIS REQUEST HAPPENS TO CARRY.
         *
         * `orders` has never carried a cart id, so a token is the only handle
         * there is; `status` is still the single field that decides whether a
         * basket is live, exactly as in cardAbandoned(). What differs is WHERE
         * the token comes from, and it has to: between the offer and the press
         * the shopper's cookie has moved on to a fresh empty cart the basket
         * page minted for them. See RESTORABLE_CART_KEY.
         *
         * The token was read off this browser's own cookie when the offer was
         * written, so nothing here trusts anything the shopper could choose.
         */
        $token = trim((string) $request->session()->get(self::RESTORABLE_CART_KEY, ''));

        $cart = $token === '' ? null : Cart::query()
            ->where('token', $token)
            ->where('status', 'converted')
            ->first();

        if ($cart === null || ! $cart->items()->exists()) {
            return $this->nothingToPutBack($request);
        }

        $restored = false;

        DB::transaction(function () use ($order, $cart, &$restored) {
            // Lane RL: the shopper is looking at this failure on screen, so no
            // "payment failed" email for it -- the 30-minute reminder follows up.
            app(\App\Services\Mail\OrderStatusMailPolicy::class)->decideFor($order, false);
            app(OrderStatus::class)->moveTo(
                $order,
                'failed',
                by: 'system',
                reason: 'The shopper put their basket back after the payment was not completed.',
                only: ['paid_at' => null],
            );

            /*
             * ▲ THE BASKET GOES BACK ONLY IF THE ORDER REALLY IS OVER, AND THAT
             * IS READ OFF THE ROW RATHER THAN OFF moveTo()'s ANSWER.
             *
             * This block used to assign moveTo()'s return to `$moved` and never
             * read it, with the cart write below unconditional underneath. A
             * webhook confirming the payment in the instant between restore()'s
             * guard and moveTo()'s own locked re-read makes that write refuse —
             * correctly, `only: ['paid_at' => null]` is exactly for this — and
             * the basket went back ANYWAY. The shopper then holds a live basket
             * of goods they have just been charged for, and the stock is never
             * released because the order never moved. A guarded write that
             * leaves state behind does not contain a failure, it seeds one.
             *
             * moveTo()'s null CANNOT be the test, because it means two things:
             * "the precondition did not hold" and "the order was already there
             * with nothing else to record". The second is an ordinary shopper
             * whose order the provider had already failed, and they are owed
             * their basket. So the question is asked of the row instead, which
             * answers both at once: is this order over, and did it take no
             * money?
             *
             * LOCKED, and inside the same transaction as the cart write, so
             * nothing can confirm the payment between the answer and the act.
             */
            $fresh = Order::query()->whereKey($order->getKey())->lockForUpdate()->first();

            $restored = $fresh !== null
                && $fresh->paid_at === null
                && $this->placement->forOrder($fresh) === PlacementState::REFUSED;

            if (! $restored) {
                return;
            }

            /*
             * INSIDE THE SAME TRANSACTION AS THE STATUS MOVE. The two are one
             * fact — this order is over and that basket is live again — and a
             * half of it is the worst of both: a basket the shopper can pay
             * with twice, or an order nothing releases.
             */
            $cart->forceFill(['status' => 'active', 'converted_at' => null, 'last_activity_at' => now()])->save();
        });

        /*
         * THE ORDER MOVED UNDER THEM. Nothing was written to either row, so
         * there is nothing to undo — and they are sent where the truth about
         * their order is, which is the same place the paid guard above sends
         * anyone else whose payment turned out to have gone through.
         */
        if (! $restored) {
            $this->forgetOffer($request);

            return $this->toReceipt($request, $order);
        }

        /*
         * AND THE BROWSER IS POINTED BACK AT IT. Putting the row to `active` is
         * only half of a restore: this shopper is carrying the token of the
         * empty cart the basket page gave them on the way in, and without this
         * they would land on /cart/ and see it. The empty row is left behind
         * rather than deleted — it holds nothing, and the abandoned-cart
         * cleanup owns rows nobody is using.
         */
        $this->carts->adopt($cart);

        $this->forgetOffer($request);
        $request->session()->forget('kbb_last_order');

        return redirect(Url::redirect('/cart/', $request))->with(self::RESTORED_KEY, '1');
    }

    /**
     * Nothing was found to put back, said the same way whatever the reason.
     *
     * A number that is not this session's, a button pressed twice, a basket
     * that has already been restored and a basket that never existed all get
     * this. Telling them apart would be telling a stranger which order numbers
     * are real, on an endpoint that takes no authentication.
     */
    private function nothingToPutBack(Request $request): RedirectResponse
    {
        $this->forgetOffer($request);

        return redirect(Url::redirect('/cart/', $request))
            ->withErrors(__('store.checkout.restore_gone'));
    }

    /**
     * Offer the button, or do not.
     *
     * The ORDER NUMBER rather than a flag, so restore() can check that the
     * offer and `kbb_last_order` still name the same order — see the note there
     * about a shopper who abandons one payment and then completes another.
     *
     * A session value rather than a flash: a flash survives exactly one request,
     * so reloading the basket page would take the button away while the basket
     * is still perfectly restorable. It is forgotten when it is used, when it is
     * refused, and when it stops matching `kbb_last_order`.
     *
     * NO QUERY IS ADDED TO THE BASKET PAGE BY THIS. The decision is made here,
     * once, on the request that already loaded the cart; the partial that draws
     * the button reads the session and nothing else.
     */
    private function rememberRestorable(Request $request, ?Order $order): void
    {
        if ($order === null
            || $order->paid_at !== null
            || $this->placement->forOrder($order) === PlacementState::CONFIRMED) {
            $this->forgetOffer($request);

            return;
        }

        /*
         * ▲ AN OFFER THAT IS STILL GOOD SURVIVES THE VISIT, AND THIS METHOD
         * USED TO THROW IT AWAY.
         *
         * It forgot both keys FIRST and then re-derived the basket from the
         * LIVE cookie. That is right exactly once — on the request the
         * provider sends them in on, which is the only one where the cookie
         * still names the `converted` basket. By a SECOND visit the basket page
         * has minted an empty `active` cart for that token and re-cookied the
         * browser (see RESTORABLE_CART_KEY), so re-deriving found nothing and
         * the offer it had just discarded was the last handle on that basket
         * in existence. The way back was gone for good.
         *
         * The shot run found it: re-arming by visiting this address a second
         * time printed "the offer was not written", in Chromium, against the
         * real controller. Reachable from the Back button, from a provider that
         * sends the shopper twice, and from a plain reload.
         *
         * So an offer that still names THIS order and whose REMEMBERED basket
         * is still `converted` with rows in it is kept as it is. One query either way; the
         * difference is which token it asks about.
         */
        $held = trim((string) $request->session()->get(self::RESTORABLE_CART_KEY, ''));
        $offered = trim((string) $request->session()->get(self::RESTORABLE_KEY, ''));
        $number = (string) $order->order_number;

        if ($held !== '' && $offered !== '' && hash_equals($number, $offered) && $this->stillRestorable($held)) {
            return;
        }

        $this->forgetOffer($request);

        /*
         * THE FIRST VISIT'S PATH. The cookie is still right here: the shopper
         * has just arrived from the provider and nothing has yet asked for a
         * cart with create:true. The token is taken and KEPT, because one
         * request later it names a different, empty basket.
         */
        $token = (string) $request->cookie(CartService::COOKIE);

        if ($token === '' || ! $this->stillRestorable($token)) {
            return;
        }

        $request->session()->put(self::RESTORABLE_KEY, $number);
        $request->session()->put(self::RESTORABLE_CART_KEY, $token);
    }

    /**
     * Where an order that turned out to stand sends its shopper.
     *
     * One place, because two branches of restore() reach it for the same
     * reason — the payment went through after all — and they must not drift
     * into telling the shopper two different things about one order.
     */
    private function toReceipt(Request $request, Order $order): RedirectResponse
    {
        return redirect(
            Url::redirect('/checkout/success', $request).'?order='.urlencode((string) $order->order_number)
        );
    }

    /** Is there a `converted` basket with rows in it under this token? */
    private function stillRestorable(string $token): bool
    {
        return Cart::query()
            ->where('token', $token)
            ->where('status', 'converted')
            ->whereHas('items')
            ->exists();
    }

    /**
     * Drop the offer, both halves together.
     *
     * One place, because the two keys are one fact and a half-forgotten offer
     * is the shape restore() cannot tell from a good one: the order number
     * without the token names a basket nothing can find.
     */
    private function forgetOffer(Request $request): void
    {
        $request->session()->forget(self::RESTORABLE_KEY);
        $request->session()->forget(self::RESTORABLE_CART_KEY);
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
