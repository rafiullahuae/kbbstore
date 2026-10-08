<?php

declare(strict_types=1);

namespace App\Services\Checkout;

use App\Models\Cart;
use App\Models\Order;
use App\Services\CartService;
use App\Services\Payments\GatewayRegistry;
use App\Services\Payments\SettlesBeforeRelease;
use Illuminate\Http\Request;

/**
 * A shopper who comes back without paying gets their basket back, by itself.
 * (Lane BK)
 *
 * THE DEFECT. CheckoutController::place() marks the basket `converted` in the
 * transaction that writes the order, before any money moves. Nothing on the way
 * back put it to `active` again except a button: Tabby and Tamara's cancel and
 * failure addresses landed the shopper on "Your Bag (0 items)" under a pink
 * notice and a "Put my basket back" they had to find and press. A Stripe 3-D
 * Secure page that failed by full redirect landed them on an order-received
 * page for an order that was never paid, and the browser's Back button or a
 * tab closed and reopened later found the bag empty with no button at all.
 *
 * The owner: "cart must not be empty in any case, so the user can re-try".
 *
 * THE RULE. Whenever this browser comes back to the basket, the checkout, or
 * any provider's cancel/failure/3-D Secure return, and the order it placed did
 * not take money, that order is released and its basket given back -- through
 * BasketRelease::giveBack(), the same locked transaction every release in the
 * shop goes through: the order moves to `failed` and OrderStatus hands its
 * stock and coupon back; the basket returns with its lines, sets, bundles and
 * coupon; and a basket the shopper has started since is merged into, never
 * replaced.
 *
 * PAID ALWAYS WINS, three times over:
 *   1. an order PlacementState already calls confirmed is never touched;
 *   2. an order still awaiting money is first settled with its provider
 *      (SettlesBeforeRelease): Stripe's intent is CANCELLED at Stripe before
 *      anything is released, and a Tabby or Tamara payment that did go through
 *      is applied, making the order paid;
 *   3. the move to `failed` is conditional on the status decided on, and the
 *      basket write is inside the same transaction behind a locked re-read.
 *
 * A PAYMENT STILL IN PROGRESS IS NOT UNFINISHED (Lane SW). The basket or the
 * checkout opened in another tab while the shopper answers a 3-D Secure
 * challenge must not cancel it: Stripe's settleBeforeRelease() leaves a young
 * `requires_action` / `requires_confirmation` intent alone unless the shopper
 * came back by the provider's own failure return (StripeGateway::
 * AUTHENTICATING_GRACE_SECONDS). Tabby's `CREATED` and Tamara's `new` are NOT
 * spared, deliberately: neither provider tells "on our page now" from "pressed
 * Back from our page", and Back to the checkout is the owner's own case for an
 * immediate basket ("cart must not be empty in any case"). A Tabby or Tamara
 * payment finished after such a release is recorded by PaymentConfirmer as a
 * late confirmation with an ACTION NEEDED refund note, never a revived order.
 *
 * WHO MAY TRIGGER IT. Only the browser that placed the order: the order number
 * is read from `kbb_last_order` in this session (written by place(), or by the
 * signed "Complete your order" link), never from the request -- a number in a
 * return address must MATCH it -- and the basket must be this browser's own
 * (its cookie, or the token place() remembered in this session) and converted
 * INTO that order. A guessed number restores nothing and every refusal looks
 * like "nothing to restore".
 *
 * WHAT IT COSTS. due() reads the session and nothing else, so an ordinary
 * basket or checkout view runs no query here. Only a session still holding an
 * order it has not settled does the work, and once.
 */
final class UnfinishedPayment
{
    /** The basket token place() converted, so a later new cookie cannot lose it. */
    public const BASKET_KEY = 'kbb_last_basket';

    /** The order number already looked at and found final, so it is not asked twice. */
    public const CHECKED_KEY = 'kbb_unfinished_checked';

    /**
     * What the page says once: 'restored' or 'merged'. The same session key
     * "Put my basket back" always used, so a page that reads one reads both.
     */
    public const BACK_KEY = 'kbb_basket_restored';

    /**
     * [order number, outcome] of the basket this session was last given back.
     * A return address answered twice must answer the same way twice: the
     * shop's own Site App service worker re-sends a redirected navigation
     * (measured in Chromium: one press of Tabby's cancel, two GETs of
     * /checkout/pending), and so do a reload and a double-tap. Without this
     * the second copy found nothing to restore and drew the old sentence.
     */
    public const GIVEN_BACK_KEY = 'kbb_basket_given_back';

    public const PAID = 'paid';

    public const NONE = 'none';

    /** The provider could not say yet; nothing was written, and it is asked again next time. */
    public const WAITING = 'waiting';

    public function __construct(
        private readonly BasketRelease $release,
        private readonly PlacementState $placement,
        private readonly GatewayRegistry $gateways,
        private readonly CartService $carts,
    ) {}

    /**
     * Where a return address sends a shopper whose basket was given back.
     *
     * The outcome rides in the ADDRESS, not only in a flash: the Site App's
     * service worker re-sends a redirected navigation, and the copy the
     * browser finally draws arrives after another copy has already aged the
     * flash away -- measured, the basket came back with no sentence over it.
     * The page draws the sentence only when this session really was given a
     * basket back (noticeFor()), so a crafted link shows nothing.
     */
    public static function cartUrl(Request $request, string $outcome): string
    {
        return \App\Support\Url::redirect('/cart/', $request).'?back='.($outcome === BasketRelease::MERGED ? 'merged' : 'restored');
    }

    /** 'restored', 'merged' or '' -- what the basket or checkout page should say. Session and query only. */
    public static function noticeFor(Request $request): string
    {
        if (! $request->hasSession()) {
            return '';
        }

        $now = (string) $request->session()->get(self::BACK_KEY, '');

        if ($now === BasketRelease::RESTORED || $now === BasketRelease::MERGED) {
            return $now;
        }

        $asked = (string) $request->query('back', '');
        $given = $request->session()->get(self::GIVEN_BACK_KEY);

        if (($asked === BasketRelease::RESTORED || $asked === BasketRelease::MERGED)
            && is_array($given) && ($given[1] ?? null) === $asked) {
            return $asked;
        }

        return '';
    }

    /** Is there an unsettled order in this session? Session only, no query. */
    public static function due(Request $request): bool
    {
        if (! $request->hasSession()) {
            return false;
        }

        $mine = trim((string) $request->session()->get('kbb_last_order', ''));

        return $mine !== '' && $mine !== (string) $request->session()->get(self::CHECKED_KEY, '');
    }

    /**
     * Settle this browser's last order and give its basket back if it took no money.
     *
     * $number, when given, is the order a return address names: it must be
     * this session's own, or nothing happens. $shopperCameBack is true on a
     * provider's own cancel/failure return (see SettlesBeforeRelease).
     *
     * @return array{0: string, 1: ?Order}  [RESTORED|MERGED|PAID|NONE|WAITING, the order]
     */
    public function recover(Request $request, bool $shopperCameBack, ?string $number = null): array
    {
        $session = $request->session();
        $mine = trim((string) $session->get('kbb_last_order', ''));

        if ($mine === '' && $number !== null) {
            $given = $session->get(self::GIVEN_BACK_KEY);

            if (is_array($given) && count($given) === 2 && trim($number) !== ''
                && hash_equals((string) $given[0], trim($number))) {
                return [(string) $given[1], null];
            }
        }

        if ($mine === '' || ($number !== null && ! hash_equals($mine, trim($number)))) {
            return [self::NONE, null];
        }

        $order = Order::where('order_number', $mine)->first();

        if ($order === null) {
            $session->put(self::CHECKED_KEY, $mine);

            return [self::NONE, null];
        }

        if ($this->placement->forOrder($order) === PlacementState::CONFIRMED) {
            $session->put(self::CHECKED_KEY, $mine);

            return [self::PAID, $order];
        }

        $basket = $this->basketOf($request, $order);

        if ($basket === null) {
            $session->put(self::CHECKED_KEY, $mine);

            return [self::NONE, $order];
        }

        if ($this->placement->forOrder($order) === PlacementState::AWAITING) {
            $gateway = $this->gateways->find((string) $order->payment_method);

            // A gateway that cannot be asked is not released: it may be taking
            // the money right now. Every real gateway can be (see the test).
            $mayRelease = $gateway instanceof SettlesBeforeRelease
                && $gateway->settleBeforeRelease($order, $shopperCameBack);

            $order->refresh();

            if ($this->placement->forOrder($order) === PlacementState::CONFIRMED) {
                $session->put(self::CHECKED_KEY, $mine);

                return [self::PAID, $order];
            }

            if (! $mayRelease) {
                return [self::WAITING, $order];
            }
        }

        [$live, $liveHadItems] = $this->liveBasket($request, $basket);

        $result = $this->release->giveBack(
            $order,
            $basket,
            $live,
            'The shopper came back without finishing the payment; nothing was charged and their basket was given back.',
        );

        if ($result === BasketRelease::HELD) {
            // The order moved under us -- a confirmation landed first. Final.
            $order->refresh();
            $session->put(self::CHECKED_KEY, $mine);

            return [$this->placement->forOrder($order) === PlacementState::CONFIRMED ? self::PAID : self::NONE, $order];
        }

        if ($result === BasketRelease::GONE) {
            $session->put(self::CHECKED_KEY, $mine);

            return [self::NONE, $order];
        }

        $session->forget(['kbb_last_order', self::BASKET_KEY, self::CHECKED_KEY, 'kbb_restorable', 'kbb_restorable_cart']);

        // The gift-wrap tick place() cleared is the shopper's choice; it comes back too.
        if ($order->is_gift) {
            $session->put('kbb_gift', true);
        }

        $this->carts->adopt(($result === BasketRelease::MERGED ? $live : $basket)->fresh());

        $said = $result === BasketRelease::MERGED && $liveHadItems ? BasketRelease::MERGED : BasketRelease::RESTORED;
        $session->put(self::GIVEN_BACK_KEY, [$mine, $said]);

        return [$said, $order];
    }

    /**
     * The converted basket of THIS browser that became THIS order, or null.
     * One query: the remembered token and the cookie's, whichever names it.
     */
    private function basketOf(Request $request, Order $order): ?Cart
    {
        $tokens = array_values(array_unique(array_filter([
            trim((string) $request->session()->get(self::BASKET_KEY, '')),
            trim((string) $request->cookie(CartService::COOKIE, '')),
        ], fn (string $t) => $t !== '')));

        if ($tokens === []) {
            return null;
        }

        $basket = Cart::query()
            ->whereIn('token', $tokens)
            ->where('status', 'converted')
            ->withCount('items')
            ->get()
            ->first(fn (Cart $c) => $c->items_count > 0 && $this->release->cartBelongsTo($c, $order));

        return $basket instanceof Cart ? $basket : null;
    }

    /**
     * The basket this browser holds NOW, when it is a different one -- the
     * shopper came back and started again before returning. [cart, had items]
     *
     * @return array{0: ?Cart, 1: bool}
     */
    private function liveBasket(Request $request, Cart $basket): array
    {
        $live = $this->carts->current($request, create: false);

        if ($live === null || (int) $live->getKey() === (int) $basket->getKey()) {
            return [null, false];
        }

        return [$live, $live->items()->exists()];
    }
}
